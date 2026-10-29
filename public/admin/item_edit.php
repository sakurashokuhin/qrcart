<?php
require_once __DIR__ . '/../lib/bootstrap.php';

$itemId = (int)($_GET['id'] ?? $_POST['item_id'] ?? 0);

$stmt = db()->prepare(
    'SELECT id, itemcode, display_order, picture, brand, name, price, regular_price, misc1, misc2, caption, stock
     FROM items WHERE id = ?'
);
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if ($item === false) {
    header('Location: items.php?error=notfound');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemcode       = trim((string)($_POST['itemcode'] ?? ''));
    $displayOrder   = (int)($_POST['display_order'] ?? 0);
    $picture        = trim((string)($_POST['picture'] ?? ''));
    $brand          = trim((string)($_POST['brand'] ?? ''));
    $name           = trim((string)($_POST['name'] ?? ''));
    $price          = (int)($_POST['price'] ?? -1);
    $regularPriceRaw = trim((string)($_POST['regular_price'] ?? ''));
    $misc1          = trim((string)($_POST['misc1'] ?? ''));
    $misc2          = trim((string)($_POST['misc2'] ?? ''));
    $caption        = normalize_multiline((string)($_POST['caption'] ?? ''));
    $stockUnlimited = isset($_POST['stock_unlimited']);
    $stockRaw       = trim((string)($_POST['stock'] ?? ''));

    $regularPriceValid = $regularPriceRaw === '' || ctype_digit($regularPriceRaw);
    $stockValid = $stockUnlimited || ctype_digit($stockRaw);
    if ($picture === '' || $name === '' || $price < 0 || !$regularPriceValid || !$stockValid
        || !item_lengths_valid($itemcode, $picture, $brand, $name, $misc1, $misc2, $caption)) {
        $error = ITEM_INVALID_MESSAGE;
    } else {
        $regularPrice = $regularPriceRaw === '' ? null : (int)$regularPriceRaw;
        $stock = $stockUnlimited ? null : (int)$stockRaw;
        try {
            db()->prepare(
                'UPDATE items
                 SET itemcode = ?, display_order = ?, picture = ?, brand = ?, name = ?, price = ?, regular_price = ?,
                     misc1 = ?, misc2 = ?, caption = ?, stock = ?
                 WHERE id = ?'
            )->execute([
                $itemcode !== '' ? $itemcode : null,
                $displayOrder, $picture,
                $brand !== '' ? $brand : null,
                $name, $price, $regularPrice,
                $misc1 !== '' ? $misc1 : null,
                $misc2 !== '' ? $misc2 : null,
                $caption !== '' ? $caption : null,
                $stock,
                $itemId,
            ]);
            header('Location: items.php?msg=updated');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'その商品コードは既に別の商品に登録されています。';
            } else {
                throw $e;
            }
        }
    }
    // 入力値を保持して再表示
    $item['itemcode']      = $itemcode;
    $item['display_order'] = $displayOrder;
    $item['picture']       = $picture;
    $item['brand']         = $brand;
    $item['name']          = $name;
    $item['price']         = max(0, $price);
    $item['regular_price'] = $regularPriceRaw;
    $item['misc1']         = $misc1;
    $item['misc2']         = $misc2;
    $item['caption']       = $caption;
    $item['stock']         = $stockUnlimited ? null : $stockRaw;
}

$pageTitle = '商品編集';
$active    = 'items';
require __DIR__ . '/../lib/admin_header.php';
?>

<h1 class="h4 mb-3">商品編集 (ID: <?= (int)$item['id'] ?>)</h1>

<?php if ($error !== null): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width: 560px;">
  <div class="card-body">
    <div class="text-center mb-3">
      <img src="../<?= h(asset_url($item['picture'])) ?>" alt="" width="160" height="120"
           style="object-fit: cover;" class="rounded border">
    </div>
    <form method="post">
      <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
      <div class="mb-3">
        <label class="form-label" for="edit-itemcode">商品コード <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="edit-itemcode" name="itemcode" maxlength="64"
               value="<?= h($item['itemcode'] ?? '') ?>" autocomplete="off">
        <div class="form-text">CSV全置換時の照合キーです (重複不可)。利用者には表示されません。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-order">表示順</label>
        <input type="number" class="form-control" id="edit-order" name="display_order"
               value="<?= (int)$item['display_order'] ?>" required>
        <div class="form-text">小さいほど商品一覧の先頭に表示されます。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-picture">画像パス</label>
        <input type="text" class="form-control" id="edit-picture" name="picture" maxlength="255"
               value="<?= h($item['picture']) ?>" required autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-brand">メーカー <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="edit-brand" name="brand" maxlength="100"
               value="<?= h($item['brand'] ?? '') ?>" autocomplete="off">
        <div class="form-text">商品一覧で商品名の上に表示されます。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-name">商品名</label>
        <input type="text" class="form-control" id="edit-name" name="name" maxlength="200"
               value="<?= h($item['name']) ?>" required autocomplete="off">
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-price">販売価格 (円)</label>
        <input type="number" class="form-control" id="edit-price" name="price"
               value="<?= (int)$item['price'] ?>" min="0" required>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-regular-price">通常価格 (円) <span class="text-muted">(任意)</span></label>
        <input type="number" class="form-control" id="edit-regular-price" name="regular_price"
               value="<?= h((string)($item['regular_price'] ?? '')) ?>" min="0">
        <div class="form-text">入力すると商品一覧画面で販売価格の下に取り消し線付きで表示され、割引率も表示されます。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-misc1">備考1 <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="edit-misc1" name="misc1" maxlength="100"
               value="<?= h($item['misc1'] ?? '') ?>" autocomplete="off">
        <div class="form-text">利用者には表示されません。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-misc2">備考2 <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="edit-misc2" name="misc2" maxlength="100"
               value="<?= h($item['misc2'] ?? '') ?>" autocomplete="off">
        <div class="form-text">利用者には表示されません。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-caption">キャプション <span class="text-muted">(任意)</span></label>
        <textarea class="form-control" id="edit-caption" name="caption" rows="3"
                  maxlength="255" autocomplete="off"><?= h($item['caption'] ?? '') ?></textarea>
        <div class="form-text">商品一覧で商品名の下に表示されます。改行もそのまま表示されます。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-stock">在庫数</label>
        <input type="number" class="form-control" id="edit-stock" name="stock" min="0"
               value="<?= h($item['stock'] ?? '') ?>" <?= $item['stock'] === null ? 'disabled' : '' ?>>
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="edit-stock-unlimited" name="stock_unlimited"
                 <?= $item['stock'] === null ? 'checked' : '' ?>>
          <label class="form-check-label" for="edit-stock-unlimited">無制限</label>
        </div>
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">更新</button>
        <a href="items.php" class="btn btn-outline-secondary">キャンセル</a>
      </div>
    </form>
    <script>
      (function () {
        var checkbox = document.getElementById('edit-stock-unlimited');
        var input = document.getElementById('edit-stock');
        checkbox.addEventListener('change', function () {
          input.disabled = checkbox.checked;
          if (checkbox.checked) input.value = '';
        });
      })();
    </script>
  </div>
</div>

<?php require __DIR__ . '/../lib/footer.php'; ?>
