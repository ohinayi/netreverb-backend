<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageRecord extends Model
{
    protected $fillable = ['organization_id', 'ai_assistant_session_id', 'provider', 'usage_type', 'input_units', 'output_units', 'duration_seconds', 'metadata'];

    protected $attributes = ['input_units' => 0, 'output_units' => 0, 'duration_seconds' => 0];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assistantSession(): BelongsTo
    {
        return $this->belongsTo(AiAssistantSession::class, 'ai_assistant_session_id');
    }

    protected function casts(): array
    {
        return ['input_units' => 'integer', 'output_units' => 'integer', 'duration_seconds' => 'integer', 'metadata' => 'array'];
    }
}
