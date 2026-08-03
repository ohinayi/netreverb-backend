<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiAssistant extends Model
{
    use BelongsToWorkspace, HasUlids, SoftDeletes;

    protected $fillable = ['organization_id', 'workspace_id', 'extension_id', 'name', 'enabled', 'language', 'welcome_message', 'system_instruction', 'knowledge', 'handoff_rules'];

    protected $attributes = ['enabled' => false, 'language' => 'en'];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(AiAssistantField::class)->orderBy('sort_order');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(AiAssistantSession::class);
    }

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'knowledge' => 'array', 'handoff_rules' => 'array'];
    }
}
