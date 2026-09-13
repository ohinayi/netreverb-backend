<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:15360',
                'mimes:jpg,jpeg,png,gif,webp,heic,pdf,doc,docx,xls,xlsx,txt,mp4,mov,mp3,m4a,zip',
            ],
        ];
    }
}
