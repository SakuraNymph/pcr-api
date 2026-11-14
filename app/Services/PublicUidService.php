<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * 六位公开 UID 的档位判断、生成与分配。
 *
 * 自动发放只使用精品和吉利；特殊号固定保留，普通档暂不发放。
 */
class PublicUidService
{
    public const TIER_SPECIAL = 'special';

    public const TIER_PREMIUM = 'premium';

    public const TIER_AUSPICIOUS = 'auspicious';

    public const TIER_NORMAL = 'normal';

    /**
     * 自动发放只允许精品和吉利；特殊号永远保留，普通档暂不发放。
     *
     * @var array<int, string>
     */
    private const AUTO_TIERS = [
        self::TIER_PREMIUM,
        self::TIER_AUSPICIOUS,
    ];

    /**
     * 含 4 的六连顺是唯一的 4 字例外。
     *
     * @var array<int, int>
     */
    private const STRAIGHTS = [
        123456,
        234567,
        345678,
        456789,
        543210,
        654321,
        765432,
        876543,
        987654,
    ];

    /**
     * @var array<int, int>|null
     */
    private ?array $specialUids = null;

    /**
     * 判断号码是否符合六位 UID 的基础格式。
     */
    public function isValid(int $uid): bool
    {
        $value = (string) $uid;

        if (preg_match('/^[1-9]\d{5}$/', $value) !== 1) {
            return false;
        }

        return ! str_contains($value, '4') || $this->isStraight($uid);
    }

    /**
     * 返回号码所属档位；格式或 4 字规则不合法时返回 null。
     */
    public function classify(int $uid): ?string
    {
        if (! $this->isValid($uid)) {
            return null;
        }

        if (in_array($uid, $this->specialUids(), true)) {
            return self::TIER_SPECIAL;
        }

        $value = (string) $uid;

        if ($this->isPremium($value)) {
            return self::TIER_PREMIUM;
        }

        if ($this->isAuspicious($value)) {
            return self::TIER_AUSPICIOUS;
        }

        return self::TIER_NORMAL;
    }

    /**
     * 按当前权重生成一个精品或吉利 UID。
     */
    public function generate(): int
    {
        return $this->generateForTier($this->drawTier());
    }

    /**
     * 生成指定档位的 UID，仅允许自动发放的精品和吉利。
     */
    public function generateForTier(string $tier): int
    {
        if (! in_array($tier, self::AUTO_TIERS, true)) {
            throw new InvalidArgumentException('自动发放仅支持精品或吉利 UID');
        }

        $attempts = max(1, (int) config('public_uid.generation_attempts', 1000));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $this->randomCandidate();

            if ($this->classify($candidate) === $tier) {
                return $candidate;
            }
        }

