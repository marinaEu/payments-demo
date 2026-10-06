<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the number input for incrementing.
 */
class IncrementNumberRequest extends FormRequest
{
    /**
     * Determines if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'numeric'],
        ];
    }

    /**
     * Prepare the data for validation, allowing path and query parameters.
     */
    protected function prepareForValidation(): void
    {
        if ($this->route('number') !== null) {
            $this->merge([
                'number' => $this->route('number'),
            ]);
        }
    }
}
