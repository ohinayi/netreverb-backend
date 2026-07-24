<?php

namespace App\Models;

use App\Enums\ExtensionStatus;
use App\Enums\ExtensionType;
use Database\Factories\ExtensionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'dialable_number_id', 'user_id', 'display_name', 'type', 'status',
    'unavailable_action', 'fallback_extension_id', 'ring_timeout_seconds',
])]
class Extension extends Model
{
    /** @use HasFactory<ExtensionFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'type' => ExtensionType::User->value,
        'status' => ExtensionStatus::Pending->value,
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

    public function dialableNumber(): BelongsTo
    {
        return $this->belongsTo(DialableNumber::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function credential(): HasOne
    {
        return $this->hasOne(SipCredential::class);
    }

    public function provisioningState(): HasOne
    {
        return $this->hasOne(SipProvisioningState::class);
    }

    public function provisioningEvents(): HasMany
    {
        return $this->hasMany(SipProvisioningEvent::class);
    }

    public function fallbackExtension(): BelongsTo
    {
        return $this->belongsTo(self::class, 'fallback_extension_id');
    }

    protected function casts(): array
    {
        return [
            'type' => ExtensionType::class,
            'status' => ExtensionStatus::class,
        ];
    }
}
