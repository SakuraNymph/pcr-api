<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserClanSchedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserClanScheduleApiTest extends TestCase
{
    private User $user;

    private User $guest;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestTables();

        $this->user = $this->createUser('owner@example.com', '正式用户', 1);
        $this->guest = $this->createUser('guest@example.com', '游客', 0);
        $this->otherUser = $this->createUser('other@example.com', '其他用户', 1);

        Sanctum::actingAs($this->user);
    }

    public function test_registered_user_can_read_an_empty_schedule_without_creating_a_row(): void
    {
        $this->getJson('/api/user/clan-schedule')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.cells', [])
            ->assertJsonPath('data.player_order', []);

        $this->assertSame(0, UserClanSchedule::query()->count());
    }

    public function test_guest_is_rejected_from_all_clan_schedule_endpoints(): void
    {
        Sanctum::actingAs($this->guest);

        $this->getJson('/api/user/clan-schedule')->assertUnauthorized();
        $this->putJson('/api/user/clan-schedule', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/user/clan-schedule')->assertUnauthorized();
        $this->getJson('/api/user/clan-schedules')->assertUnauthorized();
        $this->postJson('/api/user/clan-schedules/share-code')->assertUnauthorized();
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => 'ABCD2345',
        ])->assertUnauthorized();
        $this->getJson('/api/user/clan-schedules/1')->assertUnauthorized();
        $this->putJson('/api/user/clan-schedules/1', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/user/clan-schedules/1')->assertUnauthorized();
        $this->deleteJson('/api/user/clan-schedules/1/membership')->assertUnauthorized();

        $this->assertSame(0, UserClanSchedule::query()->count());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        Auth::forgetGuards();

        $this->getJson('/api/user/clan-schedule')->assertUnauthorized();
        $this->putJson('/api/user/clan-schedule', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/user/clan-schedule')->assertUnauthorized();
    }

    public function test_save_trims_names_sorts_cells_and_normalizes_player_order(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, ' Alice ', 0),
            $this->cell('B', 1, 2, 'Alice', 1),
            $this->cell('C', 7, 3, 'Bob', 0),
        ], ['Bob', 'Alice', 'Unknown']))
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('msg', '排刀表已保存')
            ->assertJsonPath('data.cells.0.player', 'Alice')
            ->assertJsonPath('data.cells.0.round', 1)
            ->assertJsonPath('data.cells.2.player', 'Bob')
            ->assertJsonPath('data.player_order', ['Bob', 'Alice']);

        $schedule = UserClanSchedule::query()->firstOrFail();

        $this->assertSame('Alice', $schedule->cells[0]['player']);
        $this->assertSame(['Bob', 'Alice'], $schedule->player_order);
        $this->assertSame(1, UserClanSchedule::query()->count());

        $this->getJson('/api/user/clan-schedule')
            ->assertOk()
            ->assertJsonPath('data.cells.0.player', 'Alice')
            ->assertJsonPath('data.player_order', ['Bob', 'Alice']);
    }

    public function test_missing_player_order_is_filled_from_canonical_cell_order(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('C', 8, 2, 'Bob', 0),
            $this->cell('B', 2, 1, 'Alice', 0),
        ], []))
            ->assertOk()
            ->assertJsonPath('data.player_order', ['Alice', 'Bob']);
    }

    public function test_full_snapshot_replaces_previous_data(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'Alice', 0),
        ], ['Alice']))->assertOk();

        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('C', 7, 2, 'Bob', 0),
        ], ['Bob']))->assertOk();

        $this->getJson('/api/user/clan-schedule')
            ->assertOk()
            ->assertJsonCount(1, 'data.cells')
            ->assertJsonPath('data.cells.0.player', 'Bob')
            ->assertJsonPath('data.player_order', ['Bob']);

        $this->assertSame(1, UserClanSchedule::query()->count());
    }

    public function test_registered_user_data_are_isolated(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        Sanctum::actingAs($this->otherUser);

        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OtherPlayer', 0),
        ], ['OtherPlayer']))->assertOk();

        Sanctum::actingAs($this->user);
        $this->getJson('/api/user/clan-schedule')
            ->assertJsonPath('data.cells.0.player', 'OwnerPlayer');

        Sanctum::actingAs($this->otherUser);
        $this->getJson('/api/user/clan-schedule')
            ->assertJsonPath('data.cells.0.player', 'OtherPlayer');

        $this->assertSame(2, UserClanSchedule::query()->count());
    }

    public function test_client_supplied_uid_is_ignored(): void
    {
        $payload = $this->payload([
            $this->cell('B', 1, 1, 'Alice', 0),
        ], ['Alice']);
        $payload['uid'] = $this->otherUser->id;

        $this->putJson('/api/user/clan-schedule', $payload)->assertOk();

        $this->assertDatabaseHas('user_clan_schedules', [
            'uid' => $this->user->id,
        ]);
        $this->assertDatabaseMissing('user_clan_schedules', [
            'uid' => $this->otherUser->id,
        ]);
    }

    public function test_maximum_30_players_and_180_cells_are_accepted(): void
    {
        $cells = $this->fullCapacityCells();

        $this->putJson('/api/user/clan-schedule', $this->payload($cells, []))
            ->assertOk()
            ->assertJsonCount(180, 'data.cells')
            ->assertJsonCount(30, 'data.player_order');

        $this->assertSame(180, count(UserClanSchedule::query()->firstOrFail()->cells));
    }

    public function test_more_than_30_distinct_players_is_rejected(): void
    {
        $cells = [];
        for ($index = 0; $index < 31; $index++) {
            $phase = $index < 30 ? 'B' : 'C';
            $round = $index < 30 ? 1 + intdiv($index, 5) : 7;
            $cells[] = $this->cell(
                $phase,
                $round,
                ($index % 5) + 1,
                'Player' . $index,
                0
            );
        }

        $this->putJson('/api/user/clan-schedule', $this->payload($cells, []))
            ->assertUnprocessable();

        $this->assertSame(0, UserClanSchedule::query()->count());
    }

    public function test_more_than_6_cells_for_one_player_is_rejected(): void
    {
        $cells = [];
        for ($index = 0; $index < 7; $index++) {
            $cells[] = $this->cell(
                $index < 6 ? 'B' : 'C',
                $index < 6 ? $index + 1 : 7,
                1,
                'Alice',
                $index === 6 ? 0 : $index
            );
        }

        $this->putJson('/api/user/clan-schedule', $this->payload($cells, ['Alice']))
            ->assertUnprocessable();
    }

    public function test_more_than_3_d_cells_for_one_player_is_rejected(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('D', 23, 1, 'Alice', 0),
            $this->cell('D', 23, 2, 'Alice', 1),
            $this->cell('D', 23, 3, 'Alice', 2),
            $this->cell('D', 23, 4, 'Alice', 3),
        ], ['Alice']))->assertUnprocessable();
    }

    public function test_return_slot_requires_b_or_c_base_slot(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('C', 7, 1, 'Alice', 3),
        ], ['Alice']))->assertUnprocessable();

        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('D', 23, 1, 'Alice', 0),
            $this->cell('C', 7, 1, 'Alice', 3),
        ], ['Alice']))->assertUnprocessable();

        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'Alice', 0),
            $this->cell('C', 7, 1, 'Alice', 3),
        ], ['Alice']))->assertOk();
    }

    public function test_duplicate_positions_slots_and_invalid_ranges_are_rejected(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'Alice', 0),
        ], ['Alice']))->assertOk();

        $invalidCells = [
            [
                $this->cell('B', 1, 1, 'Alice', 0),
                $this->cell('B', 1, 1, 'Bob', 0),
            ],
            [
                $this->cell('B', 1, 1, 'Alice', 0),
                $this->cell('B', 1, 2, 'Alice', 0),
            ],
            [$this->cell('B', 7, 1, 'Alice', 0)],
            [$this->cell('C', 6, 1, 'Alice', 0)],
            [$this->cell('D', 22, 1, 'Alice', 0)],
            [$this->cell('D', 37, 1, 'Alice', 0)],
            [$this->cell('B', 1, 6, 'Alice', 0)],
            [$this->cell('A', 1, 1, 'Alice', 0)],
            [$this->cell('B', 1, 1, '', 0)],
            [$this->cell('B', 1, 1, str_repeat('a', 33), 0)],
        ];

        foreach ($invalidCells as $cells) {
            $this->putJson('/api/user/clan-schedule', $this->payload($cells, []))
                ->assertUnprocessable();
        }

        $this->assertSame(
            'Alice',
            UserClanSchedule::query()->firstOrFail()->cells[0]['player']
        );
    }

    public function test_player_order_duplicates_missing_fields_and_extra_cell_fields_are_rejected(): void
    {
        $this->putJson('/api/user/clan-schedule', [
            'cells' => [
                $this->cell('B', 1, 1, 'Alice', 0),
                $this->cell('B', 1, 2, 'Bob', 0),
            ],
            'player_order' => ['Alice', 'Alice'],
        ])->assertUnprocessable();

        $this->putJson('/api/user/clan-schedule', [
            'cells' => [
                [
                    ...$this->cell('B', 1, 1, 'Alice', 0),
                    'uid' => $this->otherUser->id,
                ],
            ],
            'player_order' => ['Alice'],
        ])->assertUnprocessable();

        $this->putJson('/api/user/clan-schedule', [
            'cells' => [],
        ])->assertUnprocessable();

        $this->putJson('/api/user/clan-schedule', [
            'player_order' => [],
        ])->assertUnprocessable();
    }

    public function test_delete_is_idempotent_and_only_affects_current_user(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        Sanctum::actingAs($this->otherUser);
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OtherPlayer', 0),
        ], ['OtherPlayer']))->assertOk();

        Sanctum::actingAs($this->user);

        $this->deleteJson('/api/user/clan-schedule')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('msg', '排刀表已清空')
            ->assertJsonPath('data', null);

        $schedule = UserClanSchedule::query()
            ->where('uid', $this->user->id)
            ->firstOrFail();

        $this->assertSame([], $schedule->cells);
        $this->assertSame([], $schedule->player_order);
        $this->assertNotNull($schedule->share_code);

        $this->getJson('/api/user/clan-schedule')
            ->assertJsonPath('data.cells', []);

        $this->deleteJson('/api/user/clan-schedule')->assertOk();

        $this->assertDatabaseHas('user_clan_schedules', [
            'uid' => $this->otherUser->id,
        ]);
        $this->assertSame(2, UserClanSchedule::query()->count());
    }

    public function test_empty_own_schedule_can_generate_a_stable_share_code(): void
    {
        $first = $this->postJson('/api/user/clan-schedules/share-code')
            ->assertOk()
            ->assertJsonPath('status', 0)
            ->assertJsonPath('data.role', 'owner')
            ->assertJsonPath('data.cells', [])
            ->assertJsonPath('data.player_order', []);

        $code = $first->json('data.share_code');

        $this->assertIsString($code);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $code);
        $this->assertSame(1, UserClanSchedule::query()->count());

        $second = $this->postJson('/api/user/clan-schedules/share-code')
            ->assertOk()
            ->assertJsonPath('data.share_code', $code);

        $this->assertSame([], $second->json('data.cells'));
        $this->assertSame(1, UserClanSchedule::query()->count());
    }

    public function test_member_can_join_and_edit_the_owner_schedule_without_changing_own_schedule(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();
        $shareCode = $schedule->share_code;

        Sanctum::actingAs($this->otherUser);

        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => strtolower($shareCode),
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'member')
            ->assertJsonPath('data.share_code', $shareCode);

        $this->putJson("/api/user/clan-schedules/{$schedule->id}", $this->payload([
            $this->cell('B', 1, 1, 'MemberPlayer', 0),
        ], ['MemberPlayer']))->assertOk();

        $this->assertSame(
            'MemberPlayer',
            UserClanSchedule::query()->findOrFail($schedule->id)->cells[0]['player']
        );
        $this->assertSame(1, UserClanSchedule::query()->count());
        $this->assertDatabaseHas('user_clan_schedule_members', [
            'schedule_id' => $schedule->id,
            'uid' => $this->otherUser->id,
        ]);

        $this->getJson('/api/user/clan-schedule')
            ->assertOk()
            ->assertJsonPath('data.cells', [])
            ->assertJsonPath('data.player_order', []);

        $this->getJson('/api/user/clan-schedules')
            ->assertOk()
            ->assertJsonCount(1, 'data.shared')
            ->assertJsonPath('data.shared.0.id', $schedule->id)
            ->assertJsonPath('data.shared.0.role', 'member')
            ->assertJsonPath('data.shared.0.can_clear', false)
            ->assertJsonPath('data.shared.0.share_code', $shareCode);
    }

    public function test_join_is_idempotent_and_self_join_returns_owner_role(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();
        $shareCode = $schedule->share_code;

        Sanctum::actingAs($this->otherUser);
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $shareCode,
        ])->assertOk();
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $shareCode,
        ])->assertOk();

        $this->assertSame(1, DB::table('user_clan_schedule_members')->count());

        Sanctum::actingAs($this->user);
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $shareCode,
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'owner');

        $this->assertSame(1, DB::table('user_clan_schedule_members')->count());
    }

    public function test_member_cannot_clear_and_owner_clear_keeps_code_and_membership(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();
        $shareCode = $schedule->share_code;

        Sanctum::actingAs($this->otherUser);
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $shareCode,
        ])->assertOk();

        $this->deleteJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertForbidden();

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data', null);

        $schedule->refresh();
        $this->assertSame([], $schedule->cells);
        $this->assertSame([], $schedule->player_order);
        $this->assertSame($shareCode, $schedule->share_code);
        $this->assertDatabaseHas('user_clan_schedule_members', [
            'schedule_id' => $schedule->id,
            'uid' => $this->otherUser->id,
        ]);

        Sanctum::actingAs($this->otherUser);
        $this->getJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertOk()
            ->assertJsonPath('data.cells', [])
            ->assertJsonPath('data.share_code', $shareCode);
    }

    public function test_member_can_leave_and_loses_access_but_owner_cannot_leave(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();

        Sanctum::actingAs($this->otherUser);
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $schedule->share_code,
        ])->assertOk();
        $this->deleteJson("/api/user/clan-schedules/{$schedule->id}/membership")
            ->assertOk();

        $this->assertDatabaseMissing('user_clan_schedule_members', [
            'schedule_id' => $schedule->id,
            'uid' => $this->otherUser->id,
        ]);
        $this->getJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertNotFound();

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/user/clan-schedules/{$schedule->id}/membership")
            ->assertForbidden();
    }

    public function test_unrelated_user_cannot_access_a_schedule_by_id(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();

        Sanctum::actingAs($this->otherUser);
        $this->getJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertNotFound();
        $this->putJson("/api/user/clan-schedules/{$schedule->id}", $this->payload([
            $this->cell('B', 1, 1, 'Intruder', 0),
        ], ['Intruder']))->assertNotFound();
        $this->deleteJson("/api/user/clan-schedules/{$schedule->id}")
            ->assertNotFound();

        $this->assertSame(
            'OwnerPlayer',
            UserClanSchedule::query()->findOrFail($schedule->id)->cells[0]['player']
        );
    }

    public function test_invalid_share_codes_are_rejected(): void
    {
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => 'IIIIIIII',
        ])->assertUnprocessable();

        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => 'ABCDEFGH',
        ])->assertNotFound();
    }

    public function test_registered_member_can_join_and_edit_a_shared_schedule(): void
    {
        $this->putJson('/api/user/clan-schedule', $this->payload([
            $this->cell('B', 1, 1, 'OwnerPlayer', 0),
        ], ['OwnerPlayer']))->assertOk();

        $schedule = UserClanSchedule::query()->firstOrFail();

        Sanctum::actingAs($this->otherUser);
        $this->postJson('/api/user/clan-schedules/join', [
            'share_code' => $schedule->share_code,
        ])->assertOk();

        $this->putJson("/api/user/clan-schedules/{$schedule->id}", $this->payload([
            $this->cell('C', 7, 1, 'OtherPlayer', 0),
        ], ['OtherPlayer']))->assertOk();

        $this->assertSame(
            'OtherPlayer',
            UserClanSchedule::query()->findOrFail($schedule->id)->cells[0]['player']
        );
    }

    private function payload(array $cells = [], array $playerOrder = []): array
    {
        return [
            'cells' => $cells,
            'player_order' => $playerOrder,
        ];
    }

    private function cell(
        string $phase,
        int $round,
        int $boss,
        string $player,
        int $slot
    ): array {
        return [
            'phase' => $phase,
            'round' => $round,
            'boss' => $boss,
            'player' => $player,
            'slot' => $slot,
        ];
    }

    /**
     * B 30格 + C 80格 + D 70格 = 180格，正好容纳30名玩家各6次；
     * D阶段每名玩家不超过3次，且D阶段基础刀不填写对应返还刀。
     */
    private function fullCapacityCells(): array
    {
        $nonDPositions = [];
        $dPositions = [];

        for ($round = 1; $round <= 6; $round++) {
            for ($boss = 1; $boss <= 5; $boss++) {
                $nonDPositions[] = ['B', $round, $boss];
            }
        }

        for ($round = 7; $round <= 22; $round++) {
            for ($boss = 1; $boss <= 5; $boss++) {
                $nonDPositions[] = ['C', $round, $boss];
            }
        }

        for ($round = 23; $round <= 36; $round++) {
            for ($boss = 1; $boss <= 5; $boss++) {
                $dPositions[] = ['D', $round, $boss];
            }
        }

        $cells = [];
        $nonDIndex = 0;
        $dIndex = 0;

        for ($playerIndex = 0; $playerIndex < 30; $playerIndex++) {
            $player = 'P' . str_pad((string) $playerIndex, 2, '0', STR_PAD_LEFT);
            $dCount = $playerIndex < 23 ? 3 : ($playerIndex === 23 ? 1 : 0);

            for ($slot = 0; $slot < 6; $slot++) {
                $useD = $slot >= 3 && ($slot - 3) < $dCount;
                $position = $useD
                    ? $dPositions[$dIndex++]
                    : $nonDPositions[$nonDIndex++];

                $cells[] = $this->cell(
                    $position[0],
                    $position[1],
                    $position[2],
                    $player,
                    $slot
                );
            }
        }

        return $cells;
    }

    private function createTestTables(): void
    {
        foreach ([
            'user_clan_schedule_members',
            'user_clan_schedules',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('ip', 45)->unique();
            $table->string('country')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->string('nickname')->nullable();
            $table->tinyInteger('status')->default(0);
            $table->tinyInteger('is_subscribe')->default(1);
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('user_clan_schedules', function (Blueprint $table): void {
            $table->id();
            $table->integer('uid');
            $table->string('share_code', 8)->nullable()->unique();
            $table->json('cells');
            $table->json('player_order');
            $table->timestamps();
            $table->unique('uid');
        });

        Schema::create('user_clan_schedule_members', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('schedule_id');
            $table->integer('uid');
            $table->timestamps();
            $table->unique(['schedule_id', 'uid']);
        });
    }

    private function createUser(
        string $email,
        string $nickname,
        int $status
    ): User {
        return User::create([
            'ip' => '127.0.0.' . (User::query()->count() + 1),
            'nickname' => $nickname,
            'email' => $email,
            'password' => 'secret',
            'status' => $status,
        ]);
    }
}
