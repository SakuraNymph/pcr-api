<?php

namespace App\Http\Requests\Guild;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CopyGuildScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_uid' => ['required', 'integer', 'min:1'],
            'target_uid' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    $this->input('source_uid') !== null
                    && (int) $this->input('source_uid')
                        === (int) $this->input('target_uid')
                ) {
                    $validator->errors()->add(
                        'source_uid',
                        '来源成员与目标成员不能相同'
                    );
                }
            },
        ];
    }
}
