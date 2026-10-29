<?php
/**
 * 編集中の申込みをキャンセルする (ソフトデリート)。
 * カート確認画面で編集中の申込みの最後の商品を削除したときに呼ばれる。
 * POST のみ。
 */
require_once __DIR__ . '/lib/bootstrap.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: cart.php');
    exit;
}

$editing = $_SESSION['editing_order'] ?? null;
if ($editing !== null) {
    db()->prepare(
        'UPDATE orders SET cancelled_at = NOW() WHERE id = ? AND user_id = ? AND cancelled_at IS NULL'
    )->execute([(int)$editing['id'], (int)$user['id']]);
    unset($_SESSION['editing_order']);
}

$_SESSION['cart_bootstrap'] = ['action' => 'clear'];
header('Location: orders.php?msg=cancelled');
exit;
