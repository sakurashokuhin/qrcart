<?php
/**
 * 共通ヘッダー
 * 呼び出し側で以下の変数を設定してから include する:
 *   $pageTitle (string)         ページタイトル
 *   $showNav   (bool, 省略可)   ナビゲーションバーを表示するか (既定: true)
 *   $navActive (string, 省略可) 現在のページ ('shop' | 'cart')
 */
$pageTitle = $pageTitle ?? site_title();
$showNav   = $showNav ?? true;
$navActive = $navActive ?? '';
$navUser   = current_user();

// カートはクライアント (localStorage) が保持する。サーバーからは一度きりの
// 指示 (cart_bootstrap) を JSON で渡し、cart.js が localStorage に反映する。
$cartBootstrap = $_SESSION['cart_bootstrap'] ?? null;
unset($_SESSION['cart_bootstrap']);
$editingOriginalCart = isset($_SESSION['editing_order'])
    ? ($_SESSION['editing_order']['original_cart'] ?? [])
    : null;
$jsonFlags = JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

// 現在のページのボタンを白い枠で囲んで強調する
$activeStyle = 'outline: 3px solid #fff; outline-offset: 3px;';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?></title>
<link rel="stylesheet" href="<?= h($assetPrefix ?? '') ?>assets/css/bootstrap.min.css">
</head>
<body class="bg-light">
<?php if ($navUser !== null): ?>
<?php if ($cartBootstrap !== null): ?>
<script id="cart-bootstrap" type="application/json"><?= json_encode($cartBootstrap, $jsonFlags) ?></script>
<?php endif; ?>
<?php if ($editingOriginalCart !== null): ?>
<script id="editing-order-cart" type="application/json"><?= json_encode((object)$editingOriginalCart, $jsonFlags) ?></script>
<?php endif; ?>
<script src="<?= h(($assetPrefix ?? '') . asset_url('assets/js/cart.js')) ?>"></script>
<?php endif; ?>
<?php if ($showNav && $navUser !== null): ?>
<nav class="navbar navbar-expand navbar-dark bg-primary mb-4 sticky-top shadow-sm">
  <div class="container flex-wrap">
    <a class="navbar-brand" href="shop.php"><?= h(site_title()) ?></a>
    <ul class="navbar-nav align-items-center flex-wrap gap-3 py-2">
      <li class="nav-item">
        <a class="btn btn-light btn-lg fw-bold px-4" href="shop.php"
           <?= $navActive === 'shop' ? 'style="' . $activeStyle . '" aria-current="page"' : '' ?>>
          <span aria-hidden="true">🍱</span> 商品一覧
        </a>
      </li>
      <li class="nav-item me-2">
        <a class="btn btn-warning btn-lg fw-bold px-4 position-relative" href="cart.php"
           <?= $navActive === 'cart' ? 'style="' . $activeStyle . '" aria-current="page"' : '' ?>>
          <span aria-hidden="true">🛒</span> カート確認
          <span id="cart-badge"
                class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger fs-6 d-none">0</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="orders.php">申込履歴</a>
      </li>
    </ul>
    <ul class="navbar-nav ms-auto align-items-center">
      <li class="nav-item text-white">
        <?= h($navUser['name']) ?> 様
      </li>
      <!-- 誤操作によるログアウトを防ぐためのスペーサー -->
      <li class="nav-item" style="width: 3.5rem;" aria-hidden="true"></li>
      <li class="nav-item">
        <button type="button" class="btn btn-outline-light btn-sm"
                data-bs-toggle="modal" data-bs-target="#logout-modal">ログアウト</button>
      </li>
    </ul>
  </div>
</nav>

<!-- ログアウト確認モーダル -->
<div class="modal fade" id="logout-modal" tabindex="-1" aria-labelledby="logout-modal-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="logout-modal-title">ログアウト確認</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body">
        ログアウトしますか?<br>
        <small class="text-muted">選択中の商品は破棄されます。</small>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">キャンセル</button>
        <a href="logout.php" class="btn btn-danger">ログアウトする</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>
<div class="container pb-5">
