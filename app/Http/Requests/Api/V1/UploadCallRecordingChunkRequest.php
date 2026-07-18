<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UploadCallRecordingChunkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'sequence' => ['required', 'integer', 'min:0'],
            'chunk' => [
                'required',
                File::default()->max((int) config('telephony.webrtc.recording.direct_video_max_chunk_size_kb', 8192)),
            ],
        ];
    }
}
