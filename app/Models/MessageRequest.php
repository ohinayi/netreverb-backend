<?php

namespace App\Models;

use App\Enums\MessageRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'sender_user_id',
    'recipient_user_id',
    'body',
    'status',
    'conversation_id',
    'responded_at',
])]
class MessageRequest extends Model
{
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => MessageRequestStatus::Pending->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    protected function casts(): array
    {
        return [
            'status' => MessageRequestStatus::class,
            'responded_at' => 'datetime',
        ];
    }
}
