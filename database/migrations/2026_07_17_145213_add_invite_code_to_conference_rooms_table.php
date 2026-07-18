<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table): void {
            $table->string('invite_code', 22)->nullable()->after('room_id');
            $table->unique('invite_code');
        });

        DB::table('conference_rooms')
            ->select(['id'])
            ->orderBy('id')
            ->lazyById()
            ->each(function (object $conferenceRoom): void {
                DB::table('conference_rooms')
                    ->where('id', $conferenceRoom->id)
                    ->update([
                        'invite_code' => $this->generateInviteCode(),
                    ]);
            });

        Schema::table('conference_rooms', function (Blueprint $table): void {
            $table->string('invite_code', 22)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('conference_rooms', function (Blueprint $table): void {
            $table->dropUnique(['invite_code']);
            $table->dropColumn('invite_code');
        });
    }

    private function generateInviteCode(): string
    {
        do {
            $inviteCode = Str::random(22);
        } while (DB::table('conference_rooms')->where('invite_code', $inviteCode)->exists());

        return $inviteCode;
    }
};
