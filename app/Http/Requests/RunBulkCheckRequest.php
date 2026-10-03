<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RunBulkCheckRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:5120', 'extensions:csv,txt'],
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
            'file.required' => 'Upload a CSV or TXT file of domains or emails.',
            'file.file' => 'Upload a valid file.',
            'file.max' => 'The upload may not be larger than 5 MB.',
            'file.extensions' => 'Only CSV or TXT files are allowed.',
            'dkim_selector.regex' => 'DKIM selector may only contain letters, numbers, dots, underscores, and hyphens.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $selector = trim((string) $this->input('dkim_selector', ''));

        $this->merge([
            'dkim_selector' => $selector === '' ? null : $selector,
        ]);
    }
}
