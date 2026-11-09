<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;
use Throwable;

class SystemLogService
{
    /**
     * 记录成功日志
     *
     * @param string $module 模块
     * @param string $action 操作
     * @param string|null $targetType 操作对象类型
     * @param int|null $targetId 操作对象 ID
     * @param string|null $description 操作描述
     * @param array|null $changes 数据变化
     */
    public function record(
        string $module,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $description = null,
        ?array $changes = null
    ): void {
        $this->write(
            module: $module,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            description: $description,
            changes: $changes,
            result: 1
        );
    }

    /**
     * 记录失败日志
     *
     * @param string $module 模块
     * @param string $action 操作
     * @param string|null $targetType 操作对象类型
     * @param int|null $targetId 操作对象 ID
     * @param string|null $description 操作描述
     * @param array|null $changes 数据变化
     * @param string|null $errorMessage 错误信息
     */
    public function recordFailure(
        string $module,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $description = null,
        ?array $changes = null,
        ?string $errorMessage = null
    ): void {
        $this->write(
            module: $module,
            action: $action,
            targetType: $targetType,
            targetId: $targetId,
            description: $description,
            changes: $changes,
            result: 0,
            errorMessage: $errorMessage
        );
    }

    /**
     * 写入系统日志
     *
     * 日志写入失败不能影响原本的业务操作。
     */
    protected function write(
        string $module,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $description = null,
        ?array $changes = null,
        int $result = 1,
        ?string $errorMessage = null
    ): void {
        try {
            $admin = request()->user();

            $requestData = $this->getRequestData();

            SystemLog::create([
                'admin_id' => $admin?->id,
                'admin_username' => $admin?->username,

                'module' => $module,
                'action' => $action,

                'target_type' => $targetType,
                'target_id' => $targetId,

                'description' => $description,

                'method' => request()->method(),
                'path' => request()->path(),

                'request_data' => $requestData,
                'changes' => $changes,

                'result' => $result,
                'error_message' => $errorMessage,

                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        } catch (Throwable $e) {
            /*
             * 系统日志写入失败不能影响正常业务。
             *
             * 例如：
             * 管理员修改用户成功
             * ↓
             * 业务数据库已经修改成功
             * ↓
             * system_logs 写入失败
             *
             * 此时不能因为日志失败而让业务操作失败。
             */
            Log::error('系统日志写入失败', [
                'module' => $module,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'description' => $description,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取当前请求参数
     *
     * 同时过滤敏感字段。
     */
    protected function getRequestData(): array
    {
        $data = request()->all();

        return $this->filterSensitiveData($data);
    }

    /**
     * 过滤敏感参数
     *
     * 防止密码、Token、API Key 等敏感信息进入系统日志。
     */
    protected function filterSensitiveData(array $data): array
    {
        $sensitiveFields = [
            'password',
            'password_confirmation',
            'current_password',
            'new_password',

            'token',
            'access_token',
            'refresh_token',

            'api_key',
            'apikey',

            'secret',
            'client_secret',

            'authorization',

            'private_key',
            'secret_key',
        ];

        foreach ($data as $key => $value) {
            $key = strtolower((string) $key);

            if (in_array($key, $sensitiveFields, true)) {
                $data[$key] = '[FILTERED]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->filterSensitiveData($value);
            }
        }

        return $data;
    }
}