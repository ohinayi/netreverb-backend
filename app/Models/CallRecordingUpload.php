<?php

namespace App\Models;

use App\Enums\CallRecordingMediaType;
use App\Enums\CallRecordingUploadStatus;
use Database\Factories\CallRecordingUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'organization_id',
    'call_log_id',
    'recording_id',
    'status',
    'media_type',
    'container',
    'mime_type',
    'file_path',
    'file_name',
    'next_sequence',
    'uploaded_chunks_count',
    'uploaded_size',
    'upload_started_at',
    'last_chunk_received_at',
    'upload_completed_at',
    'finalized_at',
])]
class CallRecordingUpload extends Model
{
    /** @use HasFactory<CallRecordingUploadFactory> */
    use HasFactory, HasUlids;

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

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CallRecordingUploadStatus::class,
            'media_type' => CallRecordingMediaType::class,
            'next_sequence' => 'integer',
            'uploaded_chunks_count' => 'integer',
            'uploaded_size' => 'integer',
            'upload_started_at' => 'datetime',
            'last_chunk_received_at' => 'datetime',
            'upload_completed_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }
}
