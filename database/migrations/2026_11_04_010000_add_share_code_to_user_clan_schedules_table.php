<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SHARE_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const SHARE_CODE_LENGTH = 8;

    public function up(): void
    {
        Schema::table('user_clan_schedules', function (Blueprint $table) {
            $table->string('share_code', self::SHARE_CODE_LENGTH)
                ->nullable()
                ->after('uid')
                ->comment('排刀表分享码');
        });

        foreach (DB::table('user_clan_schedules')->orderBy('id')->pluck('id') as $id) {
            DB::table('user_clan_schedules')
                ->where('id', $id)
                ->update(['share_code' => $this->generateUniqueShareCode()]);
        }

        Schema::table('user_clan_schedules', function (Blueprint $table) {
            $table->unique('share_code', 'user_clan_schedules_share_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('user_clan_schedules', function (Blueprint $table) {
            $table->dropUnique('user_clan_schedules_share_code_unique');
            $table->dropColumn('share_code');
        });
    }

    private function generateUniqueShareCode(): string
    {
        do {
            $code = '';
            $alphabetLength = strlen(self::SHARE_CODE_ALPHABET);

            for ($index = 0; $index < self::SHARE_CODE_LENGTH; $index++) {
                $code .= self::SHARE_CODE_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
        } while (
            DB::table('user_clan_schedules')
                ->where('share_code', $code)
                ->exists()
        );

        return $code;
    }
};
