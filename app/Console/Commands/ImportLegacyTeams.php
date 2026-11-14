<?php

namespace App\Console\Commands;

use App\Services\SystemTeamSourceService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use JsonException;
use Throwable;

class ImportLegacyTeams extends Command
{
    protected $signature = 'game:import-legacy-teams
        {--file= : Path to the legacy teams JSON manifest}
        {--copy-month= : Copy imported teams into YYYY-MM with created_at advanced one month}
        {--execute : Persist the validated data in a transaction}';

    protected $description = 'Import legacy pcr.teams data into teams, team_roles and team_links';

    private const BOSS_HEALTH = [
        2 => [0, 4200, 4800, 5000, 5200, 6000],
        3 => [0, 7000, 8000, 9000, 10000, 12000],
    ];

    private const DIFFICULTIES = [
        '纯AUTO' => 1,
        '简单SET' => 2,
        '开关SET' => 3,
        '简单目押' => 4,
        '目押' => 5,
    ];

    private const DIFFICULTY_LABELS = [
        1 => '纯AUTO',
        2 => '简单SET',
        3 => '开关SET',
        4 => '简单目押',
        5 => '目押',
    ];

    public function handle(SystemTeamSourceService $sourceCache): int
    {
        try {
            $path = $this->resolvePath();
            $records = $this->loadRecords($path);
            $prepared = $this->prepareRecords($records);
            $copies = $this->prepareCopies(
                $prepared['included'],
                (string) $this->option('copy-month'),
            );
            $existing = $this->inspectExisting(
                $prepared['included'],
                $copies,
            );

            $this->displaySummary($path, $prepared, $copies, $existing);

            if (! $this->option('execute')) {
                $this->warn('DRY RUN: 未写入数据库。确认无误后使用 --execute 执行。');

                return self::SUCCESS;
            }

            $result = DB::transaction(
                fn (): array => $this->importEntries(
                    $prepared['included'],
                    $copies,
                    true,
                ),
                3,
            );
            $sourceCache->forgetMonths($this->affectedMonths(
                $prepared['included'],
                $copies,
            ));

            $this->info(sprintf(
                '导入完成：原始新增 %d 条，副本新增 %d 条；原始跳过 %d 条，副本跳过 %d 条。',
                $result['inserted'],
                $result['copy_inserted'],
                $result['skipped'],
                $result['copy_skipped'],
            ));

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('导入失败：'.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function resolvePath(): string
    {
        $path = (string) ($this->option('file') ?: database_path('imports/legacy_teams_2026_10.json'));

        if (! File::isFile($path)) {
            throw new DomainException("数据文件不存在：{$path}");
        }

        return $path;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRecords(string $path): array
    {
        try {
            $manifest = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new DomainException('数据文件不是有效 JSON：'.$exception->getMessage());
        }

        if (! is_array($manifest) || ! isset($manifest['records']) || ! is_array($manifest['records'])) {
            throw new DomainException('数据文件缺少 records 数组。');
        }

        return $manifest['records'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{
     *     included: array<int, array<string, mixed>>,
     *     excluded: array<int, array{id: int, reason: string}>,
     *     expected: array{teams: int, roles: int, links: int},
     *     stage_counts: array<int, int>,
     *     mode_counts: array<int, int>,
     *     difficulty_counts: array<int, int>,
     *     note_counts: array<string, int>
     * }
     */
    private function prepareRecords(array $records): array
    {
        $this->assertSchema();

        $errors = [];
        $included = [];
        $excluded = [];
        $seenIds = [];
        $seenSignatures = [];
        $stageCounts = [];
        $modeCounts = [];
        $difficultyCounts = [];
        $noteCounts = [];
        $roleIds = [];

        foreach ($records as $index => $record) {
            $label = '第 '.($index + 1).' 条记录';

            if (! is_array($record)) {
                $errors[] = "{$label}不是对象。";

                continue;
            }

            $id = $this->integer($record['id'] ?? null);
            if ($id === null || $id < 1) {
                $errors[] = "{$label}的 id 无效。";

                continue;
            }

            $label = "作业 {$id}";
            if (isset($seenIds[$id])) {
                $errors[] = "{$label}的 id 重复。";

                continue;
            }
            $seenIds[$id] = true;

            $includedFlag = $record['included'] ?? true;
            if (! is_bool($includedFlag)) {
                $errors[] = "{$label}的 included 必须是布尔值。";

                continue;
            }

            if (! $includedFlag) {
                $reason = trim((string) ($record['exclusion_reason'] ?? ''));
                if ($reason === '') {
                    $errors[] = "{$label}已排除，但缺少 exclusion_reason。";

                    continue;
                }

                $excluded[] = ['id' => $id, 'reason' => $reason];

                continue;
            }

            $legacyStage = $this->integer($record['stage'] ?? null);
            if ($legacyStage === null || ! in_array($legacyStage, [2, 3, 5], true)) {
                $errors[] = "{$label}的 stage 必须是 2、3 或 5。";
            }
            $stage = $legacyStage === 5 ? 4 : $legacyStage;

            $boss = $this->integer($record['boss'] ?? null);
            if ($boss === null || $boss < 1 || $boss > 5) {
                $errors[] = "{$label}的 boss 必须是 1-5。";
            }

            $damage = $this->integer($record['damage'] ?? null);
            if ($damage === null || $damage < 1 || $damage > 99999) {
                $errors[] = "{$label}的 damage 必须在 1-99999。";
            }

            $teamKey = trim((string) ($record['team_key'] ?? ''));
            if ($teamKey === '' || strlen($teamKey) > 100) {
                $errors[] = "{$label}的 team_key 无效。";
            }

            $mode = $this->integer($record['auto'] ?? null);
            if ($mode === null || ! in_array($mode, [1, 2], true)) {
                $errors[] = "{$label}的 auto/mode 必须是 1 或 2。";
            }

            $atkValue = $this->integer($record['atk_value'] ?? null);
            if ($atkValue === null) {
                $errors[] = "{$label}的 atk_value 必须是整数。";
            }

            $status = $this->integer($record['status'] ?? null);
            if ($status === null || ! in_array($status, [0, 1], true)) {
                $errors[] = "{$label}的 status 必须是 0 或 1。";
            }

            $importTimestamp = $this->integer($record['timestamp'] ?? null);
            if ($importTimestamp === null || $importTimestamp < 1) {
                $errors[] = "{$label}的 timestamp 无效。";
            }

            $createdAt = (string) ($record['created_at'] ?? '');
            if (! $this->isDateTime($createdAt)) {
                $errors[] = "{$label}的 created_at 无效。";
            }

            $roles = $record['roles'] ?? null;
            if (! is_array($roles) || count($roles) < 1 || count($roles) > 5) {
                $errors[] = "{$label}的 roles 必须包含 1-5 个角色。";
                $roles = [];
            }

            $normalizedRoles = [];
            $teamRoleIds = [];
            foreach ($roles as $roleIndex => $role) {
                if (! is_array($role)) {
                    $errors[] = "{$label}的第 ".($roleIndex + 1).' 个角色无效。';

                    continue;
                }

                $roleId = $this->integer($role['role_id'] ?? null);
                $roleStatus = $this->integer($role['status'] ?? 1);
                if ($roleId === null || $roleId < 1) {
                    $errors[] = "{$label}的第 ".($roleIndex + 1).' 个 role_id 无效。';

                    continue;
                }
                if ($roleStatus === null || ! in_array($roleStatus, [0, 1], true)) {
                    $errors[] = "{$label}的角色 {$roleId} status 无效。";

                    continue;
                }
                if (isset($teamRoleIds[$roleId])) {
                    $errors[] = "{$label}包含重复角色 {$roleId}。";

                    continue;
                }

                $teamRoleIds[$roleId] = true;
                $roleIds[$roleId] = true;
                $normalizedRoles[] = [
                    'team_id' => $id,
                    'role_id' => $roleId,
                    'status' => $roleStatus,
                ];
            }

            $links = $record['links'] ?? null;
            if (! is_array($links) || count($links) < 1) {
                $errors[] = "{$label}的 links 至少需要一条。";
                $links = [];
            }

            $normalizedLinks = [];
            $linkDifficulties = [];
            foreach ($links as $linkIndex => $link) {
                if (! is_array($link)) {
                    $errors[] = "{$label}的第 ".($linkIndex + 1).' 条链接无效。';

                    continue;
                }

                $url = trim((string) ($link['url'] ?? ''));
                if (! $this->isUrl($url)) {
                    $errors[] = "{$label}的第 ".($linkIndex + 1).' 条链接 URL 无效。';

                    continue;
                }

                $parsed = $this->parseLinkText(
                    (string) ($link['text'] ?? ''),
                    (string) ($link['note'] ?? ''),
                    $damage ?? 0,
                    $legacyStage ?? 0,
                );
                if (isset($parsed['error'])) {
                    $errors[] = "{$label}的第 ".($linkIndex + 1)." 条链接：{$parsed['error']}";

                    continue;
                }

                $linkDifficulties[] = $parsed['difficulty'];
                $normalizedLinks[] = [
                    'team_id' => $id,
                    'url' => $url,
                    'author' => $parsed['author'],
                    'difficulty' => $parsed['difficulty'],
                    'damage' => $parsed['damage'],
                    'status' => 1,
                    'note' => $parsed['note'],
                ];
            }

            if ($normalizedLinks !== []) {
                $expectedMode = in_array(5, $linkDifficulties, true) ? 2 : 1;
                if ($mode !== null && $mode !== $expectedMode) {
                    $errors[] = "{$label}的 auto/mode 与链接难度不一致。";
                }

                $maxLinkDamage = max(array_column($normalizedLinks, 'damage'));
                if ($legacyStage === 2 || $legacyStage === 3) {
                    $expectedDamage = self::BOSS_HEALTH[$legacyStage][$boss] ?? null;
                    if ($expectedDamage === null || $damage !== $expectedDamage) {
                        $errors[] = "{$label}的 damage 与阶段 {$legacyStage} Boss {$boss} 固定血量不一致。";
                    }
                    if (array_filter($normalizedLinks, fn (array $item): bool => $item['damage'] !== $expectedDamage) !== []) {
                        $errors[] = "{$label}的 B/C 阶段链接伤害应统一为 {$expectedDamage}。";
                    }
                } elseif ($legacyStage === 5 && $damage !== $maxLinkDamage) {
                    $errors[] = "{$label}的 damage 应等于链接最大伤害 {$maxLinkDamage}。";
                }
            }

            $signature = $this->teamSignature([
                'team_key' => $teamKey,
                'mode' => $mode,
                'stage' => $stage,
                'boss' => $boss,
                'created_at' => $createdAt,
            ]);
            if (isset($seenSignatures[$signature])) {
                $errors[] = "{$label}与其他记录具有相同的月份、阵容、模式、阶段和 Boss。";
            }
            $seenSignatures[$signature] = true;

            if ($stage !== null) {
                $stageCounts[$stage] = ($stageCounts[$stage] ?? 0) + 1;
            }
            if ($mode !== null) {
                $modeCounts[$mode] = ($modeCounts[$mode] ?? 0) + 1;
            }
            foreach ($linkDifficulties as $difficulty) {
                $difficultyCounts[$difficulty] = ($difficultyCounts[$difficulty] ?? 0) + 1;
            }
            foreach ($normalizedLinks as $normalizedLink) {
                $noteKey = $normalizedLink['note'] ?? '';
                $noteCounts[$noteKey] = ($noteCounts[$noteKey] ?? 0) + 1;
            }

            $included[] = [
                'id' => $id,
                'team' => [
                    'id' => $id,
                    'stage' => $stage,
                    'source_team_id' => null,
                    'uid' => 0,
                    'boss' => $boss,
                    'damage' => $damage,
                    'team_key' => $teamKey,
                    'status' => $status,
                    'mode' => $mode,
                    'atk_value' => $atkValue,
                    'remark' => (string) ($record['remark'] ?? ''),
                    'import_timestamp' => $importTimestamp,
                    'created_at' => $createdAt,
                    'updated_at' => null,
                ],
                'roles' => $normalizedRoles,
                'links' => $normalizedLinks,
            ];
        }

        $this->validateRoles($included, $roleIds, $errors);

        if ($errors !== []) {
            throw new DomainException("数据校验失败：\n- ".implode("\n- ", $errors));
        }

        ksort($stageCounts);
        ksort($modeCounts);
        ksort($difficultyCounts);
        ksort($noteCounts);

        return [
            'included' => $included,
            'excluded' => $excluded,
            'expected' => [
                'teams' => count($included),
                'roles' => array_sum(array_map(fn (array $entry): int => count($entry['roles']), $included)),
                'links' => array_sum(array_map(fn (array $entry): int => count($entry['links']), $included)),
            ],
            'stage_counts' => $stageCounts,
            'mode_counts' => $modeCounts,
            'difficulty_counts' => $difficultyCounts,
            'note_counts' => $noteCounts,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @return array<int, array{
     *     source_id: int,
     *     signature: string,
     *     team: array<string, mixed>,
     *     roles: array<int, array{role_id: int, status: int}>,
     *     links: array<int, array<string, mixed>>
     * }>
     */
    private function prepareCopies(array $included, string $copyMonth): array
    {
        $copyMonth = trim($copyMonth);
        if ($copyMonth === '') {
            return [];
        }

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $copyMonth)) {
            throw new DomainException('--copy-month 必须是有效的 YYYY-MM 月份。');
        }

        $copies = [];
        $seenSignatures = [];
        $timezone = (string) config('app.timezone', 'Asia/Shanghai');

        foreach ($included as $entry) {
            $sourceTeam = $entry['team'];
            $sourceMonth = substr((string) $sourceTeam['created_at'], 0, 7);
            if ($sourceMonth === $copyMonth) {
                throw new DomainException(
                    "作业 {$sourceTeam['id']} 的来源月份与副本月份相同，无法生成副本。",
                );
            }

            $sourceCreatedAt = CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) $sourceTeam['created_at'],
                $timezone,
            );
            if ($sourceCreatedAt === false) {
                throw new DomainException(
                    "作业 {$sourceTeam['id']} 的 created_at 无法转换为副本时间。",
                );
            }

            $copyCreatedAt = $sourceCreatedAt
                ->addMonthNoOverflow()
                ->format('Y-m-d H:i:s');
            if (substr($copyCreatedAt, 0, 7) !== $copyMonth) {
                throw new DomainException(
                    "作业 {$sourceTeam['id']} 顺延一个月后不属于 {$copyMonth}。",
                );
            }

            $team = $sourceTeam;
            unset($team['id']);
            $team['created_at'] = $copyCreatedAt;

            $signature = $this->teamSignature($team);
            if (isset($seenSignatures[$signature])) {
                throw new DomainException(
                    "副本月份 {$copyMonth} 中存在重复的系统作业签名。",
                );
            }
            $seenSignatures[$signature] = true;

            $copies[] = [
                'source_id' => (int) $sourceTeam['id'],
                'signature' => $signature,
                'team' => $team,
                'roles' => array_map(function (array $role): array {
                    return [
                        'role_id' => (int) $role['role_id'],
                        'status' => (int) $role['status'],
                    ];
                }, $entry['roles']),
                'links' => array_map(function (array $link): array {
                    unset($link['team_id']);

                    return $link;
                }, $entry['links']),
            ];
        }

        return $copies;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    private function teamSignature(array $team): string
    {
        return substr((string) $team['created_at'], 0, 7)
            .'|'.$team['team_key']
            .'|'.(int) $team['mode']
            .'|'.(int) $team['stage']
            .'|'.(int) $team['boss'];
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @param  array<int, array<string, mixed>>  $copies
     * @return array<int, string>
     */
    private function affectedMonths(array $included, array $copies): array
    {
        $months = [];

        foreach ($included as $entry) {
            $this->appendMonth($months, $entry['team']['created_at'] ?? null);
        }

        foreach ($copies as $copy) {
            $this->appendMonth($months, $copy['team']['created_at'] ?? null);
        }

        return array_keys($months);
    }

    /**
     * @param  array<string, bool>  $months
     */
    private function appendMonth(array &$months, mixed $createdAt): void
    {
        $month = substr((string) $createdAt, 0, 7);

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return;
        }

        $months[str_replace('-', '', $month)] = true;
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @param  array<int, bool>  $roleIds
     * @param  array<int, string>  $errors
     */
    private function validateRoles(array $included, array $roleIds, array &$errors): void
    {
        $roles = DB::table('game_roles')
            ->whereIn('id', array_keys($roleIds))
            ->get(['id', 'atk_type', 'search_area_width', 'status'])
            ->keyBy(fn (object $role): int => (int) $role->id);

        foreach ($included as $entry) {
            $team = $entry['team'];
            $roleWidths = [];
            $atkTotal = 0;

            foreach ($entry['roles'] as $roleEntry) {
                $roleId = (int) $roleEntry['role_id'];
                /** @var object|null $role */
                $role = $roles->get($roleId);
                if (! $role) {
                    $errors[] = "作业 {$team['id']} 引用的角色 {$roleId} 不存在。";

                    continue;
                }
                if ((int) $role->status !== 1) {
                    $errors[] = "作业 {$team['id']} 引用的角色 {$roleId} 未启用。";
                }

                $roleWidths[] = [
                    'role_id' => $roleId,
                    'search_area_width' => (int) $role->search_area_width,
                ];
                $atkTotal += (int) $role->atk_type;
            }

            if ($atkTotal !== (int) $team['atk_value']) {
                $errors[] = "作业 {$team['id']} 的 atk_value 应为 {$atkTotal}，实际为 {$team['atk_value']}。";
            }

            usort($roleWidths, function (array $left, array $right): int {
                return ($right['search_area_width'] <=> $left['search_area_width'])
                    ?: ($left['role_id'] <=> $right['role_id']);
            });
            $canonicalKey = implode(',', array_column($roleWidths, 'role_id'));
            if ($canonicalKey !== $team['team_key']) {
                $errors[] = "作业 {$team['id']} 的 team_key 应为 {$canonicalKey}，实际为 {$team['team_key']}。";
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @param  array<int, array<string, mixed>>  $copies
     * @return array{
     *     insert: int,
     *     skip: int,
     *     copy_insert: int,
     *     copy_skip: int,
     *     existing_ids: array<int, bool>,
     *     existing_copy_signatures: array<string, bool>
     * }
     */
    private function inspectExisting(
        array $included,
        array $copies,
        bool $lock = false
    ): array {
        $ids = array_map(fn (array $entry): int => (int) $entry['team']['id'], $included);
        $query = DB::table('teams')->whereIn('id', $ids);
        if ($lock) {
            $query->lockForUpdate();
        }
        $existingTeams = $query->get()->keyBy(fn (object $team): int => (int) $team->id);

        $errors = [];
        $existingCopies = [];
        $copyMonth = $copies === []
            ? null
            : substr((string) $copies[0]['team']['created_at'], 0, 7);
        if ($copyMonth !== null) {
            $copyQuery = DB::table('teams')
                ->where('uid', 0)
                ->whereNull('source_team_id')
                ->where('created_at', '>=', $copyMonth.'-01 00:00:00')
                ->where('created_at', '<', $this->nextMonthStart($copyMonth));
            if ($lock) {
                $copyQuery->lockForUpdate();
            }
            foreach ($copyQuery->get() as $copyTeam) {
                $signature = $this->teamSignature((array) $copyTeam);
                if (isset($existingCopies[$signature])) {
                    $errors[] = "副本月份 {$copyMonth} 存在重复的系统作业签名。";
                }
                $existingCopies[$signature] = $copyTeam;
            }
        }

        $allExistingIds = array_values(array_unique(array_merge(
            $existingTeams->keys()->map(fn (int $id): int => (int) $id)->all(),
            array_map(fn (object $team): int => (int) $team->id, array_values($existingCopies)),
        )));
        $children = $this->childSnapshots($allExistingIds);
        $insert = 0;
        $skip = 0;
        $copyInsert = 0;
        $copySkip = 0;
        $existingIds = [];
        $existingCopySignatures = [];

        foreach ($included as $entry) {
            $team = $entry['team'];
            $id = (int) $team['id'];
            $existing = $existingTeams->get($id);
            if (! $existing) {
                $insert++;

                continue;
            }

            $existingIds[$id] = true;
            $this->assertTeamMatches((array) $existing, $team, "作业 {$id}", $errors);
            $this->assertChildrenMatch(
                $children[$id] ?? ['roles' => [], 'links' => []],
                $entry['roles'],
                $entry['links'],
                "作业 {$id}",
                $errors,
            );
            $skip++;
        }

        foreach ($copies as $copy) {
            $signature = (string) $copy['signature'];
            $existing = $existingCopies[$signature] ?? null;
            if (! $existing) {
                $copyInsert++;

                continue;
            }

            $existingCopySignatures[$signature] = true;
            $existingId = (int) $existing->id;
            $this->assertTeamMatches(
                (array) $existing,
                $copy['team'],
                "副本 {$copy['source_id']}（作业 {$existingId}）",
                $errors,
            );
            $this->assertChildrenMatch(
                $children[$existingId] ?? ['roles' => [], 'links' => []],
                $copy['roles'],
                $copy['links'],
                "副本 {$copy['source_id']}（作业 {$existingId}）",
                $errors,
            );
            $copySkip++;
        }

        if ($errors !== []) {
            throw new DomainException("目标数据冲突：\n- ".implode("\n- ", $errors));
        }

        return [
            'insert' => $insert,
            'skip' => $skip,
            'copy_insert' => $copyInsert,
            'copy_skip' => $copySkip,
            'existing_ids' => $existingIds,
            'existing_copy_signatures' => $existingCopySignatures,
        ];
    }

    /**
     * @param  array<int, int>  $teamIds
     * @return array<int, array{roles: array<int, array{role_id: int, status: int}>, links: array<int, array<string, mixed>>}>
     */
    private function childSnapshots(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $snapshots = [];
        $roleRows = DB::table('team_roles')
            ->whereIn('team_id', $teamIds)
            ->get(['team_id', 'role_id', 'status']);
        foreach ($roleRows->groupBy('team_id') as $teamId => $roles) {
            $snapshots[(int) $teamId]['roles'] = $this->canonicalRoles($roles);
        }

        $linkRows = DB::table('team_links')
            ->whereIn('team_id', $teamIds)
            ->get(['team_id', 'url', 'author', 'difficulty', 'damage', 'status', 'note']);
        foreach ($linkRows->groupBy('team_id') as $teamId => $links) {
            $snapshots[(int) $teamId]['links'] = $this->canonicalLinks($links);
        }

        foreach ($teamIds as $teamId) {
            $snapshots[$teamId] ??= ['roles' => [], 'links' => []];
        }

        return $snapshots;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $expected
     * @param  array<int, string>  $errors
     */
    private function assertTeamMatches(
        array $existing,
        array $expected,
        string $label,
        array &$errors
    ): void {
        $fields = [
            'stage', 'source_team_id', 'uid', 'boss', 'damage', 'team_key',
            'status', 'mode', 'atk_value', 'remark', 'import_timestamp',
            'created_at', 'updated_at',
        ];

        foreach ($fields as $field) {
            if ($this->normalizeValue($existing[$field] ?? null)
                !== $this->normalizeValue($expected[$field] ?? null)) {
                $errors[] = "{$label} 的字段 {$field} 与导入数据不一致。";
            }
        }
    }

    /**
     * @param  array{roles: array<int, array{role_id: int, status: int}>, links: array<int, array<string, mixed>>}  $existing
     * @param  array<int, array<string, mixed>>  $expectedRoles
     * @param  array<int, array<string, mixed>>  $expectedLinks
     * @param  array<int, string>  $errors
     */
    private function assertChildrenMatch(
        array $existing,
        array $expectedRoles,
        array $expectedLinks,
        string $label,
        array &$errors
    ): void {
        if ($existing['roles'] !== $this->canonicalRoles($expectedRoles)) {
            $errors[] = "{$label} 的 team_roles 内容与导入数据不一致。";
        }
        if ($existing['links'] !== $this->canonicalLinks($expectedLinks)) {
            $errors[] = "{$label} 的 team_links 内容与导入数据不一致。";
        }
    }

    /**
     * @param  iterable<mixed>  $roles
     * @return array<int, array{role_id: int, status: int}>
     */
    private function canonicalRoles(iterable $roles): array
    {
        $result = [];
        foreach ($roles as $role) {
            $row = (array) $role;
            $result[] = [
                'role_id' => (int) $row['role_id'],
                'status' => (int) $row['status'],
            ];
        }
        usort($result, fn (array $left, array $right): int => $left['role_id'] <=> $right['role_id']);

        return $result;
    }

    /**
     * @param  iterable<mixed>  $links
     * @return array<int, array<string, mixed>>
     */
    private function canonicalLinks(iterable $links): array
    {
        $result = [];
        foreach ($links as $link) {
            $row = (array) $link;
            $result[] = [
                'url' => (string) $row['url'],
                'author' => (string) $row['author'],
                'difficulty' => (int) $row['difficulty'],
                'damage' => (int) $row['damage'],
                'status' => (int) $row['status'],
                'note' => $row['note'] === null ? null : (string) $row['note'],
            ];
        }
        usort(
            $result,
            fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR),
                json_encode($right, JSON_THROW_ON_ERROR),
            ),
        );

        return $result;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return (string) $value;
    }

    private function nextMonthStart(string $yearMonth): string
    {
        return CarbonImmutable::createFromFormat(
            '!Y-m-d',
            $yearMonth.'-01',
            (string) config('app.timezone', 'Asia/Shanghai'),
        )->addMonthNoOverflow()->format('Y-m-d H:i:s');
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @param  array<int, array<string, mixed>>  $copies
     * @return array{
     *     inserted: int,
     *     skipped: int,
     *     copy_inserted: int,
     *     copy_skipped: int
     * }
     */
    private function importEntries(array $included, array $copies, bool $lock): array
    {
        $existing = $this->inspectExisting($included, $copies, $lock);
        $missing = array_values(array_filter(
            $included,
            fn (array $entry): bool => ! isset($existing['existing_ids'][(int) $entry['id']]),
        ));

        if ($missing !== []) {
            DB::table('teams')->insert(array_map(fn (array $entry): array => $entry['team'], $missing));

            $roleRows = [];
            $linkRows = [];
            foreach ($missing as $entry) {
                array_push($roleRows, ...$entry['roles']);
                array_push($linkRows, ...$entry['links']);
            }

            if ($roleRows !== []) {
                DB::table('team_roles')->insert($roleRows);
            }
            if ($linkRows !== []) {
                DB::table('team_links')->insert($linkRows);
            }
        }

        $missingCopies = array_values(array_filter(
            $copies,
            fn (array $copy): bool => ! isset($existing['existing_copy_signatures'][(string) $copy['signature']]),
        ));

        foreach ($missingCopies as $copy) {
            $teamId = DB::table('teams')->insertGetId($copy['team']);
            $roleRows = array_map(function (array $role) use ($teamId): array {
                return [
                    'team_id' => $teamId,
                    'role_id' => $role['role_id'],
                    'status' => $role['status'],
                ];
            }, $copy['roles']);
            $linkRows = array_map(function (array $link) use ($teamId): array {
                $link['team_id'] = $teamId;

                return $link;
            }, $copy['links']);

            if ($roleRows !== []) {
                DB::table('team_roles')->insert($roleRows);
            }
            if ($linkRows !== []) {
                DB::table('team_links')->insert($linkRows);
            }
        }

        $this->verifyImportedTotals($included, $copies);

        return [
            'inserted' => $existing['insert'],
            'skipped' => $existing['skip'],
            'copy_inserted' => $existing['copy_insert'],
            'copy_skipped' => $existing['copy_skip'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $included
     * @param  array<int, array<string, mixed>>  $copies
     */
    private function verifyImportedTotals(array $included, array $copies): void
    {
        $verified = $this->inspectExisting($included, $copies);
        if ($verified['insert'] !== 0 || $verified['copy_insert'] !== 0) {
            throw new DomainException('导入后校验失败：仍有作业未写入目标表。');
        }

        $ids = array_map(fn (array $entry): int => (int) $entry['team']['id'], $included);
        $copyIds = $this->copyTeamIds($copies);
        $allIds = array_values(array_unique(array_merge($ids, $copyIds)));
        $expectedRoles = array_sum(array_map(fn (array $entry): int => count($entry['roles']), $included))
            + array_sum(array_map(fn (array $entry): int => count($entry['roles']), $copies));
        $expectedLinks = array_sum(array_map(fn (array $entry): int => count($entry['links']), $included))
            + array_sum(array_map(fn (array $entry): int => count($entry['links']), $copies));

        $actualTeams = DB::table('teams')->whereIn('id', $allIds)->count();
        $actualRoles = DB::table('team_roles')->whereIn('team_id', $allIds)->count();
        $actualLinks = DB::table('team_links')->whereIn('team_id', $allIds)->count();

        if ($actualTeams !== count($included) + count($copies)
            || $actualRoles !== $expectedRoles
            || $actualLinks !== $expectedLinks) {
            throw new DomainException(sprintf(
                '导入后校验失败：teams=%d/%d, team_roles=%d/%d, team_links=%d/%d。',
                $actualTeams,
                count($included) + count($copies),
                $actualRoles,
                $expectedRoles,
                $actualLinks,
                $expectedLinks,
            ));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $copies
     * @return array<int, int>
     */
    private function copyTeamIds(array $copies): array
    {
        if ($copies === []) {
            return [];
        }

        $copyMonth = substr((string) $copies[0]['team']['created_at'], 0, 7);
        $rows = DB::table('teams')
            ->where('uid', 0)
            ->whereNull('source_team_id')
            ->where('created_at', '>=', $copyMonth.'-01 00:00:00')
            ->where('created_at', '<', $this->nextMonthStart($copyMonth))
            ->get();
        $expectedSignatures = array_fill_keys(
            array_map(fn (array $copy): string => (string) $copy['signature'], $copies),
            true,
        );
        $ids = [];
        foreach ($rows as $row) {
            $signature = $this->teamSignature((array) $row);
            if (isset($expectedSignatures[$signature])) {
                $ids[$signature] = (int) $row->id;
            }
        }

        return array_values($ids);
    }

    /**
     * @return array{author?: string, difficulty?: int, damage?: int, note?: null|string, error?: string}
     */
    private function parseLinkText(string $text, string $oldNote, int $teamDamage, int $stage): array
    {
        $pattern = '/^(?<author>.+?)\s+(?<difficulty>纯AUTO|简单SET|开关SET|简单目押|目押)(?:\s+(?<damage>\d+)w)?(?:\s+(?<note>.+))?$/u';
        if (! preg_match($pattern, trim($text), $matches)) {
            return ['error' => 'text 无法解析出作者和难度。'];
        }

        $author = trim($matches['author']);
        if ($author === '-Evexy-') {
            $author = 'Evexy';
        }

        if (! preg_match('/^[\x{4E00}-\x{9FFF}A-Za-z0-9]+$/u', $author)) {
            return ['error' => "作者 {$author} 包含不允许的字符。"];
        }
        if ($this->authorLength($author) > 16) {
            return ['error' => "作者 {$author} 超过长度限制。"];
        }

        $difficulty = self::DIFFICULTIES[$matches['difficulty']] ?? null;
        if ($difficulty === null) {
            return ['error' => "难度 {$matches['difficulty']} 无法映射。"];
        }

        $hasDamage = isset($matches['damage']) && $matches['damage'] !== '';
        if (! $hasDamage && $stage === 5) {
            return ['error' => 'E 阶段链接必须包含伤害。'];
        }

        $damage = $hasDamage ? (int) $matches['damage'] : $teamDamage;
        if ($damage < 1 || $damage > 99999) {
            return ['error' => "伤害 {$damage} 超出 1-99999。"];
        }

        $note = trim((string) ($matches['note'] ?? ''));
        if ($note === '') {
            $note = trim($oldNote);
        }

        return [
            'author' => $author,
            'difficulty' => $difficulty,
            'damage' => $damage,
            'note' => $note === '' ? null : $note,
        ];
    }

    private function authorLength(string $author): int
    {
        $length = 0;
        foreach (preg_split('//u', $author, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $length += preg_match('/^[\x{4E00}-\x{9FFF}]$/u', $character) ? 2 : 1;
        }

        return $length;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return (int) $value;
        }

        return null;
    }

    private function isDateTime(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);

        return $date !== false && $date->format('Y-m-d H:i:s') === $value;
    }

    private function isUrl(string $url): bool
    {
        if ($url === '' || strlen($url) > 1000 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }

    private function assertSchema(): void
    {
        $requirements = [
            'teams' => ['id', 'stage', 'source_team_id', 'uid', 'boss', 'damage', 'team_key', 'status', 'mode', 'atk_value', 'remark', 'import_timestamp', 'created_at', 'updated_at'],
            'team_roles' => ['id', 'team_id', 'role_id', 'status'],
            'team_links' => ['id', 'team_id', 'url', 'author', 'difficulty', 'damage', 'status', 'note'],
            'game_roles' => ['id', 'atk_type', 'search_area_width', 'status'],
        ];

        foreach ($requirements as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new DomainException("目标表 {$table} 不存在。");
            }
            if (! Schema::hasColumns($table, $columns)) {
                throw new DomainException("目标表 {$table} 缺少导入所需列。");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $prepared
     * @param  array{insert: int, skip: int}  $existing
     */
    private function displaySummary(
        string $path,
        array $prepared,
        array $copies,
        array $existing
    ): void {
        $this->info("数据文件：{$path}");
        $this->line(sprintf(
            '待处理原始：%d 条作业，%d 条角色关系，%d 条链接；本次新增 %d 条，跳过 %d 条。',
            $prepared['expected']['teams'],
            $prepared['expected']['roles'],
            $prepared['expected']['links'],
            $existing['insert'],
            $existing['skip'],
        ));

        if ($copies !== []) {
            $copyTeams = count($copies);
            $copyRoles = array_sum(array_map(
                fn (array $copy): int => count($copy['roles']),
                $copies,
            ));
            $copyLinks = array_sum(array_map(
                fn (array $copy): int => count($copy['links']),
                $copies,
            ));
            $this->line(sprintf(
                '待处理副本：%d 条作业，%d 条角色关系，%d 条链接；本次新增 %d 条，跳过 %d 条。',
                $copyTeams,
                $copyRoles,
                $copyLinks,
                $existing['copy_insert'],
                $existing['copy_skip'],
            ));
            $this->line(sprintf(
                '合计待处理：%d 条作业，%d 条角色关系，%d 条链接。',
                $prepared['expected']['teams'] + $copyTeams,
                $prepared['expected']['roles'] + $copyRoles,
                $prepared['expected']['links'] + $copyLinks,
            ));
        }

        if ($prepared['excluded'] !== []) {
            foreach ($prepared['excluded'] as $excluded) {
                $this->warn("已排除作业 {$excluded['id']}：{$excluded['reason']}");
            }
        }

        $this->line('阶段分布：'.$this->formatCounts($prepared['stage_counts'], [2 => 'B', 3 => 'C', 4 => 'D']));
        $this->line('模式分布：'.$this->formatCounts($prepared['mode_counts'], [1 => '自动', 2 => '手动']));
        $this->line('难度分布：'.$this->formatCounts($prepared['difficulty_counts'], self::DIFFICULTY_LABELS));
        $this->line('链接备注：'.$this->formatNoteCounts($prepared['note_counts']));
    }

    /**
     * @param  array<int, int>  $counts
     * @param  array<int, string>  $labels
     */
    private function formatCounts(array $counts, array $labels): string
    {
        $parts = [];
        foreach ($counts as $value => $count) {
            $parts[] = ($labels[$value] ?? $value).'='.$count;
        }

        return implode('，', $parts);
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function formatNoteCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $note => $count) {
            $parts[] = ($note === '' ? '无额外备注' : $note).'='.$count;
        }

        return implode('，', $parts);
    }
}
