<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAssistantField extends Model
{
    use HasUlids;

    protected $fillable = ['ai_assistant_id', 'key', 'label', 'field_type', 'question', 'question_audio_path', 'required', 'options', 'sort_order'];

    protected $attributes = ['field_type' => 'text', 'required' => false, 'sort_order' => 0];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function assistant(): BelongsTo
    {
        return $this->belongsTo(AiAssistant::class, 'ai_assistant_id');
    }

    protected function casts(): array
    {
        return ['required' => 'boolean', 'options' => 'array', 'sort_order' => 'integer'];
    }
}
