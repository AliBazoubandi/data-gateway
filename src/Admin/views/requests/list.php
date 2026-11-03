<?php /** 
 * @var array $rows 
 * @var array $sparks 
 * @var array $f 
 * @var array $connections 
 * @var array $clients 
 * @var ?array $grantReq 
 * @var array $grantedClientIds 
 * @var array $categoryTree 
 * @var int $uncategorizedCount 
 * @var int $totalRequestsCount
 * @var ?array $activeCategory 
 * @var string $drillState
 * @var ?array $currentCategory
 * @var ?array $parentCategory
 * @var array $subcategories
 * @var array $uncategorizedRequests
 * @var array $directRequests
 * @var array $subcategoryRequests
 */
use Admin\View;
use Core\Str;

$categoryTree = $categoryTree ?? [];
$uncategorizedCount = (int)($uncategorizedCount ?? 0);
$totalRequestsCount = (int)($totalRequestsCount ?? 0);
$activeCategory = $activeCategory ?? null;
$drillState = $drillState ?? 'root';
$subcategories = $subcategories ?? [];
$uncategorizedRequests = $uncategorizedRequests ?? [];
$directRequests = $directRequests ?? [];
$subcategoryRequests = $subcategoryRequests ?? [];

/* duration sparkline: index 0 = newest in $sparks; drawn oldest -> newest */
$sparkSvg = static function (array $vals, string $color = ''): string {
    if (count($vals) < 2) {
        return '';
    }
    $pts = array_reverse($vals);            // oldest first for drawing
    $w = 100;
    $h = 24;
    $max = max(1, max($pts));
    $step = $w / (count($pts) - 1);
    $xy = [];
    foreach ($pts as $i => $n) {
        $xy[] = round($i * $step, 1) . ',' . round($h - 3 - ($h - 7) * ($n / $max), 1);
    }
    $line = implode(' ', $xy);
    return '<svg class="spark ' . $color . '" viewBox="0 0 ' . $h * 4 . ' ' . $h
        . '" preserveAspectRatio="none" style="height:22px" aria-hidden="true">'
        . '<polygon class="area" points="0,' . $h . ' ' . $line . ' ' . $w . ',' . $h . '"/>'
        . '<polyline points="' . $line . '"/></svg>';
};

$modeBadge = static function (string $mode): string {
    return $mode === 'instant'
        ? '<span class="badge b-mode instant">' . View::icon('zap', 11) . 'Instant</span>'
        : '<span class="badge b-mode delayed">' . View::icon('clock', 11) . 'Delayed</span>';
};
$stateBadge = static function (int $active): string {
    return $active === 1
        ? '<span class="badge b-ok">' . View::icon('check', 11) . 'Active</span>'
        : '<span class="badge b-warn">Off</span>';
};

