<?php

namespace App\Http\Requests\Guild;

use Illuminate\Foundation\Http\FormRequest;

class SendGuildInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'invitee_uid' => ['required', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'invitee_uid.required' => '被邀请人UID不能为空',
            'invitee_uid.integer' => '被邀请人UID格式错误',
            'invitee_uid.min' => '被邀请人UID格式错误',
        ];
    }
}
