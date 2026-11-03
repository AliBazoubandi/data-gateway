<?php
declare(strict_types=1);
namespace Admin\Controllers;

use Admin\Flashes;
use Admin\View;
use Core\Db;
use Core\Http;
use Core\Str;
use Core\Time;
use Services\AuditLogger;

final class CategoryController
{
    public static function list(): void
    {
        $db = Db::instance();
        $tree = self::getTree();
        $uncategorizedCount = (int)$db->value('SELECT COUNT(*) FROM requests WHERE category_id IS NULL');
        $totalRequests = (int)$db->value('SELECT COUNT(*) FROM requests');

        View::render('categories/list', [
            'tree' => $tree,
            'uncategorizedCount' => $uncategorizedCount,
            'totalRequests' => $totalRequests,
        ], 'Request Categories', 'categories-list');
    }

    /** @param int $id 0 = new */
    public static function edit(int $id): void
    {
        $db = Db::instance();
        $row = null;
        if ($id > 0) {
            $row = $db->one('SELECT * FROM request_categories WHERE id = ?', [$id]);
            if ($row === null) {
                Flashes::set('error', 'Category not found.');
                Http::redirect('/admin/categories');
            }
        }

        // If new, allow preselecting parent_id via query string (?parent_id=123)
        $preselectedParent = (int)(Http::param('parent_id', 'g') ?? 0);
        if ($row === null && $preselectedParent > 0) {
            $row = self::defaults();
            $row['parent_id'] = $preselectedParent;
        }

        // A category can only have a parent if:
        // 1. It is a new category, OR
        // 2. It is an existing category that has no children of its own (to keep strictly 2 levels)
        $hasChildren = false;
        if ($id > 0) {
            $childrenCount = (int)$db->value('SELECT COUNT(*) FROM request_categories WHERE parent_id = ?', [$id]);
            $hasChildren = ($childrenCount > 0);
        }

        // Only top-level categories (parent_id IS NULL) can be parents
        $topLevelCategories = $db->rows(
            'SELECT id, name FROM request_categories WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order ASC, name ASC',
            [$id]
        );

        View::render('categories/edit', [
            'row' => $row ?? self::defaults(),
            'topLevelCategories' => $topLevelCategories,
            'hasChildren' => $hasChildren,
        ], $id > 0 ? 'Edit Category' : 'New Category', 'category-edit');
    }

    public static function save(int $id): void
    {
        $db = Db::instance();
        $in = self::input();
        $errors = self::validate($in, $id);

        if ($errors !== []) {
            $hasChildren = false;
            if ($id > 0) {
                $childrenCount = (int)$db->value('SELECT COUNT(*) FROM request_categories WHERE parent_id = ?', [$id]);
                $hasChildren = ($childrenCount > 0);
            }
            $topLevelCategories = $db->rows(
                'SELECT id, name FROM request_categories WHERE parent_id IS NULL AND id <> ? ORDER BY sort_order ASC, name ASC',
                [$id]
            );

            View::render('categories/edit', [
                'row' => self::rowFromInput($in, $id),
                'topLevelCategories' => $topLevelCategories,
                'hasChildren' => $hasChildren,
                'errors' => $errors,
            ], $id > 0 ? 'Edit Category' : 'New Category', 'category-edit');
            return;
        }

        $now = Time::now();
        $parentId = $in['parent_id'] > 0 ? $in['parent_id'] : null;

        if ($id > 0) {
            $before = $db->one('SELECT * FROM request_categories WHERE id = ?', [$id]);
            $db->execute(
                'UPDATE request_categories SET parent_id = ?, name = ?, description = ?, sort_order = ?, updated_at = ? WHERE id = ?',
                [$parentId, $in['name'], $in['description'], $in['sort_order'], $now, $id]
            );
            $after = $db->one('SELECT * FROM request_categories WHERE id = ?', [$id]);
            AuditLogger::record('request_category', 'update', $id, $in['name'], $before, $after);
            Flashes::set('ok', 'Category «' . $in['name'] . '» updated.');
        } else {
            $db->execute(
                'INSERT INTO request_categories (parent_id, name, description, sort_order, created_at) VALUES (?, ?, ?, ?, ?)',
                [$parentId, $in['name'], $in['description'], $in['sort_order'], $now]
            );
            $newId = $db->insertId();
            $after = $db->one('SELECT * FROM request_categories WHERE id = ?', [$newId]);
            AuditLogger::record('request_category', 'create', $newId, $in['name'], null, $after);
            Flashes::set('ok', 'Category «' . $in['name'] . '» created.');
        }

        Http::redirect('/admin/categories');
    }

