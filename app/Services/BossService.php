<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

class BossService
{
    private const SEAT_LABELS = [
        1 => '一王',
        2 => '二王',
        3 => '三王',
        4 => '四王',
        5 => '五王',
    ];

    /**
     * 用户端固定返回 1-5 王座位。
     */
    public function publicSeats(string $period): array
    {
        [$currentMonth, $previousMonth] = $this->months();
        $targetMonth = $period === 'previous'
            ? $previousMonth
            : $currentMonth;
        $fallbackMonth = $period === 'current'
            ? $previousMonth
            : null;

        $months = $fallbackMonth === null
            ? [$targetMonth]
            : [$targetMonth, $fallbackMonth];

        $selected = DB::table('bosses')
            ->select([
                'id',
                'name',
                'file_path',
                'year_month',
                'status',
                'sort',
            ])
            ->whereIn('year_month', $months)
            ->where('status', 1)
            ->orderBy('id')
            ->get()
            ->reduce(function (array $carry, stdClass $boss): array {
                $sort = $this->normalizeSort($boss->sort);

                if (
                    $sort === null
                    || ! is_string($boss->year_month)
                    || isset($carry[$boss->year_month][$sort])
                ) {
                    return $carry;
                }

                $carry[$boss->year_month][$sort] = $boss;

                return $carry;
            }, []);

        $seats = [];

        foreach (array_keys(self::SEAT_LABELS) as $sort) {
            $boss = $selected[$targetMonth][$sort]
                ?? ($fallbackMonth === null
                    ? null
                    : ($selected[$fallbackMonth][$sort] ?? null));

            $seats[] = $boss instanceof stdClass
                ? $this->publicBoss($boss, $sort, $targetMonth)
                : $this->publicPlaceholder($sort, $targetMonth);
        }

        return $seats;
    }

    /**
     * 管理端返回本月/上月五个王位及可选 BOSS。
     */
    public function adminOverview(string $period): array
    {
        [$currentMonth, $previousMonth] = $this->months();
        $targetMonth = $period === 'previous'
            ? $previousMonth
            : $currentMonth;

        $records = DB::table('bosses')
            ->select([
                'id',
                'name',
                'file_path',
                'year_month',
                'status',
                'sort',
            ])
            ->orderBy('id')
            ->get();
        $currentSelected = [];
        $previousSelected = [];
        $options = [];

        foreach ($records as $record) {
            if (! $record instanceof stdClass) {
                continue;
            }

            $sort = $this->normalizeSort($record->sort);

            if ($sort === null) {
                continue;
            }

            $candidateKey = $this->candidateKey($record);
            $existing = $options[$sort][$candidateKey] ?? null;

            if (
                ! $existing instanceof stdClass
                || $this->shouldReplaceCandidate(
                    $record,
                    $existing,
                    $currentMonth
                )
            ) {
                $options[$sort][$candidateKey] = $record;
            }

            if ($record->year_month === $currentMonth) {
                if (
                    (int) $record->status === 1
                    && ! isset($currentSelected[$sort])
                ) {
                    $currentSelected[$sort] = $record;
                }

                continue;
            }

            if ($record->year_month === $previousMonth) {
                if (
                    (int) $record->status === 1
                    && ! isset($previousSelected[$sort])
                ) {
                    $previousSelected[$sort] = $record;
                }

                continue;
            }
        }

        $seats = [];

        foreach (self::SEAT_LABELS as $sort => $label) {
            $current = $currentSelected[$sort] ?? null;
            $previous = $previousSelected[$sort] ?? null;
            $display = $period === 'current'
                ? ($current ?? $previous)
                : $previous;
            $source = $period === 'current'
                ? ($current !== null
                    ? 'current'
                    : ($previous !== null ? 'previous' : 'none'))
                : ($previous !== null ? 'previous' : 'none');

            $seatOptions = $this->sortOptions(
                array_values($options[$sort] ?? []),
                $period === 'current' ? $current : $previous,
                $sort
            );

            $seats[] = [
                'sort' => $sort,
                'label' => $label,
                'current_boss' => $this->adminBoss($current, $sort),
                'previous_boss' => $this->adminBoss($previous, $sort),
                'display_boss' => $this->adminBoss($display, $sort),
                'display_source' => $source,
                'options' => array_map(
                    fn (stdClass $boss): array => $this->adminBoss($boss, $sort),
                    $seatOptions
                ),
            ];
        }

        return [
            'period' => $period,
            'year_month' => $targetMonth,
            'previous_year_month' => $previousMonth,
            'read_only' => false,
            'seats' => $seats,
        ];
    }

