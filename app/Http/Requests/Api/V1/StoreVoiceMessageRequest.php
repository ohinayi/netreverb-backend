<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreVoiceMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $allowedMimeTypes = implode(',', config('messaging.voice_messages.allowed_mime_types'));

        return [
            'audio' => [
                'required',
                'file',
                "mimetypes:{$allowedMimeTypes}",
                'max:'.config('messaging.voice_messages.max_file_size_kb'),
            ],
            'duration_ms' => [
                'required',
                'integer',
                'min:1',
                'max:'.config('messaging.voice_messages.max_duration_ms'),
            ],
            'waveform' => ['sometimes', 'nullable', 'array', 'max:256'],
            'waveform.*' => ['numeric', 'min:0', 'max:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $maxSeconds = (int) round(config('messaging.voice_messages.max_duration_ms') / 1000);
        $maxMegabytes = round(config('messaging.voice_messages.max_file_size_kb') / 1024, 1);

        return [
            'audio.required' => 'A recorded audio file is required.',
            'audio.mimetypes' => 'Voice messages must be a webm, ogg, m4a, or mp3 recording.',
            'audio.max' => "Voice messages can be at most {$maxMegabytes} MB.",
            'duration_ms.max' => "Voice messages can be at most {$maxSeconds} seconds long.",
        ];
    }
}
