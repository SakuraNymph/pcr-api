<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 将大师币角色配置字段升级为 JSON。
     */
    public function up(): void
    {
        $this->assertSafeCoinData();

        $this->normalizeCoinData();

        Schema::table('user_role_pools', function (Blueprint $table): void {
            $table->json('coin')
                ->nullable()
                ->comment('大师币角色ID列表')
                ->change();
        });
    }

    /**
     * 回滚为原 varchar(255) 字段。
     *
     * JSON 文本超过原字段长度时中止，避免静默截断。
     */
    public function down(): void
    {
        $this->assertSafeCoinData(validateLength: true);

        Schema::table('user_role_pools', function (Blueprint $table): void {
            $table->string('coin', 255)
                ->nullable()
                ->comment('预留字段')
                ->change();
        });
    }

    /**
     * 将历史数据转换为稳定的整数 ID 数组。
     *
     * 空字符串按未选择处理，数字字符串转换为整数，避免模型读取后
     * 返回混合类型。数据在 DDL 前一次性写入，转换失败时不会继续修改字段。
     */
    private function normalizeCoinData(): void
    {
        DB::transaction(function (): void {
            DB::table('user_role_pools')
                ->select(['id', 'coin'])
                ->whereNotNull('coin')
                ->orderBy('id')
                ->chunkById(500, function ($rows): void {
                    foreach ($rows as $row) {
                        $value = (string) $row->coin;

                        if ($value === '') {
                            DB::table('user_role_pools')
                                ->where('id', $row->id)
                                ->update(['coin' => null]);

                            continue;
                        }

                        $decoded = json_decode(
                            $value,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );

                        $normalized = array_map(
                            static fn (int|string $roleId): int => (int) $roleId,
                            $decoded
                        );

                        if ($normalized === $decoded) {
                            continue;
                        }

                        DB::table('user_role_pools')
                            ->where('id', $row->id)
                            ->update([
                                'coin' => json_encode(
                                    $normalized,
                                    JSON_UNESCAPED_UNICODE
                                    | JSON_UNESCAPED_SLASHES
                                    | JSON_THROW_ON_ERROR
                                ),
                            ]);
                    }
                });
        });
    }

    private function assertSafeCoinData(bool $validateLength = false): void
    {
        DB::table('user_role_pools')
            ->select(['id', 'coin'])
            ->whereNotNull('coin')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($validateLength): void {
                foreach ($rows as $row) {
                    $value = (string) $row->coin;

                    if ($value === '') {
                        continue;
                    }

                    try {
                        $decoded = json_decode(
                            $value,
                            true,
                            512,
                            JSON_THROW_ON_ERROR
                        );
                    } catch (\JsonException $exception) {
                        throw new RuntimeException(
                            "user_role_pools.coin 第 {$row->id} 行不是有效 JSON，迁移已终止",
                            0,
                            $exception
                        );
                    }

                    if (! is_array($decoded) || ! array_is_list($decoded)) {
                        throw new RuntimeException(
                            "user_role_pools.coin 第 {$row->id} 行不是角色 ID 数组，迁移已终止"
                        );
                    }

                    foreach ($decoded as $roleId) {
                        $isPositiveInteger = is_int($roleId) && $roleId > 0;
                        $isPositiveNumericString = is_string($roleId)
                            && ctype_digit($roleId)
                            && (int) $roleId > 0;

                        if (! $isPositiveInteger && ! $isPositiveNumericString) {
                            throw new RuntimeException(
                                "user_role_pools.coin 第 {$row->id} 行包含非法角色 ID，迁移已终止"
                            );
                        }
                    }

                    if (
                        $validateLength
                        && strlen(json_encode(
                            $decoded,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        )) > 255
                    ) {
                        throw new RuntimeException(
                            "user_role_pools.coin 第 {$row->id} 行超过 varchar(255)，回滚已终止"
                        );
                    }
                }
            });
    }
};
