<?php
require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * 会員CSVの取込 (アップロード → 変更内容のプレビュー → 反映/キャンセル)
 * QRコード値で既存会員と照合し、氏名が変わっていれば更新する。
 *
 * mode=add     : 追加・更新のみ (CSVに含まれない会員はそのまま残す)
 * mode=replace : 全置換 (CSVに含まれない会員は削除。申込履歴がある会員は削除できないため残す)
 *
 * POST action=upload  : CSVを検証してセッションに保存し、プレビュー (GET) へリダイレクト
 * GET                 : セッションのCSVと現在の会員一覧を比べて変更内容を表示
 * POST action=confirm : プレビューした内容を反映
 * POST action=cancel  : 取込を取り消す
 */

/**
 * モードごとのCSVの列名 (同名の列が複数あるときは最初の列を使う)
 * add     : 来場者リスト (guestlist.csv) 形式。QRコード値 = 受付番号、氏名 = 会社名 + " | " + 氏名
 * replace : 会員CSV (エクスポートと同じ) 形式。QRコード値 = qr_code、氏名 = name
 */
const USER_CSV_COLUMNS = [
    'add'     => ['qr_code' => '受付番号', 'company' => '会社名', 'name' => '氏名'],
    'replace' => ['qr_code' => 'qr_code', 'name' => 'name'],
];

/**
 * アップロードされた会員CSVを読み込む。列は USER_CSV_COLUMNS[$mode] の列名で探す。
 * ヘッダー行から各列を名前で探すため列順は問わない (先頭列のUTF-8 BOMは除去する)。
 * Excel で「CSV (コンマ区切り)」として保存した Shift_JIS (CP932) のファイルは UTF-8 に変換して読む。
 * 来場者リスト形式では会社名が空の行は氏名のみを使う。
 * 戻り値: ['rows' => [['qr_code', 'name'], ...], 'errors' => ['N行目: 理由', ...]] か、
 * ファイルが読めない/必須列がない場合は null。行番号は Excel 上の行番号 (ヘッダーが1行目)。
 */
function read_users_csv(array $file, string $mode): ?array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $content = file_get_contents($file['tmp_name']);
    if ($content === false) {
        return null;
    }
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    if (!mb_check_encoding($content, 'UTF-8')) {
        $content = mb_convert_encoding($content, 'UTF-8', 'SJIS-win');
    }
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $content);
    rewind($handle);

    $header = fgetcsv($handle);
    if ($header === false) {
        fclose($handle);
        return null;
    }
    $header  = array_map(fn($col) => trim((string)$col), $header);
    $columns = USER_CSV_COLUMNS[$mode];
    $idx = [];
    foreach ($columns as $col => $label) {
        $pos = array_search($label, $header, true);
        if ($pos === false) {
            fclose($handle);
            return null;
        }
        $idx[$col] = $pos;
    }

    $rows    = [];
    $errors  = [];
    $seenQrs = [];
    $lineNo  = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNo++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue; // 空行 (Excel が出力するカンマだけの行を含む)
        }
        $get = fn(string $col): string => trim((string)($row[$idx[$col]] ?? ''));
        $qrCode   = $get('qr_code');
        $name     = $get('name');
        if (isset($idx['company'])) {
            if ($name !== '' && $get('company') !== '') {
                $name = $get('company') . ' | ' . $name;
            }
        }

        $qrLabel   = "{$columns['qr_code']} (QRコード値)";
        $nameLabel = $columns['name'] === '氏名' ? '氏名' : "{$columns['name']} (氏名)";

        $problems = [];
        if ($qrCode === '') {
            $problems[] = "{$qrLabel} が空です";
        } elseif (mb_strlen($qrCode) > 255) {
            $problems[] = "{$qrLabel} が255文字を超えています";
        } elseif (isset($seenQrs[$qrCode])) {
            $problems[] = "{$qrLabel}「{$qrCode}」が{$seenQrs[$qrCode]}行目と重複しています";
        }
        if ($name === '') {
            $problems[] = "{$nameLabel} が空です";
        } elseif (mb_strlen($name) > 100) {
            $problems[] = "氏名「{$name}」が100文字を超えています";
        }
        if ($problems !== []) {
            $errors[] = "{$lineNo}行目: " . implode('、', $problems);
            continue;
        }
        $seenQrs[$qrCode] = $lineNo;
        $rows[] = ['qr_code' => $qrCode, 'name' => $name];
    }
    fclose($handle);

    return ['rows' => $rows, 'errors' => $errors];
}

/** 現在の会員一覧 (id順・申込み数つき)。$forUpdate なら反映処理のために行ロックを取る */
function current_users(PDO $pdo, bool $forUpdate = false): array
{
    if ($forUpdate) {
        $pdo->query('SELECT id FROM users FOR UPDATE')->fetchAll();
    }
    return $pdo->query(
        'SELECT u.id, u.qr_code, u.name, COUNT(o.id) AS order_count
         FROM users u
         LEFT JOIN orders o ON o.user_id = u.id
         GROUP BY u.id, u.qr_code, u.name
         ORDER BY u.id'
    )->fetchAll();
}

