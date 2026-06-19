<?php

namespace App\Data;

readonly class SipSubscriber
{
    public function __construct(
        public string $username,
        public string $realm,
        public string $password,
    ) {}
}
