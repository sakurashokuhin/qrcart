<?php
require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * 商品CSVの全置換取込 (アップロード → 変更内容のプレビュー → 反映/キャンセル)
 *
 * POST action=upload  : CSVを検証してセッションに保存し、プレビュー (GET) へリダイレクト
 * GET                 : セッションのCSVと現在の商品一覧を比べて変更内容を表示
 * POST action=confirm : プレビューした内容を反映
 * POST action=cancel  : 取込を取り消す
 */

/** CSVで置き換える items の列 (itemcode 以外) */
const IMPORT_COLUMNS = [
    'display_order' => '表示順',
    'picture'       => '画像パス',
    'brand'         => 'メーカー',
    'name'          => '商品名',
    'price'         => '販売価格',
    'regular_price' => '通常価格',
    'misc1'         => '備考1',
    'misc2'         => '備考2',
    'caption'       => 'キャプション',
    'stock'         => '在庫数',
];

/**
 * アップロードされた商品CSVを読み込む。
 * ヘッダー行から各列を名前で探すため列順は問わない (先頭列のUTF-8 BOMは除去する)。
 * Excel で「CSV (コンマ区切り)」として保存した Shift_JIS (CP932) のファイルは UTF-8 に変換して読む。
 * id列は無視する。itemcode列 (任意) の値で既存商品と照合する。
 * 数値列は Excel の表示形式で保存された「1,000」「¥1,000」も受け付ける。
 * 戻り値: ['rows' => [[...items列], ...], 'errors' => ['N行目: 理由', ...]] か、
 * ファイルが読めない/必須列がない場合は null。行番号は Excel 上の行番号 (ヘッダーが1行目)。
 */
function read_items_csv(array $file): ?array
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
    $header = array_map(fn($col) => trim((string)$col), $header);
    $idx = [];
    foreach (['display_order', 'picture', 'name', 'price'] as $required) {
        $pos = array_search($required, $header, true);
        if ($pos === false) {
            fclose($handle);
            return null;
        }
        $idx[$required] = $pos;
    }
    foreach (['itemcode', 'brand', 'regular_price', 'misc1', 'misc2', 'caption', 'stock'] as $optional) {
        $pos = array_search($optional, $header, true);
        $idx[$optional] = $pos === false ? null : $pos;
    }

    // 数値列: Excel の桁区切り・通貨記号を取り除く
    $number = fn(string $s): string => str_replace([',', '，', '¥', '￥', '円', ' '], '', $s);

    $rows      = [];
    $errors    = [];
    $seenCodes = [];
    $lineNo    = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $lineNo++;
        if (count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) {
            continue; // 空行 (Excel が出力するカンマだけの行を含む)
        }
        $get = function (string $col) use ($row, $idx): string {
            $pos = $idx[$col];
            return $pos === null ? '' : trim((string)($row[$pos] ?? ''));
        };

        $itemcode        = $get('itemcode');
        $displayOrderRaw = $number($get('display_order'));
        $picture         = $get('picture');
        $brand           = $get('brand');
        $name            = $get('name');
        $priceRaw        = $number($get('price'));
        $regularPriceRaw = $number($get('regular_price'));
        $misc1           = $get('misc1');
        $misc2           = $get('misc2');
        $caption         = normalize_multiline($get('caption'));
        $stockRaw        = $get('stock') === '無制限' ? '' : $number($get('stock'));

        $problems = [];
        if ($picture === '') {
            $problems[] = 'picture (画像パス) が空です';
        } elseif (mb_strlen($picture) > 255) {
            $problems[] = 'picture (画像パス) が255文字を超えています';
        }
        if ($name === '') {
            $problems[] = 'name (商品名) が空です';
        } elseif (mb_strlen($name) > 200) {
            $problems[] = 'name (商品名) が200文字を超えています';
        }
        if ($displayOrderRaw !== '' && !preg_match('/\A-?\d+\z/', $displayOrderRaw)) {
            $problems[] = "display_order (表示順)「{$get('display_order')}」が整数ではありません";
        }
        if (!ctype_digit($priceRaw)) {
            $problems[] = "price (販売価格)「{$get('price')}」が0以上の整数ではありません";
        }
        if ($regularPriceRaw !== '' && !ctype_digit($regularPriceRaw)) {
            $problems[] = "regular_price (通常価格)「{$get('regular_price')}」が0以上の整数ではありません";
        }
        if ($stockRaw !== '' && !ctype_digit($stockRaw)) {
            $problems[] = "stock (在庫数)「{$get('stock')}」が0以上の整数か「無制限」ではありません";
        }
        if (mb_strlen($itemcode) > 64) {
            $problems[] = 'itemcode (商品コード) が64文字を超えています';
        } elseif ($itemcode !== '' && isset($seenCodes[$itemcode])) {
            $problems[] = "itemcode (商品コード)「{$itemcode}」が{$seenCodes[$itemcode]}行目と重複しています";
        }
        if (mb_strlen($brand) > 100) {
            $problems[] = 'brand (メーカー) が100文字を超えています';
        }
        if (mb_strlen($misc1) > 100) {
            $problems[] = 'misc1 (備考1) が100文字を超えています';
        }
        if (mb_strlen($misc2) > 100) {
            $problems[] = 'misc2 (備考2) が100文字を超えています';
        }
        if (mb_strlen($caption) > 255) {
            $problems[] = 'caption (キャプション) が255文字を超えています';
        }
        if ($problems !== []) {
            $errors[] = "{$lineNo}行目: " . implode('、', $problems);
            continue;
        }
        if ($itemcode !== '') {
            $seenCodes[$itemcode] = $lineNo;
        }

        $rows[] = [
            'itemcode'      => $itemcode !== '' ? $itemcode : null,
            'display_order' => (int)$displayOrderRaw,
            'picture'       => $picture,
            'brand'         => $brand !== '' ? $brand : null,
            'name'          => $name,
            'price'         => (int)$priceRaw,
            'regular_price' => $regularPriceRaw === '' ? null : (int)$regularPriceRaw,
            'misc1'         => $misc1 !== '' ? $misc1 : null,
            'misc2'         => $misc2 !== '' ? $misc2 : null,
            'caption'       => $caption !== '' ? $caption : null,
            'stock'         => $stockRaw === '' ? null : (int)$stockRaw,
        ];
    }
    fclose($handle);

    return ['rows' => $rows, 'errors' => $errors];
}