        throw new RuntimeException('无法生成指定档位的 UID，请稍后重试');
    }

    /**
     * 为正式用户分配唯一 UID。
     *
     * 幂等：用户已有 UID 时直接返回原值。
     */
    public function assign(User $user): int
    {
        if ((int) $user->status !== 1) {
            throw new InvalidArgumentException('仅正式注册用户可分配 UID');
        }

        if ($user->public_uid !== null) {
            return (int) $user->public_uid;
        }

        $attempts = max(1, (int) config('public_uid.assignment_attempts', 10));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $candidate = $this->generate();

            try {
                $affected = User::query()
                    ->whereKey($user->getKey())
                    ->whereNull('public_uid')
                    ->update(['public_uid' => $candidate]);
            } catch (UniqueConstraintViolationException $exception) {
                continue;
            }

            if ($affected === 1) {
                $user->public_uid = $candidate;

                return $candidate;
            }

            $existing = User::query()->find($user->getKey());

            if ($existing?->public_uid !== null) {
                $user->public_uid = (int) $existing->public_uid;

                return (int) $existing->public_uid;
            }
        }

        throw new RuntimeException('无法分配唯一用户 UID，请稍后重试');
    }

    /**
     * 返回固定保留号列表。
     *
     * @return array<int, int>
     */
    public function specialUids(): array
    {
        if ($this->specialUids === null) {
            $this->specialUids = array_values(array_unique(array_map(
                static fn (mixed $uid): int => (int) $uid,
                (array) config('public_uid.special', [])
            )));
        }

        return $this->specialUids;
    }

    private function drawTier(): string
    {
        $weights = $this->weights();
        $total = 0;

        foreach (self::AUTO_TIERS as $tier) {
            $total += $weights[$tier];
        }

        if ($total <= 0) {
            throw new RuntimeException('未配置可用的 UID 发放档位');
        }

        $roll = random_int(1, $total);

        foreach (self::AUTO_TIERS as $tier) {
            $roll -= $weights[$tier];

            if ($roll <= 0) {
                return $tier;
            }
        }

        throw new RuntimeException('UID 档位抽取失败');
    }

    /**
     * @return array<string, int>
     */
    private function weights(): array
    {
        $configured = (array) config('public_uid.weights', []);

        return [
            self::TIER_SPECIAL => max(0, (int) ($configured[self::TIER_SPECIAL] ?? 0)),
            self::TIER_PREMIUM => max(0, (int) ($configured[self::TIER_PREMIUM] ?? 0)),
            self::TIER_AUSPICIOUS => max(0, (int) ($configured[self::TIER_AUSPICIOUS] ?? 0)),
            self::TIER_NORMAL => max(0, (int) ($configured[self::TIER_NORMAL] ?? 0)),
        ];
    }

    /**
     * 生成一个不含 4 的六位候选号码。
     */
    private function randomCandidate(): int
    {
        $firstDigits = [1, 2, 3, 5, 6, 7, 8, 9];
        $otherDigits = [0, 1, 2, 3, 5, 6, 7, 8, 9];

        $candidate = $firstDigits[random_int(0, count($firstDigits) - 1)];

        for ($position = 0; $position < 5; $position++) {
            $candidate = $candidate * 10
                + $otherDigits[random_int(0, count($otherDigits) - 1)];
        }

        return $candidate;
    }

    private function isStraight(int $uid): bool
    {
        return in_array($uid, self::STRAIGHTS, true);
    }

    private function isPremium(string $value): bool
    {
        // 六位回文
        if (
            $value[0] === $value[5]
            && $value[1] === $value[4]
            && $value[2] === $value[3]
        ) {
            return true;
        }

        // AABBCC
        if (
            $value[0] === $value[1]
            && $value[2] === $value[3]
            && $value[4] === $value[5]
            && $value[0] !== $value[2]
            && $value[2] !== $value[4]
            && $value[0] !== $value[4]
        ) {
            return true;
        }

        // 含豹子号
        if (
            str_contains($value, '666')
            || str_contains($value, '888')
            || str_contains($value, '999')
        ) {
            return true;
        }

        // 只由 6、8、9 组成
        if (preg_match('/^[689]+$/', $value) === 1) {
            return true;
        }

        // 含四位以上连续顺子
        for ($index = 0; $index < 3; $index++) {
            $first = ord($value[$index]);
            $second = ord($value[$index + 1]);
            $third = ord($value[$index + 2]);
            $fourth = ord($value[$index + 3]);

            if (
                ($second - $first === 1
                    && $third - $second === 1
                    && $fourth - $third === 1)
                || ($second - $first === -1
                    && $third - $second === -1
                    && $fourth - $third === -1)
            ) {
                return true;
            }
        }

        // 整万号
        return str_ends_with($value, '0000');
    }

    private function isAuspicious(string $value): bool
    {
        $luckyDigits = preg_match_all('/[689]/', $value);
        $lastDigit = $value[5];

        if (
            $luckyDigits >= 2
            && in_array($lastDigit, ['6', '8', '9'], true)
        ) {
            return true;
        }

        if (in_array(substr($value, -2), ['66', '88', '99'], true)) {
            return true;
        }

        return in_array(
            substr($value, -3),
            ['168', '518', '520', '521', '886', '918'],
            true
        );
    }
}
