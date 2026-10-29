<?php
require_once __DIR__ . '/../lib/bootstrap.php';

$orderId = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare(
    'SELECT o.order_number, o.created_at, o.remarks, u.name AS user_name
     FROM orders o
     JOIN users u ON u.id = o.user_id
     WHERE o.id = ?'
);
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if ($order === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo '指定された申込みが見つかりません。';
    exit;
}

$stmt = db()->prepare(
    'SELECT item_name, quantity FROM order_items WHERE order_id = ? ORDER BY id'
);
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll();

$header  = setting('receipt_header');
$footer  = setting('receipt_footer');
$remarks = (string)($order['remarks'] ?? '');

$widthMm   = format_mm(receipt_width_mm());
$fitHeight = receipt_fit_height();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>レシート <?= h($order['order_number']) ?></title>
<style>
  /*
   * 用紙の幅は「設定」のレシート幅。用紙の長さは下のスクリプトが内容に合わせて
   * @page { size: 幅 長さ } を設定する。長さを合わせない設定のとき (またはブラウザ・
   * ドライバーが指定サイズに対応しないとき) は、プリンタードライバーの用紙設定に従う。
   */
  @page { margin: 0; }

  html, body { margin: 0; padding: 0; background: #fff; }

  body {
    width: <?= $widthMm ?>mm;
    font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif;
    color: #000;
    font-size: 12px;
    line-height: 1.5;
  }

  .sheet { padding: 3mm 3.5mm 7mm; }

  .msg { word-break: break-word; }
  .msg strong { font-weight: 700; }
  .rs-s  { font-size: 10px; }
  .rs-l  { font-size: 15px; }
  .rs-xl { font-size: 19px; }
  .hr { border-top: 1px dashed #000; margin: 2mm 0; }

  .row { margin: 0.4mm 0; }
  .label { font-weight: bold; }

  .items { width: 100%; border-collapse: collapse; }
  .items th, .items td { text-align: left; padding: 0.6mm 0; vertical-align: top; }
  .items .qty { text-align: right; white-space: nowrap; padding-left: 2mm; }
  .items thead th { border-bottom: 1px solid #000; }

  .remarks { white-space: pre-wrap; word-break: break-word; }

  /* 用紙の長さが足りず複数ページになる場合も、行の途中で切れないようにする */
  .row, .hr, .msg > div, .items tr { break-inside: avoid; }

  /* 画面表示時の操作バー (印刷時は非表示) */
  .toolbar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #f0f0f0; border-bottom: 1px solid #ccc;
    padding: 8px 12px; text-align: center; font-family: sans-serif;
  }
  .toolbar button { font-size: 14px; padding: 6px 18px; margin: 0 4px; cursor: pointer; }
  .screen-pad { height: 52px; }

  @media print {
    .toolbar, .screen-pad { display: none !important; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <button type="button" onclick="window.print()">印刷</button>
  <button type="button" onclick="window.close()">閉じる</button>
</div>
<div class="screen-pad"></div>

<div class="sheet">

  <?php if ($header !== ''): ?>
  <div class="msg"><?= receipt_markup($header) ?></div>
  <div class="hr"></div>
  <?php endif; ?>

  <div class="row"><span class="label">申込番号</span> <?= h($order['order_number']) ?></div>
  <div class="row"><span class="label">氏名</span> <?= h($order['user_name']) ?> 様</div>
  <div class="row"><span class="label">申込日時</span> <?= h($order['created_at']) ?></div>

  <div class="hr"></div>

  <table class="items">
    <thead>
      <tr><th>商品名</th><th class="qty">数量</th></tr>
    </thead>
    <tbody>
      <?php foreach ($orderItems as $oi): ?>
      <tr>
        <td><?= h($oi['item_name']) ?></td>
        <td class="qty"><?= (int)$oi['quantity'] ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="hr"></div>

  <div class="row"><span class="label">備考欄</span></div>
  <div class="remarks"><?= $remarks !== '' ? h($remarks) : '(記入なし)' ?></div>

  <?php if ($footer !== ''): ?>
  <div class="hr"></div>
  <div class="msg"><?= receipt_markup($footer) ?></div>
  <?php endif; ?>

</div>

<script>
  (function () {
    var WIDTH_MM   = <?= json_encode($widthMm) ?>;
    var FIT_HEIGHT = <?= $fitHeight ? 'true' : 'false' ?>;
    var PX_PER_MM  = 96 / 25.4; // CSS の 1px = 1/96 インチ

    // 用紙の長さをレシートの内容に合わせる
    function fitPageHeight() {
      if (!FIT_HEIGHT) {
        return;
      }
      var sheet = document.querySelector('.sheet');
      // 端数で2ページ目にはみ出さないよう、切り上げて 1mm の余裕を持たせる
      var heightMm = Math.ceil(sheet.getBoundingClientRect().height / PX_PER_MM) + 1;
      var style = document.createElement('style');
      style.textContent = '@page { size: ' + WIDTH_MM + 'mm ' + heightMm + 'mm; margin: 0; }';
      document.head.appendChild(style);
    }

    // ページを開いたら、フォントの読み込みを待って長さを測り、自動で印刷ダイアログを表示する
    window.addEventListener('load', function () {
      var ready = document.fonts && document.fonts.ready ? document.fonts.ready : Promise.resolve();
      ready.then(function () {
        fitPageHeight();
        window.print();
      });
    });
  })();
</script>

</body>
</html>
