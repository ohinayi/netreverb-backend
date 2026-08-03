<?php

namespace App\Contracts\Ai;

interface StructuredAssistantProvider
{
    /** @param array<string, mixed> $schema @return array<string, mixed> */
    public function extract(string $instruction, string $transcript, array $schema): array;
}