    public static function delete(int $id): void
    {
        $db = Db::instance();
        $row = $db->one('SELECT * FROM request_categories WHERE id = ?', [$id]);
        if ($row === null) {
            Flashes::set('error', 'Category not found.');
            Http::redirect('/admin/categories');
        }

        // Count affected requests (direct and under subcategories if any)
        $childRows = $db->rows('SELECT id FROM request_categories WHERE parent_id = ?', [$id]);
        $childIds = array_map(static fn(array $r): int => (int)$r['id'], $childRows);
        $allIds = array_merge([$id], $childIds);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $affectedRequests = (int)$db->value(
            "SELECT COUNT(*) FROM requests WHERE category_id IN ($placeholders)",
            $allIds
        );

        // Requests referencing these categories become uncategorized (ON DELETE SET NULL)
        $db->execute('DELETE FROM request_categories WHERE id = ?', [$id]);

        AuditLogger::record('request_category', 'delete', $id, (string)$row['name'], $row, null);

        $msg = 'Category «' . $row['name'] . '» deleted.';
        if ($affectedRequests > 0) {
            $msg .= ' ' . $affectedRequests . ' request(s) became uncategorized.';
        }
        Flashes::set('ok', $msg);
        Http::redirect('/admin/categories');
    }

    /**
     * Get the full 2-level category tree with request counts.
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   description: ?string,
     *   sort_order: int,
     *   direct_requests_count: int,
     *   total_requests_count: int,
     *   children: array<int, array{
     *     id: int,
     *     name: string,
     *     description: ?string,
     *     sort_order: int,
     *     requests_count: int
     *   }>
     * }>
     */
    public static function getTree(): array
    {
        $db = Db::instance();

        // Level 1: parent_id IS NULL
        $parents = $db->rows(
            'SELECT c.id, c.name, c.description, c.sort_order,
                    (SELECT COUNT(*) FROM requests r WHERE r.category_id = c.id) AS direct_count
             FROM request_categories c
             WHERE c.parent_id IS NULL
             ORDER BY c.sort_order ASC, c.name ASC'
        );

        // Level 2: parent_id IS NOT NULL
        $children = $db->rows(
            'SELECT c.id, c.parent_id, c.name, c.description, c.sort_order,
                    (SELECT COUNT(*) FROM requests r WHERE r.category_id = c.id) AS requests_count
             FROM request_categories c
             WHERE c.parent_id IS NOT NULL
             ORDER BY c.sort_order ASC, c.name ASC'
        );

        $childrenByParent = [];
        foreach ($children as $ch) {
            $pid = (int)$ch['parent_id'];
            $childrenByParent[$pid][] = [
                'id' => (int)$ch['id'],
                'parent_id' => $pid,
                'name' => (string)$ch['name'],
                'description' => $ch['description'] !== null ? (string)$ch['description'] : null,
                'sort_order' => (int)$ch['sort_order'],
                'requests_count' => (int)$ch['requests_count'],
            ];
        }

        $tree = [];
        foreach ($parents as $p) {
            $pid = (int)$p['id'];
            $direct = (int)$p['direct_count'];
            $subs = $childrenByParent[$pid] ?? [];
            $subTotal = 0;
            foreach ($subs as $sub) {
                $subTotal += (int)$sub['requests_count'];
            }

            $tree[] = [
                'id' => $pid,
                'name' => (string)$p['name'],
                'description' => $p['description'] !== null ? (string)$p['description'] : null,
                'sort_order' => (int)$p['sort_order'],
                'direct_requests_count' => $direct,
                'total_requests_count' => $direct + $subTotal,
                'children' => $subs,
            ];
        }

        return $tree;
    }

