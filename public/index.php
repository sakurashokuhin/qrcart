<?php
require_once __DIR__ . '/lib/bootstrap.php';

// ログイン済みなら商品一覧へ
if (current_user() !== null) {
    header('Location: shop.php');
    exit;
}

$error = isset($_GET['error']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ログイン - <?= h(site_title()) ?></title>
<link rel="stylesheet" href="assets/css/bootstrap.min.css">
<style>
#reader { width: 100%; max-width: 400px; margin: 0 auto; }
</style>
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 480px;">

  <h1 class="h3 text-center mb-4"><?= h(site_title()) ?> ログイン</h1>

  <?php if ($error): ?>
  <div class="alert alert-danger" role="alert">
    QRコードが登録されていません。もう一度お試しください。
  </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body text-center">
      <p class="card-text">お手持ちの会員QRコードをカメラにかざしてください。</p>
      <div id="reader" class="mb-3"></div>
      <button id="start-btn" class="btn btn-primary btn-lg w-100" type="button">
        カメラを起動してスキャン
      </button>
      <button id="stop-btn" class="btn btn-secondary w-100 d-none" type="button">
        カメラを停止
      </button>
      <button id="switch-btn" class="btn btn-outline-secondary w-100 mt-2 d-none" type="button">
        インカメラ/アウトカメラ切替
      </button>
      <div id="camera-error" class="alert alert-warning mt-3 d-none" role="alert"></div>
    </div>
  </div>

  <!-- スキャン結果送信用 (非表示) -->
  <form id="qr-form" action="login.php" method="post" class="d-none">
    <input type="hidden" name="qr_code" id="qr_code" value="">
  </form>

  <!-- カメラが使えない場合の手入力 (ハンディスキャナ等にも対応) -->
  <div class="card shadow-sm">
    <div class="card-body">
      <p class="card-text small text-muted mb-2">
        カメラが使用できない場合は、QRコードの値を直接入力してください。
      </p>
      <form action="login.php" method="post" class="d-flex gap-2">
        <input type="text" name="qr_code" class="form-control" placeholder="QRコードの値" required autocomplete="off">
        <button class="btn btn-outline-primary" type="submit">ログイン</button>
      </form>
    </div>
  </div>

</div>

<script>
// ログイン画面ではカート (localStorage) を必ず空にする (ログアウト後の残留対策)
try {
  localStorage.removeItem('qrcart_cart');
  localStorage.removeItem('qrcart_remarks');
} catch (e) {}
</script>
<script src="assets/js/html5-qrcode.min.js"></script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
  var scanner = null;
  // 最初に使うカメラ (管理画面の「設定」で変更可): 'user' = インカメラ / 'environment' = アウトカメラ
  var facingMode = <?= json_encode(login_camera()) ?>;
  var startBtn = document.getElementById('start-btn');
  var stopBtn = document.getElementById('stop-btn');
  var switchBtn = document.getElementById('switch-btn');
  var errorBox = document.getElementById('camera-error');

  function showError(msg) {
    errorBox.textContent = msg;
    errorBox.classList.remove('d-none');
  }

  function onScanSuccess(decodedText) {
    // 多重送信を防ぐためカメラを止めてからフォーム送信
    scanner.stop().catch(function () {}).then(function () {
      document.getElementById('qr_code').value = decodedText;
      document.getElementById('qr-form').submit();
    });
  }

  function startScanner(isFallback) {
    errorBox.classList.add('d-none');
    if (!scanner) {
      scanner = new Html5Qrcode('reader');
    }
    return scanner.start(
      { facingMode: facingMode },
      { fps: 10, qrbox: { width: 250, height: 250 } },
      onScanSuccess
    ).then(function () {
      startBtn.classList.add('d-none');
      stopBtn.classList.remove('d-none');
      switchBtn.classList.remove('d-none');
    }).catch(function (err) {
      // 指定したカメラが存在しない端末向けに、もう一方のカメラへ自動フォールバック
      if (!isFallback) {
        facingMode = facingMode === 'user' ? 'environment' : 'user';
        return startScanner(true);
      }
      showError('カメラを起動できませんでした。HTTPS でアクセスしているか、' +
        'カメラの使用が許可されているかを確認してください。(' + err + ')');
    });
  }

  // startScanner に click イベントが渡らないようにする (isFallback と誤認されるため)
  startBtn.addEventListener('click', function () { startScanner(false); });

  stopBtn.addEventListener('click', function () {
    if (scanner) {
      scanner.stop().catch(function () {});
    }
    stopBtn.classList.add('d-none');
    switchBtn.classList.add('d-none');
    startBtn.classList.remove('d-none');
  });

  switchBtn.addEventListener('click', function () {
    facingMode = facingMode === 'environment' ? 'user' : 'environment';
    if (scanner) {
      scanner.stop().catch(function () {}).then(function () { startScanner(false); });
    }
  });
})();
</script>
</body>
</html>
