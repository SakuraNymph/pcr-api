<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Menu extends Model
{
    protected $table = 'menus';

    protected $fillable = [
        'parent_id',
        'type',
        'name',
        'path',
        'component',
        'redirect',
        'title',
        'icon',
        'permission',
        'sort',
        'hidden',
        'keep_alive',
        'affix_tab',
        'iframe_src',
        'link',
        'status',
    ];

    protected $casts = [
        'parent_id' => 'integer',
        'type' => 'integer',
        'sort' => 'integer',
        'hidden' => 'boolean',
        'keep_alive' => 'boolean',
        'affix_tab' => 'boolean',
        'status' => 'boolean',
    ];

    /**
     * 父级菜单
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * 角色
     */
    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_menu');
    }

    /**
     * 子菜单
     */
    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort')
            ->orderBy('id');
    }

    /**
     * 获取 Vben 前端路由树
     *
     * type：
     * 1 = 目录
     * 2 = 菜单
     * 3 = 按钮
     *
     * 注意：
     * type=3 按钮属于权限数据，不进入 Vue Router 路由树。
     *
     * @param array $menuIds
     * @return array
     */
    public static function getNestedForVue(array $menuIds = []): array
    {
        if (empty($menuIds)) {
            return [];
        }

        /*
         * 只获取：
         * 1 = 目录
         * 2 = 菜单
         *
         * type=3 按钮不生成前端路由。
         */
        $menus = self::query()
            ->where('status', 1)
            ->whereIn('id', $menuIds)
            ->whereIn('type', [1, 2])
            ->orderBy('sort')
            ->orderBy('id')
            ->get([
                'id',
                'parent_id',
                'type',
                'name',
                'path',
                'component',
                'redirect',
                'title',
                'icon',
                'affix_tab',
                'keep_alive',
                'hidden',
                'status',
                'sort',
            ]);

        if ($menus->isEmpty()) {
            return [];
        }

        /*
         * 按 parent_id 分组。
         *
         * parent_id = 0
         * 表示顶级目录/菜单。
         */
        $grouped = $menus->groupBy('parent_id');

        /**
         * 递归构造 Vben 路由树
         */
        $buildTree = function (int $parentId) use (&$buildTree, $grouped) {
            if (! $grouped->has($parentId)) {
                return collect();
            }

            return $grouped[$parentId]
                ->map(function (Menu $menu) use ($buildTree) {

                    /*
                     * Vben 路由基础数据
                     */
                    $node = [
                        'name' => $menu->name,
                        'path' => $menu->path,
                        'title' => $menu->title,
                        'parent_id' => $menu->parent_id,
                    ];

                    /*
                     * type=2 菜单才需要 component。
                     *
                     * type=1 目录本身一般不需要 component。
                     */
                    if ($menu->type === 2 && ! empty($menu->component)) {
                        $node['component'] = $menu->component;
                    }

                    /*
                     * 如果配置了 redirect，则返回给前端。
                     */
                    if (! empty($menu->redirect)) {
                        $node['redirect'] = $menu->redirect;
                    }

                    /*
                     * 如果配置了 link，则返回给前端。
                     */
                    if (! empty($menu->link)) {
                        $node['link'] = $menu->link;
                    }

                    /*
                     * Vben 路由 meta
                     */
                    $node['meta'] = [
                        'id' => $menu->id,
                        'parent_id' => $menu->parent_id,
                        'title' => $menu->title,
                        'icon' => $menu->icon,
                        'affixTab' => (bool) $menu->affix_tab,
                        'order' => $menu->sort,
                        'keepAlive' => (bool) $menu->keep_alive,
                        'hidden' => (bool) $menu->hidden,
                    ];

                    /*
                     * 递归获取子级。
                     *
                     * 注意：
                     * 由于上面的查询已经排除了 type=3，
                     * 所以这里不会出现按钮节点。
                     */
                    $children = $buildTree($menu->id);

                    if ($children->isNotEmpty()) {
                        $node['children'] = $children->values()->all();
                    }

                    return $node;
                })
                ->values();
        };

        /*
         * 从顶级菜单开始构造树。
         */
        $tree = $buildTree(0);

        return $tree->values()->all();
    }

    /**
     * 将菜单列表构造成完整树形结构
     *
     * 这个方法主要用于：
     * 后台菜单管理页面
     *
     * 与 getNestedForVue() 不同：
     * 这里不能过滤 type=3。
     *
     * 因为菜单管理页面需要看到：
     * 目录、菜单、按钮。
     */
    public static function buildTree($list, $parentId = 0)
    {
        $tree = [];

        foreach ($list as $item) {

            if ($item['parent_id'] == $parentId) {

                $children = self::buildTree(
                    $list,
                    $item['id']
                );

                if (! empty($children)) {
                    $item['children'] = $children;
                } else {
                    $item['children'] = [];
                }

                $tree[] = $item;
            }
        }

        /*
         * 按 sort 排序。
         * sort 相同时按照原有顺序。
         */
        usort($tree, function ($a, $b) {
            return ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0);
        });

        return $tree;
    }

    /**
     * 自动补充父级菜单 ID
     *
     * 例如：
     *
     * 当前拥有：
     * [10]
     *
     * 10 的父级：
     * 5
     *
     * 5 的父级：
     * 1
     *
     * 最终：
     * [10, 5, 1]
     */
    public static function addParentIds(array $menuIds): array
    {
        $allIds = collect($menuIds);

        foreach ($menuIds as $menuId) {

            $menu = self::find($menuId);

            while ($menu && $menu->parent_id != 0) {

                $allIds->push($menu->parent_id);

                $menu = $menu->parent;
            }
        }

        return $allIds
            ->unique()
            ->values()
            ->all();
    }

    /**
     * 根据一组根节点 ID，
     * 递归获取所有子孙节点 ID。
     *
     * 包括根节点本身。
     *
     * 主要用于：
     * 删除菜单。
     *
     * 例如：
     *
     * 1
     * └── 2
     *     ├── 3
     *     └── 4
     *
     * descendantIds([1])
     *
     * 返回：
     * [1, 2, 3, 4]
     *
     * @param array|int $rootIds
     * @return array
     */
    public static function descendantIds($rootIds): array
    {
        /*
         * 统一转换成数组。
         */
        $rootIds = is_array($rootIds)
            ? $rootIds
            : [$rootIds];

        /*
         * 转成整数。
         */
        $rootIds = array_map('intval', $rootIds);

        /*
         * 一次性读取所有菜单的：
         * id
         * parent_id
         */
        $menus = self::query()
            ->select([
                'id',
                'parent_id',
            ])
            ->orderBy('id')
            ->get()
            ->groupBy('parent_id');

        /*
         * 保存最终需要删除的 ID。
         */
        $result = collect();

        /*
         * 待遍历队列。
         */
        $queue = collect($rootIds);

        /*
         * 广度优先遍历。
         */
        while ($queue->isNotEmpty()) {

            $current = $queue->shift();

            /*
             * 当前节点本身也需要删除。
             */
            $result->push($current);

            /*
             * 获取直接子节点。
             */
            if ($menus->has($current)) {

                $children = $menus[$current]
                    ->pluck('id')
                    ->all();

                /*
                 * 加入队列继续遍历。
                 */
                $queue = $queue->concat($children);
            }
        }

        /*
         * 去重并重新索引。
         */
        return $result
            ->unique()
            ->values()
            ->all();
    }
}
