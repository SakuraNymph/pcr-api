<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $permissionId = $this->route('id');

        return [
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('permissions', 'name')->ignore($permissionId),
            ],
            'display_name' => ['required', 'string', 'max:100'],
            'menu_id'      => ['nullable', 'integer', 'exists:menus,id'],
            'http_method'  => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'http_path'    => ['required', 'string', 'max:255'],
        ];
    }
}