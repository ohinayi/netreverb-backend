<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Tables that represent an operational workspace boundary. Billing remains organization-scoped. */
    private array $scopedTables = [
        'organization_memberships', 'departments', 'extensions', 'service_numbers',
        'dialable_numbers', 'call_logs', 'call_recording_uploads', 'conference_rooms',
        'call_queues', 'leads', 'lead_activities',
        'lead_contact_channels', 'message_templates',
        'outbound_messages', 'outbound_campaigns', 'ai_assistants', 'ai_assistant_sessions',
    ];

    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('kind', 24)->default('team')->index();
            $table->string('status', 24)->default('active')->index();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'slug']);
            $table->index(['organization_id', 'status']);
        });

        foreach ($this->scopedTables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'workspace_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('workspace_id')->nullable()->after('organization_id')
                    ->constrained('workspaces')->nullOnDelete();
                $table->index(['workspace_id', 'created_at']);
            });
        }

        // Preserve all existing data by placing it in one default workspace per
        // organization. Future workspace-aware code can move records explicitly.
        DB::table('organizations')->orderBy('id')->each(function (object $organization): void {
            $workspaceId = DB::table('workspaces')->insertGetId([
                'public_id' => (string) Str::ulid(),
                'organization_id' => $organization->id,
                'name' => $organization->name,
                'slug' => 'default',
                'kind' => (($organization->settings ?? null) && str_contains((string) $organization->settings, 'individual'))
                    ? 'personal'
                    : 'team',
                'status' => 'active',
                'settings' => json_encode(['system_default' => true]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($this->scopedTables as $tableName) {
                if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'workspace_id')) {
                    DB::table($tableName)
                        ->where('organization_id', $organization->id)
                        ->whereNull('workspace_id')
                        ->update(['workspace_id' => $workspaceId]);
                }
            }
        });
    }

    public function down(): void
    {
        foreach (array_reverse($this->scopedTables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'workspace_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['workspace_id']);
                $table->dropIndex(['workspace_id', 'created_at']);
                $table->dropColumn('workspace_id');
            });
        }

        Schema::dropIfExists('workspaces');
    }
};
