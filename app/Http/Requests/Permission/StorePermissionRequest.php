<?php

namespace App\Http\Requests\Permission;

use Illuminate\Foundation\Http\FormRequest;

class StorePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:100', 'unique:permissions,name'],
            'display_name' => ['required', 'string', 'max:100'],
            'menu_id'      => ['nullable', 'integer', 'exists:menus,id'],
            'http_method'  => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'http_path'    => ['required', 'string', 'max:255'],
        ];
    }
}