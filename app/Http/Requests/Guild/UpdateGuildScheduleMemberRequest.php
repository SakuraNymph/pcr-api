<?php

namespace App\Http\Requests\Guild;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGuildScheduleMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            'month' => [
                'sometimes',
                'string',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
            ],
            'team_1' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'team_1_return' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'team_2' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'team_2_return' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'team_3' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'team_3_return' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
