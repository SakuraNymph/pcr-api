<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Models\UserRolePool;
use App\Services\SystemTripleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BenchmarkSystemTriple extends Command
{
    protected $signature = 'game:benchmark-system-triple
        {--difficulty=1 : Difficulty 1-5}
        {--account-id= : Active user role-pool ID; defaults to the first active pool}';

    protected $description = 'Read-only system triple cache and algorithm benchmark';

    public function handle(SystemTripleService $service): int
    {
        $difficulty = (int) $this->option('difficulty');

        if ($difficulty < 1 || $difficulty > 5) {
            $this->error('difficulty must be between 1 and 5');

            return self::FAILURE;
        }

        config(['cache.default' => 'array']);
        Cache::store('array')->flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $started = hrtime(true);
        $cold = $service->getGroups($difficulty);
        $coldMs = $this->elapsedMs($started);
        $coldQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $started = hrtime(true);
        $hit = $service->getGroups($difficulty);
        $hitMs = $this->elapsedMs($started);
        $hitQueries = count(DB::getQueryLog());

        $yearMonth = now()->format('Ym');
        $sourcePayload = Cache::get(
            'systemTripleTeams:'.$yearMonth
        );
        $makePayload = Cache::get(
            'systemTripleTeamsMakeTeams:'.$yearMonth
        );
        $account = $this->resolveAccount();
        $accountMs = null;
        $accountQueries = null;
        $accountCount = null;

        if ($account !== null) {
            DB::flushQueryLog();
            $started = hrtime(true);
            $accountResults = $service->getAccountGroups(
                (int) $account->uid,
                (int) $account->id,
                $difficulty
            );
            $accountMs = $this->elapsedMs($started);
            $accountQueries = count(DB::getQueryLog());
            $accountCount = count($accountResults);
        }

        $legacy = $this->measureLegacyGenerator($service);
        $makeMetrics = $this->measurePayload($makePayload);
        $sourceMetrics = $this->measurePayload($sourcePayload);

        $this->line(json_encode([
            'cache_store' => 'array',
            'persistent_cache_written' => false,
            'difficulty' => $difficulty,
            'source_teams' => is_array($sourcePayload)
                ? (int) ($sourcePayload['team_count'] ?? 0)
                : 0,
            'group_counts' => is_array($makePayload)
                ? array_map('count', $makePayload['groups'] ?? [])
                : [],
            'planner_stats' => is_array($makePayload)
                ? ($makePayload['stats'] ?? [])
                : [],
            'cold_service_ms' => round($coldMs, 3),
            'cold_service_results' => count($cold),
            'cold_sql_queries' => $coldQueries,
            'hit_service_ms' => round($hitMs, 3),
            'hit_service_results' => count($hit),
            'hit_sql_queries' => $hitQueries,
            'account_id' => $account?->id,
            'account_results' => $accountCount,
            'account_service_ms' => $accountMs === null
                ? null
                : round($accountMs, 3),
            'account_sql_queries' => $accountQueries,
            'make_payload_bytes' => $makeMetrics['bytes'],
            'make_payload_serialize_ms' => $makeMetrics['serialize_ms'],
            'make_payload_unserialize_ms' => $makeMetrics['unserialize_ms'],
            'source_payload_bytes' => $sourceMetrics['bytes'],
            'source_payload_serialize_ms' => $sourceMetrics['serialize_ms'],
            'source_payload_unserialize_ms' => $sourceMetrics['unserialize_ms'],
            'legacy_generator' => $legacy,
            'peak_memory_mb' => round(
                memory_get_peak_usage(true) / 1048576,
                2
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        DB::disableQueryLog();

        return self::SUCCESS;
    }

    private function resolveAccount(): ?UserRolePool
    {
        $accountId = (int) $this->option('account-id');

        if ($accountId > 0) {
            return UserRolePool::query()
                ->where('status', 1)
                ->find($accountId);
        }

        return UserRolePool::query()
            ->where('status', 1)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<int, array{teams: int, combinations: int, ms: float}>
     */
    private function measureLegacyGenerator(
        SystemTripleService $service
    ): array {
        $teams = Team::query()
            ->where('uid', 0)
            ->where('stage', 4)
            ->where('status', 1)
            ->where('created_at', '>=', now()->startOfMonth())
            ->where(
                'created_at',
                '<',
                now()->startOfMonth()->addMonthNoOverflow()
            )
            ->with([
                'teamRoles' => fn ($query) => $query
                    ->orderBy('id')
                    ->with('role'),
                'links' => fn ($query) => $query
                    ->where('status', 1)
                    ->orderByDesc('damage')
                    ->orderBy('id'),
            ])
            ->orderBy('id')
            ->get();
        $snapshots = $service->buildSnapshotState($teams, [4])['snapshots'];
        $result = [];

        foreach ([1, 2, 3, 4, 5] as $difficulty) {
            $filtered = array_filter(
                $snapshots,
                static function (array $snapshot) use ($difficulty): bool {
                    foreach ($snapshot['links'] as $link) {
                        if ((int) $link['difficulty'] === $difficulty) {
                            return true;
                        }
                    }

                    return false;
                }
            );
            $started = hrtime(true);
            $combinations = $service->buildCombinationsForState([
                'snapshots' => $filtered,
                'team_ids' => implode(',', array_keys($filtered)),
                'team_count' => count($filtered),
                'signature' => '',
            ]);
            $result[$difficulty] = [
                'teams' => count($filtered),
                'combinations' => count($combinations),
                'ms' => round($this->elapsedMs($started), 3),
            ];
        }

        return $result;
    }

    /**
     * @return array{bytes: int, serialize_ms: float, unserialize_ms: float}
     */
    private function measurePayload(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [
                'bytes' => 0,
                'serialize_ms' => 0.0,
                'unserialize_ms' => 0.0,
            ];
        }

        $iterations = 3;
        $bytes = 0;
        $serializeMs = 0.0;
        $unserializeMs = 0.0;

        for ($index = 0; $index < $iterations; $index++) {
            $started = hrtime(true);
            $serialized = serialize($payload);
            $serializeMs += $this->elapsedMs($started);
            $bytes = strlen($serialized);

            $started = hrtime(true);
            unserialize($serialized, ['allowed_classes' => false]);
            $unserializeMs += $this->elapsedMs($started);
        }

        return [
            'bytes' => $bytes,
            'serialize_ms' => round($serializeMs / $iterations, 3),
            'unserialize_ms' => round($unserializeMs / $iterations, 3),
        ];
    }

    private function elapsedMs(int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000;
    }
}