$renderCard = static function (array $r, array $sparks, string $csrf, callable $modeBadge, callable $stateBadge, callable $sparkSvg): string {
    $sp = $sparks[(int)$r['id']] ?? [];
    $isOff = (int)$r['is_active'] === 0 ? ' off' : '';
    $id = (int)$r['id'];
    $code = Str::e((string)$r['code']);
    $name = Str::e((string)$r['name']);
    $connName = Str::e((string)$r['connection_name']);
    $runs = (int)$r['runs_total'];
    $updatedAt = Str::e(substr((string)($r['updated_at'] ?? ''), 0, 16));
    $rawUpdatedAt = Str::e((string)($r['updated_at'] ?? ''));

    $catBadge = '';
    if (!empty($r['category_name'])) {
        $catFullName = !empty($r['parent_category_name']) ? ($r['parent_category_name'] . ' › ' . $r['category_name']) : $r['category_name'];
        $catBadge = '<span class="badge b-key" style="font-size:10px;padding:2px 7px" title="Category: ' . Str::e($catFullName) . '">'
            . View::icon('folder', 10) . ' ' . Str::e($catFullName) . '</span>';
    }

    $sparkHtml = $sp !== [] ? $sparkSvg($sp) : '';

    return '<article class="req-card' . $isOff . '">'
      . '<div class="req-code-row">'
      .   '<a class="req-code" href="/admin/requests/' . $id . '">' . $code . '</a>'
      .   $stateBadge((int)$r['is_active'])
      . '</div>'
      . '<span class="req-name">' . $name . '</span>'
      . '<div class="req-meta">'
      .   $catBadge
      .   $modeBadge((string)$r['response_mode'])
      .   '<span class="req-conn">' . View::icon('database', 12) . $connName . '</span>'
      .   '<span class="badge">' . $runs . ' runs</span>'
      . '</div>'
      . $sparkHtml
      . '<div class="req-foot">'
      .   '<span class="req-updated" title="Last updated ' . $rawUpdatedAt . '">'
      .     View::icon('clock', 11) . ' updated ' . $updatedAt
      .   '</span>'
      .   '<div class="ops">'
      .     '<a class="btn btn-sm" href="/admin/requests?grant_permissions=' . $id . '" title="Manage client permissions">' . View::icon('shield', 13) . ' Permissions</a>'
      .     '<a class="btn btn-sm" href="/admin/requests/' . $id . '">Edit</a>'
      .     '<form method="post" action="/admin/requests/delete" data-confirm="Delete request «' . $code . '»? This will permanently remove the request." class="inline">'
      .       $csrf . '<input type="hidden" name="id" value="' . $id . '">'
      .       '<button class="btn btn-sm danger" type="submit">' . View::icon('trash', 12) . ' Delete</button>'
      .     '</form>'
      .   '</div>'
      . '</div>'
      . '</article>';
};

$renderTableRow = static function (array $r, array $sparks, string $csrf, callable $modeBadge, callable $stateBadge, callable $sparkSvg): string {
    $sp = $sparks[(int)$r['id']] ?? [];
    $id = (int)$r['id'];
    $code = Str::e((string)$r['code']);
    $name = Str::e((string)$r['name']);
    $connName = Str::e((string)$r['connection_name']);
    $runs = number_format((int)$r['runs_total']);
    $sparkHtml = $sp !== [] ? $sparkSvg($sp) : '<span class="hint small">—</span>';

    $catHtml = '<span class="hint small">—</span>';
    if (!empty($r['category_name'])) {
        $catFullName = !empty($r['parent_category_name']) ? ($r['parent_category_name'] . ' › ' . $r['category_name']) : $r['category_name'];
        $catHtml = '<a href="/admin/requests?category=' . (int)$r['category_id'] . '" class="badge b-key" style="text-decoration:none" title="Filter by ' . Str::e($r['category_name']) . '">'
            . View::icon('folder', 11) . ' ' . Str::e($catFullName) . '</a>';
    }

    return '<tr>'
      . '<td class="nowrap"><a href="/admin/requests/' . $id . '"><code>' . $code . '</code></a></td>'
      . '<td class="nowrap cell-name">' . $name . '</td>'
      . '<td class="nowrap">' . $catHtml . '</td>'
      . '<td class="nowrap"><span class="req-conn">' . View::icon('database', 12) . $connName . '</span></td>'
      . '<td class="nowrap">' . $modeBadge((string)$r['response_mode']) . '</td>'
      . '<td class="num nowrap">' . $runs . '</td>'
      . '<td class="nowrap">' . $sparkHtml . '</td>'
      . '<td class="nowrap">' . $stateBadge((int)$r['is_active']) . '</td>'
      . '<td class="ops nowrap">'
      .   '<a class="btn btn-sm" href="/admin/requests?grant_permissions=' . $id . '" title="Manage client permissions">' . View::icon('shield', 13) . ' Permissions</a>'
      .   '<a class="btn btn-sm" href="/admin/requests/' . $id . '">Edit</a>'
      .   '<form method="post" action="/admin/requests/delete" data-confirm="Delete request «' . $code . '»? This will permanently remove the request." class="inline">'
      .     $csrf . '<input type="hidden" name="id" value="' . $id . '">'
      .     '<button class="btn btn-sm danger" type="submit">' . View::icon('trash', 12) . ' Delete</button>'
      .   '</form>'
      . '</td>'
      . '</tr>';
};
?>

