<?php

namespace App\Models;

use App\Enums\CallRecordingStatus;
use App\Enums\CallStatus;
use Database\Factories\CallLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id',
    'caller_extension_id',
    'callee_extension_id',
    'caller_number',
    'callee_number',
    'status',
    'duration',
    'freeswitch_uuid',
    'recording_url',
    'recording_id',
    'recording_uuid',
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
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => CallStatus::Ringing->value,
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

    protected function casts(): array
    {
        return [
            'status' => CallStatus::class,
            'duration' => 'integer',
            'recording_status' => CallRecordingStatus::class,
            'recording_duration' => 'integer',
            'recording_size' => 'integer',
            'recording_started_at' => 'datetime',
            'recording_ended_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }
}
