<?php

namespace App\Models;

use App\Enums\AiAssistantResponseMode;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantSession extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $fillable = ['organization_id', 'workspace_id', 'ai_assistant_id', 'call_log_id', 'freeswitch_uuid', 'status', 'mode', 'bridge_session_token', 'transcript', 'captured_data', 'provider_metadata', 'current_field_key', 'pending_value', 'answer_processing_started_at', 'answer_ready_at', 'retry_count', 'duration_seconds', 'started_at', 'completed_at'];

    protected $attributes = ['status' => 'pending', 'mode' => 'turn_based', 'duration_seconds' => 0, 'retry_count' => 0];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'ai_assistant_id');
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class);
    }

    protected function casts(): array
    {
        return ['mode' => AiAssistantResponseMode::class, 'captured_data' => 'array', 'provider_metadata' => 'array', 'retry_count' => 'integer', 'duration_seconds' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'answer_processing_started_at' => 'datetime', 'answer_ready_at' => 'datetime'];
    }
}
