<?php
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$items = db()->query(
    'SELECT id, picture, brand, name, price, regular_price, caption FROM items ORDER BY display_order, id'
)->fetchAll();

// メーカー絞り込み用の一覧 (登録順を保ちつつ重複排除)
$brands = [];
foreach ($items as $item) {
    $brand = (string)($item['brand'] ?? '');
    if ($brand !== '' && !in_array($brand, $brands, true)) {
        $brands[] = $brand;
    }
}
sort($brands, SORT_FLAG_CASE | SORT_NATURAL);

// カートはクライアント (localStorage) が保持し、cart.js が数量を画面に反映する
$stockMap = remaining_stock(db(), $_SESSION['editing_order']['id'] ?? null);

$pageTitle = '商品一覧 - ' . site_title();
$navActive = 'shop';
require __DIR__ . '/lib/header.php';
?>

<h1 class="h4 mb-3">商品一覧</h1>

<?php if (isset($_SESSION['editing_order'])): ?>
<div class="alert alert-warning">
  <strong>申込番号 <?= h($_SESSION['editing_order']['order_number']) ?> を編集中です。</strong>
  カート確認画面で「申込みを更新する」を押すと、この申込みの内容が上書きされます。
  <form method="post" action="orders.php" class="mt-2"
        onsubmit="return confirm('編集を取り消して、新しく申込みを始めますか?現在の入力内容は失われます。');">
    <input type="hidden" name="action" value="new">
    <button type="submit" class="btn btn-sm btn-outline-secondary">編集内容を取り消す</button>
  </form>
</div>
<?php endif; ?>

<p class="text-muted small">
  数量を変更すると、その場で申込み内容に反映されます。
  入力が終わったら「カート確認へ進む」を押してください。
</p>

<?php if ($brands !== []): ?>
<div class="row mb-3">
  <div class="col-12 col-sm-6 col-md-4">
    <label class="form-label small mb-1" for="brand-filter">メーカーで絞り込み</label>
    <select class="form-select" id="brand-filter">
      <option value="">すべて</option>
      <?php foreach ($brands as $brand): ?>
      <option value="<?= h($brand) ?>"><?= h($brand) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</div>
<?php endif; ?>

<div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3" id="item-grid">
  <?php foreach ($items as $item): ?>
  <?php
    $remaining = $stockMap[$item['id']]['remaining'] ?? null;
    $soldOut   = $remaining !== null && $remaining <= 0;
    $maxQty    = $remaining === null ? 99 : min(99, max(1, $remaining));
  ?>
  <div class="col item-col" data-brand="<?= h((string)($item['brand'] ?? '')) ?>">
    <div class="card h-100 shadow-sm">
      <button type="button" class="w-100 p-0 border-0 bg-transparent" style="cursor: zoom-in;"
              data-bs-toggle="modal" data-bs-target="#photo-modal"
              data-photo="<?= h(asset_url($item['picture'])) ?>" data-name="<?= h($item['name']) ?>"
              aria-label="<?= h($item['name']) ?>の画像を拡大">
        <img src="<?= h(asset_url($item['picture'])) ?>" class="card-img-top" alt="<?= h($item['name']) ?>"
             style="height: 180px; object-fit: contain; background-color: #f8f9fa;">
      </button>
      <div class="card-body d-flex flex-column">
        <?php if ((string)($item['brand'] ?? '') !== ''): ?>
        <p class="card-text small text-muted mb-0"><?= h($item['brand']) ?></p>
        <?php endif; ?>
        <h2 class="card-title h6 mb-1"><?= h($item['name']) ?></h2>
        <?php if ((string)($item['caption'] ?? '') !== ''): ?>
        <p class="card-text small text-muted mb-1"><?= nl2br(h($item['caption'])) ?></p>
        <?php endif; ?>
        <?php
          $regularPrice = $item['regular_price'] !== null ? (int)$item['regular_price'] : null;
          $hasDiscount  = $regularPrice !== null && $regularPrice > (int)$item['price'];
          $discountPct  = $hasDiscount ? (int)round((1 - ((int)$item['price'] / $regularPrice)) * 100) : 0;
        ?>
        <p class="card-text mb-1">
          <span class="fw-bold text-danger"><?= h(yen((int)$item['price'])) ?></span>
          <?php if ($hasDiscount): ?>
          <span class="badge bg-danger ms-1"><?= $discountPct ?>% OFF</span>
          <?php endif; ?>
          <?php if ($regularPrice !== null && $regularPrice >= (int)$item['price']): ?>
          <br><span class="text-muted text-decoration-line-through small"><?= h(regular_yen($regularPrice)) ?></span>
          <?php endif; ?>
        </p>
        <?php if ($remaining !== null): ?>
        <p class="card-text small mb-2">
          在庫数：<?= (int)$remaining ?>
          <?php if ($remaining <= 0): ?>
          <span class="badge bg-secondary ms-1">在庫切れ</span>
          <?php endif; ?>
        </p>
        <?php endif; ?>
        <div class="input-group mt-auto" style="max-width: 200px;">
          <button class="btn btn-outline-secondary qty-minus fs-5" type="button"
                  data-target="qty-<?= (int)$item['id'] ?>" aria-label="減らす">&minus;</button>
          <input type="number" class="form-control text-center qty-input"
                 id="qty-<?= (int)$item['id'] ?>" data-item-id="<?= (int)$item['id'] ?>"
                 data-price="<?= (int)$item['price'] ?>"
                 value="0" min="0" max="<?= $maxQty ?>" inputmode="numeric"
                 aria-label="数量" <?= $soldOut ? 'disabled' : '' ?>>
          <button class="btn btn-outline-secondary qty-plus fs-5" type="button"
                  data-target="qty-<?= (int)$item['id'] ?>" aria-label="増やす"
                  <?= $soldOut ? 'disabled' : '' ?>>&plus;</button>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="text-center mt-4">
  <a href="cart.php" class="btn btn-success btn-lg px-5">カート確認へ進む</a>
