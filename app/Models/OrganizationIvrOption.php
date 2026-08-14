<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_ivr_id', 'digit', 'label', 'destination_type', 'destination', 'directive_text', 'directive_audio_path', 'sort_order', 'enabled'])]
class OrganizationIvrOption extends Model
{
    protected $table = 'organization_ivr_options';
    public function ivr(): BelongsTo { return $this->belongsTo(OrganizationIvr::class, 'organization_ivr_id'); }
    protected function casts(): array { return ['enabled' => 'boolean', 'sort_order' => 'integer']; }
}
