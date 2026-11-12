<?php

namespace App\Http\Requests\User;

use App\Models\UserFeedbackMessage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class MarkUserFeedbackReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'through_message_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $feedbackId = (int) $this->route('id');
                $messageId = (int) $this->input('through_message_id');

                $exists = UserFeedbackMessage::query()
                    ->where('feedback_id', $feedbackId)
                    ->whereKey($messageId)
                    ->exists();

                if (! $exists) {
                    $validator->errors()->add(
                        'through_message_id',
                        '该消息不属于当前反馈'
                    );
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'through_message_id.required' => '缺少已读消息 ID',
            'through_message_id.integer' => '已读消息 ID 不正确',
            'through_message_id.min' => '已读消息 ID 不正确',
        ];
    }
}
