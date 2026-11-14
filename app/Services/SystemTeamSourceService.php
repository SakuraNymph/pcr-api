<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * 按月份与阶段池缓存系统作业源数据（teams / team_roles / team_links）。
 *
 * 后台写操作通过 forgetMonth() 主动失效；缓存未命中或过期时重新查询数据库。
 */
final class SystemTeamSourceService
{
    private const CACHE_VERSION = 1;

    private const CACHE_TTL_SECONDS = 3600;

    private const LOCK_SECONDS = 120;

    private const LOCK_WAIT_SECONDS = 15;

    private const CACHE_KEY_PREFIX = 'system-source:rows:v1';

    private const TRIPLE_SOURCE_CACHE_KEY = 'systemTripleTeams';

    private const TRIPLE_MAKE_TEAMS_CACHE_KEY = 'systemTripleTeamsMakeTeams';

    /**
     * @var array<string, array<int, int>>
     */
    private const POOLS = [
        'low' => [2, 3],
        'high' => [4],
    ];

    public function __construct(
        private readonly SystemTeamContextService $teamContext
    ) {}

    /**
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function lowRows(string $yearMonth): array
    {
        return $this->rows($yearMonth, 'low');
    }

    /**
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function highRows(string $yearMonth): array
    {
        return $this->rows($yearMonth, 'high');
    }

    /**
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }
     */
    public function rows(string $yearMonth, string $pool): array
    {
        if (! isset(self::POOLS[$pool])) {
            throw new InvalidArgumentException("Unknown system team source pool: {$pool}");
        }

        $stages = self::POOLS[$pool];
        $cacheKey = $this->cacheKey($yearMonth, $pool);
        $cached = $this->decode(
            Cache::get($cacheKey),
            $yearMonth,
            $pool,
            $stages
        );

        if ($cached !== null) {
            return $cached;
        }

        $lock = Cache::lock(
            $cacheKey.':build',
            self::LOCK_SECONDS
        );
        $locked = false;

        try {
            try {
                $lock->block(self::LOCK_WAIT_SECONDS);
                $locked = true;
            } catch (LockTimeoutException) {
                return $this->teamContext->querySourceRows(
                    $yearMonth,
                    $stages
                );
            }

            $cached = $this->decode(
                Cache::get($cacheKey),
                $yearMonth,
                $pool,
                $stages
            );

            if ($cached !== null) {
                return $cached;
            }

            $rows = $this->teamContext->querySourceRows(
                $yearMonth,
                $stages
            );

            Cache::put(
                $cacheKey,
                [
                    'version' => self::CACHE_VERSION,
                    'year_month' => $yearMonth,
                    'pool' => $pool,
                    'stages' => $stages,
                    'rows' => $rows,
                    'generated_at' => now()->toIso8601String(),
                ],
                now()->addSeconds(self::CACHE_TTL_SECONDS)
            );

            return $rows;
        } finally {
            if ($locked) {
                $lock->release();
            }
        }
    }

    /**
     * 清除指定月份的系统作业源缓存与三刀缓存。
     */
    public function forgetMonth(string $yearMonth): void
    {
        Cache::forget($this->cacheKey($yearMonth, 'low'));
        Cache::forget($this->cacheKey($yearMonth, 'high'));
        Cache::forget(self::TRIPLE_SOURCE_CACHE_KEY.':'.$yearMonth);
        Cache::forget(
            self::TRIPLE_MAKE_TEAMS_CACHE_KEY.':'.$yearMonth
        );
    }

    /**
     * @param  iterable<int, string>  $yearMonths
     */
    public function forgetMonths(iterable $yearMonths): void
    {
        $normalized = [];

        foreach ($yearMonths as $yearMonth) {
            $yearMonth = (string) $yearMonth;

            if (preg_match('/^\d{6}$/', $yearMonth) !== 1) {
                continue;
            }

            $normalized[$yearMonth] = true;
        }

        foreach (array_keys($normalized) as $yearMonth) {
            $this->forgetMonth($yearMonth);
        }
    }

    private function cacheKey(string $yearMonth, string $pool): string
    {
        return self::CACHE_KEY_PREFIX.':'.$yearMonth.':'.$pool;
    }

    /**
     * @param  array<int, int>  $stages
     * @return array{
     *     teams: array<int, array<string, mixed>>,
     *     roles: array<int, array<int, array<string, int>>>,
     *     role_meta: array<int, array{image_id: int|null}>,
     *     links: array<int, array<int, array<string, mixed>>>
     * }|null
     */
    private function decode(
        mixed $payload,
        string $yearMonth,
        string $pool,
        array $stages
    ): ?array {
        if (
            ! is_array($payload)
            || (int) ($payload['version'] ?? 0) !== self::CACHE_VERSION
            || (string) ($payload['year_month'] ?? '') !== $yearMonth
            || (string) ($payload['pool'] ?? '') !== $pool
            || array_values((array) ($payload['stages'] ?? [])) !== $stages
            || ! is_array($payload['rows'] ?? null)
        ) {
            return null;
        }

        $rows = $payload['rows'];

        foreach (['teams', 'roles', 'role_meta', 'links'] as $field) {
            if (! is_array($rows[$field] ?? null)) {
                return null;
            }
        }

        return $rows;
    }
}
