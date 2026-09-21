<?php

namespace App\Models;

use App\Enums\CallLogParticipantStatus;
use Database\Factories\CallLogParticipantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['call_log_id', 'extension_id', 'added_by_user_id', 'freeswitch_uuid', 'status', 'joined_at', 'left_at'])]
class CallLogParticipant extends Model
{
    /** @use HasFactory<CallLogParticipantFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => CallLogParticipantStatus::Ringing->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_user_id');
    }

    protected function casts(): array
    {
        return [
            'status' => CallLogParticipantStatus::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }
}
