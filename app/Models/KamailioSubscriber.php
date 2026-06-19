<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['username', 'domain', 'password', 'ha1', 'ha1b'])]
#[Hidden(['password', 'ha1', 'ha1b'])]
class KamailioSubscriber extends Model
{
    protected $connection = 'kamailio';

    protected $table = 'subscriber';

    public $timestamps = false;
}
