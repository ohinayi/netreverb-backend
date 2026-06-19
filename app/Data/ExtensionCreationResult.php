<?php

namespace App\Data;

use App\Models\Extension;

readonly class ExtensionCreationResult
{
    public function __construct(
        public Extension $extension,
        public string $sipPassword,
    ) {}
}