    /**
     * 将候选应用到指定月份，并保证同月同王位只有一个选中项。
     */
    public function selectBoss(
        int $bossId,
        int $sort,
        string $period = 'current'
    ): array {
        [$currentMonth, $previousMonth] = $this->months();
        $targetMonth = $period === 'previous'
            ? $previousMonth
            : $currentMonth;

        return DB::transaction(function () use (
            $bossId,
            $sort,
            $period,
            $currentMonth,
            $previousMonth,
            $targetMonth
        ): array {
            $candidates = DB::table('bosses')
                ->select([
                    'id',
                    'name',
                    'file_path',
                    'year_month',
                    'status',
                    'sort',
                ])
                ->where('sort', $sort)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $target = $candidates->first(
                fn (stdClass $candidate): bool => (int) $candidate->id === $bossId
            );

            if (! $target instanceof stdClass) {
                throw new InvalidArgumentException(
                    '所选 BOSS 不可用于该王位'
                );
            }

            $oldCurrent = $period === 'current'
                ? $candidates->first(
                    fn (stdClass $candidate): bool => $candidate->year_month === $currentMonth
                        && (int) $candidate->status === 1
                )
                : null;
            $selectedId = $this->resolveSelectionId(
                $candidates,
                $target,
                $targetMonth,
                $sort
            );

            if (
                $oldCurrent instanceof stdClass
                && $this->candidateKey($oldCurrent) !== $this->candidateKey($target)
            ) {
                $previousId = $this->resolveSelectionId(
                    $candidates,
                    $oldCurrent,
                    $previousMonth,
                    $sort
                );

                $this->setSeatSelection($previousMonth, $sort, $previousId);
            }

            $this->setSeatSelection($targetMonth, $sort, $selectedId);

            $overview = $this->adminOverview($period);

            return $overview['seats'][$sort - 1];
        });
    }

    /**
     * @param  Collection<int, stdClass>  $candidates
     */
    private function resolveSelectionId(
        Collection $candidates,
        stdClass $target,
        string $targetMonth,
        int $sort
    ): int {
        if ($target->year_month === $targetMonth) {
            return (int) $target->id;
        }

        $targetKey = $this->candidateKey($target);
        $monthCandidate = $candidates->first(
            fn (stdClass $candidate): bool => $candidate->year_month === $targetMonth
                && $this->candidateKey($candidate) === $targetKey
        );

        if ($monthCandidate instanceof stdClass) {
            return (int) $monthCandidate->id;
        }

        if (
            $target->year_month === null
            || trim((string) $target->year_month) === ''
        ) {
            return (int) $target->id;
        }

        return $this->copyCandidateToMonth($target, $targetMonth, $sort);
    }

