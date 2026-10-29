<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// CSVエクスポート (GET → ダウンロード)
if (($_GET['action'] ?? '') === 'export') {
    $exportRows = db()->query(
        'SELECT u.id, u.qr_code, u.name, COUNT(o.id) AS order_count
         FROM users u
         LEFT JOIN orders o ON o.user_id = u.id
         GROUP BY u.id, u.qr_code, u.name
         ORDER BY u.id'
    )->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'qr_code', 'name', 'order_count']);
    foreach ($exportRows as $exportRow) {
        fputcsv($out, [$exportRow['id'], $exportRow['qr_code'], $exportRow['name'], $exportRow['order_count']]);
    }
    fclose($out);
    exit;
}

// CSVアップロード(全置換)用テンプレート (GET → ダウンロード)
if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="users_template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['qr_code', 'name']);
    fputcsv($out, ['MEMBER-0001-7f3a9c', '山田 太郎']);
    fclose($out);
    exit;
}

// 追加・削除 (POST → リダイレクト)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $qrCode = trim((string)($_POST['qr_code'] ?? ''));
        $name   = trim((string)($_POST['name'] ?? ''));
        if ($qrCode === '' || $name === '') {
            header('Location: users.php?error=empty');
            exit;
        }
        if (!user_lengths_valid($qrCode, $name)) {
            header('Location: users.php?error=too_long');
            exit;
        }
        try {
            db()->prepare('INSERT INTO users (qr_code, name) VALUES (?, ?)')
                ->execute([$qrCode, $name]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: users.php?error=duplicate');
                exit;
            }
            throw $e;
        }
        header('Location: users.php?msg=added');
        exit;
    }

    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        try {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
        } catch (PDOException $e) {
            // 申込履歴がある会員は外部キー制約により削除できない
            if ($e->getCode() === '23000') {
                header('Location: users.php?error=has_orders');
                exit;
            }
            throw $e;
        }
        header('Location: users.php?msg=deleted');
        exit;
    }

    header('Location: users.php');
    exit;
}

$replaceEnabled = setting('users_csv_replace_enabled') === '1';

$messages = [
    'added'   => '会員を追加しました。',
    'updated' => '会員情報を更新しました。',
    'deleted' => '会員を削除しました。',
    'import_cancelled' => 'CSVの取り込みをキャンセルしました (会員一覧は変更していません)。',
];
$errors = [
    'empty'       => 'QRコード値と氏名を両方入力してください。',
    'too_long'    => USER_TOO_LONG_MESSAGE,
    'duplicate'   => 'そのQRコード値は既に登録されています。',
    'has_orders'  => '申込履歴のある会員は削除できません。',
    'notfound'    => '指定された会員が見つかりません。',
    'csv_invalid_add'     => 'CSVファイルの読み込みに失敗しました。受付番号・会社名・氏名の列を含む来場者リストのCSVファイルを指定してください。',
    'csv_invalid_replace' => 'CSVファイルの読み込みに失敗しました。qr_code列とname列を含むCSVファイルを指定してください。',
    'csv_rows'    => 'CSVに不正な行があるため、取り込みを中止しました (会員一覧は変更していません)。以下の行を修正して再度アップロードしてください。',
    'csv_empty'   => 'CSVに会員の行が1行もないため、取り込みを中止しました (会員一覧は変更していません)。',
    'csv_replace_disabled' => 'CSVアップロード (全置換) は無効になっています。設定画面で有効にしてから実行してください。',
    'csv_stale'   => 'CSVをアップロードした後に会員一覧が変更されたため、取り込みを中止しました (会員一覧は変更していません)。もう一度CSVをアップロードしてください。',
];
$message = $messages[$_GET['msg'] ?? ''] ?? null;
$error   = $errors[$_GET['error'] ?? ''] ?? null;

// CSV取込で見つかった不正な行 (1回表示したら消す)
$csvErrors = [];
if (($_GET['error'] ?? '') === 'csv_rows') {
    $csvErrors = $_SESSION['users_csv_errors'] ?? [];
}
unset($_SESSION['users_csv_errors']);

$importSummary = null;
if (($_GET['msg'] ?? '') === 'import') {
    $added   = (int)($_GET['added'] ?? 0);
    $updated = (int)($_GET['updated'] ?? 0);
    $deleted = (int)($_GET['deleted'] ?? 0);
    $kept    = (int)($_GET['kept'] ?? 0);
    $importSummary = "CSVの内容を反映しました (追加{$added}件・氏名変更{$updated}件・削除{$deleted}件)。";
    if ($kept > 0) {
        $importSummary .= "申込履歴があるため削除できず残した会員: {$kept}件。";
    }
}

$users = db()->query(
    'SELECT u.id, u.qr_code, u.name, COUNT(o.id) AS order_count
     FROM users u
     LEFT JOIN orders o ON o.user_id = u.id
     GROUP BY u.id, u.qr_code, u.name
     ORDER BY u.id'
)->fetchAll();

// 追加フォームの初期値 (ランダムなQRコード値を提案)
$suggestedCode = 'MEMBER-' . bin2hex(random_bytes(6));

$pageTitle = '会員管理';
$active    = 'users';
require __DIR__ . '/../lib/admin_header.php';
?>

<h1 class="h4 mb-3">会員管理</h1>

