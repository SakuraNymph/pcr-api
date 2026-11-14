<?php

namespace App\Services;

use InvalidArgumentException;

final class SystemRoleMask
{
    private const CHUNK_BITS = 60;

    /**
     * @var array<int, int>
     */
    private array $bitIndexes = [];

    private int $wordCount;

    /**
     * @var array<int, int>|null
     */
    private static ?array $popcountTable = null;

    /**
     * @param  array<int, int>  $roleIds
     */
    public function __construct(array $roleIds)
    {
        $roleIds = array_values(array_unique(array_map(
            static fn (mixed $roleId): int => (int) $roleId,
            $roleIds
        )));
        sort($roleIds, SORT_NUMERIC);

        foreach ($roleIds as $index => $roleId) {
            $this->bitIndexes[$roleId] = $index;
        }

        $this->wordCount = max(
            1,
            (int) ceil(count($this->bitIndexes) / self::CHUNK_BITS)
        );
    }

    /**
     * @return array<int, int>
     */
    public function emptyMask(): array
    {
        return array_fill(0, $this->wordCount, 0);
    }

    /**
     * @param  iterable<int, int>  $roleIds
     * @return array<int, int>
     */
    public function maskFromRoleIds(iterable $roleIds): array
    {
        $mask = $this->emptyMask();

        foreach ($roleIds as $roleId) {
            $index = $this->bitIndexes[(int) $roleId] ?? null;

            if ($index === null) {
                continue;
            }

            $word = intdiv($index, self::CHUNK_BITS);
            $bit = $index % self::CHUNK_BITS;
            $mask[$word] |= 1 << $bit;
        }

        return $mask;
    }

    /**
     * @param  array<int, int>  $mask
     * @return array<int, int>
     */
    public function withoutRole(array $mask, int $roleId): array
    {
        $index = $this->bitIndexes[$roleId] ?? null;

        if ($index === null) {
            return $mask;
        }

        $word = intdiv($index, self::CHUNK_BITS);
        $bit = $index % self::CHUNK_BITS;
        $mask[$word] &= ~(1 << $bit);

        return $mask;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     * @return array<int, int>
     */
    public function union(array $left, array $right): array
    {
        $result = [];

        for ($index = 0; $index < $this->wordCount; $index++) {
            $result[$index] = ($left[$index] ?? 0) | ($right[$index] ?? 0);
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    public function intersects(array $left, array $right): bool
    {
        for ($index = 0; $index < $this->wordCount; $index++) {
            if ((($left[$index] ?? 0) & ($right[$index] ?? 0)) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $left
     * @param  array<int, int>  $right
     */
    public function countIntersection(array $left, array $right): int
    {
        $count = 0;

        for ($index = 0; $index < $this->wordCount; $index++) {
            $count += self::popcount(
                ($left[$index] ?? 0) & ($right[$index] ?? 0)
            );
        }

        return $count;
    }

    private static function popcount(int $value): int
    {
        if (self::$popcountTable === null) {
            self::$popcountTable = [];

            for ($index = 0; $index < 256; $index++) {
                self::$popcountTable[$index] = substr_count(
                    decbin($index),
                    '1'
                );
            }
        }

        if ($value < 0) {
            throw new InvalidArgumentException('角色掩码不能为负数');
        }

        $count = 0;

        while ($value !== 0) {
            $count += self::$popcountTable[$value & 0xff];
            $value >>= 8;
        }

        return $count;
    }
}