<div class="page-head">
  <div>
    <h1>Requests</h1>
    <div class="page-sub">
      <?php if ($drillState === 'root'): ?>
        Organized in <?= count($categoryTree) ?> parent categories · <?= $totalRequestsCount ?> total request<?= $totalRequestsCount === 1 ? '' : 's' ?>.
      <?php elseif ($drillState === 'parent' && $currentCategory !== null): ?>
        Parent category: <?= e((string)$currentCategory['name']) ?> · <?= count($subcategories) ?> subcategories · <?= (int)($currentCategory['total_requests_count'] ?? 0) ?> requests total.
      <?php elseif ($drillState === 'sub' && $currentCategory !== null): ?>
        Subcategory: <?= e((string)$currentCategory['name']) ?> · <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?>.
      <?php elseif ($drillState === 'uncategorized'): ?>
        Uncategorized requests · <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?>.
      <?php else: ?>
        Search & filter results · <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?>.
      <?php endif; ?>
    </div>
  </div>
  <div class="page-tools">
    <div class="seg" role="group" aria-label="List presentation" id="view-toggle">
      <button type="button" data-view="cards" class="on"><?= View::icon('grid', 14) ?><span>Cards</span></button>
      <button type="button" data-view="table"><?= View::icon('list', 14) ?><span>Table</span></button>
    </div>
    <a class="btn btn-ghost" href="/admin/categories"><?= View::icon('folder', 14) ?><span>Categories</span></a>
    <a class="btn btn-primary" href="/admin/requests/new"><?= View::icon('plus', 15) ?><span>New request</span></a>
  </div>
</div>

