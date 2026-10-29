<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// CSVエクスポート (GET → ダウンロード, 申込み明細を1行ずつ出力)
if (($_GET['action'] ?? '') === 'export') {
    $exportRows = db()->query(
        'SELECT o.order_number, o.created_at, o.cancelled_at, o.remarks,
                u.name AS user_name, u.qr_code,
                oi.item_name, oi.price, oi.quantity,
                i.misc1, i.misc2
         FROM orders o
         JOIN users u ON u.id = o.user_id
         LEFT JOIN order_items oi ON oi.order_id = o.id
         LEFT JOIN items i ON i.id = oi.item_id
         ORDER BY o.id DESC, oi.id'
    )->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    // 備考1・備考2 は商品マスタの任意項目 (現在の値。商品が削除済みなら空)
    fputcsv($out, ['申込番号', '申込日時', '氏名', 'QRコード値', '商品名', '数量', '単価', '小計', '状態', 'キャンセル日時', '備考欄', '備考1', '備考2']);
    foreach ($exportRows as $row) {
        $price    = $row['price'] !== null ? (int)$row['price'] : null;
        $quantity = $row['quantity'] !== null ? (int)$row['quantity'] : null;
        fputcsv($out, [
            $row['order_number'],
            $row['created_at'],
            $row['user_name'],
            $row['qr_code'],
            $row['item_name'],
            $quantity,
            $price,
            $price !== null && $quantity !== null ? $price * $quantity : null,
            $row['cancelled_at'] !== null ? 'キャンセル済み' : '通常',
            $row['cancelled_at'],
            $row['remarks'],
            $row['misc1'],
            $row['misc2'],
        ]);
    }
    fclose($out);
    exit;
}

$orders = db()->query(
    'SELECT o.id, o.order_number, o.created_at, o.cancelled_at, u.name AS user_name,
            COALESCE(SUM(oi.price * oi.quantity), 0) AS total
     FROM orders o
     JOIN users u ON u.id = o.user_id
     LEFT JOIN order_items oi ON oi.order_id = o.id
     GROUP BY o.id, o.order_number, o.created_at, o.cancelled_at, u.name
     ORDER BY o.id DESC'
)->fetchAll();

$pageTitle = '申込み一覧';
$active    = 'orders';
require __DIR__ . '/../lib/admin_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h4 mb-0">申込み一覧</h1>
  <a href="index.php?action=export" class="btn btn-outline-secondary">
    <span aria-hidden="true">📥</span> CSVエクスポート
  </a>
</div>

<?php if ($orders === []): ?>
<div class="alert alert-info">申込みはまだありません。</div>
<?php else: ?>

<div class="table-responsive">
  <table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th>申込番号</th>
        <th>氏名</th>
        <th>申込日時</th>
        <th class="text-end">合計金額</th>
        <th style="width: 140px;">状態</th>
        <th style="width: 150px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $order): ?>
      <tr>
        <td><?= h($order['order_number']) ?></td>
        <td><?= h($order['user_name']) ?></td>
        <td><?= h($order['created_at']) ?></td>
        <td class="text-end"><?= h(yen((int)$order['total'])) ?></td>
        <td>
          <?php if ($order['cancelled_at'] !== null): ?>
          <span class="badge bg-secondary">キャンセル済み</span>
          <div class="small text-muted"><?= h($order['cancelled_at']) ?></div>
          <?php endif; ?>
        </td>
        <td class="text-center text-nowrap">
          <a class="btn btn-sm btn-primary" href="order.php?id=<?= (int)$order['id'] ?>">詳細</a>
          <a class="btn btn-sm btn-outline-primary" href="receipt.php?id=<?= (int)$order['id'] ?>"
             target="_blank" rel="noopener" title="レシート印刷">🧾</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../lib/footer.php'; ?>
