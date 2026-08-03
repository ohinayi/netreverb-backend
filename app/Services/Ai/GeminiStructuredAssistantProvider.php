<?php

namespace App\Services\Ai;

use App\Contracts\Ai\StructuredAssistantProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Arr;
use RuntimeException;

class GeminiStructuredAssistantProvider implements StructuredAssistantProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function extract(string $instruction, string $transcript, array $schema): array
    {
        $apiKey = (string) config('ai.gemini.api_key');

        if ($apiKey === '') {
            throw new RuntimeException('Gemini is not configured. Set GEMINI_API_KEY in the backend environment.');
        }

        $response = $this->http->baseUrl(rtrim((string) config('ai.gemini.base_url'), '/'))
            ->acceptJson()->asJson()->connectTimeout(5)
            ->timeout((int) config('ai.gemini.timeout_seconds', 30))
            ->retry([250, 750], throw: false)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            ->post('/v1beta/models/'.config('ai.gemini.model').':generateContent', [
                'system_instruction' => ['parts' => [['text' => $instruction]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => "Call transcript:\n".$transcript]]]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseJsonSchema' => $schema,
                    'temperature' => 0,
                ],
            ]);

        $response->throw();
        $text = Arr::get($response->json(), 'candidates.0.content.parts.0.text');

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Gemini returned no structured assistant result.');
        }

        $result = json_decode($text, true);

        if (! is_array($result)) {
            throw new RuntimeException('Gemini returned invalid structured assistant JSON.');
        }

        return $this->normalizeEmptyValues($result);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function normalizeEmptyValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = $this->normalizeEmptyValues($value);

                continue;
            }

            if (is_string($value) && in_array(strtolower(trim($value)), ['null', 'unknown', 'n/a', 'not provided', 'unavailable'], true)) {
                $values[$key] = null;
            }
        }

        return $values;
    }
}