<?php if ($message !== null): ?>
<div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($error !== null): ?>
<div class="alert alert-danger">
  <?= h($error) ?>
  <?php if ($csvErrors !== []): ?>
  <ul class="mb-0 mt-2 small">
    <?php foreach (array_slice($csvErrors, 0, 30) as $csvError): ?>
    <li><?= h($csvError) ?></li>
    <?php endforeach; ?>
    <?php if (count($csvErrors) > 30): ?>
    <li>ほか<?= count($csvErrors) - 30 ?>行</li>
    <?php endif; ?>
  </ul>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($importSummary !== null): ?>
<div class="alert alert-success"><?= h($importSummary) ?></div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">会員の追加</div>
  <div class="card-body">
    <form method="post" class="row g-2 align-items-start">
      <input type="hidden" name="action" value="add">
      <div class="col-12 col-md-5">
        <label class="form-label" for="add-qr">QRコード値</label>
        <input type="text" class="form-control" id="add-qr" name="qr_code" maxlength="255"
               value="<?= h($suggestedCode) ?>" required autocomplete="off">
        <div class="form-text">この文字列をQRコード化して会員に配布します。</div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="add-name">氏名</label>
        <input type="text" class="form-control" id="add-name" name="name" maxlength="100" required autocomplete="off">
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label d-none d-md-block">&nbsp;</label>
        <button type="submit" class="btn btn-primary w-100">追加</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-12 col-md-6">
    <div class="card shadow-sm h-100">
      <div class="card-header fw-bold">CSVアップロード (追加・更新)</div>
      <div class="card-body">
        <form method="post" action="users_import.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="mode" value="add">
          <div class="mb-2">
            <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
          </div>
          <div class="form-text mb-2">
            来場者リスト (受付番号・会社名・氏名の列を含むCSV) を指定してください。
            受付番号をQRコード値、「会社名 | 氏名」を氏名として取り込みます (会社名が空なら氏名のみ)。
            QRコード値が既存の会員と一致する行は氏名が違えば更新し、一致しない行は新規追加します。
            CSVに含まれない会員は変更されません。アップロード後に変更内容を確認してから反映できます。
          </div>
          <button type="submit" class="btn btn-primary">アップロードして変更内容を確認</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="card shadow-sm h-100 <?= $replaceEnabled ? 'border-danger' : '' ?>">
      <div class="card-header fw-bold <?= $replaceEnabled ? 'text-danger' : 'text-muted' ?>">CSVアップロード (全置換)<?= $replaceEnabled ? '' : ' (無効)' ?></div>
      <div class="card-body">
        <div class="mb-2 d-flex flex-wrap gap-2">
          <a href="users.php?action=export" class="btn btn-sm btn-outline-secondary">
            <span aria-hidden="true">📥</span> CSVエクスポート
          </a>
          <a href="users.php?action=template" class="btn btn-sm btn-outline-secondary">
            <span aria-hidden="true">📄</span> テンプレートファイルをダウンロード
          </a>
        </div>
        <?php if (!$replaceEnabled): ?>
        <div class="alert alert-secondary small py-2 mb-2">
          全置換は無効になっています。使う場合は<a href="settings.php">設定</a>で有効にしてください。
        </div>
        <?php endif; ?>
        <form method="post" action="users_import.php" enctype="multipart/form-data"
              <?= $replaceEnabled ? '' : 'class="opacity-50"' ?>>
          <fieldset <?= $replaceEnabled ? '' : 'disabled' ?>>
          <input type="hidden" name="action" value="upload">
          <input type="hidden" name="mode" value="replace">
          <div class="mb-2">
            <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
          </div>
          <div class="form-text mb-2">
            qr_code列とname列を含むCSVファイルを指定してください (CSVエクスポートと同じ形式)。
            会員一覧をCSVの内容と一致させます (QRコード値で照合し、氏名が違えば更新)。CSVにない会員は削除されますが、
            申込履歴がある会員は削除できないため残ります。アップロード後に変更内容を確認してから反映できます。
          </div>
          <button type="submit" class="btn btn-danger">アップロードして変更内容を確認</button>
          </fieldset>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($users === []): ?>
<div class="alert alert-info">会員が登録されていません。</div>
<?php else: ?>

<p class="mb-2">登録人数 <span class="fw-bold"><?= count($users) ?></span> 人</p>

<div class="table-responsive">
  <table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th style="width: 70px;">ID</th>
        <th>QRコード値</th>
        <th>氏名</th>
        <th class="text-end" style="width: 100px;">申込み数</th>
        <th style="width: 160px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td><?= (int)$u['id'] ?></td>
        <td><code><?= h($u['qr_code']) ?></code></td>
        <td><?= h($u['name']) ?></td>
        <td class="text-end"><?= (int)$u['order_count'] ?></td>
        <td class="text-center">
          <a class="btn btn-sm btn-outline-primary" href="user_edit.php?id=<?= (int)$u['id'] ?>">編集</a>
          <form method="post" class="d-inline"
                onsubmit="return confirm(<?= h(js_string('「' . $u['name'] . '」を削除します。よろしいですか?')) ?>);">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger"
                    <?= (int)$u['order_count'] > 0 ? 'disabled title="申込履歴のある会員は削除できません"' : '' ?>>削除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../lib/footer.php'; ?>
