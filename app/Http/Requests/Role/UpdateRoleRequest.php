<?php

namespace App\Http\Requests\Role;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends FormRequest
{
    /**
     * 是否允许请求
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 验证规则
     */
    public function rules(): array
    {
        $roleId = $this->route('role');

        return [
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:100',
                Rule::unique('roles', 'code')->ignore($roleId),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
        ];
    }
}