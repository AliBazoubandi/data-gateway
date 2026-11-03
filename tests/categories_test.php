<?php
// ============================================================================
// Two-level Category Test:
// Validates Level 1 and Level 2 category creation, 2-level hierarchy restriction,
// request assignment, tree computation, cascading / nulling on delete, and filtering.
// ============================================================================

require __DIR__ . '/../src/Core/bootstrap.php';

test_guard();

$db = db();
$failures = 0;
$test = static function (string $label, bool $pass, string $detail = '') use (&$failures): void {
    echo str_pad($pass ? ' OK ' : 'FAIL', 4, ' '), $label, $detail === '' ? '' : "   ({$detail})", PHP_EOL;
    if (!$pass) {
        $failures++;
    }
};

echo '=== CATEGORIES TEST ===', PHP_EOL;

// 1. Create Level 1 Category A
$run = substr(\Core\Str::hex(4), 0, 8);
$catAName = "CatA_{$run}";
$catBName = "CatB_{$run}";
$catCName = "CatC_{$run}";

$db->execute(
    'INSERT INTO request_categories (parent_id, name, description, sort_order, created_at) VALUES (NULL, ?, ?, 1, NOW())',
    [$catAName, 'Parent category A']
);
$catAId = $db->insertId();
$test('Level 1 category created', $catAId > 0);

// 2. Create Level 2 Subcategory B under A
$db->execute(
    'INSERT INTO request_categories (parent_id, name, description, sort_order, created_at) VALUES (?, ?, ?, 1, NOW())',
    [$catAId, $catBName, 'Subcategory B inside A']
);
$catBId = $db->insertId();
$test('Level 2 category created under Level 1', $catBId > 0);

// 3. CategoryController::validate rejects 3rd level nesting
$refClass = new ReflectionClass(\Admin\Controllers\CategoryController::class);
$validateMethod = $refClass->getMethod('validate');
$validateMethod->setAccessible(true);

$errors3rd = $validateMethod->invoke(null, [
    'name' => 'CatLevel3',
    'parent_id' => $catBId, // CatB is already Level 2!
    'description' => '',
    'sort_order' => 0,
], 0);

$test('3rd level nesting rejected', isset($errors3rd['parent_id']), $errors3rd['parent_id'] ?? '');

// 4. Reject self-parenting
$errorsSelf = $validateMethod->invoke(null, [
    'name' => 'CatSelf',
    'parent_id' => $catAId,
    'description' => '',
    'sort_order' => 0,
], $catAId);
$test('Self-parenting rejected', isset($errorsSelf['parent_id']), $errorsSelf['parent_id'] ?? '');

// 5. Reject converting parent with children into a subcategory
$errorsParentWithChildren = $validateMethod->invoke(null, [
    'name' => $catAName,
    'parent_id' => $catBId,
    'description' => '',
    'sort_order' => 0,
], $catAId);
$test('Parent with children cannot become a child', isset($errorsParentWithChildren['parent_id']), $errorsParentWithChildren['parent_id'] ?? '');

// 6. Test Tree computation
$tree = \Admin\Controllers\CategoryController::getTree();
$foundA = null;
foreach ($tree as $node) {
    if ($node['id'] === $catAId) {
        $foundA = $node;
        break;
    }
}
$test('Category A found in tree', $foundA !== null);
$test('Category B is listed as child of A in tree', !empty($foundA['children']) && $foundA['children'][0]['id'] === $catBId);

// 7. Create a dummy connection and requests in CatA and CatB
$db->execute(
    "INSERT INTO connections (name, db_type, host, db_name, username, password_encrypted, created_at)
     VALUES (?, 'mysql', '127.0.0.1', 'dummy', 'root', 'dummy', NOW())",
    ["conn_{$run}"]
);
$connId = $db->insertId();

// Request 1: in CatB (Level 2)
$db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, category_id, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())',
    ["req1_{$run}", "Req 1 in B", "SELECT 1", $connId, $catBId]
);
$req1Id = $db->insertId();

// Request 2: directly in CatA (Level 1)
$db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, category_id, created_at)
     VALUES (?, ?, ?, ?, ?, NOW())',
    ["req2_{$run}", "Req 2 in A", "SELECT 1", $connId, $catAId]
);
$req2Id = $db->insertId();

// Request 3: Uncategorized
$db->execute(
    'INSERT INTO requests (code, name, sql_text, connection_id, category_id, created_at)
     VALUES (?, ?, ?, ?, NULL, NOW())',
    ["req3_{$run}", "Req 3 Uncategorized", "SELECT 1", $connId]
);
$req3Id = $db->insertId();

// 8. Re-check Tree counts
$updatedTree = \Admin\Controllers\CategoryController::getTree();
$updatedA = null;
foreach ($updatedTree as $node) {
    if ($node['id'] === $catAId) {
        $updatedA = $node;
        break;
    }
}
$test('Direct count for CatA is 1', ($updatedA['direct_requests_count'] ?? 0) === 1);
$test('Subcategory B count is 1', ($updatedA['children'][0]['requests_count'] ?? 0) === 1);
$test('Total count for CatA (direct + children) is 2', ($updatedA['total_requests_count'] ?? 0) === 2);

// 9. Query filtering: Level 1 filter matches both direct & child
$reqsUnderARows = $db->rows(
    'SELECT id FROM requests WHERE (category_id = ? OR category_id IN (SELECT id FROM request_categories WHERE parent_id = ?)) ORDER BY id ASC',
    [$catAId, $catAId]
);
$reqsUnderA = array_map(static fn(array $r): int => (int)$r['id'], $reqsUnderARows);
$expectedA = [$req1Id, $req2Id];
sort($expectedA);
sort($reqsUnderA);
$test('Level 1 filter includes requests in A and B', $reqsUnderA === $expectedA);

// 10. Query filtering: Level 2 filter matches only B
$reqsUnderBRows = $db->rows('SELECT id FROM requests WHERE category_id = ?', [$catBId]);
$reqsUnderB = array_map(static fn(array $r): int => (int)$r['id'], $reqsUnderBRows);
$test('Level 2 filter matches only requests in B', $reqsUnderB === [$req1Id]);

// 11. Delete Subcategory B: Req 1 category_id should become NULL (FK ON DELETE SET NULL)
$db->execute('DELETE FROM request_categories WHERE id = ?', [$catBId]);
$req1CatAfter = $db->value('SELECT category_id FROM requests WHERE id = ?', [$req1Id]);
$test('Deleting subcategory sets request category_id to NULL (not deleted)', $req1CatAfter === null);

// 12. Delete Parent Category A: Req 2 category_id should become NULL
$db->execute('DELETE FROM request_categories WHERE id = ?', [$catAId]);
$req2CatAfter = $db->value('SELECT category_id FROM requests WHERE id = ?', [$req2Id]);
$test('Deleting parent category sets direct request category_id to NULL', $req2CatAfter === null);

// Cleanup
$db->execute('DELETE FROM requests WHERE id IN (?, ?, ?)', [$req1Id, $req2Id, $req3Id]);
$db->execute('DELETE FROM connections WHERE id = ?', [$connId]);

if ($failures > 0) {
    echo "=== FAILED ({$failures} errors) ===", PHP_EOL;
    exit(1);
}

echo '=== CATEGORIES TEST PASS ===', PHP_EOL;
exit(0);
