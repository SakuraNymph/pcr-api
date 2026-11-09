<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 访客身份 Token 表。
     *
     * 只保存 sha256(令牌明文后半段) 的哈希，与旧版 Sanctum visitor-token
     * 使用相同的哈希算法，便于迁移时无缝回填。
     */
    public function up(): void
    {
        Schema::create('visitor_tokens', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('uid')->comment('用户ID，对应 users.id');
            $table->char('token_hash', 64)->comment('sha256(令牌明文)');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique('token_hash', 'visitor_tokens_token_hash_unique');
            $table->index('uid', 'visitor_tokens_uid_index');
        });

        // 回填旧版 Sanctum 访客 Token：personal_access_tokens.token 本身就是
        // sha256(明文后半段)，哈希算法一致，直接复制即可保持客户端 Token 有效。
        // 只回填关联用户 status=0 的访客 Token；正式用户的遗留
        // visitor-token 属死数据，一律不回填。
        $legacyTokens = DB::table('personal_access_tokens')
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.name', 'visitor-token')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->where('users.status', 0)
            ->get([
                'personal_access_tokens.tokenable_id as tokenable_id',
                'personal_access_tokens.token as token',
                'personal_access_tokens.created_at as created_at',
            ]);

        foreach ($legacyTokens as $legacy) {
            $exists = DB::table('visitor_tokens')
                ->where('token_hash', $legacy->token)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('visitor_tokens')->insert([
                'uid' => $legacy->tokenable_id,
                'token_hash' => $legacy->token,
                'created_at' => $legacy->created_at ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_tokens');
    }
};
