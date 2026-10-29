<?php
require_once __DIR__ . '/../lib/bootstrap.php';

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

$stmt = db()->prepare('SELECT id, qr_code, name FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($user === false) {
    header('Location: users.php?error=notfound');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qrCode = trim((string)($_POST['qr_code'] ?? ''));
    $name   = trim((string)($_POST['name'] ?? ''));

    if ($qrCode === '' || $name === '') {
        $error = 'QRコード値と氏名を両方入力してください。';
    } elseif (!user_lengths_valid($qrCode, $name)) {
        $error = USER_TOO_LONG_MESSAGE;
    } else {
        try {
            db()->prepare('UPDATE users SET qr_code = ?, name = ? WHERE id = ?')
                ->execute([$qrCode, $name, $userId]);
            header('Location: users.php?msg=updated');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $error = 'そのQRコード値は既に別の会員に登録されています。';
            } else {
                throw $e;
            }
        }
    }
    // 入力値を保持して再表示
    $user['qr_code'] = $qrCode;
    $user['name']    = $name;
}

$pageTitle = '会員編集';
$active    = 'users';
require __DIR__ . '/../lib/admin_header.php';
?>

<h1 class="h4 mb-3">会員編集 (ID: <?= (int)$user['id'] ?>)</h1>

<?php if ($error !== null): ?>
<div class="alert alert-danger"><?= h($error) ?></div>
<?php endif; ?>

<div class="card shadow-sm" style="max-width: 560px;">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
      <div class="mb-3">
        <label class="form-label" for="edit-qr">QRコード値</label>
        <input type="text" class="form-control" id="edit-qr" name="qr_code" maxlength="255"
               value="<?= h($user['qr_code']) ?>" required autocomplete="off">
        <div class="form-text">変更すると既存の配布済みQRコードは使えなくなります。</div>
      </div>
      <div class="mb-3">
        <label class="form-label" for="edit-name">氏名</label>
        <input type="text" class="form-control" id="edit-name" name="name" maxlength="100"
               value="<?= h($user['name']) ?>" required autocomplete="off">
      </div>
      <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary">更新</button>
        <a href="users.php" class="btn btn-outline-secondary">キャンセル</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../lib/footer.php'; ?>
