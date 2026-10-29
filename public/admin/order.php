<?php
require_once __DIR__ . '/../lib/bootstrap.php';

$orderId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT o.id, o.order_number, o.created_at, o.remarks, o.cancelled_at, u.qr_code, u.name AS user_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order === false) {
    http_response_code(404);
    $notFound = true;
} else {
    $stmt = db()->prepare(
        'SELECT item_name, price, quantity FROM order_items WHERE order_id = ? ORDER BY id'
    );
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll();

    $grandTotal = 0;
    foreach ($orderItems as $oi) {
        $grandTotal += (int)$oi['price'] * (int)$oi['quantity'];
    }
}

$pageTitle = '申込み詳細';
$active    = 'orders';
require __DIR__ . '/../lib/admin_header.php';
?>

<?php if (!empty($notFound)): ?>

<div class="alert alert-danger">指定された申込みが見つかりません。</div>
<a href="index.php" class="btn btn-secondary">申込み一覧へ戻る</a>

<?php else: ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">申込み詳細</h1>
  <a href="receipt.php?id=<?= (int)$order['id'] ?>" target="_blank" rel="noopener"
     class="btn btn-outline-primary">
    <span aria-hidden="true">🧾</span> レシート印刷
  </a>
</div>

<div style="max-width: 720px;">

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">申込み情報</div>
    <div class="card-body p-0">
      <table class="table table-bordered mb-0">
        <tbody>
          <tr>
            <th class="table-light" style="width: 200px;">申込番号</th>
            <td><?= h($order['order_number']) ?></td>
          </tr>
          <tr>
            <th class="table-light">QRコード値</th>
            <td><code><?= h($order['qr_code']) ?></code></td>
          </tr>
          <tr>
            <th class="table-light">氏名</th>
            <td><?= h($order['user_name']) ?></td>
          </tr>
          <tr>
            <th class="table-light">申込日時</th>
            <td><?= h($order['created_at']) ?></td>
          </tr>
          <tr>
            <th class="table-light">状態</th>
            <td>
              <?php if ($order['cancelled_at'] !== null): ?>
              <span class="badge bg-secondary">キャンセル済み</span>
              <span class="text-muted ms-2"><?= h($order['cancelled_at']) ?></span>
              <?php else: ?>
              <span class="badge bg-success">通常</span>
              <?php endif; ?>
            </td>
          </tr>
          <tr>
            <th class="table-light">備考欄</th>
            <td><?= (string)($order['remarks'] ?? '') !== '' ? nl2br(h($order['remarks'])) : '<span class="text-muted">(記入なし)</span>' ?></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card shadow-sm mb-4">
    <div class="card-header fw-bold">申込み内容</div>
    <div class="card-body p-0">
      <table class="table table-bordered mb-0 align-middle">
        <thead class="table-light">
          <tr>
            <th>商品名</th>
            <th class="text-end" style="width: 120px;">単価</th>
            <th class="text-end" style="width: 80px;">数量</th>
            <th class="text-end" style="width: 140px;">小計</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($orderItems as $oi): ?>
          <tr>
            <td><?= h($oi['item_name']) ?></td>
            <td class="text-end"><?= h(yen((int)$oi['price'])) ?></td>
            <td class="text-end"><?= (int)$oi['quantity'] ?></td>
            <td class="text-end"><?= h(yen((int)$oi['price'] * (int)$oi['quantity'])) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <th colspan="3" class="text-end table-light">合計金額</th>
            <th class="text-end fs-5 text-danger"><?= h(yen($grandTotal)) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <a href="index.php" class="btn btn-secondary">申込み一覧へ戻る</a>

</div>

<?php endif; ?>

<?php require __DIR__ . '/../lib/footer.php'; ?>
