<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['extension_id', 'password', 'version', 'rotated_at'])]
#[Hidden(['password'])]
class SipCredential extends Model
{
    protected $attributes = ['version' => 1];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class);
    }

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'rotated_at' => 'datetime',
        ];
    }
}
