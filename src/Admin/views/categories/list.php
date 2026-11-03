<?php /** @var array $tree @var int $uncategorizedCount @var int $totalRequests */
use Admin\View;
use Core\Str;

$totalCategories = count($tree);
$totalSubcategories = 0;
$totalCategorizedRequests = 0;
foreach ($tree as $parent) {
    $totalSubcategories += count($parent['children']);
    $totalCategorizedRequests += $parent['total_requests_count'];
}
?>
<div class="page-head">
  <div>
    <h1>Request Categories</h1>
    <div class="page-sub">Two-level organization for gateway requests (Category A &rsaquo; Category B).</div>
  </div>
  <div class="page-tools">
    <a class="btn btn-ghost" href="/admin/requests"><?= View::icon('file', 14) ?><span>Back to requests</span></a>
    <a class="btn btn-primary" href="/admin/categories/new"><?= View::icon('plus', 15) ?><span>New category</span></a>
  </div>
</div>

<div class="chips" style="margin-bottom:20px;gap:12px">
  <span class="chip"><b><?= $totalCategories ?></b> parent categories</span>
  <span class="chip"><b><?= $totalSubcategories ?></b> subcategories</span>
  <span class="chip"><b><?= $totalCategorizedRequests ?></b> categorized requests</span>
  <?php if ($uncategorizedCount > 0): ?>
  <a href="/admin/requests?category=none" class="chip chip-warn" style="text-decoration:none">
    <?= View::icon('alert', 13) ?>
    <b><?= $uncategorizedCount ?></b> uncategorized request<?= $uncategorizedCount === 1 ? '' : 's' ?> &rarr;
  </a>
  <?php else: ?>
  <span class="chip chip-ok"><?= View::icon('check', 13) ?> All requests categorized</span>
  <?php endif; ?>
</div>

<?php if ($tree === []): ?>
<section class="card">
  <div class="empty">
    <?= View::icon('folder', 34) ?>
    <b>No categories created yet</b>
    <span>Create your first parent category (e.g. "Sales", "Accounting", "HR"), then add subcategories (e.g. "Domestic", "Export").</span>
    <a class="btn btn-primary" href="/admin/categories/new"><?= View::icon('plus', 14) ?><span>Create parent category</span></a>
  </div>
</section>
<?php else: ?>

<section class="card">
  <div class="table-scroll" style="max-height:none">
    <table class="rows">
      <thead>
        <tr>
          <th>Category Hierarchy</th>
          <th>Description</th>
          <th class="num">Requests</th>
          <th class="ops">Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($tree as $p): ?>
        <!-- ======================= Level 1 Parent Category ======================= -->
        <tr class="cat-parent-row" style="background:var(--surface-sunken, rgba(255,255,255,0.02));font-weight:600">
          <td class="nowrap" style="padding-inline-start:12px">
            <div style="display:inline-flex;align-items:center;gap:8px">
              <span style="color:var(--primary)"><?= View::icon('folder', 17) ?></span>
              <span style="font-size:14px"><?= e($p['name']) ?></span>
              <span class="badge b-key" style="font-size:10px;padding:1px 6px">Level 1</span>
            </div>
          </td>
          <td class="cell-desc hint small"><?= e($p['description'] ?? '—') ?></td>
          <td class="num nowrap">
            <a href="/admin/requests?category=<?= (int)$p['id'] ?>" class="badge <?= $p['total_requests_count'] > 0 ? 'b-ok' : '' ?>" title="View requests in this category">
              <?= number_format($p['total_requests_count']) ?> request<?= $p['total_requests_count'] === 1 ? '' : 's' ?>
            </a>
          </td>
          <td class="ops nowrap">
            <a class="btn btn-sm btn-ghost" href="/admin/categories/new?parent_id=<?= (int)$p['id'] ?>" title="Add subcategory inside <?= e($p['name']) ?>">
              <?= View::icon('plus', 12) ?> Subcategory
            </a>
            <a class="btn btn-sm" href="/admin/requests?category=<?= (int)$p['id'] ?>">View requests</a>
            <a class="btn btn-sm" href="/admin/categories/<?= (int)$p['id'] ?>">Edit</a>
            <form method="post" action="/admin/categories/delete" data-confirm="Delete category «<?= e($p['name']) ?>»? Any subcategories will also be deleted, and their requests will become uncategorized." class="inline">
              <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm danger" type="submit"><?= View::icon('trash', 12) ?> Delete</button>
            </form>
          </td>
        </tr>

        <!-- ======================= Level 2 Subcategories ======================= -->
        <?php foreach ($p['children'] as $ch): ?>
        <tr class="cat-child-row">
          <td class="nowrap" style="padding-inline-start:38px">
            <div style="display:inline-flex;align-items:center;gap:8px">
              <span style="color:var(--muted);font-size:14px;line-height:1">↳</span>
              <span style="color:var(--info-ink)"><?= View::icon('folder', 14) ?></span>
              <span style="font-weight:500"><?= e($ch['name']) ?></span>
              <span class="badge b-info" style="font-size:10px;padding:1px 5px">Level 2</span>
            </div>
          </td>
          <td class="cell-desc hint small"><?= e($ch['description'] ?? '—') ?></td>
          <td class="num nowrap">
            <a href="/admin/requests?category=<?= (int)$ch['id'] ?>" class="badge <?= $ch['requests_count'] > 0 ? 'b-ok' : '' ?>">
              <?= number_format($ch['requests_count']) ?> request<?= $ch['requests_count'] === 1 ? '' : 's' ?>
            </a>
          </td>
          <td class="ops nowrap">
            <a class="btn btn-sm" href="/admin/requests?category=<?= (int)$ch['id'] ?>">View requests</a>
            <a class="btn btn-sm" href="/admin/categories/<?= (int)$ch['id'] ?>">Edit</a>
            <form method="post" action="/admin/categories/delete" data-confirm="Delete subcategory «<?= e($ch['name']) ?>»? Requests in this subcategory will become uncategorized." class="inline">
              <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$ch['id'] ?>">
              <button class="btn btn-sm danger" type="submit"><?= View::icon('trash', 12) ?> Delete</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>

        <?php if ($p['children'] === []): ?>
        <tr>
          <td colspan="4" style="padding-inline-start:38px;padding-block:6px" class="hint small">
            <i>No subcategories in «<?= e($p['name']) ?>» yet.</i>
            <a href="/admin/categories/new?parent_id=<?= (int)$p['id'] ?>" style="margin-inline-start:8px">+ Add a subcategory</a>
          </td>
        </tr>
        <?php endif; ?>

      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

