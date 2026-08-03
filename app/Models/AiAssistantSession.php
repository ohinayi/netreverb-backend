<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantSession extends Model
{
    use BelongsToWorkspace, HasUlids;

    protected $fillable = ['organization_id', 'workspace_id', 'ai_assistant_id', 'call_log_id', 'status', 'transcript', 'captured_data', 'provider_metadata', 'duration_seconds', 'started_at', 'completed_at'];

    protected $attributes = ['status' => 'pending', 'duration_seconds' => 0];

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
        return ['captured_data' => 'array', 'provider_metadata' => 'array', 'duration_seconds' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }
}
