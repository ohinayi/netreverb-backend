<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['realm', 'next_number'])]
class NumberSequence extends Model
{
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