/** 現在の商品一覧 (id順)。$forUpdate なら反映処理のために行ロックを取る */
function current_items(PDO $pdo, bool $forUpdate = false): array
{
    return $pdo->query(
        'SELECT id, itemcode, ' . implode(', ', array_keys(IMPORT_COLUMNS)) . '
         FROM items ORDER BY id' . ($forUpdate ? ' FOR UPDATE' : '')
    )->fetchAll();
}

/** 商品一覧の指紋。プレビュー後に誰かが商品を変更していないかの確認に使う */
function items_fingerprint(array $items): string
{
    return md5(json_encode($items, JSON_INVALID_UTF8_SUBSTITUTE));
}

/** DB/CSVの値を比較用にそろえる (DBの数値列は文字列で返るため) */
function comparable(mixed $value): ?string
{
    return $value === null ? null : (string)$value;
}

/**
 * CSVの行と現在の商品一覧を比べて変更内容を返す。
 * itemcode が一致する既存商品は更新 (ID を保つので申込み明細の item_id が引き続き有効)、
 * itemcode がない/一致しない行は追加、CSV に含まれない商品は削除。
 * 戻り値: ['add' => [row...], 'update' => [['id', 'old', 'new', 'changed' => [列...]]...],
 *          'unchanged' => [id...], 'delete' => [item...]]
 */
function build_import_plan(array $rows, array $items): array
{
    $byCode = [];
    foreach ($items as $item) {
        if ($item['itemcode'] !== null) {
            $byCode[(string)$item['itemcode']] = $item;
        }
    }

    $plan   = ['add' => [], 'update' => [], 'unchanged' => [], 'delete' => []];
    $keep   = [];
    foreach ($rows as $row) {
        $existing = $row['itemcode'] !== null ? ($byCode[$row['itemcode']] ?? null) : null;
        if ($existing === null) {
            $plan['add'][] = $row;
            continue;
        }
        $keep[(int)$existing['id']] = true;
        $changed = [];
        foreach (array_keys(IMPORT_COLUMNS) as $col) {
            if (comparable($existing[$col]) !== comparable($row[$col])) {
                $changed[] = $col;
            }
        }
        if ($changed === []) {
            $plan['unchanged'][] = (int)$existing['id'];
        } else {
            $plan['update'][] = ['id' => (int)$existing['id'], 'old' => $existing, 'new' => $row, 'changed' => $changed];
        }
    }
    foreach ($items as $item) {
        if (!isset($keep[(int)$item['id']])) {
            $plan['delete'][] = $item;
        }
    }
    return $plan;
}

