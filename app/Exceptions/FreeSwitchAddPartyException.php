<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class FreeSwitchAddPartyException extends RuntimeException implements ShouldntReport {}