    private function setSeatSelection(
        string $yearMonth,
        int $sort,
        int $selectedId
    ): void {
        DB::table('bosses')
            ->where('year_month', $yearMonth)
            ->where('sort', $sort)
            ->update(['status' => 0]);

        DB::table('bosses')
            ->where('id', $selectedId)
            ->update([
                'year_month' => $yearMonth,
                'status' => 1,
                'sort' => $sort,
            ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function months(): array
    {
        $now = Carbon::now();

        return [
            $now->format('Ym'),
            $now->copy()->subMonthNoOverflow()->format('Ym'),
        ];
    }

    private function normalizeSort(mixed $sort): ?int
    {
        $value = filter_var(
            $sort,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 5]]
        );

        return $value === false ? null : $value;
    }

    /**
     * @param  Collection<int, stdClass>|array<int, stdClass>  $options
     * @return array<int, stdClass>
     */
    private function sortOptions(
        Collection|array $options,
        ?stdClass $selected,
        int $sort
    ): array {
        $options = collect($options)->values()->all();
        $selectedKey = $selected instanceof stdClass
            ? $this->candidateKey($selected)
            : null;

        usort($options, function (stdClass $left, stdClass $right) use (
            $selectedKey,
            $sort
        ): int {
            $leftSelected = $selectedKey !== null
                && $this->candidateKey($left) === $selectedKey;
            $rightSelected = $selectedKey !== null
                && $this->candidateKey($right) === $selectedKey;

            if ($leftSelected !== $rightSelected) {
                return $leftSelected ? -1 : 1;
            }

            $nameComparison = strnatcasecmp(
                $this->displayName($left, $sort),
                $this->displayName($right, $sort)
            );

            return $nameComparison !== 0
                ? $nameComparison
                : (int) $left->id <=> (int) $right->id;
        });

        return $options;
    }

    private function adminBoss(?stdClass $boss, int $sort): ?array
    {
        if (! $boss instanceof stdClass) {
            return null;
        }

        $filePath = trim((string) $boss->file_path);

        return [
            'id' => (int) $boss->id,
            'name' => $this->displayName($boss, $sort),
            'file_path' => $filePath,
            'image_url' => $this->imageUrl($filePath),
            'year_month' => $boss->year_month,
            'status' => (int) $boss->status,
            'sort' => $sort,
            'candidate_key' => $this->candidateKey($boss),
        ];
    }

    private function candidateKey(stdClass $boss): string
    {
        $filePath = trim((string) $boss->file_path);

        if ($filePath !== '') {
            return 'file:'.$filePath;
        }

        return 'name:'.trim((string) $boss->name);
    }

    private function shouldReplaceCandidate(
        stdClass $candidate,
        stdClass $existing,
        string $currentMonth
    ): bool {
        $candidateRank = $this->candidateRank($candidate, $currentMonth);
        $existingRank = $this->candidateRank($existing, $currentMonth);

        foreach ($candidateRank as $index => $value) {
            $comparison = $value <=> $existingRank[$index];

            if ($comparison !== 0) {
                return $comparison > 0;
            }
        }

        return false;
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: int}
     */
    private function candidateRank(
        stdClass $boss,
        string $currentMonth
    ): array {
        $yearMonth = $boss->year_month;
        $monthGroup = $yearMonth === $currentMonth
            ? 2
            : ($yearMonth === null || trim((string) $yearMonth) === '' ? 1 : 0);

        return [
            $monthGroup,
            (int) $boss->status,
            (string) ($yearMonth ?? ''),
            (int) $boss->id,
        ];
    }

    private function copyCandidateToMonth(
        stdClass $candidate,
        string $currentMonth,
        int $sort
    ): int {
        $newId = (int) DB::table('bosses')->max('id') + 1;

        DB::table('bosses')->insert([
            'id' => $newId,
            'name' => (string) $candidate->name,
            'file_path' => (string) $candidate->file_path,
            'year_month' => $currentMonth,
            'status' => 0,
            'sort' => $sort,
        ]);

        return $newId;
    }

    private function publicBoss(
        stdClass $boss,
        int $sort,
        string $targetMonth
    ): array {
        $filePath = trim((string) $boss->file_path);

        return [
            'id' => (int) $boss->id,
            'name' => $this->displayName($boss, $sort),
            'file_path' => $this->imageUrl($filePath),
            'year_month' => $targetMonth,
            'status' => 1,
            'sort' => $sort,
        ];
    }

    private function publicPlaceholder(int $sort, string $targetMonth): array
    {
        return [
            'id' => "fallback-boss-{$sort}",
            'name' => "BOSS {$sort}",
            'file_path' => '',
            'year_month' => $targetMonth,
            'status' => 0,
            'sort' => $sort,
        ];
    }

    private function displayName(stdClass $boss, int $sort): string
    {
        $name = trim((string) $boss->name);

        return $name !== '' ? $name : "BOSS {$sort}";
    }

    private function imageUrl(string $filePath): string
    {
        if ($filePath === '') {
            return '';
        }

        return rtrim((string) config('app.url'), '/')
            .'/images/bosses/'
            .ltrim($filePath, '/');
    }
}
