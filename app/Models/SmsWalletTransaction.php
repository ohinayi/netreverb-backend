<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'sms_wallet_id', 'sms_credit_purchase_id', 'outbound_message_id',
    'created_by_user_id', 'idempotency_key', 'type', 'units', 'balance_after',
    'description', 'metadata',
])]
class SmsWalletTransaction extends Model
{
    public const UPDATED_AT = null;

    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(SmsWallet::class, 'sms_wallet_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(SmsCreditPurchase::class, 'sms_credit_purchase_id');
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(OutboundMessage::class);
    }

    protected function casts(): array
    {
        return [
            'units' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
