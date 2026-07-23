<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('conference_room_participants', function (Blueprint $table) {
            $table->dropUnique(['conference_room_id', 'user_id']);
            $table->dropUnique(['conference_room_id', 'email']);

            $table->string('kind', 32)->default('primary')->after('role')->index();
            $table->foreignId('parent_participant_id')
                ->nullable()
                ->after('kind')
                ->constrained('conference_room_participants')
                ->cascadeOnDelete();

            $table->unique(['conference_room_id', 'user_id', 'kind'], 'conference_room_participants_room_user_kind_unique');
            $table->unique(['conference_room_id', 'email', 'kind'], 'conference_room_participants_room_email_kind_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conference_room_participants', function (Blueprint $table) {
            $table->dropUnique('conference_room_participants_room_user_kind_unique');
            $table->dropUnique('conference_room_participants_room_email_kind_unique');

            $table->dropConstrainedForeignId('parent_participant_id');
            $table->dropColumn('kind');

            $table->unique(['conference_room_id', 'user_id']);
            $table->unique(['conference_room_id', 'email']);
        });
    }
};