    /**
     * Flat mapping for select boxes and lookups: id => formatted string
     * e.g.:
     *   Level 1: "Finance"
     *   Level 2: "Finance > Invoices"
     */
    public static function getOptions(): array
    {
        $tree = self::getTree();
        $options = [];
        foreach ($tree as $parent) {
            $options[$parent['id']] = [
                'id' => $parent['id'],
                'parent_id' => null,
                'name' => $parent['name'],
                'full_name' => $parent['name'],
                'level' => 1,
            ];
            foreach ($parent['children'] as $child) {
                $options[$child['id']] = [
                    'id' => $child['id'],
                    'parent_id' => $parent['id'],
                    'name' => $child['name'],
                    'full_name' => $parent['name'] . ' › ' . $child['name'],
                    'level' => 2,
                ];
            }
        }
        return $options;
    }

    private static function defaults(): array
    {
        return [
            'id' => 0,
            'parent_id' => null,
            'name' => '',
            'description' => '',
            'sort_order' => 0,
        ];
    }

    private static function input(): array
    {
        $parentIdRaw = trim((string)(Http::param('parent_id', 'p') ?? ''));
        $parentId = ($parentIdRaw === '' || $parentIdRaw === '0') ? null : (int)$parentIdRaw;

        return [
            'parent_id' => $parentId,
            'name' => trim((string)(Http::param('name', 'p') ?? '')),
            'description' => trim((string)(Http::param('description', 'p') ?? '')),
            'sort_order' => (int)(Http::param('sort_order', 'p') ?? 0),
        ];
    }

    private static function rowFromInput(array $in, int $id): array
    {
        return ['id' => $id] + $in;
    }

    /** @return array<string,string> field => error */
    private static function validate(array $in, int $id): array
    {
        $db = Db::instance();
        $errors = [];

        if ($in['name'] === '') {
            $errors['name'] = 'Category name is required.';
        } elseif (mb_strlen($in['name']) > 100) {
            $errors['name'] = 'Category name cannot exceed 100 characters.';
        }

        if ($in['parent_id'] !== null && $in['parent_id'] > 0) {
            $parent = $db->one('SELECT id, parent_id FROM request_categories WHERE id = ?', [$in['parent_id']]);
            if ($parent === null) {
                $errors['parent_id'] = 'Selected parent category does not exist.';
            } elseif ($parent['parent_id'] !== null) {
                // The parent itself is already a subcategory!
                $errors['parent_id'] = 'Categories can only have two levels (A -> B). You cannot nest under a subcategory.';
            }

            if ($id > 0) {
                if ($id === (int)$in['parent_id']) {
                    $errors['parent_id'] = 'A category cannot be its own parent.';
                }
                // Check if this category currently has children
                $hasChildren = (int)$db->value('SELECT COUNT(*) FROM request_categories WHERE parent_id = ?', [$id]) > 0;
                if ($hasChildren) {
                    $errors['parent_id'] = 'This category already has subcategories. It cannot become a subcategory of another category.';
                }
            }
        }

        // Duplicate name check under the same parent
        if ($in['name'] !== '') {
            if ($in['parent_id'] !== null && $in['parent_id'] > 0) {
                $dupe = $db->value(
                    'SELECT id FROM request_categories WHERE name = ? AND parent_id = ? AND id <> ?',
                    [$in['name'], $in['parent_id'], $id]
                );
            } else {
                $dupe = $db->value(
                    'SELECT id FROM request_categories WHERE name = ? AND parent_id IS NULL AND id <> ?',
                    [$in['name'], $id]
                );
            }
            if ($dupe !== null) {
                $errors['name'] = 'A category with this name already exists at this level.';
            }
        }

        return $errors;
    }
}
