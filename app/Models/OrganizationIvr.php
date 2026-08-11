<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['organization_id', 'name', 'welcome_text', 'welcome_audio_path', 'tts_voice', 'tts_speed', 'max_retries', 'timeout_seconds', 'enabled'])]
class OrganizationIvr extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'organization_ivrs';

    public function uniqueIds(): array { return ['public_id']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function options(): HasMany { return $this->hasMany(OrganizationIvrOption::class)->orderBy('sort_order'); }
    protected function casts(): array { return ['enabled' => 'boolean', 'max_retries' => 'integer', 'timeout_seconds' => 'integer', 'tts_speed' => 'float']; }
}
