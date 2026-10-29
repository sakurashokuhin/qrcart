<?php
require_once __DIR__ . '/lib/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$code = trim((string)($_POST['qr_code'] ?? ''));
if ($code === '') {
    header('Location: index.php?error=1');
    exit;
}

$stmt = db()->prepare('SELECT id, qr_code, name FROM users WHERE qr_code = ?');
$stmt->execute([$code]);
$user = $stmt->fetch();

if ($user === false) {
    header('Location: index.php?error=1');
    exit;
}

// セッション固定攻撃対策
session_regenerate_id(true);
$_SESSION['user'] = $user;
// カートはクライアント (localStorage) 保持。次の画面で cart.js にクリアさせる。
$_SESSION['cart_bootstrap'] = ['action' => 'clear'];
unset($_SESSION['editing_order']);

// 既存の申込みがある場合は申込履歴ページへ (編集 or 新規申込みを選択)
$stmt = db()->prepare('SELECT COUNT(*) FROM orders WHERE user_id = ?');
$stmt->execute([(int)$user['id']]);
$hasOrders = (int)$stmt->fetchColumn() > 0;

header('Location: ' . ($hasOrders ? 'orders.php' : 'shop.php'));
exit;
