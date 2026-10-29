<?php
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login();

$editing = $_SESSION['editing_order'] ?? null;

// 全商品カタログ + 在庫を埋め込み、クライアント (cart.js + 下記スクリプト) が
// localStorage のカートを結合して描画する。
$items = db()->query(
    'SELECT id, picture, name, price FROM items ORDER BY display_order, id'
)->fetchAll();
$stockMap = remaining_stock(db(), $editing['id'] ?? null);

$catalog = [];
foreach ($items as $it) {
    $rid = (int)$it['id'];
    $catalog[] = [
        'id'        => $rid,
        'name'      => (string)$it['name'],
        'price'     => (int)$it['price'],
        'picture'   => asset_url((string)$it['picture']),
        'remaining' => $stockMap[$rid]['remaining'] ?? null, // null = 無制限
    ];
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$pageTitle = 'カート確認 - ' . site_title();
$navActive = 'cart';
require __DIR__ . '/lib/header.php';
?>

<script id="catalog" type="application/json"><?= json_encode($catalog, $jsonFlags) ?></script>

<h1 class="h4 mb-3">カート確認</h1>

<?php if ($editing !== null): ?>
<div class="alert alert-warning">
  <strong>申込番号 <?= h($editing['order_number']) ?> を編集中です。</strong>
  「申込みを更新する」を押すと、この申込みの内容が上書きされます。
  <form method="post" action="orders.php" class="mt-2"
        onsubmit="return confirm('編集を取り消して、新しく申込みを始めますか?現在の入力内容は失われます。');">
    <input type="hidden" name="action" value="new">
    <button type="submit" class="btn btn-sm btn-outline-secondary">編集内容を取り消す</button>
  </form>
</div>
<?php endif; ?>

<?php if (($_GET['error'] ?? '') === 'out_of_stock'): ?>
<div class="alert alert-danger">
  在庫切れのため、カート内の商品を申込みできませんでした。数量を見直してください。
</div>
<?php endif; ?>

<div id="stock-adjust" class="alert alert-warning d-none"></div>

<div id="cart-empty" class="d-none">
  <div class="alert alert-info">
    商品が選択されていません。商品一覧で数量を入力してください。
  </div>
  <a href="shop.php" class="btn btn-primary">商品一覧へ戻る</a>
</div>

<div id="cart-body" class="d-none">

  <p class="text-muted small">
    内容をご確認ください。数量の変更・削除はこの画面で行えます。
  </p>

  <div class="table-responsive">
    <table class="table table-bordered bg-white align-middle">
      <thead class="table-light">
        <tr>
          <th>商品</th>
          <th class="text-end">単価</th>
          <th style="width: 190px;">数量</th>
          <th class="text-end">小計</th>
          <th style="width: 90px;"></th>
        </tr>
      </thead>
      <tbody id="cart-rows"></tbody>
      <tfoot>
        <tr class="table-light">
          <th colspan="3" class="text-end">合計</th>
          <th class="text-end fs-5 text-danger" id="cart-total"><?= h(yen(0)) ?></th>
          <th></th>
        </tr>
      </tfoot>
    </table>
  </div>

  <form action="checkout.php" method="post" id="checkout-form">
    <input type="hidden" name="cart" id="cart-field">
    <div class="card shadow-sm mt-3">
      <div class="card-body">
        <label for="remarks" class="form-label fw-bold">備考欄</label>
        <textarea class="form-control" id="remarks" name="remarks" rows="3"
                  maxlength="1000" placeholder="ご要望などがあればご記入ください"></textarea>
      </div>
    </div>
    <div class="d-flex justify-content-between mt-3">
      <a href="shop.php" class="btn btn-outline-secondary">商品一覧へ戻る</a>
      <button type="submit" class="btn btn-success btn-lg">
        <?= $editing !== null ? '申込みを更新する' : '申し込む' ?>
      </button>
    </div>
  </form>
</div>

<!-- 行テンプレート -->
<template id="cart-row-tpl">
  <tr>
    <td>
      <img alt="" width="48" height="48" style="object-fit: cover;" class="rounded me-2 row-img">
      <span class="row-name"></span>
      <div class="small text-muted row-remaining d-none">在庫数：<span></span></div>
    </td>
    <td class="text-end row-price"></td>
    <td>
      <div class="input-group input-group-sm">
        <button class="btn btn-outline-secondary qty-minus" type="button" aria-label="減らす">&minus;</button>
        <input type="number" class="form-control text-center qty-input"
               min="0" inputmode="numeric" aria-label="数量">
        <button class="btn btn-outline-secondary qty-plus" type="button" aria-label="増やす">&plus;</button>
      </div>
    </td>
    <td class="text-end row-subtotal"></td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger row-remove">削除</button>
    </td>
  </tr>
</template>

<!-- 編集中の申込みで最後の商品を削除する際の確認モーダル -->
<div class="modal fade" id="cancel-order-modal" tabindex="-1" aria-labelledby="cancel-order-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cancel-order-modal-title">申込みのキャンセル確認</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body">
        カート最後の商品を削除しようとしています。申込みのキャンセルをご希望ですか?
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">戻る</button>
        <button type="button" class="btn btn-danger" id="cancel-order-confirm-btn">申込みをキャンセルする</button>
      </div>
    </div>
  </div>
</div>

<!-- 申込みキャンセル送信用 (非表示) -->
<form method="post" action="cancel_order.php" id="cancel-order-form" class="d-none"></form>

<script>
(function () {
  if (!window.qrCart) return;

  var catalog = {};   // id -> {id,name,price,picture,remaining}
  var order = [];     // 表示順 (display_order, id) の id 一覧
  try {
    (JSON.parse(document.getElementById('catalog').textContent) || []).forEach(function (it) {
      catalog[it.id] = it;
      order.push(String(it.id));
    });
  } catch (e) {}

  var editing = document.getElementById('editing-order-cart') !== null;

  var rowsEl   = document.getElementById('cart-rows');
  var tpl      = document.getElementById('cart-row-tpl');
  var bodyEl   = document.getElementById('cart-body');
  var emptyEl  = document.getElementById('cart-empty');
  var totalEl  = document.getElementById('cart-total');
  var adjustEl = document.getElementById('stock-adjust');
  var remarksEl = document.getElementById('remarks');

  // public/lib/bootstrap.php の yen() と同じ書式
  var PRICE_PREFIX = <?= json_encode(setting('price_prefix'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  var PRICE_SUFFIX = <?= json_encode(setting('price_suffix'), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  function yen(n) {
    return PRICE_PREFIX + String(Math.round(n)).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + '円' + PRICE_SUFFIX;
  }
  function maxFor(it, qty) {
    if (typeof it.remaining !== 'number') return 99;
    return Math.min(99, Math.max(it.remaining, qty));
  }

  // ---- 最後の商品削除 → 申込みキャンセル 確認モーダル ----
  // bootstrap.bundle.min.js は footer.php でこの後に読み込まれるため生成を遅延する。
  var cancelModalEl = document.getElementById('cancel-order-modal');
  var cancelModal = null;
  var pendingConfirm = null;
  var pendingCancel = null;

  function getCancelModal() {
    if (!cancelModal && cancelModalEl && window.bootstrap) {
      cancelModal = new bootstrap.Modal(cancelModalEl);
      document.getElementById('cancel-order-confirm-btn').addEventListener('click', function () {
        var fn = pendingConfirm;
        pendingConfirm = null;
        pendingCancel = null;
        cancelModal.hide();
        if (fn) fn();
      });
      cancelModalEl.addEventListener('hidden.bs.modal', function () {
        var fn = pendingCancel;
        pendingConfirm = null;
        pendingCancel = null;
        if (fn) fn();
      });
    }
    return cancelModal;
  }

  function confirmCancelOrder(onConfirm, onCancel) {
    var modal = getCancelModal();
    if (!modal) {
      if (window.confirm('この申込みをキャンセルしますか?')) { onConfirm(); }
      else if (onCancel) { onCancel(); }
      return;
    }
    pendingConfirm = onConfirm;
    pendingCancel = onCancel || null;
    modal.show();
  }

  function submitCancelOrder() {
    document.getElementById('cancel-order-form').submit();
  }

  // ---- 在庫超過分をクライアント側で調整 ----
  function applyStockAdjustments() {
    var cart = qrCart.get();
    var msgs = [];
    for (var id in cart) {
      if (!Object.prototype.hasOwnProperty.call(cart, id)) continue;
      var it = catalog[id];
      if (!it || typeof it.remaining !== 'number') continue;
      if (cart[id] > it.remaining) {
        msgs.push(it.name + 'は在庫不足のため数量を' + cart[id] + '個→' + Math.max(0, it.remaining) + '個に調整しました。');
        qrCart.setQty(id, it.remaining);
      }
    }
    if (msgs.length) {
      adjustEl.innerHTML = '';
      msgs.forEach(function (m) {
        var d = document.createElement('div');
        d.textContent = m;
        adjustEl.appendChild(d);
      });
      adjustEl.classList.remove('d-none');
    }
  }

  // ---- 描画 ----
  function render() {
    var cart = qrCart.get();
    var ids = order.filter(function (id) { return cart[id]; });

    if (!ids.length) {
      bodyEl.classList.add('d-none');
      emptyEl.classList.remove('d-none');
      qrCart.refreshBadge();
      return;
    }
    emptyEl.classList.add('d-none');
    bodyEl.classList.remove('d-none');

    rowsEl.innerHTML = '';
    var total = 0;
    ids.forEach(function (id) {
      var it = catalog[id];
      var qty = cart[id];
      var frag = tpl.content.cloneNode(true);
      var tr = frag.querySelector('tr');
      tr.setAttribute('data-item-id', id);

      frag.querySelector('.row-img').src = it.picture;
      frag.querySelector('.row-name').textContent = it.name;
      if (typeof it.remaining === 'number') {
        var rem = frag.querySelector('.row-remaining');
        rem.classList.remove('d-none');
        rem.querySelector('span').textContent = it.remaining;
      }
      frag.querySelector('.row-price').textContent = yen(it.price);

      if (editing && ids.length === 1) tr.setAttribute('data-last-item', '1');

      var input = frag.querySelector('.qty-input');
      input.value = qty;
      input.setAttribute('max', String(maxFor(it, qty)));
      input.setAttribute('data-item-id', id);

      var sub = it.price * qty;
      total += sub;
      frag.querySelector('.row-subtotal').textContent = yen(sub);

      rowsEl.appendChild(frag);
    });
    totalEl.textContent = yen(total);
    qrCart.refreshBadge();
  }

  function recalcTotal() {
    var cart = qrCart.get();
    var total = 0;
    for (var id in cart) {
      if (!Object.prototype.hasOwnProperty.call(cart, id)) continue;
      if (catalog[id]) total += catalog[id].price * cart[id];
    }
    totalEl.textContent = yen(total);
    qrCart.refreshBadge();
  }

  function updateRow(tr) {
    var id = tr.getAttribute('data-item-id');
    var it = catalog[id];
    var input = tr.querySelector('.qty-input');
    var max = parseInt(input.getAttribute('max'), 10) || 99;
    var qty = parseInt(input.value, 10);
    if (isNaN(qty) || qty < 0) qty = 0;
    if (qty > max) qty = max;
    input.value = qty;

    if (qty === 0) {
      if (editing && tr.getAttribute('data-last-item') === '1') {
        confirmCancelOrder(submitCancelOrder, function () { render(); });
        return;
      }
      qrCart.setQty(id, 0);
      render();
      return;
    }
    qrCart.setQty(id, qty);
    tr.querySelector('.row-subtotal').textContent = yen(it.price * qty);
    recalcTotal();
  }

  rowsEl.addEventListener('click', function (e) {
    var btn = e.target.closest('.qty-plus, .qty-minus, .row-remove');
    if (!btn) return;
    var tr = btn.closest('tr');
    var input = tr.querySelector('.qty-input');
    if (btn.classList.contains('row-remove')) {
      input.value = 0;
    } else {
      var v = parseInt(input.value, 10);
      if (isNaN(v)) v = 0;
      input.value = v + (btn.classList.contains('qty-plus') ? 1 : -1);
    }
    updateRow(tr);
  });

  rowsEl.addEventListener('change', function (e) {
    if (!e.target.classList.contains('qty-input')) return;
    updateRow(e.target.closest('tr'));
  });

  // 備考欄
  if (remarksEl) {
    remarksEl.value = qrCart.remarks();
    remarksEl.addEventListener('input', function () {
      qrCart.setRemarks(remarksEl.value);
    });
  }

  // 送信直前にカート JSON を hidden フィールドへ
  document.getElementById('checkout-form').addEventListener('submit', function () {
    document.getElementById('cart-field').value = qrCart.toJSON();
  });

  applyStockAdjustments();
  render();
})();
</script>

<?php require __DIR__ . '/lib/footer.php'; ?>
