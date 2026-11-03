<?php /** @var array $row @var array $topLevelCategories @var bool $hasChildren @var array<string,string> $errors */
use Admin\View;
$errors = $errors ?? [];
$isNew = ((int)($row['id'] ?? 0)) === 0;
$hasChildren = $hasChildren ?? false;
$fe = static fn(string $f): string => \Admin\View::ferr($errors, $f);
$fc = static fn(string $f): string => \Admin\View::fcls($errors, $f);
?>
<div class="page-head">
  <div>
    <h1><?= $isNew ? 'New Category' : 'Edit Category — ' . e((string)$row['name']) ?></h1>
    <div class="page-sub">Two-level category structure for organizing requests.</div>
  </div>
  <div class="page-tools">
    <?php if (!$isNew): ?>
    <form method="post" action="/admin/categories/delete" data-confirm="Delete category «<?= e((string)$row['name']) ?>»? Subcategories and requests will be affected." class="inline" style="margin:0">
      <?= $csrf ?><input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
      <button class="btn danger" type="submit"><?= View::icon('trash', 14) ?><span>Delete</span></button>
    </form>
    <?php endif; ?>
    <a class="btn" href="/admin/categories"><?= View::icon('arrow-start', 14) ?><span>All categories</span></a>
  </div>
</div>

<section class="card slim">
  <form method="post" action="/admin/categories" class="form grid2">
    <?= $csrf ?>
    <input type="hidden" name="id" value="<?= (int)($row['id'] ?? 0) ?>">

    <label>Category Name
      <input type="text" name="name" value="<?= e((string)$row['name']) ?>" class="<?= $fc('name') ?>" placeholder="e.g. Sales, Accounting, Invoices…" required autofocus>
      <?= $fe('name') ?>
    </label>

    <label>Parent Category (Hierarchy Level)
      <?php if ($hasChildren): ?>
      <select name="parent_id" disabled class="disabled">
        <option value="">— None (Top-level Parent Category) —</option>
      </select>
      <input type="hidden" name="parent_id" value="">
      <p class="hint small" style="margin-top:4px;color:var(--warn-ink)">
        <?= View::icon('info', 13) ?> This category has subcategories, so it must remain a top-level category (max 2 levels).
      </p>
      <?php else: ?>
      <select name="parent_id" class="<?= $fc('parent_id') ?>">
        <option value="">— None (Make this a Top-level Category / Level 1) —</option>
        <?php foreach ($topLevelCategories as $parent): ?>
        <option value="<?= (int)$parent['id'] ?>" <?= ((int)($row['parent_id'] ?? 0) === (int)$parent['id']) ? 'selected' : '' ?>>
          ↳ Inside: <?= e((string)$parent['name']) ?> (Level 2 Subcategory)
        </option>
        <?php endforeach; ?>
      </select>
      <?= $fe('parent_id') ?>
      <p class="hint small" style="margin-top:4px">
        Choose <b>None</b> for Level 1 (e.g. "Category A"), or select a parent to make this a Level 2 Subcategory (e.g. "Category B" inside "Category A").
      </p>
      <?php endif; ?>
    </label>

    <label style="grid-column:1 / -1">Description (Optional)
      <textarea name="description" rows="2" class="<?= $fc('description') ?>" placeholder="Brief description of the requests grouped in this category…"><?= e((string)($row['description'] ?? '')) ?></textarea>
      <?= $fe('description') ?>
    </label>

    <label>Sort Order
      <input type="number" name="sort_order" value="<?= (int)($row['sort_order'] ?? 0) ?>" class="<?= $fc('sort_order') ?>">
      <?= $fe('sort_order') ?>
      <p class="hint small" style="margin-top:4px">Lower numbers appear first.</p>
    </label>

    <div class="form-ops" style="grid-column:1 / -1">
      <button class="btn btn-primary" type="submit"><?= View::icon('check', 14) ?><span>Save Category</span></button>
      <a class="btn" href="/admin/categories">Cancel</a>
    </div>
  </form>
</section>

