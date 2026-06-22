<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Database\Factories\FriendshipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'requester_id',
    'addressee_id',
    'status',
    'requested_at',
    'responded_at',
    'note',
])]
class Friendship extends Model
{
    /** @use HasFactory<FriendshipFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => FriendshipStatus::Pending->value,
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }

    protected function casts(): array
    {
        return [
            'status' => FriendshipStatus::class,
            'requested_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }
}
