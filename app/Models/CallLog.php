<?php

namespace App\Models;

use App\Enums\CallMediaType;
use App\Enums\CallRecordingMediaType;
use App\Enums\CallRecordingStatus;
use App\Enums\CallSessionType;
use App\Enums\CallStatus;
use Database\Factories\CallLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'workspace_id',
    'caller_extension_id',
    'callee_extension_id',
    'caller_number',
    'callee_number',
    'status',
    'media_type',
    'video_upgrade_status',
    'session_type',
    'duration',
    'freeswitch_uuid',
    'recording_url',
    'recording_id',
    'recording_uuid',
    'recording_media_type',
    'recording_container',
    'recording_file_path',
    'recording_file_name',
    'recording_duration',
    'recording_size',
    'recording_status',
    'recording_started_at',
    'recording_ended_at',
    'started_at',
    'ended_at',
])]
class CallLog extends Model
{
    /** @use HasFactory<CallLogFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => CallStatus::Ringing->value,
        'media_type' => CallMediaType::Audio->value,
        'session_type' => CallSessionType::Direct->value,
        'duration' => 0,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function callerExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'caller_extension_id');
    }

    public function calleeExtension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'callee_extension_id');
    }

    public function recordingUpload(): HasOne
    {
        return $this->hasOne(CallRecordingUpload::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CallNote::class)->latest();
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'media_type' => CallMediaType::class,
            'session_type' => CallSessionType::class,
            'duration' => 'integer',
            'recording_status' => CallRecordingStatus::class,
            'recording_media_type' => CallRecordingMediaType::class,
            'recording_duration' => 'integer',
            'recording_size' => 'integer',
            'recording_started_at' => 'datetime',
            'recording_ended_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
