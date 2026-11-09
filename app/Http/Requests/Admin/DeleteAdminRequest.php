<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DeleteAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }

    /**
     * 验证是否允许删除管理员
     */
    protected function passedValidation(): void
    {
        $admin = $this->route('admin');

        if ($admin && $admin->id === $this->user()->id) {
            abort(422, '不能删除当前登录的管理员');
        }
    }
}