<?php
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

// カートはクライアント (localStorage) から JSON で送られてくる
$cart = parse_posted_cart((string)($_POST['cart'] ?? ''));
if ($cart === []) {
    header('Location: shop.php');
    exit;
}

$pdo = db();

// カート内商品の最新情報を取得
$placeholders = implode(',', array_fill(0, count($cart), '?'));
$stmt = $pdo->prepare("SELECT id, name, price FROM items WHERE id IN ($placeholders)");
$stmt->execute(array_keys($cart));
$items = $stmt->fetchAll();

if ($items === []) {
    header('Location: shop.php');
    exit;
}

$remarks = trim((string)($_POST['remarks'] ?? ''));
if (mb_strlen($remarks) > 1000) {
    $remarks = mb_substr($remarks, 0, 1000);
}

$editing  = $_SESSION['editing_order'] ?? null;
$isUpdate = false;

$pdo->beginTransaction();
try {
    if ($editing !== null) {
        // 既存申込みの編集: 本人の申込みであることを確認してから上書きする
        $stmt = $pdo->prepare(
            'SELECT order_number FROM orders WHERE id = ? AND user_id = ? AND cancelled_at IS NULL'
        );
        $stmt->execute([(int)$editing['id'], (int)$user['id']]);
        $row = $stmt->fetch();
        if ($row !== false) {
            $isUpdate    = true;
            $orderId     = (int)$editing['id'];
            $orderNumber = $row['order_number'];
            // 申込日時は最終確定時刻に更新する
            $pdo->prepare('UPDATE orders SET remarks = ?, created_at = NOW() WHERE id = ?')
                ->execute([$remarks, $orderId]);
            $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')
                ->execute([$orderId]);
        }
    }

    if (!$isUpdate) {
        $pdo->prepare('INSERT INTO orders (order_number, user_id, remarks) VALUES (?, ?, ?)')
            ->execute(['', (int)$user['id'], $remarks]);
        $orderId = (int)$pdo->lastInsertId();

        // 申込番号: 日付 + 申込みID (例: 20260610-00012)
        $orderNumber = date('Ymd') . '-' . str_pad((string)$orderId, 5, '0', STR_PAD_LEFT);
        $pdo->prepare('UPDATE orders SET order_number = ? WHERE id = ?')
            ->execute([$orderNumber, $orderId]);
    }

    // 在庫を再確認し、不足分は申込み可能な数量まで調整する
    $stockMap = remaining_stock($pdo, $orderId);
    $stockAdjustments = [];
    $insertRows = [];

    foreach ($items as $item) {
        $desired   = (int)$cart[$item['id']];
        $remaining = $stockMap[(int)$item['id']]['remaining'] ?? null;
        $allowed   = $remaining === null ? $desired : min($desired, max(0, $remaining));

        if ($allowed < $desired) {
            $stockAdjustments[] = ['name' => $item['name'], 'requested' => $desired, 'allowed' => $allowed];
        }
        if ($allowed > 0) {
            $insertRows[] = ['item' => $item, 'quantity' => $allowed];
        }
    }

    if ($insertRows === []) {
        // 在庫切れで申込み可能な商品が一つもなくなった場合は確定させない
        $pdo->rollBack();
        header('Location: cart.php?error=out_of_stock');
        exit;
    }

    // 商品名と価格は申込み時点の値を保存する
    $insertItem = $pdo->prepare(
        'INSERT INTO order_items (order_id, item_id, item_name, price, quantity)
         VALUES (?, ?, ?, ?, ?)'
    );
    foreach ($insertRows as $row) {
        $insertItem->execute([
            $orderId,
            (int)$row['item']['id'],
            $row['item']['name'],
            (int)$row['item']['price'],
            $row['quantity'],
        ]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

if ($stockAdjustments !== []) {
    $_SESSION['stock_warning'] = $stockAdjustments;
}

// 完了画面 (header.php) でクライアントのカートをクリアさせる
$_SESSION['cart_bootstrap'] = ['action' => 'clear'];
unset($_SESSION['editing_order']);

$stockWarning = $_SESSION['stock_warning'] ?? [];
unset($_SESSION['stock_warning']);

// --- Google Chat へ通知 (設定されていれば) ---
// 印刷担当者がすぐレシートを出せるよう、申込み確定 (新規・変更) をチャットに流す。
// 通知の失敗で完了画面を止めないよう、全体を try/catch で囲む。
try {
    $notifyCount = 0;
    $notifyTotal = 0;
    foreach ($insertRows as $r) {
        $notifyCount += $r['quantity'];
        $notifyTotal += (int)$r['item']['price'] * $r['quantity'];
    }
    $base = site_base_url();
    $msg = [
        ($isUpdate ? '*✏️ 申込み変更*  ' : '*🧾 新規申込み*  ') . $orderNumber,
        '氏名: ' . $user['name'] . ' 様',
        '点数: ' . $notifyCount . '点 ／ 合計: ' . yen($notifyTotal),
    ];
    if ($remarks !== '') {
        $msg[] = '備考: ' . preg_replace('/\s+/', ' ', $remarks);
    }
    $msg[] = 'レシート印刷: <' . $base . '/admin/receipt.php?id=' . $orderId . '|開く>'
           . '  ／  詳細: <' . $base . '/admin/order.php?id=' . $orderId . '|申込み詳細>';
    notify_chat(implode("\n", $msg));
} catch (Throwable $e) {
    app_log('warning', 'checkout notify error: ' . $e->getMessage());
}

$pageTitle = $isUpdate ? '申込み更新完了 - ' . site_title() : '申込み完了 - ' . site_title();
$showNav   = false;
require __DIR__ . '/lib/header.php';
?>

<div class="text-center py-5" style="max-width: 480px; margin: 0 auto;">
  <?php if ($stockWarning !== []): ?>
  <div class="alert alert-warning text-start">
    <?php foreach ($stockWarning as $adj): ?>
    <div><?= h($adj['name']) ?>は在庫切れにより<?= (int)$adj['requested'] ?>個→<?= (int)$adj['allowed'] ?>個申込みしました。</div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="card shadow-sm">
    <div class="card-body py-5">
      <h1 class="h4 mb-4"><?= $isUpdate ? '申込み内容を更新しました' : 'ご申込みありがとうございました' ?></h1>
      <p class="mb-1">申込番号</p>
      <p class="display-6 fw-bold text-primary mb-4"><?= h($orderNumber) ?></p>
      <p class="text-muted"><?= h($user['name']) ?> 様の申込みを受け付けました。</p>
      <a href="logout.php" class="btn btn-primary btn-lg w-100 mt-3">ログアウト</a>
    </div>
  </div>
</div>

<?php require __DIR__ . '/lib/footer.php'; ?>
