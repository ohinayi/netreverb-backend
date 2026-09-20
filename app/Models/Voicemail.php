<?php

namespace App\Models;

use Database\Factories\VoicemailFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'organization_id', 'workspace_id', 'extension_id',
    'caller_number', 'file_path', 'duration_seconds', 'listened_at',
])]
class Voicemail extends Model
{
    /** @use HasFactory<VoicemailFactory> */
    use BelongsToWorkspace, HasFactory, HasUlids, SoftDeletes;

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

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'listened_at' => 'datetime',
        ];
    }
}
