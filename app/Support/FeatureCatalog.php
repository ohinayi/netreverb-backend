<?php

namespace App\Support;

/**
 * The fixed set of modules a pricing group can gate. Kept here rather than
 * free-text so the admin UI can offer a checklist instead of inventing
 * feature keys that nothing in the app actually checks, and shared between
 * PricingGroup/Organization (feature resolution) and the super-admin
 * pricing controller (validation/UI catalog) rather than duplicated.
 */
class FeatureCatalog
{
    public const MODULES = [
        'sip_calling' => 'SIP & WebRTC calling',
        'call_recording' => 'Call recording',
        'messaging' => 'Messages',
        'conference_rooms' => 'Conference rooms',
        'ai_assistants' => 'AI voice agents',
        'translation' => 'Real-time translation',
        'outbound_messaging' => 'Outbound messaging & campaigns',
        'sms_wallet' => 'SMS credits',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::MODULES);
    }
}
