<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ImportLegacyTeamsCommandTest extends TestCase
{
    private ?string $manifestPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->manifestPath !== null) {
            File::delete($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_dry_run_validates_without_writing(): void
    {
        $this->seedRoles();
        $this->manifestPath = $this->writeManifest($this->records());

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
        ])
            ->expectsOutputToContain('待处理原始：3 条作业，6 条角色关系，4 条链接；本次新增 3 条，跳过 0 条。')
            ->expectsOutputToContain('待处理副本：3 条作业，6 条角色关系，4 条链接；本次新增 3 条，跳过 0 条。')
            ->expectsOutputToContain('合计待处理：6 条作业，12 条角色关系，8 条链接。')
            ->expectsOutputToContain('DRY RUN: 未写入数据库')
            ->assertExitCode(0);

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_roles', 0);
        $this->assertDatabaseCount('team_links', 0);
    }

    public function test_execute_imports_and_maps_legacy_fields(): void
    {
        $this->seedRoles();
        $this->manifestPath = $this->writeManifest($this->records());
        Cache::put(
            'system-source:rows:v1:202610:high',
            ['stub' => true],
            now()->addHour()
        );
        Cache::put(
            'system-source:rows:v1:202611:low',
            ['stub' => true],
            now()->addHour()
        );
        Cache::put(
            'systemTripleTeams:202610',
            ['stub' => true],
            now()->addHour()
        );
        Cache::put(
            'systemTripleTeamsMakeTeams:202611',
            ['stub' => true],
            now()->addHour()
        );

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])->assertExitCode(0);

        $this->assertFalse(Cache::has('system-source:rows:v1:202610:high'));
        $this->assertFalse(Cache::has('system-source:rows:v1:202611:low'));
        $this->assertFalse(Cache::has('systemTripleTeams:202610'));
        $this->assertFalse(
            Cache::has('systemTripleTeamsMakeTeams:202611')
        );

        $this->assertDatabaseCount('teams', 6);
        $this->assertDatabaseCount('team_roles', 12);
        $this->assertDatabaseCount('team_links', 8);

        $this->assertDatabaseHas('teams', [
            'id' => 3908,
            'stage' => 2,
            'source_team_id' => null,
            'uid' => 0,
            'boss' => 1,
            'damage' => 4200,
            'team_key' => '102,101',
            'status' => 1,
            'mode' => 1,
            'atk_value' => 0,
            'remark' => '',
            'import_timestamp' => 1792592401,
            'created_at' => '2026-10-21 22:20:01',
            'updated_at' => null,
        ]);
        $this->assertDatabaseHas('team_links', [
            'team_id' => 3908,
            'author' => '作者甲',
            'difficulty' => 1,
            'damage' => 4200,
            'status' => 1,
            'note' => '返90s',
        ]);

        $this->assertDatabaseHas('teams', [
            'id' => 3980,
            'stage' => 4,
            'boss' => 2,
            'damage' => 44000,
            'team_key' => '103,104',
            'mode' => 2,
            'atk_value' => 0,
        ]);
        $this->assertDatabaseHas('team_links', [
            'team_id' => 3980,
            'author' => 'Evexy',
            'difficulty' => 5,
            'damage' => 41606,
            'status' => 1,
            'note' => null,
        ]);
        $this->assertDatabaseHas('teams', [
            'id' => 3959,
            'stage' => 4,
            'boss' => 2,
            'damage' => 33214,
            'team_key' => '1361,103',
        ]);
        $this->assertDatabaseHas('team_roles', [
            'team_id' => 3959,
            'role_id' => 1361,
            'status' => 1,
        ]);
        $this->assertDatabaseMissing('teams', ['stage' => 5]);

        $copyTeams = DB::table('teams')
            ->where('created_at', '>=', '2026-11-01 00:00:00')
            ->where('created_at', '<', '2026-12-01 00:00:00')
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $copyTeams);
        $this->assertTrue($copyTeams->every(
            fn (object $team): bool => (int) $team->uid === 0
                && $team->source_team_id === null,
        ));

        foreach ([3908, 3959, 3980] as $originalId) {
            $this->assertCopyMatchesOriginal($originalId);
        }
    }

    public function test_repeat_execute_skips_matching_records(): void
    {
        $this->seedRoles();
        $this->manifestPath = $this->writeManifest($this->records());

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])->assertExitCode(0);

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])
            ->expectsOutputToContain('原始新增 0 条，副本新增 0 条；原始跳过 3 条，副本跳过 3 条。')
            ->assertExitCode(0);

        $this->assertDatabaseCount('teams', 6);
        $this->assertDatabaseCount('team_roles', 12);
        $this->assertDatabaseCount('team_links', 8);
    }

    public function test_missing_role_aborts_before_writing(): void
    {
        $this->seedRoles(without: [104]);
        $this->manifestPath = $this->writeManifest($this->records());

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])
            ->expectsOutputToContain('引用的角色 104 不存在')
            ->assertExitCode(1);

        $this->assertDatabaseCount('teams', 0);
        $this->assertDatabaseCount('team_roles', 0);
        $this->assertDatabaseCount('team_links', 0);
    }

    public function test_existing_conflict_aborts_without_partial_import(): void
    {
        $this->seedRoles();
        DB::table('teams')->insert([
            'id' => 3908,
            'stage' => 2,
            'source_team_id' => null,
            'uid' => 0,
            'boss' => 1,
            'damage' => 4200,
            'team_key' => 'wrong-key',
            'status' => 1,
            'mode' => 1,
            'atk_value' => 0,
            'remark' => '',
            'import_timestamp' => 1792592401,
            'created_at' => '2026-10-21 22:20:01',
            'updated_at' => null,
        ]);
        $this->manifestPath = $this->writeManifest($this->records());

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])
            ->expectsOutputToContain('字段 team_key 与导入数据不一致')
            ->assertExitCode(1);

        $this->assertDatabaseCount('teams', 1);
        $this->assertDatabaseMissing('teams', ['id' => 3980]);
        $this->assertDatabaseCount('team_roles', 0);
        $this->assertDatabaseCount('team_links', 0);
    }

    public function test_copy_conflict_aborts_without_partial_import(): void
    {
        $this->seedRoles();
        $this->manifestPath = $this->writeManifest($this->records());

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])->assertExitCode(0);

        $copy = DB::table('teams')
            ->where('created_at', '2026-11-21 22:20:01')
            ->where('team_key', '103,104')
            ->where('boss', 2)
            ->firstOrFail();
        DB::table('teams')->where('id', $copy->id)->update(['damage' => 1]);

        $this->artisan('game:import-legacy-teams', [
            '--file' => $this->manifestPath,
            '--copy-month' => '2026-11',
            '--execute' => true,
        ])
            ->expectsOutputToContain('字段 damage 与导入数据不一致')
            ->assertExitCode(1);

        $this->assertDatabaseCount('teams', 6);
        $this->assertDatabaseCount('team_roles', 12);
        $this->assertDatabaseCount('team_links', 8);
    }

    public function test_application_and_database_timezones_use_shanghai_time(): void
    {
        $this->assertSame('Asia/Shanghai', config('app.timezone'));
        $this->assertSame('+08:00', config('database.connections.mysql.timezone'));
        $this->assertSame('+08:00', config('database.connections.mariadb.timezone'));
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('team_links');
        Schema::dropIfExists('team_roles');
        Schema::dropIfExists('teams');
        Schema::dropIfExists('game_roles');

        Schema::create('game_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->tinyInteger('atk_type');
            $table->unsignedInteger('search_area_width');
            $table->boolean('status')->default(true);
        });

        Schema::create('teams', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->unsignedTinyInteger('stage');
            $table->unsignedInteger('source_team_id')->nullable();
            $table->unsignedInteger('uid')->default(0);
            $table->unsignedTinyInteger('boss');
            $table->unsignedInteger('damage');
            $table->string('team_key', 100);
            $table->unsignedTinyInteger('status')->default(1);
            $table->unsignedTinyInteger('mode');
            $table->tinyInteger('atk_value');
            $table->text('remark')->nullable();
            $table->unsignedBigInteger('import_timestamp')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('team_roles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('role_id');
            $table->unsignedTinyInteger('status')->default(1);
            $table->unique(['team_id', 'role_id']);
        });

        Schema::create('team_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('team_id');
            $table->string('url', 1000);
            $table->string('author', 32);
            $table->unsignedTinyInteger('difficulty');
            $table->unsignedInteger('damage');
            $table->unsignedTinyInteger('status')->default(1);
            $table->string('note', 255)->nullable();
        });
    }

    /**
     * @param  array<int, int>  $without
     */
    private function seedRoles(array $without = []): void
    {
        $roles = [
            ['id' => 101, 'atk_type' => 1, 'search_area_width' => 100, 'status' => 1],
            ['id' => 102, 'atk_type' => -1, 'search_area_width' => 300, 'status' => 1],
            ['id' => 103, 'atk_type' => 1, 'search_area_width' => 200, 'status' => 1],
            ['id' => 104, 'atk_type' => -1, 'search_area_width' => 100, 'status' => 1],
            ['id' => 1361, 'atk_type' => 1, 'search_area_width' => 432, 'status' => 1],
        ];

        DB::table('game_roles')->insert(array_values(array_filter(
            $roles,
            fn (array $role): bool => ! in_array($role['id'], $without, true),
        )));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function records(): array
    {
        return [
            $this->record([
                'id' => 3908,
                'stage' => 2,
                'boss' => 1,
                'damage' => 4200,
                'team_key' => '102,101',
                'auto' => 1,
                'atk_value' => 0,
                'roles' => [101, 102],
                'links' => [
                    ['url' => 'https://example.com/team-a', 'text' => '作者甲 纯AUTO 返90s'],
                ],
            ]),
            $this->record([
                'id' => 3980,
                'stage' => 5,
                'boss' => 2,
                'damage' => 44000,
                'team_key' => '103,104',
                'auto' => 2,
                'atk_value' => 0,
                'roles' => [103, 104],
                'links' => [
                    ['url' => 'https://example.com/team-b-1', 'text' => '作者乙 目押 44000w'],
                    ['url' => 'https://example.com/team-b-2', 'text' => '-Evexy- 目押 41606w'],
                ],
            ]),
            $this->record([
                'id' => 3959,
                'stage' => 5,
                'boss' => 2,
                'damage' => 33214,
                'team_key' => '1361,103',
                'auto' => 1,
                'atk_value' => 2,
                'roles' => [1361, 103],
                'links' => [
                    ['url' => 'https://example.com/team-c', 'text' => '作者丙 纯AUTO 33214w'],
                ],
                'created_at' => '2026-10-21 23:01:08',
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function record(array $overrides): array
    {
        $roleIds = $overrides['roles'];
        $links = $overrides['links'];

        $record = array_merge([
            'id' => 3908,
            'stage' => 2,
            'sn' => null,
            'otid' => null,
            'uid' => 0,
            'boss' => 1,
            'damage' => 4200,
            'team_key' => '',
            'roles' => [],
            'open' => null,
            'status' => 1,
            'auto' => 1,
            'atk_value' => 0,
            'remark' => '',
            'links' => [],
            'timestamp' => 1792592401,
            'created_at' => '2026-10-21 22:20:01',
            'updated_at' => null,
            'included' => true,
        ], $overrides);

        $record['roles'] = array_map(
            fn (int $roleId): array => ['role_id' => $roleId, 'status' => 1],
            $roleIds,
        );
        $record['links'] = array_map(fn (array $link): array => [
            'url' => $link['url'],
            'note' => '',
            'text' => $link['text'],
            'image' => [],
        ], $links);

        return $record;
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     */
    private function writeManifest(array $records): string
    {
        $path = tempnam(sys_get_temp_dir(), 'legacy-teams-');
        File::put($path, json_encode([
            'source' => 'test',
            'records' => $records,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return $path;
    }

    private function assertCopyMatchesOriginal(int $originalId): void
    {
        $original = (array) DB::table('teams')->where('id', $originalId)->firstOrFail();
        $copy = (array) DB::table('teams')
            ->where('uid', 0)
            ->whereNull('source_team_id')
            ->where('created_at', '>=', '2026-11-01 00:00:00')
            ->where('created_at', '<', '2026-12-01 00:00:00')
            ->where('team_key', $original['team_key'])
            ->where('mode', $original['mode'])
            ->where('stage', $original['stage'])
            ->where('boss', $original['boss'])
            ->firstOrFail();

        $copyId = (int) $copy['id'];
        $this->assertNotSame($original['id'], $copy['id']);
        $this->assertSame(
            CarbonImmutable::createFromFormat(
                '!Y-m-d H:i:s',
                (string) $original['created_at'],
                'Asia/Shanghai',
            )->addMonthNoOverflow()->format('Y-m-d H:i:s'),
            $copy['created_at'],
        );

        unset($original['id'], $original['created_at'], $copy['id'], $copy['created_at']);
        $this->assertSame($original, $copy);

        $this->assertSame(
            $this->childRows('team_roles', $originalId, ['role_id', 'status']),
            $this->childRows('team_roles', $copyId, ['role_id', 'status']),
        );
        $this->assertSame(
            $this->childRows('team_links', $originalId, [
                'url', 'author', 'difficulty', 'damage', 'status', 'note',
            ]),
            $this->childRows('team_links', $copyId, [
                'url', 'author', 'difficulty', 'damage', 'status', 'note',
            ]),
        );
    }

    /**
     * @param  array<int, string>  $columns
     * @return array<int, array<string, mixed>>
     */
    private function childRows(string $table, int $teamId, array $columns): array
    {
        $rows = DB::table($table)
            ->where('team_id', $teamId)
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();
        usort(
            $rows,
            fn (array $left, array $right): int => strcmp(
                json_encode($left, JSON_THROW_ON_ERROR),
                json_encode($right, JSON_THROW_ON_ERROR),
            ),
        );

        return $rows;
    }
}
