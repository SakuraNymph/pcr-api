<?php

namespace App\Http\Requests\Guild;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateGuildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $updates = [];

        if ($this->has('name') && is_string($this->input('name'))) {
            $updates['name'] = Str::trim($this->input('name'));
        }

        if ($this->has('description') && is_string($this->input('description'))) {
            $updates['description'] = Str::trim($this->input('description'));
        }

        if ($updates !== []) {
            $this->merge($updates);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:32',
                'regex:/^[^\x00-\x1F\x7F]+$/u',
            ],
            'description' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[^\x00-\x1F\x7F]*$/u',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '公会名称不能为空',
            'name.max' => '公会名称最多32个字符',
            'name.regex' => '公会名称不能包含换行或控制字符',
            'description.max' => '公会简介最多255个字符',
            'description.regex' => '公会简介不能包含换行或控制字符',
        ];
    }
}
