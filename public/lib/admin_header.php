<?php
/**
 * 管理画面 共通ヘッダー
 * 呼び出し側で以下の変数を設定してから include する:
 *   $pageTitle (string)        ページタイトル
 *   $active    (string, 省略可) 'orders' | 'users' | 'items' | 'settings'
 */
$pageTitle   = $pageTitle ?? '管理画面';
$active      = $active ?? '';
$assetPrefix = '../';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle) ?> - 管理画面</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand navbar-dark bg-dark mb-4">
  <div class="container">
    <span class="navbar-brand">管理画面</span>
    <ul class="navbar-nav me-auto">
      <li class="nav-item">
        <a class="nav-link<?= $active === 'orders' ? ' active' : '' ?>" href="index.php">申込み一覧</a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $active === 'users' ? ' active' : '' ?>" href="users.php">会員管理</a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $active === 'items' ? ' active' : '' ?>" href="items.php">商品管理</a>
      </li>
      <li class="nav-item">
        <a class="nav-link<?= $active === 'settings' ? ' active' : '' ?>" href="settings.php">設定</a>
      </li>
    </ul>
  </div>
</nav>
<div class="container pb-5">