/** 会員一覧の指紋。プレビュー後に誰かが会員を変更していないかの確認に使う */
function users_fingerprint(array $users): string
{
    return md5(json_encode(array_map(fn($u) => [$u['id'], $u['qr_code'], $u['name']], $users), JSON_INVALID_UTF8_SUBSTITUTE));
}

/**
 * CSVの行と現在の会員一覧を比べて変更内容を返す。
 * QRコード値が一致する既存会員は氏名が違えば更新、一致しない行は追加。
 * 全置換モードでは CSV に含まれない会員を削除 (申込履歴がある会員は残す)。
 * 戻り値: ['add' => [row...], 'update' => [['id', 'old', 'new']...], 'unchanged' => [id...],
 *          'delete' => [user...], 'keep' => [user...]]
 */
function build_users_import_plan(array $rows, array $users, string $mode): array
{
    $byQr = [];
    foreach ($users as $user) {
        $byQr[(string)$user['qr_code']] = $user;
    }

    $plan = ['add' => [], 'update' => [], 'unchanged' => [], 'delete' => [], 'keep' => []];
    $seen = [];
    foreach ($rows as $row) {
        $existing = $byQr[$row['qr_code']] ?? null;
        if ($existing === null) {
            $plan['add'][] = $row;
            continue;
        }
        $seen[(int)$existing['id']] = true;
        if ($existing['name'] === $row['name']) {
            $plan['unchanged'][] = (int)$existing['id'];
        } else {
            $plan['update'][] = ['id' => (int)$existing['id'], 'old' => $existing, 'new' => $row];
        }
    }
    if ($mode === 'replace') {
        foreach ($users as $user) {
            if (isset($seen[(int)$user['id']])) {
                continue;
            }
            if ((int)$user['order_count'] === 0) {
                $plan['delete'][] = $user;
            } else {
                // 申込履歴がある会員は外部キー制約により削除できないため残す
                $plan['keep'][] = $user;
            }
        }
    }
    return $plan;
}

$action = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $mode   = ($_POST['mode'] ?? '') === 'replace' ? 'replace' : 'add';
    if ($mode === 'replace' && setting('users_csv_replace_enabled') !== '1') {
        header('Location: users.php?error=csv_replace_disabled');
        exit;
    }
    $parsed = read_users_csv($_FILES['csv_file'] ?? [], $mode);
    if ($parsed === null) {
        header('Location: users.php?error=csv_invalid_' . $mode);
        exit;
    }
    if ($parsed['errors'] !== []) {
        $_SESSION['users_csv_errors'] = $parsed['errors'];
        header('Location: users.php?error=csv_rows');
        exit;
    }
    if ($parsed['rows'] === []) {
        header('Location: users.php?error=csv_empty');
        exit;
    }
    $_SESSION['users_csv_import'] = [
        'mode'        => $mode,
        'filename'    => (string)($_FILES['csv_file']['name'] ?? ''),
        'rows'        => $parsed['rows'],
        'fingerprint' => users_fingerprint(current_users(db())),
    ];
    header('Location: users_import.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel') {
    unset($_SESSION['users_csv_import']);
    header('Location: users.php?msg=import_cancelled');
    exit;
}

$import = $_SESSION['users_csv_import'] ?? null;
if ($import === null) {
    header('Location: users.php');
    exit;
}
$mode = $import['mode'];

