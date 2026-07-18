<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ConferenceControlUnavailableException extends RuntimeException implements ShouldntReport
{
    public static function conferenceRosterUnavailable(?string $details = null): self
    {
        $message = 'Conference control is temporarily unavailable because the live conference roster could not be verified.';

        if (is_string($details) && trim($details) !== '') {
            $message .= ' '.$details;
        }

        return new self($message);
    }

    public static function freeswitchUnavailable(?string $details = null): self
    {
        $message = 'Conference control is temporarily unavailable because FreeSWITCH is not connected.';

        if (is_string($details) && trim($details) !== '') {
            $message .= ' '.$details;
        }

        return new self($message);
    }

    public function render(Request $request): JsonResponse|bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        return response()->json([
            'message' => 'Conference control unavailable.',
            'error_code' => 'conference_control_unavailable',
            'details' => str_contains($this->getMessage(), 'live conference roster could not be verified')
                ? 'FreeSWITCH did not return a usable conference member roster for this room. The participant may still be connected; retry once the conference roster is available.'
                : 'FreeSWITCH event socket is not connected. Start the configured tunnel or restore backend access to FreeSWITCH and try again.',
        ], Response::HTTP_SERVICE_UNAVAILABLE);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'raw_message' => $this->getMessage(),
        ];
    }
}
