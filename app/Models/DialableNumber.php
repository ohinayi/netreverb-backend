<?php

namespace App\Models;

use App\Enums\DialableNumberType;
use Database\Factories\DialableNumberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['organization_id', 'workspace_id', 'realm', 'number', 'type'])]
class DialableNumber extends Model
{
    /** @use HasFactory<DialableNumberFactory> */
    use HasFactory, HasUlids;

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function extension(): HasOne
    {
        return $this->hasOne(Extension::class);
    }

    public function serviceNumber(): HasOne
    {
        return $this->hasOne(ServiceNumber::class);
    }

    protected function casts(): array
    {
        return ['type' => DialableNumberType::class];
    }
}
