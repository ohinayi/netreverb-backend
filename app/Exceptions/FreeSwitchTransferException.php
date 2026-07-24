<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class FreeSwitchTransferException extends RuntimeException implements ShouldntReport {}
