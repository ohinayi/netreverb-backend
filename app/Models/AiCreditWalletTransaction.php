<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ai_credit_wallet_id', 'ai_credit_purchase_id', 'ai_assistant_session_id',
    'created_by_user_id', 'idempotency_key', 'type', 'units', 'balance_after',
    'description', 'metadata',
])]
class AiCreditWalletTransaction extends Model
{
    public const UPDATED_AT = null;

    use HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(AiCreditWallet::class, 'ai_credit_wallet_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(AiCreditPurchase::class, 'ai_credit_purchase_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiAssistantSession::class, 'ai_assistant_session_id');
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
