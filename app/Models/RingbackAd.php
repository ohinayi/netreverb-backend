<?php

namespace App\Models;

use App\Enums\RingbackAdStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'title', 'audio_path', 'status', 'enabled', 'rejection_reason', 'reviewed_by_user_id', 'reviewed_at'])]
class RingbackAd extends Model
{
    use HasUlids;

    public function uniqueIds(): array { return ['public_id']; }
    public function getRouteKeyName(): string { return 'public_id'; }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function reviewedBy(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by_user_id'); }
    protected function casts(): array { return ['enabled' => 'boolean', 'status' => RingbackAdStatus::class, 'reviewed_at' => 'datetime']; }
}
