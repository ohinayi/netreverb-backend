<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outbound_campaign_id', 'lead_id', 'outbound_message_id', 'status',
    'blocked_reason', 'attempts', 'processed_at',
])]
class OutboundCampaignRecipient extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(OutboundCampaign::class, 'outbound_campaign_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
