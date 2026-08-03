<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sms_credit_purchase_id', 'provider', 'provider_event_id', 'event_type',
    'status', 'payload_hash', 'metadata', 'processing_error', 'processed_at',
])]
class PaymentWebhookEvent extends Model
{
    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(SmsCreditPurchase::class, 'sms_credit_purchase_id');
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
