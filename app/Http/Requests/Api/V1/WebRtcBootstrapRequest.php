<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\ExtensionStatus;
use App\Enums\MembershipStatus;
use App\Models\Extension;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WebRtcBootstrapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'extension_id' => ['nullable', 'ulid'],
        ];
    }

    public function extension(): Extension
    {
        $extensions = Extension::query()
            ->whereBelongsTo($this->user())
            ->where('status', ExtensionStatus::Active)
            ->whereHas(
                'organization.memberships',
                fn (Builder $query): Builder => $query
                    ->whereBelongsTo($this->user())
                    ->where('status', MembershipStatus::Active),
            )
            ->when(
                $this->validated('extension_id'),
                fn (Builder $query, string $extensionId): Builder => $query->where('public_id', $extensionId),
            )
            ->with(['dialableNumber', 'credential'])
            ->limit(2)
            ->get();

        if ($extensions->isEmpty()) {
            throw new NotFoundHttpException('No active assigned extension was found.');
        }

        if ($this->validated('extension_id') === null && $extensions->count() > 1) {
            throw ValidationException::withMessages([
                'extension_id' => 'Select an extension when more than one active extension is assigned.',
            ]);
        }

        return $extensions->first();
    }
}
