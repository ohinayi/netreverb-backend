<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A per-organization credential for the public partner REST API
 * (`v1/partner/*`, see AuthenticatePartnerApiKey). Org-level, not
 * workspace-scoped or tied to a specific human user - a partner
 * integration authenticates as the organization itself, the same way a
 * Stripe/Twilio API key does.
 */
class OrganizationApiKey extends Model
{
    use HasUlids;

    protected $fillable = ['organization_id', 'name', 'key_prefix', 'key_hash', 'abilities', 'expires_at', 'created_by_user_id'];

    protected $hidden = ['key_hash'];

    protected $attributes = ['abilities' => '["read:calls"]'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * Generates a new key, persists only its hash and a short unhashed
     * prefix (for support/log identification), and returns the plaintext -
     * the only time it's ever available. Callers must show it to the user
     * immediately and never log or store it themselves.
     *
     * @return array{plaintext: string, model: self}
     */
    public static function generate(int $organizationId, string $name, ?int $createdByUserId = null): array
    {
        $plaintext = 'nrb_live_'.Str::random(40);

        $model = self::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'key_prefix' => substr($plaintext, 0, 16),
            'key_hash' => hash('sha256', $plaintext),
            'created_by_user_id' => $createdByUserId,
        ]);

        return ['plaintext' => $plaintext, 'model' => $model];
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