</div>

<!-- 商品画像の拡大表示 (サムネイルをタップすると開く) -->
<div class="modal fade" id="photo-modal" tabindex="-1" aria-labelledby="photo-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="photo-modal-title"></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body text-center p-2" style="background-color: #f8f9fa;">
        <img id="photo-modal-img" src="" alt="" class="img-fluid"
             style="max-height: 75vh; object-fit: contain;">
      </div>
    </div>
  </div>
</div>

<!-- ページ先頭へ戻るボタン -->
<button type="button" id="back-to-top" class="btn btn-primary rounded-circle d-none"
        aria-label="ページの先頭へ戻る" title="先頭へ戻る"
        style="position: fixed; right: 1rem; bottom: 1rem; z-index: 1090; width: 3rem; height: 3rem;
               opacity: 0.55; font-size: 1.35rem; line-height: 1; padding: 0;">
  <span aria-hidden="true">&uarr;</span>
</button>

<script>
// 画像拡大モーダル: 開く直前に、タップされたサムネイルの画像と商品名を差し込む
document.getElementById('photo-modal').addEventListener('show.bs.modal', function (e) {
  var trigger = e.relatedTarget;
  if (!trigger) return;
  var img = document.getElementById('photo-modal-img');
  img.src = trigger.dataset.photo;
  img.alt = trigger.dataset.name;
  document.getElementById('photo-modal-title').textContent = trigger.dataset.name;
});

// 数量の増減をクライアントのカート (localStorage) に反映する。通信は発生しない。
(function () {
  if (!window.qrCart) return;
  var inputs = document.querySelectorAll('.qty-input');
  if (!inputs.length) return;

  function maxOf(input) {
    var max = parseInt(input.getAttribute('max'), 10);
    return isNaN(max) ? 99 : max;
  }
  function clampValue(input, value) {
    if (isNaN(value)) value = 0;
    return Math.min(maxOf(input), Math.max(0, value));
  }

  // localStorage の数量を画面に反映する
  var cart = qrCart.get();
  Array.prototype.forEach.call(inputs, function (input) {
    var qty = cart[parseInt(input.dataset.itemId, 10)] || 0;
    if (qty > 0) {
      // 編集中の既存数量が在庫上限を超える場合は、その値を上限として尊重する
      if (qty > maxOf(input)) input.setAttribute('max', String(qty));
      input.value = qty;
      input.disabled = false;
    } else {
      input.value = 0;
    }
  });
  qrCart.refreshBadge();

  // プラス・マイナスボタン
  document.addEventListener('click', function (e) {
    var btn = e.target.closest('.qty-plus, .qty-minus');
    if (!btn) return;
    var input = document.getElementById(btn.dataset.target);
    if (!input) return;
    var value = parseInt(input.value, 10);
    if (isNaN(value)) value = 0;
    input.value = clampValue(input, value + (btn.classList.contains('qty-plus') ? 1 : -1));
    qrCart.setQty(input.dataset.itemId, input.value);
  });

  // キー入力 (確定時)
  document.addEventListener('change', function (e) {
    if (!e.target.classList.contains('qty-input')) return;
    e.target.value = clampValue(e.target, parseInt(e.target.value, 10));
    qrCart.setQty(e.target.dataset.itemId, e.target.value);
  });
})();

// メーカー絞り込み
(function () {
  var filter = document.getElementById('brand-filter');
  if (!filter) return;
  filter.addEventListener('change', function () {
    var value = filter.value;
    document.querySelectorAll('.item-col').forEach(function (col) {
      var match = value === '' || col.dataset.brand === value;
      col.classList.toggle('d-none', !match);
    });
  });
})();

// ページ先頭へ戻るボタン
(function () {
  var btn = document.getElementById('back-to-top');
  if (!btn) return;
  function toggle() {
    btn.classList.toggle('d-none', window.scrollY < 400);
  }
  window.addEventListener('scroll', toggle, { passive: true });
  btn.addEventListener('click', function () {
    window.scrollTo({ top: 0, behavior: 'smooth' });
  });
  toggle();
})();
</script>

<?php require __DIR__ . '/lib/footer.php'; ?>
