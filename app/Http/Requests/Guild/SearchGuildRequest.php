<?php

namespace App\Http\Requests\Guild;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SearchGuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'keyword' => is_string($this->input('keyword'))
                ? Str::trim($this->input('keyword'))
                : $this->input('keyword'),
        ]);
    }

    public function rules(): array
    {
        return [
            'keyword' => ['nullable', 'string', 'max:32'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
