<?php

namespace App\Models;

use App\Enums\CallRecordingAnnouncementTarget;
use App\Enums\ExtensionProvisioningMode;
use App\Enums\MembershipStatus;
use App\Enums\OrganizationStatus;
use App\Enums\RingbackAdStatus;
use App\Support\FeatureCatalog;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'slug',
    'status',
    'extension_provisioning_mode',
    'timezone',
    'locale',
    'settings',
    'pricing_group_id',
    'payment_required',
    'payment_confirmed',
    'ad_exempt',
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

    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
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

    public function callQueues(): HasMany
    {
        return $this->hasMany(CallQueue::class);
    }

    public function serviceNumbers(): HasMany
    {
        return $this->hasMany(ServiceNumber::class);
    }

    public function conferenceRooms(): HasMany
    {
        return $this->hasMany(ConferenceRoom::class);
    }

    public function ivrs(): HasMany
    {
        return $this->hasMany(OrganizationIvr::class);
    }

    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    public function dialableNumbers(): HasMany
    {
        return $this->hasMany(DialableNumber::class);
    }

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }

    public function aiAssistants(): HasMany
    {
        return $this->hasMany(AiAssistant::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function messageTemplates(): HasMany
    {
        return $this->hasMany(MessageTemplate::class);
    }

    public function outboundMessages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class);
    }

    public function smsWallet(): HasOne
    {
        return $this->hasOne(SmsWallet::class);
    }

    public function smsCreditPurchases(): HasMany
    {
        return $this->hasMany(SmsCreditPurchase::class);
    }

    public function pricingGroup(): BelongsTo
    {
        return $this->belongsTo(PricingGroup::class);
    }

    /**
     * True by default for every organization - nothing is paywalled until an
     * admin explicitly activates billing for this specific org
     * (payment_required). Once active, a feature is only available once
     * payment has also been confirmed and the assigned plan actually
     * includes it.
     */
    public function hasFeature(string $key): bool
    {
        if (! $this->payment_required) {
            return true;
        }

        if (! $this->payment_confirmed) {
            return false;
        }

        return $this->pricingGroup?->hasFeature($key) ?? false;
    }

    /**
     * The full catalog when access isn't gated, or only what the confirmed
     * plan actually includes otherwise - lets the frontend hide UI for a
     * feature without needing to know the gating rules itself.
     *
     * @return list<string>
     */
    public function availableFeatures(): array
    {
        if (! $this->payment_required) {
            return FeatureCatalog::keys();
        }

        if (! $this->payment_confirmed) {
            return [];
        }

        return $this->pricingGroup?->features ?? [];
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
        $requestedAudioPath = $announcementSettings['audio_path'] ?? $defaultAudioPath;
        $allowedAudioPaths = config('telephony.call_recordings.announcement.allowed_audio_paths', []);
        $audioPath = in_array($requestedAudioPath, $allowedAudioPaths, true)
            ? $requestedAudioPath
            : $defaultAudioPath;

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

    /**
     * The org's custom ringback/hold audio, played instead of the default
     * tone while an external caller waits - both for an inbound service
     * number call (FreeSWITCH ringback=) and for an extension-to-extension
     * call (client-side CallerWaitAudioController), which never touches
     * FreeSWITCH and so cannot share a server-side ringback= mechanism.
     */
    public function ringbackAudioPath(): ?string
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $path = $settings['ringback_audio']['audio_path'] ?? null;

        return is_string($path) && trim($path) !== '' ? trim($path) : null;
    }

    public function ringbackAds(): HasMany
    {
        return $this->hasMany(RingbackAd::class);
    }

    /**
     * What actually plays for a waiting caller: the org's own custom audio
     * only if it has separately paid for ad-exemption - otherwise (and
     * always, for a personal/individual workspace, which can never be
     * exempt) a random clip from the shared, moderated ad pool. Null means
     * fall back to the default tone - either no custom audio was uploaded,
     * or the ad pool is currently empty.
     */
    public function effectiveRingbackAudioPath(): ?string
    {
        if ($this->ad_exempt && ! $this->isPersonalWorkspace()) {
            return $this->ringbackAudioPath();
        }

        $ad = RingbackAd::query()
            ->where('status', RingbackAdStatus::Approved)
            ->where('enabled', true)
            ->inRandomOrder()
            ->first();

        return $ad?->audio_path;
    }

    /**
     * Organization-controlled operational policy with conservative bounds.
     *
     * @return array{
     *     transfers_enabled: bool,
     *     agent_recording_enabled: bool,
     *     supervisor_recording_access: bool,
     *     supervisor_queue_management: bool,
     *     recording_retention_days: int,
     *     campaigns_enabled: bool,
     *     campaign_max_recipients: int,
     *     campaign_rate_limit_per_minute: int
     * }
     */
    public function operationalPolicy(): array
    {
        $settings = is_array($this->settings) ? $this->settings : [];
        $policy = $settings['operational_policy'] ?? [];
        $policy = is_array($policy) ? $policy : [];

        return [
            'transfers_enabled' => (bool) ($policy['transfers_enabled'] ?? true),
            'agent_recording_enabled' => (bool) ($policy['agent_recording_enabled'] ?? true),
            'supervisor_recording_access' => (bool) ($policy['supervisor_recording_access'] ?? true),
            'supervisor_queue_management' => (bool) ($policy['supervisor_queue_management'] ?? true),
            'recording_retention_days' => min(3650, max(
                1,
                (int) ($policy['recording_retention_days']
                    ?? config('telephony.call_recordings.retention_days', 30)),
            )),
            'campaigns_enabled' => (bool) ($policy['campaigns_enabled'] ?? true),
            'campaign_max_recipients' => min(5000, max(
                1,
                (int) ($policy['campaign_max_recipients'] ?? 5000),
            )),
            'campaign_rate_limit_per_minute' => min(300, max(
                1,
                (int) ($policy['campaign_rate_limit_per_minute'] ?? 60),
            )),
        ];
    }

    public function policyAllows(string $capability): bool
    {
        return (bool) ($this->operationalPolicy()[$capability] ?? false);
    }

    public function recordingRetentionDays(): int
    {
        return $this->operationalPolicy()['recording_retention_days'];
    }

    /**
     * Individual users retain a private workspace so existing extensions,
     * call logs and SIP provisioning continue to have a tenant boundary. It
     * must not expose organization administration capabilities.
     */
    public function isPersonalWorkspace(): bool
    {
        $settings = is_array($this->settings) ? $this->settings : [];

        return ($settings['kind'] ?? null) === 'individual';
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

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
            'payment_required' => 'boolean',
            'ad_exempt' => 'boolean',
            'payment_confirmed' => 'boolean',
        ];
    }
}