// プレビュー中に全置換が無効にされた場合は反映しない
if ($mode === 'replace' && setting('users_csv_replace_enabled') !== '1') {
    unset($_SESSION['users_csv_import']);
    header('Location: users.php?error=csv_replace_disabled');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm') {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $users = current_users($pdo, true);
        // プレビュー後に会員が変更されていたら、確認した内容と結果が変わるため中止する
        if (users_fingerprint($users) !== $import['fingerprint']) {
            $pdo->rollBack();
            unset($_SESSION['users_csv_import']);
            header('Location: users.php?error=csv_stale');
            exit;
        }
        $plan = build_users_import_plan($import['rows'], $users, $mode);

        $updateStmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
        $insertStmt = $pdo->prepare('INSERT INTO users (qr_code, name) VALUES (?, ?)');
        $deleteStmt = $pdo->prepare('DELETE FROM users WHERE id = ?');

        foreach ($plan['update'] as $update) {
            $updateStmt->execute([$update['new']['name'], $update['id']]);
        }
        foreach ($plan['add'] as $row) {
            $insertStmt->execute([$row['qr_code'], $row['name']]);
        }
        foreach ($plan['delete'] as $user) {
            $deleteStmt->execute([$user['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    unset($_SESSION['users_csv_import']);

    header(
        'Location: users.php?msg=import'
        . '&added=' . count($plan['add']) . '&updated=' . count($plan['update'])
        . '&deleted=' . count($plan['delete']) . '&kept=' . count($plan['keep'])
    );
    exit;
}

// プレビュー表示
$users      = current_users(db());
$plan       = build_users_import_plan($import['rows'], $users, $mode);
$stale      = users_fingerprint($users) !== $import['fingerprint'];
$hasChanges = $plan['add'] !== [] || $plan['update'] !== [] || $plan['delete'] !== [];

$pageTitle = '会員CSV取込の確認';
$active    = 'users';
require __DIR__ . '/../lib/admin_header.php';

$renderButtons = function () use ($hasChanges, $stale): void { ?>
<div class="d-flex gap-2 my-3">
  <?php if ($hasChanges && !$stale): ?>
  <form method="post">
    <input type="hidden" name="action" value="confirm">
    <button type="submit" class="btn btn-danger">この内容で反映する</button>
  </form>
  <?php endif; ?>
  <form method="post">
    <input type="hidden" name="action" value="cancel">
    <button type="submit" class="btn btn-outline-secondary"><?= $hasChanges && !$stale ? 'キャンセル' : '会員管理に戻る' ?></button>
  </form>
</div>
<?php };
?>

<h1 class="h4 mb-3">会員CSV取込の確認 (<?= $mode === 'replace' ? '全置換' : '追加・更新' ?>)</h1>

<p>
  <?php if ($import['filename'] !== ''): ?>
  <strong><?= h($import['filename']) ?></strong> の内容で
  <?= $mode === 'replace' ? '会員一覧を置き換えます。' : '会員を追加・更新します。' ?>
  <?php endif; ?>
  まだ反映されていません。内容を確認して「この内容で反映する」を押してください。
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="badge text-bg-success fs-6">追加 <?= count($plan['add']) ?>件</span>
  <span class="badge text-bg-primary fs-6">氏名変更 <?= count($plan['update']) ?>件</span>
  <span class="badge text-bg-secondary fs-6">変更なし <?= count($plan['unchanged']) ?>件</span>
  <?php if ($mode === 'replace'): ?>
  <span class="badge text-bg-danger fs-6">削除 <?= count($plan['delete']) ?>件</span>
  <?php if ($plan['keep'] !== []): ?>
  <span class="badge text-bg-warning fs-6">削除できず残る <?= count($plan['keep']) ?>件</span>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($stale): ?>
<div class="alert alert-warning">
  CSVをアップロードした後に会員一覧が変更されました。もう一度CSVをアップロードしてください。
</div>
<?php elseif (!$hasChanges): ?>
<div class="alert alert-info">CSVの内容は現在の会員一覧と同じです。変更はありません。</div>
<?php elseif ($plan['delete'] !== []): ?>
<div class="alert alert-danger">
  CSVに含まれない<?= count($plan['delete']) ?>件の会員が<strong>削除されます</strong>。
  <?php if (count($plan['delete']) === count($users)): ?>
  <br>現在の会員が<strong>すべて</strong>削除されます。QRコード値の列が正しいか確認してください。
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $renderButtons(); ?>

<?php if ($plan['delete'] !== []): ?>
<div class="card shadow-sm mb-4 border-danger">
  <div class="card-header fw-bold text-danger">削除される会員 (<?= count($plan['delete']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>QRコード値</th><th>氏名</th></tr></thead>
      <tbody>
      <?php foreach ($plan['delete'] as $user): ?>
        <tr>
          <td><code><?= h($user['qr_code']) ?></code></td>
          <td><?= h($user['name']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($plan['keep'] !== []): ?>
<div class="card shadow-sm mb-4 border-warning">
  <div class="card-header fw-bold">申込履歴があるため削除できず残る会員 (<?= count($plan['keep']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>QRコード値</th><th>氏名</th><th class="text-end">申込み数</th></tr></thead>
      <tbody>
      <?php foreach ($plan['keep'] as $user): ?>
        <tr>
          <td><code><?= h($user['qr_code']) ?></code></td>
          <td><?= h($user['name']) ?></td>
          <td class="text-end"><?= (int)$user['order_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($plan['update'] !== []): ?>
<div class="card shadow-sm mb-4 border-primary">
  <div class="card-header fw-bold text-primary">氏名が変更される会員 (<?= count($plan['update']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>QRコード値</th><th>氏名</th></tr></thead>
      <tbody>
      <?php foreach ($plan['update'] as $update): ?>
        <tr>
          <td><code><?= h($update['old']['qr_code']) ?></code></td>
          <td>
            <del class="text-danger"><?= h($update['old']['name']) ?></del>
            →
            <span class="text-success"><?= h($update['new']['name']) ?></span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($plan['add'] !== []): ?>
<div class="card shadow-sm mb-4 border-success">
  <div class="card-header fw-bold text-success">追加される会員 (<?= count($plan['add']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>QRコード値</th><th>氏名</th></tr></thead>
      <tbody>
      <?php foreach ($plan['add'] as $row): ?>
        <tr>
          <td><code><?= h($row['qr_code']) ?></code></td>
          <td><?= h($row['name']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($hasChanges && !$stale && (count($plan['add']) + count($plan['update']) + count($plan['delete'])) > 10): ?>
<?php $renderButtons(); ?>
<?php endif; ?>

<?php require __DIR__ . '/../lib/footer.php'; ?>
