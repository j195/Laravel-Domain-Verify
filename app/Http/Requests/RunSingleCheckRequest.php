<?php

namespace App\Http\Requests;

use App\Rules\DomainOrEmail;
use Illuminate\Foundation\Http\FormRequest;

class RunSingleCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:blacklist,provider'],
            'input' => ['required', 'string', 'max:255', new DomainOrEmail],
            'dkim_selector' => ['nullable', 'string', 'max:63', 'regex:/^[A-Za-z0-9](?:[A-Za-z0-9._-]{0,62})$/'],
            'include_all_records' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'type.required' => 'Choose a check type.',
            'type.in' => 'The check type must be blacklist or provider.',
            'input.required' => 'Enter a domain or email address.',
            'input.max' => 'The domain or email may not be longer than 255 characters.',
            'dkim_selector.regex' => 'DKIM selector may only contain letters, numbers, dots, underscores, and hyphens.',
            'dkim_selector.max' => 'DKIM selector may not be longer than 63 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $selector = trim((string) $this->input('dkim_selector', ''));

        $this->merge([
            'input' => trim((string) $this->input('input')),
            'dkim_selector' => $selector === '' ? null : $selector,
        ]);
    }
}
