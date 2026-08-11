<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ServiceNumberType;
use App\Models\DialableNumber;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceNumberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'number' => [
                'required',
                'string',
                'regex:/^[0-9]{2,15}$/',
                'not_regex:/^45[0-9]{9}$/',
                Rule::unique((new DialableNumber)->getTable(), 'number')
                    ->where(fn ($query) => $query->where('realm', config('telephony.sip_realm'))),
            ],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::enum(ServiceNumberType::class)],
            'target' => ['sometimes', 'nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:@-]+$/'],
            'enabled' => ['sometimes', 'boolean'],
            'configuration' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