<form method="get" action="/admin/requests" class="form filters">
  <label class="f-grow">Search
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Code or name…" spellcheck="false">
  </label>
  <label>Category
    <select name="category">
      <option value="">— all categories —</option>
      <option value="none" <?= ($f['category'] ?? '') === 'none' ? 'selected' : '' ?>>— Uncategorized (<?= $uncategorizedCount ?>) —</option>
      <?php foreach ($categoryTree as $p): ?>
      <option value="<?= (int)$p['id'] ?>" <?= ($f['category'] ?? '') === (string)(int)$p['id'] ? 'selected' : '' ?>>
        📁 <?= e((string)$p['name']) ?> (<?= (int)$p['total_requests_count'] ?>)
      </option>
        <?php foreach ($p['children'] as $ch): ?>
        <option value="<?= (int)$ch['id'] ?>" <?= ($f['category'] ?? '') === (string)(int)$ch['id'] ? 'selected' : '' ?>>
          &nbsp;&nbsp;&nbsp;&nbsp;↳ <?= e((string)$ch['name']) ?> (<?= (int)$ch['requests_count'] ?>)
        </option>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Mode
    <select name="mode">
      <option value="">— all —</option>
      <option value="delayed" <?= $f['mode'] === 'delayed' ? 'selected' : '' ?>>Delayed</option>
      <option value="instant" <?= $f['mode'] === 'instant' ? 'selected' : '' ?>>Instant</option>
    </select>
  </label>
  <label>Connection
    <select name="connection">
      <option value="">— all —</option>
      <?php foreach ($connections as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $f['conn'] === (string)(int)$c['id'] ? 'selected' : '' ?>><?= e((string)$c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>State
    <select name="active">
      <option value="">— all —</option>
      <option value="1" <?= $f['active'] === '1' ? 'selected' : '' ?>>Active</option>
      <option value="0" <?= $f['active'] === '0' ? 'selected' : '' ?>>Off</option>
    </select>
  </label>
  <div class="f-submit">
    <button class="btn" type="submit"><?= View::icon('filter', 13) ?><span>Apply</span></button>
    <a class="btn btn-clear" href="/admin/requests"><?= View::icon('x', 13) ?><span>Clear</span></a>
  </div>
</form>

<?php if ($drillState === 'parent' && $currentCategory !== null): ?>
<!-- Breadcrumb for Phase 2: Inside Parent Category -->
<div class="cat-drill-banner">
  <div class="cat-drill-info">
    <div class="cat-drill-icon"><?= View::icon('folder', 24) ?></div>
    <div class="cat-drill-title-wrap">
      <h2><?= e((string)$currentCategory['name']) ?></h2>
      <div class="sub">
        <?= count($subcategories) ?> subcategories · <?= (int)($currentCategory['total_requests_count'] ?? 0) ?> requests total
        <?php if (!empty($currentCategory['description'])): ?>
          · <?= e((string)$currentCategory['description']) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <a href="/admin/requests" class="btn btn-sm btn-ghost">
    <?= View::icon('arrow-start', 13) ?><span>All Categories</span>
  </a>
</div>
<?php elseif ($drillState === 'sub' && $currentCategory !== null && $parentCategory !== null): ?>
<!-- Breadcrumb for Phase 3: Inside Subcategory -->
<div class="cat-drill-banner">
  <div class="cat-drill-info">
    <div class="cat-drill-icon"><?= View::icon('folder', 24) ?></div>
    <div class="cat-drill-title-wrap">
      <h2><?= e((string)$currentCategory['name']) ?></h2>
      <div class="sub">
        Under <a href="/admin/requests?category=<?= (int)$parentCategory['id'] ?>"><?= e((string)$parentCategory['name']) ?></a> · <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?>
        <?php if (!empty($currentCategory['description'])): ?>
          · <?= e((string)$currentCategory['description']) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <a href="/admin/requests?category=<?= (int)$parentCategory['id'] ?>" class="btn btn-sm btn-ghost">
    <?= View::icon('arrow-start', 13) ?><span>Back to <?= e((string)$parentCategory['name']) ?></span>
  </a>
</div>
<?php elseif ($drillState === 'uncategorized'): ?>
<!-- Breadcrumb for Uncategorized -->
<div class="cat-drill-banner">
  <div class="cat-drill-info">
    <div class="cat-drill-icon" style="background:var(--warn-soft, rgba(255,181,71,0.15));color:var(--warn)"><?= View::icon('file', 24) ?></div>
    <div class="cat-drill-title-wrap">
      <h2>Uncategorized Requests</h2>
      <div class="sub">Requests not assigned to any category · <?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?></div>
    </div>
  </div>
  <a href="/admin/requests" class="btn btn-sm btn-ghost">
    <?= View::icon('arrow-start', 13) ?><span>All Categories</span>
  </a>
</div>
<?php elseif ($drillState === 'search'): ?>
<!-- Search banner -->
<div class="cat-drill-banner">
  <div class="cat-drill-info">
    <div class="cat-drill-icon"><?= View::icon('search', 22) ?></div>
    <div class="cat-drill-title-wrap">
      <h2>Filtered Results</h2>
      <div class="sub"><?= count($rows) ?> request<?= count($rows) === 1 ? '' : 's' ?> matching current search & filters</div>
    </div>
  </div>
  <a href="/admin/requests" class="btn btn-sm btn-ghost">
    <?= View::icon('arrow-start', 13) ?><span>Back to Categories</span>
  </a>
</div>
<?php endif; ?>


<?php if ($drillState === 'root'): ?>
  <!-- ======================================================================
       PHASE 1: Root / Top-Level View
       Shows:
       1) Big cards for Parent Categories
       2) Uncategorized Requests next to / below parent categories
       ====================================================================== -->
  
  <?php if ($categoryTree !== []): ?>
    <div class="cat-section-head">
      <h3><?= View::icon('folder', 16) ?> Parent Categories (<?= count($categoryTree) ?>)</h3>
      <a href="/admin/categories" class="hint small" style="text-decoration:none">Manage categories →</a>
    </div>

    <!-- Cards presentation for parent categories -->
    <div class="view-cards cat-big-grid">
      <?php foreach ($categoryTree as $p): ?>
      <a href="/admin/requests?category=<?= (int)$p['id'] ?>" class="cat-big-card" title="Open <?= e($p['name']) ?>">
        <div class="cat-card-header">
          <div class="cat-card-icon"><?= View::icon('folder', 22) ?></div>
          <div>
            <div class="cat-card-title"><?= e($p['name']) ?></div>
          </div>
          <div class="cat-card-arrow"><?= View::icon('chevron-end', 16) ?></div>
        </div>
        <?php if (!empty($p['description'])): ?>
          <div class="cat-card-desc"><?= e($p['description']) ?></div>
        <?php endif; ?>
        <div class="cat-card-meta">
          <span class="badge b-info"><?= count($p['children']) ?> subcategories</span>
          <span class="badge b-key"><?= (int)$p['total_requests_count'] ?> requests</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Table presentation for parent categories -->
    <section class="card view-table" style="margin-bottom:24px" hidden>
      <div class="table-scroll" style="max-height:none">
        <table class="rows sortable">
          <thead>
            <tr>
              <th>Category</th>
              <th>Description</th>
              <th class="num">Subcategories</th>
              <th class="num">Total Requests</th>
              <th class="ops">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($categoryTree as $p): ?>
            <tr>
              <td class="nowrap cell-name">
                <a href="/admin/requests?category=<?= (int)$p['id'] ?>" style="font-weight:650;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                  <?= View::icon('folder', 13) ?> <?= e($p['name']) ?>
                </a>
              </td>
              <td><?= !empty($p['description']) ? e($p['description']) : '<span class="hint small">—</span>' ?></td>
              <td class="num nowrap"><?= count($p['children']) ?></td>
              <td class="num nowrap"><?= (int)$p['total_requests_count'] ?></td>
              <td class="ops nowrap">
                <a href="/admin/requests?category=<?= (int)$p['id'] ?>" class="btn btn-sm">
                  Open <?= View::icon('chevron-end', 12) ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <!-- Uncategorized Requests in Phase 1 -->
  <div class="cat-section-head">
    <h3>
      <?= View::icon('file', 16) ?>
      Uncategorized Requests (<?= count($uncategorizedRequests) ?>)
    </h3>
  </div>

  <?php if ($uncategorizedRequests !== []): ?>
    <!-- Cards View for uncategorized requests -->
    <div id="req-cards" class="view-cards req-grid">
      <?php foreach ($uncategorizedRequests as $r): ?>
        <?= $renderCard($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
      <?php endforeach; ?>
    </div>

    <!-- Table View for uncategorized requests -->
    <section class="card view-table" id="req-table" hidden>
      <div class="table-scroll" style="max-height:none">
        <table class="rows sortable">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Category</th>
              <th>Connection</th>
              <th>Mode</th>
              <th class="num">Runs</th>
              <th>Durations · 7d</th>
              <th>State</th>
              <th class="ops">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($uncategorizedRequests as $r): ?>
            <?= $renderTableRow($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php else: ?>
    <div class="card" style="padding:16px 20px;text-align:center;margin-bottom:20px">
      <span class="hint small">No uncategorized requests. All requests are organized in categories.</span>
    </div>
  <?php endif; ?>


<?php elseif ($drillState === 'parent'): ?>
  <!-- ======================================================================
       PHASE 2: Inside Parent Category View
       Shows:
       1) Big cards for Subcategories
       2) Requests in this category branch
       ====================================================================== -->

  <?php if ($subcategories !== []): ?>
    <div class="cat-section-head">
      <h3><?= View::icon('folder', 16) ?> Subcategories (<?= count($subcategories) ?>)</h3>
    </div>

    <!-- Cards presentation for subcategories -->
    <div class="view-cards cat-big-grid">
      <?php foreach ($subcategories as $sub): ?>
      <a href="/admin/requests?category=<?= (int)$sub['id'] ?>" class="cat-big-card" title="Open <?= e($sub['name']) ?>">
        <div class="cat-card-header">
          <div class="cat-card-icon"><?= View::icon('folder', 22) ?></div>
          <div>
            <div class="cat-card-title"><?= e($sub['name']) ?></div>
          </div>
          <div class="cat-card-arrow"><?= View::icon('chevron-end', 16) ?></div>
        </div>
        <?php if (!empty($sub['description'])): ?>
          <div class="cat-card-desc"><?= e($sub['description']) ?></div>
        <?php endif; ?>
        <div class="cat-card-meta">
          <span class="badge b-key"><?= (int)$sub['requests_count'] ?> requests</span>
        </div>
      </a>
      <?php endforeach; ?>
    </div>

    <!-- Table presentation for subcategories -->
    <section class="card view-table" style="margin-bottom:24px" hidden>
      <div class="table-scroll" style="max-height:none">
        <table class="rows sortable">
          <thead>
            <tr>
              <th>Subcategory</th>
              <th>Description</th>
              <th class="num">Requests</th>
              <th class="ops">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($subcategories as $sub): ?>
            <tr>
              <td class="nowrap cell-name">
                <a href="/admin/requests?category=<?= (int)$sub['id'] ?>" style="font-weight:650;text-decoration:none;display:inline-flex;align-items:center;gap:6px">
                  <?= View::icon('folder', 13) ?> <?= e($sub['name']) ?>
                </a>
              </td>
              <td><?= !empty($sub['description']) ? e($sub['description']) : '<span class="hint small">—</span>' ?></td>
              <td class="num nowrap"><?= (int)$sub['requests_count'] ?></td>
              <td class="ops nowrap">
                <a href="/admin/requests?category=<?= (int)$sub['id'] ?>" class="btn btn-sm">
                  Open <?= View::icon('chevron-end', 12) ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <!-- Only show direct requests if any exist directly under parent without a subcategory -->
  <?php if ($directRequests !== []): ?>
    <div class="cat-section-head">
      <h3><?= View::icon('file', 16) ?> Direct Requests in <?= e((string)$currentCategory['name']) ?> (<?= count($directRequests) ?>)</h3>
    </div>

    <div id="req-cards" class="view-cards req-grid">
      <?php foreach ($directRequests as $r): ?>
        <?= $renderCard($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
      <?php endforeach; ?>
    </div>

    <section class="card view-table" id="req-table" hidden>
      <div class="table-scroll" style="max-height:none">
        <table class="rows sortable">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Category</th>
              <th>Connection</th>
              <th>Mode</th>
              <th class="num">Runs</th>
              <th>Durations · 7d</th>
              <th>State</th>
              <th class="ops">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($directRequests as $r): ?>
            <?= $renderTableRow($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php elseif ($subcategories === []): ?>
    <div class="card" style="padding:16px 20px;text-align:center">
      <span class="hint small">No subcategories or requests in this category yet.</span>
    </div>
  <?php endif; ?>


<?php else: ?>
  <!-- ======================================================================
       PHASE 3 / Uncategorized / Search Views
       Direct requests listing (cards or table)
       ====================================================================== -->

  <?php if ($rows === []): ?>
    <section class="card">
      <div class="empty">
        <?= View::icon('file', 34) ?>
        <b>No requests<?= ($f['q'] !== '' || $f['mode'] !== '' || $f['conn'] !== '' || $f['active'] !== '') ? ' match these filters' : ' in this category' ?></b>
        <span>A request bundles a read-only SQL statement, a connection and response settings. Create the first one, then grant it to clients.</span>
        <a class="btn btn-primary" href="/admin/requests/new"><?= View::icon('plus', 14) ?><span>New request</span></a>
      </div>
    </section>
  <?php else: ?>
    <!-- Cards view -->
    <div id="req-cards" class="view-cards req-grid">
      <?php foreach ($rows as $r): ?>
        <?= $renderCard($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
      <?php endforeach; ?>
    </div>

    <!-- Table view -->
    <section class="card view-table" id="req-table" hidden>
      <div class="table-scroll" style="max-height:none">
        <table class="rows sortable">
          <thead>
            <tr>
              <th>Code</th>
              <th>Name</th>
              <th>Category</th>
              <th>Connection</th>
              <th>Mode</th>
              <th class="num">Runs</th>
              <th>Durations · 7d</th>
              <th>State</th>
              <th class="ops">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <?= $renderTableRow($r, $sparks, $csrf, $modeBadge, $stateBadge, $sparkSvg) ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

<?php endif; ?>


<?php if ($grantReq !== null): ?>
<div class="modal" id="perm-modal" role="dialog" aria-modal="true" aria-labelledby="perm-modal-title">
  <div class="modal-box modal-lg">
    <div class="modal-head-bar">
      <div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
          <span class="badge b-key"><?= View::icon('shield', 12) ?> Permissions</span>
          <code><?= e((string)$grantReq['code']) ?></code>
        </div>
        <h3 id="perm-modal-title" style="margin:0 0 4px">Grant Client Permissions</h3>
        <p class="hint small" style="margin:0">Select all clients allowed to run <b><?= e((string)$grantReq['name']) ?></b>.</p>
      </div>
      <a href="/admin/requests" class="icon-btn" title="Close" aria-label="Close"><?= View::icon('x', 16) ?></a>
    </div>

    <form method="post" action="/admin/requests/permissions" class="perm-modal-form">
      <?= $csrf ?>
      <input type="hidden" name="id" value="<?= (int)$grantReq['id'] ?>">

      <div class="checklist-tools">
        <div class="check-search">
          <?= View::icon('search', 15) ?>
          <input type="search" id="modal-perm-filter" placeholder="Filter clients by name or type…" aria-label="Filter clients" autocomplete="off" spellcheck="false">
        </div>
        <div class="inline">
          <label class="chk" style="margin:0"><input type="checkbox" id="modal-perm-all"> <span>Select all shown</span></label>
          <span class="count" id="modal-perm-count"><?= count($clients) ?> clients</span>
        </div>
      </div>

      <div class="checklist perm-modal-list" id="modal-perm-list">
        <?php foreach ($clients as $c): 
          $cid = (int)$c['id'];
          $checked = in_array($cid, $grantedClientIds, true);
        ?>
        <label class="chk perm-row" data-search="<?= e(strtolower((string)$c['name'] . ' ' . (string)$c['type'])) ?>">
          <input type="checkbox" name="client_ids[]" value="<?= $cid ?>" <?= $checked ? 'checked' : '' ?>>
          <div class="perm-client-info">
            <b><?= e((string)$c['name']) ?></b>
            <span class="badge badge-sm <?= $c['type'] === 'agent' ? 'b-info' : 'b-key' ?>"><?= e((string)$c['type']) ?></span>
          </div>
          <span class="hint small perm-status" style="margin-inline-start:auto">
            <?= $checked ? '<span class="granted-tag">' . View::icon('check', 11) . ' Granted</span>' : 'No access' ?>
          </span>
        </label>
        <?php endforeach; ?>
        <?php if ($clients === []): ?>
        <div class="empty" style="margin:20px 0">
          <b>No active clients</b>
          <span>Create an active client first in <a href="/admin/clients">Clients</a>.</span>
        </div>
        <?php endif; ?>
      </div>

      <div class="m-actions" style="margin-top:16px;display:flex;justify-content:space-between;align-items:center">
        <a href="/admin/requests" class="btn">Cancel / Skip</a>
        <button type="submit" class="btn btn-primary"><?= View::icon('check', 14) ?> Save Permissions</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
