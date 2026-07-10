<?php

namespace App\Models;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Enums\ExtensionProvisioningMode;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'status',
    'extension_provisioning_mode',
    'timezone',
    'locale',
    'settings',
])]
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $attributes = [
        'status' => OrganizationStatus::Active->value,
        'extension_provisioning_mode' => ExtensionProvisioningMode::Manual->value,
        'timezone' => 'UTC',
        'locale' => 'en',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['public_id', 'role', 'status', 'joined_at'])
            ->withTimestamps();
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class);
    }

    public function serviceNumbers(): HasMany
    {
        return $this->hasMany(ServiceNumber::class);
    }

    public function conferenceRooms(): HasMany
    {
        return $this->hasMany(ConferenceRoom::class);
    }

    public function dialableNumbers(): HasMany
    {
        return $this->hasMany(DialableNumber::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    /**
     * @return array{enabled: bool, target: string, audio_path: ?string}
     */
    public function callRecordingAnnouncementSettings(): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $announcementSettings = $settings['call_recording_announcement'] ?? [];

        if (! is_array($announcementSettings)) {
            $announcementSettings = [];
        }

        $defaultEnabled = (bool) config('telephony.call_recordings.announcement.enabled', true);
        $defaultTarget = (string) config('telephony.call_recordings.announcement.default_target', CallRecordingAnnouncementTarget::Both->value);
        $defaultAudioPath = config('telephony.call_recordings.announcement.default_audio_path');
        $audioPath = $announcementSettings['audio_path'] ?? $defaultAudioPath;

        return [
            'enabled' => (bool) ($announcementSettings['enabled'] ?? $defaultEnabled),
            'target' => (string) ($announcementSettings['target'] ?? $defaultTarget),
            'audio_path' => is_string($audioPath) && trim($audioPath) !== '' ? trim($audioPath) : null,
        ];
    }

    public function shouldAnnounceCallRecording(): bool
    {
        return $this->callRecordingAnnouncementSettings()['enabled'];
    }

    public function callRecordingAnnouncementTarget(): CallRecordingAnnouncementTarget
    {
        return CallRecordingAnnouncementTarget::tryFrom(
            $this->callRecordingAnnouncementSettings()['target'],
        ) ?? CallRecordingAnnouncementTarget::Both;
    }

    public function callRecordingAnnouncementAudioPath(): ?string
    {
        return $this->callRecordingAnnouncementSettings()['audio_path'];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas(
            'memberships',
            fn (Builder $membershipQuery): Builder => $membershipQuery
                ->whereBelongsTo($user)
                ->where('status', MembershipStatus::Active),
        );
    }

    protected function casts(): array
    {
        return [
            'status' => OrganizationStatus::class,
            'extension_provisioning_mode' => ExtensionProvisioningMode::class,
            'settings' => 'array',
        ];
    }
}
