<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AdminRoleController;
use App\Http\Controllers\Api\BossController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GameRoleController;
use App\Http\Controllers\Api\MasterCoinShopController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PublicTeamController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SystemLogController;
use App\Http\Controllers\Api\SystemSixHitController;
use App\Http\Controllers\Api\SystemTripleController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\UserAuthController;
use App\Http\Controllers\Api\UserCardStatisticsController;
use App\Http\Controllers\Api\UserClanScheduleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserGuideConfigController;
use App\Http\Controllers\Api\UserGuildController;
use App\Http\Controllers\Api\UserGuildApplicationController;
use App\Http\Controllers\Api\UserGuildInvitationController;
use App\Http\Controllers\Api\UserGuildMemberController;
use App\Http\Controllers\Api\UserGuildScheduleController;
use App\Http\Controllers\Api\UserMasterCoinShopController;
use App\Http\Controllers\Api\UserPasswordResetController;
use App\Http\Controllers\Api\UserProfileController;
use App\Http\Controllers\Api\UserRolePoolController;
use App\Http\Controllers\Api\UserTeamController;
use App\Http\Controllers\Api\UserTripleController;
use Illuminate\Support\Facades\Route;


// ==================== 管理端 ====================

Route::prefix('admin')->group(function () {

    // 登录
    Route::post('login', [AdminController::class, 'login']);

    // 只需要管理员登录，不需要 API 权限
    Route::middleware([
        'auth:sanctum',
        'admin.identity',
    ])->group(function () {

        // 当前管理员信息
        Route::get('info', [AdminController::class, 'info']);

        // 权限编码
        Route::get('codes', [AdminController::class, 'codes']);

        // 当前管理员菜单
        Route::get('menus', [MenuController::class, 'tree']);

        // 退出登录
        Route::post('logout', [AdminController::class, 'logout']);
    });

    // 需要管理员登录 + API 权限
    Route::middleware([
        'auth:sanctum',
        'admin.identity',
        'permission',
    ])->group(function () {

        // ==================== 管理员 ====================

        Route::get('admins', [AdminController::class, 'index']);
        Route::post('admins', [AdminController::class, 'store']);
        Route::put('admins/{admin}', [AdminController::class, 'update']);
        Route::delete('admins/{admin}', [AdminController::class, 'destroy']);
        Route::put('admins/{admin}/status', [AdminController::class, 'updateStatus']);

        // ==================== Dashboard ====================

        Route::get('analytics', [DashboardController::class, 'analytics']);
        Route::get('trend', [DashboardController::class, 'trend']);

        // ==================== 用户管理 ====================

        Route::get('users', [UserController::class, 'index']);

        // ==================== 角色 ====================

        Route::get('roles', [RoleController::class, 'list']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::put('roles/{role}', [RoleController::class, 'update']);
        Route::delete('roles/{role}', [RoleController::class, 'destroy']);

        // 角色菜单权限
        Route::get('roles/{role}/menus', [RoleController::class, 'menus']);
        Route::put('roles/{role}/menus', [RoleController::class, 'syncMenus']);

        // 角色 API 权限
        Route::get('roles/{role}/permissions', [RoleController::class, 'permissions']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions']);

        // ==================== 管理员角色 ====================

        Route::get('admins/{admin}/roles', [AdminRoleController::class, 'getRoles']);
        Route::put('admins/{admin}/roles', [AdminRoleController::class, 'assignRoles']);

        // ==================== 菜单管理 ====================

        Route::get('menus_list', [MenuController::class, 'list']);
        Route::post('menus', [MenuController::class, 'store']);
        Route::put('menus/{menu}', [MenuController::class, 'update']);
        Route::delete('menus', [MenuController::class, 'destroy']);

        // ==================== 游戏角色管理 ====================

        Route::get('game/roles/options', [GameRoleController::class, 'options']);
        Route::get('game/roles', [GameRoleController::class, 'index']);
        Route::post('game/roles', [GameRoleController::class, 'store']);
        Route::get('game/roles/{gameRole}', [GameRoleController::class, 'show']);
        Route::put('game/roles/{gameRole}', [GameRoleController::class, 'update']);
        Route::delete('game/roles/{gameRole}', [GameRoleController::class, 'destroy']);

        // ==================== BOSS 管理 ====================

        Route::get('game/bosses', [BossController::class, 'adminIndex']);
        Route::put('game/bosses/select', [BossController::class, 'select']);

        // ==================== 大师币商店管理 ====================

        Route::get('game/master-coin-shop', [MasterCoinShopController::class, 'index']);
        Route::get('game/master-coin-shop/game-roles', [MasterCoinShopController::class, 'searchGameRoles']);
        Route::post('game/master-coin-shop/roles', [MasterCoinShopController::class, 'store']);
        Route::put('game/master-coin-shop/roles/{gameRole}/type', [MasterCoinShopController::class, 'updateType']);
        Route::put('game/master-coin-shop/groups/{type}/sort', [MasterCoinShopController::class, 'sortGroup']);
        Route::delete('game/master-coin-shop/roles/{gameRole}', [MasterCoinShopController::class, 'destroy']);

        // ==================== 作业管理 ====================

        Route::get('game/teams/d-stage-lineups/options', [TeamController::class, 'dStageLineupOptions']);

        // 作业列表
        Route::get('game/teams', [TeamController::class, 'index']);

        // 批量添加作业
        Route::post('game/teams', [TeamController::class, 'store']);

        // 作业详情
        Route::get('game/teams/{team}', [TeamController::class, 'show']);

        // 修改作业
        Route::put('game/teams/{team}', [TeamController::class, 'update']);

        // 软隐藏作业
        Route::delete('game/teams/{team}', [TeamController::class, 'destroy']);

        // 恢复或修改作业状态
        Route::patch('game/teams/{team}/status', [TeamController::class, 'updateStatus']);

        // 对列表中的单条来源链接进行操作
        Route::put('game/teams/{team}/links/{teamLink}', [TeamController::class, 'updateLink']);
        Route::delete('game/teams/{team}/links/{teamLink}', [TeamController::class, 'destroyLink']);
        Route::patch('game/teams/{team}/links/{teamLink}/status', [TeamController::class, 'updateLinkStatus']);

        // ==================== API 权限管理 ====================

        Route::get('permissions', [PermissionController::class, 'index']);
        Route::post('permissions', [PermissionController::class, 'store']);
        Route::put('permissions/{id}', [PermissionController::class, 'update']);
        Route::delete('permissions/{id}', [PermissionController::class, 'destroy']);

        // ==================== 系统日志 ====================

        Route::get('system-logs', [SystemLogController::class, 'index']);
        Route::get('system-logs/{systemLog}', [SystemLogController::class, 'show']);
    });
});


// ==================== 用户端：身份相关接口 ====================

Route::prefix('user')->group(function () {

    // ==================== 无需 Token ====================

    // 游客初始化：根据 IP 创建或复用游客记录
    Route::post('visitor', [UserAuthController::class, 'visitor']);

    // 发送注册邮箱验证码
    Route::post('send-register-code', [UserAuthController::class, 'sendRegisterCode']);

    // 用户登录
    Route::post('login', [UserAuthController::class, 'login']);


    // ==================== 仅游客 ====================

    Route::middleware([
        'user.auth',
        'user.identity:guest',
    ])->group(function () {

        // 邮箱注册
        Route::post('register', [UserAuthController::class, 'register']);
    });


    // ==================== 游客和正式用户均可使用 ====================

    Route::middleware([
        'user.auth',
        'user.identity:any',
    ])->group(function () {

        // 获取当前用户的个人作业列表
        Route::get('teams', [UserTeamController::class, 'index']);

        // 创建自定义作业
        Route::post('teams/custom', [UserTeamController::class, 'storeCustom']);

        // 修改自定义作业
        Route::put('teams/custom/{teamId}', [UserTeamController::class, 'updateCustom'])
            ->whereNumber('teamId')
            ->name('user.teams.custom.update');

        // 软删除自定义作业：只修改 status，不物理删除
        Route::delete('teams/custom/{teamId}', [UserTeamController::class, 'destroyCustom'])
            ->whereNumber('teamId')
            ->name('user.teams.custom.destroy');

        // 将系统作业添加到个人作业列表
        Route::post('teams/from-system/{systemTeamId}', [UserTeamController::class, 'addFromSystem'])
            ->whereNumber('systemTeamId')
            ->name('user.teams.from-system.add');

        // 将系统作业从个人作业列表中取消（软删除）
        Route::delete('teams/from-system/{systemTeamId}', [UserTeamController::class, 'removeFromSystem'])
            ->whereNumber('systemTeamId')
            ->name('user.teams.from-system.remove');

        // ==================== 新手指引 ====================

        // 获取指定新手指引状态
        Route::get('guide-config', [UserGuideConfigController::class, 'show']);

        // 更新新手指引状态或重置全部指引
        Route::post('guide-config', [UserGuideConfigController::class, 'update']);

        // 获取上月系统三刀组合，游客和正式用户均可使用
        Route::post(
            'game/teams/triple/system/previous-month',
            [SystemTripleController::class, 'previousMonth']
        );

        // 获取上月系统六刀组合，游客和正式用户均可使用
        Route::post(
            'game/teams/six/system/previous-month',
            [SystemSixHitController::class, 'previousMonth']
        );

        // 获取当前用户本月三刀组合，实时计算且不使用缓存
        Route::post(
            'game/teams/triple/user',
            [UserTripleController::class, 'index']
        );

    });


    // ==================== 仅正式注册用户 ====================

    Route::middleware([
        'user.auth',
        'user.identity:registered',
    ])->group(function () {

        // 当前登录用户信息
        Route::get('me', [UserAuthController::class, 'me']);

        // ==================== 我的公会 ====================

        Route::get('guilds/overview', [UserGuildController::class, 'overview'])
            ->name('user.guilds.overview');
        Route::get('guilds', [UserGuildController::class, 'index'])
            ->name('user.guilds.index');
        Route::post('guilds', [UserGuildController::class, 'store'])
            ->name('user.guilds.store');
        Route::get('guilds/{guild}', [UserGuildController::class, 'show'])
            ->whereNumber('guild')
            ->name('user.guilds.show');
        Route::put('guilds/{guild}', [UserGuildController::class, 'update'])
            ->whereNumber('guild')
            ->name('user.guilds.update');
        Route::delete('guilds/{guild}', [UserGuildController::class, 'destroy'])
            ->whereNumber('guild')
            ->name('user.guilds.destroy');

        Route::delete(
            'guilds/{guild}/members/me',
            [UserGuildMemberController::class, 'leave']
        )
            ->whereNumber('guild')
            ->name('user.guilds.members.leave');
        Route::delete(
            'guilds/{guild}/members/{uid}',
            [UserGuildMemberController::class, 'kick']
        )
            ->whereNumber('guild')
            ->whereNumber('uid')
            ->name('user.guilds.members.kick');
        Route::put(
            'guilds/{guild}/members/{uid}/vice',
            [UserGuildMemberController::class, 'appointVice']
        )
            ->whereNumber('guild')
            ->whereNumber('uid')
            ->name('user.guilds.members.vice.store');
        Route::delete(
            'guilds/{guild}/members/{uid}/vice',
            [UserGuildMemberController::class, 'revokeVice']
        )
            ->whereNumber('guild')
            ->whereNumber('uid')
            ->name('user.guilds.members.vice.destroy');
        Route::put(
            'guilds/{guild}/owner',
            [UserGuildMemberController::class, 'transferOwner']
        )
            ->whereNumber('guild')
            ->name('user.guilds.owner.transfer');

        Route::get(
            'guilds/{guild}/invitation-candidates',
            [UserGuildInvitationController::class, 'candidate']
        )
            ->whereNumber('guild')
            ->name('user.guilds.invitation-candidates');
        Route::post(
            'guilds/{guild}/invitations',
            [UserGuildInvitationController::class, 'store']
        )
            ->whereNumber('guild')
            ->name('user.guilds.invitations.store');

        Route::get(
            'guilds/{guild}/applications',
            [UserGuildApplicationController::class, 'index']
        )
            ->whereNumber('guild')
            ->name('user.guilds.applications.index');
        Route::post(
            'guilds/{guild}/applications',
            [UserGuildApplicationController::class, 'store']
        )
            ->whereNumber('guild')
            ->name('user.guilds.applications.store');
        Route::post(
            'guilds/{guild}/applications/{application}/approve',
            [UserGuildApplicationController::class, 'approve']
        )
            ->whereNumber('guild')
            ->whereNumber('application')
            ->name('user.guilds.applications.approve');
        Route::post(
            'guilds/{guild}/applications/{application}/reject',
            [UserGuildApplicationController::class, 'reject']
        )
            ->whereNumber('guild')
            ->whereNumber('application')
            ->name('user.guilds.applications.reject');

        Route::get(
            'guild-invitations',
            [UserGuildInvitationController::class, 'index']
        )->name('user.guild-invitations.index');
        Route::delete(
            'guild-invitations/{invitation}',
            [UserGuildInvitationController::class, 'revoke']
        )
            ->whereNumber('invitation')
            ->name('user.guild-invitations.revoke');
        Route::post(
            'guild-invitations/{invitation}/accept',
            [UserGuildInvitationController::class, 'accept']
        )
            ->whereNumber('invitation')
            ->name('user.guild-invitations.accept');
        Route::post(
            'guild-invitations/{invitation}/reject',
            [UserGuildInvitationController::class, 'reject']
        )
            ->whereNumber('invitation')
            ->name('user.guild-invitations.reject');
        Route::delete(
            'guild-applications/{application}',
            [UserGuildApplicationController::class, 'withdraw']
        )
            ->whereNumber('application')
            ->name('user.guild-applications.withdraw');

        Route::get(
            'guilds/{guild}/schedule/team-options',
            [UserGuildScheduleController::class, 'teamOptions']
        )
            ->whereNumber('guild')
            ->name('user.guilds.schedule.team-options');
        Route::post(
            'guilds/{guild}/schedule/copy',
            [UserGuildScheduleController::class, 'copy']
        )
            ->whereNumber('guild')
            ->name('user.guilds.schedule.copy');
        Route::get(
            'guilds/{guild}/schedule',
            [UserGuildScheduleController::class, 'show']
        )
            ->whereNumber('guild')
            ->name('user.guilds.schedule.show');
        Route::put(
            'guilds/{guild}/schedule/members/{uid}',
            [UserGuildScheduleController::class, 'update']
        )
            ->whereNumber('guild')
            ->whereNumber('uid')
            ->name('user.guilds.schedule.members.update');
        Route::delete(
            'guilds/{guild}/schedule',
            [UserGuildScheduleController::class, 'clear']
        )
            ->whereNumber('guild')
            ->name('user.guilds.schedule.clear');

        // ==================== 公会排刀表 ====================

        // 获取当前用户的排刀表，无记录时返回空表
        Route::get('clan-schedule', [UserClanScheduleController::class, 'show'])
            ->name('user.clan-schedule.show');

        // 保存当前用户的完整排刀快照
        Route::put('clan-schedule', [UserClanScheduleController::class, 'update'])
            ->name('user.clan-schedule.update');

        // 清空当前用户的排刀表
        Route::delete('clan-schedule', [UserClanScheduleController::class, 'destroy'])
            ->name('user.clan-schedule.destroy');

        // 获取当前用户可访问的自有及共享排刀表
        Route::get('clan-schedules', [UserClanScheduleController::class, 'index'])
            ->name('user.clan-schedules.index');

        // 为空的自有排刀表生成分享码
        Route::post(
            'clan-schedules/share-code',
            [UserClanScheduleController::class, 'generateShareCode']
        )->name('user.clan-schedules.share-code');

        // 通过分享码加入共享排刀表
        Route::post(
            'clan-schedules/join',
            [UserClanScheduleController::class, 'join']
        )
            ->middleware('throttle:20,1')
            ->name('user.clan-schedules.join');

        // 读取、保存和清空有权限访问的排刀表
        Route::get(
            'clan-schedules/{scheduleId}',
            [UserClanScheduleController::class, 'showShared']
        )
            ->whereNumber('scheduleId')
            ->name('user.clan-schedules.show');

        Route::put(
            'clan-schedules/{scheduleId}',
            [UserClanScheduleController::class, 'updateShared']
        )
            ->whereNumber('scheduleId')
            ->name('user.clan-schedules.update');

        Route::delete(
            'clan-schedules/{scheduleId}',
            [UserClanScheduleController::class, 'destroyShared']
        )
            ->whereNumber('scheduleId')
            ->name('user.clan-schedules.destroy');

        // 退出共享排刀表
        Route::delete(
            'clan-schedules/{scheduleId}/membership',
            [UserClanScheduleController::class, 'leaveShared']
        )
            ->whereNumber('scheduleId')
            ->name('user.clan-schedules.leave');

        // 获取个人中心头像候选
        Route::get('avatar-options', [UserProfileController::class, 'avatarOptions']);

        // 修改昵称和头像
        Route::put('profile', [UserProfileController::class, 'updateProfile']);

        // 修改密码
        Route::put('password', [UserProfileController::class, 'updatePassword']);

        // 忘记密码：发送邮箱验证码
        Route::post(
            'password/reset-code',
            [UserPasswordResetController::class, 'sendCode']
        );

        // 忘记密码：验证邮箱验证码
        Route::post(
            'password/verify-code',
            [UserPasswordResetController::class, 'verifyCode']
        );

        // 忘记密码：使用一次性凭证重置密码
        Route::put(
            'password/reset',
            [UserPasswordResetController::class, 'resetPassword']
        );

        // 用户退出登录
        Route::post('logout', [UserAuthController::class, 'logout']);

        // ==================== 用户端大师币商店 ====================

        // 获取系统配置的全部大师币角色
        Route::get('master-coin-shop/roles', [UserMasterCoinShopController::class, 'roles']);

        // 获取指定游戏账号的大师币配置
        Route::get('role-pools/{id}/master-coin-shop', [UserMasterCoinShopController::class, 'show'])
            ->whereNumber('id');

        // 保存指定游戏账号的大师币配置
        Route::put('role-pools/{id}/master-coin-shop', [UserMasterCoinShopController::class, 'update'])
            ->whereNumber('id');

        // ==================== 抽卡统计 ====================

        // 获取指定游戏账号、年份的角色列表和已保存抽数
        Route::get('card-statistics/roles', [UserCardStatisticsController::class, 'roles']);

        // 保存指定游戏账号、年份的抽卡记录并生成报告
        Route::put('card-statistics', [UserCardStatisticsController::class, 'update']);

        // ==================== 用户角色池 ====================

        // 获取当前用户的角色池列表
        Route::get('role-pools', [UserRolePoolController::class, 'index']);

        // 创建角色池
        Route::post('role-pools', [UserRolePoolController::class, 'store']);

        // 获取角色池详情
        Route::get('role-pools/{id}', [UserRolePoolController::class, 'show'])
            ->whereNumber('id');

        // 修改角色池
        Route::put('role-pools/{id}', [UserRolePoolController::class, 'update'])
            ->whereNumber('id');

        // 软删除角色池
        Route::delete('role-pools/{id}', [UserRolePoolController::class, 'destroy']);

        // ==================== 系统三刀 ====================

        // 生成或获取系统三刀组合，仅正式注册用户可用
        Route::post('game/teams/triple/system', [SystemTripleController::class, 'index']);

        // 生成或获取本月系统六刀组合，仅正式注册用户可用
        Route::post('game/teams/six/system', [SystemSixHitController::class, 'current']);
    });
});


// ==================== 公共接口：无需 Token ====================

// 获取用户端 BOSS 列表
Route::get('game/bosses', [BossController::class, 'index']);

// 用户端系统作业查询
Route::get('game/teams', [PublicTeamController::class, 'index']);

// 获取用户端全部可用游戏角色
Route::get('game/roles', [GameRoleController::class, 'publicOptions']);


