<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            // Signals an in-call audio-to-video upgrade handshake between the
            // two parties' clients. FreeSWITCH bridges each leg as a separate
            // B2BUA dialog and does not transparently relay arbitrary SIP
            // in-dialog messages across the bridge, so this handshake rides
            // the same call-log polling loop already used for everything
            // else about an active call instead of any SIP-layer signaling.
            $table->string('video_upgrade_status')->nullable()->after('media_type');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table): void {
            $table->dropColumn('video_upgrade_status');
        });
    }
};
