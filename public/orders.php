<?php
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login();

// 「編集」「新しく申込み」の選択 (POST → リダイレクト)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'new') {
        $_SESSION['cart_bootstrap'] = ['action' => 'clear'];
        unset($_SESSION['editing_order']);
        header('Location: shop.php');
        exit;
    }

    if ($action === 'edit') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = db()->prepare(
            'SELECT id, order_number, remarks FROM orders WHERE id = ? AND user_id = ? AND cancelled_at IS NULL'
        );
        $stmt->execute([$orderId, (int)$user['id']]);
        $order = $stmt->fetch();

        if ($order !== false) {
            // 申込み内容をカートに読み込む (商品マスタから削除済みの商品は除外)
            $stmt = db()->prepare(
                'SELECT oi.item_id, oi.quantity
                 FROM order_items oi
                 JOIN items i ON i.id = oi.item_id
                 WHERE oi.order_id = ?'
            );
            $stmt->execute([$orderId]);

            $cart = [];
            foreach ($stmt->fetchAll() as $row) {
                $cart[(int)$row['item_id']] = min(99, (int)$row['quantity']);
            }

            // 編集対象の内容をクライアントのカートに読み込ませる
            $_SESSION['cart_bootstrap'] = [
                'action'  => 'load',
                'cart'    => $cart,
                'remarks' => (string)($order['remarks'] ?? ''),
            ];
            $_SESSION['editing_order'] = [
                'id'            => (int)$order['id'],
                'order_number'  => $order['order_number'],
                'original_cart' => $cart,
            ];
            header('Location: cart.php');
            exit;
        }
    }

    header('Location: orders.php');
    exit;
}

$orders = db()->prepare(
    'SELECT o.id, o.order_number, o.created_at, o.cancelled_at,
            COALESCE(SUM(oi.price * oi.quantity), 0) AS total,
            COALESCE(SUM(oi.quantity), 0) AS quantity
     FROM orders o
     LEFT JOIN order_items oi ON oi.order_id = o.id
     WHERE o.user_id = ?
     GROUP BY o.id, o.order_number, o.created_at, o.cancelled_at
     ORDER BY o.id DESC'
);
$orders->execute([(int)$user['id']]);
$orders = $orders->fetchAll();

$messages = [
    'cancelled' => '申込みをキャンセルしました。',
];
$message = $messages[$_GET['msg'] ?? ''] ?? null;

$pageTitle = '申込履歴 - ' . site_title();
require __DIR__ . '/lib/header.php';
?>

<h1 class="h4 mb-3">申込履歴</h1>

<?php if ($message !== null): ?>
<div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>

<?php if ($orders === []): ?>

<div class="alert alert-info">ご申込みはまだありません。</div>
<a href="shop.php" class="btn btn-primary btn-lg">商品一覧へ進む</a>

<?php else: ?>

<div class="alert alert-info">
  以前のご申込みがあります。内容を変更する場合は「編集」を、
  追加で申込む場合は「追加で申込む」を押してください。
</div>

<form method="post" class="mb-4">
  <input type="hidden" name="action" value="new">
  <button type="submit" class="btn btn-success btn-lg px-5 fw-bold">
    <span aria-hidden="true">🆕</span> 追加で申込む
  </button>
</form>

<div class="table-responsive">
  <table class="table table-bordered bg-white align-middle">
    <thead class="table-light">
      <tr>
        <th>申込番号</th>
        <th>申込日時</th>
        <th class="text-end">点数</th>
        <th class="text-end">合計金額</th>
        <th style="width: 180px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($orders as $order): ?>
      <tr>
        <td class="fw-bold"><?= h($order['order_number']) ?></td>
        <td><?= h($order['created_at']) ?></td>
        <td class="text-end"><?= (int)$order['quantity'] ?>点</td>
        <td class="text-end"><?= h(yen((int)$order['total'])) ?></td>
        <td class="text-center">
          <?php if ($order['cancelled_at'] !== null): ?>
          <span class="badge bg-secondary">キャンセル済み</span>
          <div class="small text-muted"><?= h($order['cancelled_at']) ?></div>
          <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>">
            <button type="submit" class="btn btn-primary fw-bold">
              <span aria-hidden="true">✏️</span> 編集
            </button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require __DIR__ . '/lib/footer.php'; ?>