/** プレビュー用の値表示 */
function preview_value(string $col, mixed $value): string
{
    if ($col === 'stock' && $value === null) {
        return '無制限';
    }
    if ($value === null || $value === '') {
        return '<span class="text-muted">(空)</span>';
    }
    if ($col === 'price') {
        return h(yen((int)$value));
    }
    if ($col === 'regular_price') {
        return h(regular_yen((int)$value));
    }
    return nl2br(h($value));
}

$action = (string)($_POST['action'] ?? '');

// 全置換が無効なら取込を受け付けない (キャンセルは許可)
if (setting('items_csv_replace_enabled') !== '1' && $action !== 'cancel') {
    unset($_SESSION['csv_import']);
    header('Location: items.php?error=csv_replace_disabled');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'upload') {
    $parsed = read_items_csv($_FILES['csv_file'] ?? []);
    if ($parsed === null) {
        header('Location: items.php?error=csv_invalid');
        exit;
    }
    if ($parsed['errors'] !== []) {
        $_SESSION['csv_errors'] = $parsed['errors'];
        header('Location: items.php?error=csv_rows');
        exit;
    }
    if ($parsed['rows'] === []) {
        header('Location: items.php?error=csv_empty');
        exit;
    }
    $_SESSION['csv_import'] = [
        'filename'    => (string)($_FILES['csv_file']['name'] ?? ''),
        'rows'        => $parsed['rows'],
        'fingerprint' => items_fingerprint(current_items(db())),
    ];
    header('Location: items_import.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cancel') {
    unset($_SESSION['csv_import']);
    header('Location: items.php?msg=import_cancelled');
    exit;
}

$import = $_SESSION['csv_import'] ?? null;
if ($import === null) {
    header('Location: items.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm') {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $items = current_items($pdo, true);
        // プレビュー後に商品が変更されていたら、確認した内容と結果が変わるため中止する
        if (items_fingerprint($items) !== $import['fingerprint']) {
            $pdo->rollBack();
            unset($_SESSION['csv_import']);
            header('Location: items.php?error=csv_stale');
            exit;
        }
        $plan = build_import_plan($import['rows'], $items);

        $cols = array_keys(IMPORT_COLUMNS);
        $updateStmt = $pdo->prepare(
            'UPDATE items SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?'
        );
        $insertStmt = $pdo->prepare(
            'INSERT INTO items (' . implode(', ', $cols) . ', itemcode)
             VALUES (' . implode(', ', array_fill(0, count($cols) + 1, '?')) . ')'
        );
        $deleteStmt = $pdo->prepare('DELETE FROM items WHERE id = ?');

        foreach ($plan['update'] as $update) {
            $values = array_map(fn($col) => $update['new'][$col], $cols);
            $updateStmt->execute([...$values, $update['id']]);
        }
        foreach ($plan['add'] as $row) {
            $values = array_map(fn($col) => $row[$col], $cols);
            $insertStmt->execute([...$values, $row['itemcode']]);
        }
        foreach ($plan['delete'] as $item) {
            $deleteStmt->execute([$item['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    unset($_SESSION['csv_import']);

    header(
        'Location: items.php?msg=import_replace'
        . '&added=' . count($plan['add']) . '&updated=' . count($plan['update'])
        . '&deleted=' . count($plan['delete'])
    );
    exit;
}

// プレビュー表示
$items      = current_items(db());
$plan       = build_import_plan($import['rows'], $items);
$stale      = items_fingerprint($items) !== $import['fingerprint'];
$hasChanges = $plan['add'] !== [] || $plan['update'] !== [] || $plan['delete'] !== [];
$stockMap   = remaining_stock(db());

$pageTitle = 'CSV取込の確認';
$active    = 'items';
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
    <button type="submit" class="btn btn-outline-secondary"><?= $hasChanges && !$stale ? 'キャンセル' : '商品管理に戻る' ?></button>
  </form>
</div>
<?php };
?>

<h1 class="h4 mb-3">CSV取込の確認</h1>

<p>
  <?php if ($import['filename'] !== ''): ?>
  <strong><?= h($import['filename']) ?></strong> の内容で商品一覧を置き換えます。
  <?php endif; ?>
  まだ反映されていません。内容を確認して「この内容で反映する」を押してください。
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
  <span class="badge text-bg-success fs-6">追加 <?= count($plan['add']) ?>件</span>
  <span class="badge text-bg-primary fs-6">更新 <?= count($plan['update']) ?>件</span>
  <span class="badge text-bg-secondary fs-6">変更なし <?= count($plan['unchanged']) ?>件</span>
  <span class="badge text-bg-danger fs-6">削除 <?= count($plan['delete']) ?>件</span>
</div>

<?php if ($stale): ?>
<div class="alert alert-warning">
  CSVをアップロードした後に商品一覧が変更されました。もう一度CSVをアップロードしてください。
</div>
<?php elseif (!$hasChanges): ?>
<div class="alert alert-info">CSVの内容は現在の商品一覧と同じです。変更はありません。</div>
<?php elseif ($plan['delete'] !== []): ?>
<div class="alert alert-danger">
  CSVに含まれない<?= count($plan['delete']) ?>件の商品が<strong>削除されます</strong>。
  <?php if ($plan['delete'] !== [] && count($plan['delete']) === count($items)): ?>
  <br>現在の商品が<strong>すべて</strong>削除されます。itemcode (商品コード) 列が正しいか確認してください。
  <?php endif; ?>
</div>
<?php endif; ?>

<?php $renderButtons(); ?>

<?php if ($plan['delete'] !== []): ?>
<div class="card shadow-sm mb-4 border-danger">
  <div class="card-header fw-bold text-danger">削除される商品 (<?= count($plan['delete']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>商品コード</th><th>商品名</th><th class="text-end">販売価格</th><th>申込み</th></tr></thead>
      <tbody>
      <?php foreach ($plan['delete'] as $item): ?>
        <?php $ordered = $stockMap[(int)$item['id']]['ordered'] ?? 0; ?>
        <tr>
          <td><small><?= h($item['itemcode'] ?? '') ?></small></td>
          <td><?= h($item['name']) ?></td>
          <td class="text-end"><?= h(yen((int)$item['price'])) ?></td>
          <td>
            <?php if ($ordered > 0): ?>
            <span class="badge text-bg-warning">申込み <?= $ordered ?>点あり</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($plan['update'] !== []): ?>
<div class="card shadow-sm mb-4 border-primary">
  <div class="card-header fw-bold text-primary">更新される商品 (<?= count($plan['update']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>商品コード</th><th>商品名</th><th>変更内容</th></tr></thead>
      <tbody>
      <?php foreach ($plan['update'] as $update): ?>
        <tr>
          <td><small><?= h($update['old']['itemcode']) ?></small></td>
          <td><?= h($update['old']['name']) ?></td>
          <td>
            <?php foreach ($update['changed'] as $col): ?>
            <div class="small">
              <span class="text-muted"><?= h(IMPORT_COLUMNS[$col]) ?>:</span>
              <del class="text-danger"><?= preview_value($col, $update['old'][$col]) ?></del>
              →
              <span class="text-success"><?= preview_value($col, $update['new'][$col]) ?></span>
            </div>
            <?php endforeach; ?>
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
  <div class="card-header fw-bold text-success">追加される商品 (<?= count($plan['add']) ?>件)</div>
  <div class="table-responsive">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>商品コード</th><th class="text-end">表示順</th><th>商品名</th>
          <th class="text-end">販売価格</th><th class="text-end">通常価格</th><th class="text-end">在庫数</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($plan['add'] as $row): ?>
        <tr>
          <td><small><?= h($row['itemcode'] ?? '') ?></small></td>
          <td class="text-end"><?= (int)$row['display_order'] ?></td>
          <td>
            <?php if ($row['brand'] !== null): ?><small class="text-muted"><?= h($row['brand']) ?></small><br><?php endif; ?>
            <?= h($row['name']) ?>
            <?php if ($row['caption'] !== null): ?><br><small class="text-muted"><?= nl2br(h($row['caption'])) ?></small><?php endif; ?>
          </td>
          <td class="text-end"><?= h(yen($row['price'])) ?></td>
          <td class="text-end"><?= $row['regular_price'] !== null ? h(regular_yen($row['regular_price'])) : '' ?></td>
          <td class="text-end"><?= $row['stock'] !== null ? (int)$row['stock'] : '無制限' ?></td>
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
