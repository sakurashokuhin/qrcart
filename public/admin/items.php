<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// CSVエクスポート・テンプレート (GET → ダウンロード)
if (($_GET['action'] ?? '') === 'export') {
    $exportItems = db()->query(
        'SELECT id, itemcode, display_order, picture, brand, name, price, regular_price, misc1, misc2, caption, stock
         FROM items ORDER BY display_order, id'
    )->fetchAll();

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="items.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'itemcode', 'display_order', 'picture', 'brand', 'name', 'price', 'regular_price', 'misc1', 'misc2', 'caption', 'stock']);
    foreach ($exportItems as $exportItem) {
        fputcsv($out, [
            $exportItem['id'],
            $exportItem['itemcode'],
            $exportItem['display_order'],
            $exportItem['picture'],
            $exportItem['brand'],
            $exportItem['name'],
            $exportItem['price'],
            $exportItem['regular_price'],
            $exportItem['misc1'],
            $exportItem['misc2'],
            $exportItem['caption'],
            $exportItem['stock'],
        ]);
    }
    fclose($out);
    exit;
}

if (($_GET['action'] ?? '') === 'template') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="items_template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['id', 'itemcode', 'display_order', 'picture', 'brand', 'name', 'price', 'regular_price', 'misc1', 'misc2', 'caption', 'stock']);
    fputcsv($out, ['', 'SAMPLE-001', '10', 'assets/img/sample.jpg', 'サンプルメーカー', 'サンプル商品', '1000', '1500', '', '', '', '']);
    fclose($out);
    exit;
}

// 追加・削除 (POST → リダイレクト)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add') {
        $itemcode        = trim((string)($_POST['itemcode'] ?? ''));
        $displayOrder    = (int)($_POST['display_order'] ?? 0);
        $picture         = trim((string)($_POST['picture'] ?? ''));
        $brand           = trim((string)($_POST['brand'] ?? ''));
        $name            = trim((string)($_POST['name'] ?? ''));
        $price           = (int)($_POST['price'] ?? -1);
        $regularPriceRaw = trim((string)($_POST['regular_price'] ?? ''));
        $misc1           = trim((string)($_POST['misc1'] ?? ''));
        $misc2           = trim((string)($_POST['misc2'] ?? ''));
        $caption         = normalize_multiline((string)($_POST['caption'] ?? ''));
        $stockUnlimited  = isset($_POST['stock_unlimited']);
        $stockRaw        = trim((string)($_POST['stock'] ?? ''));

        $regularPriceValid = $regularPriceRaw === '' || ctype_digit($regularPriceRaw);
        $stockValid = $stockUnlimited || ctype_digit($stockRaw);
        if ($picture === '' || $name === '' || $price < 0 || !$regularPriceValid || !$stockValid
            || !item_lengths_valid($itemcode, $picture, $brand, $name, $misc1, $misc2, $caption)) {
            header('Location: items.php?error=invalid');
            exit;
        }
        $regularPrice = $regularPriceRaw === '' ? null : (int)$regularPriceRaw;
        $stock = $stockUnlimited ? null : (int)$stockRaw;

        try {
            db()->prepare(
                'INSERT INTO items (itemcode, display_order, picture, brand, name, price, regular_price,
                                    misc1, misc2, caption, stock)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $itemcode !== '' ? $itemcode : null,
                $displayOrder, $picture,
                $brand !== '' ? $brand : null,
                $name, $price, $regularPrice,
                $misc1 !== '' ? $misc1 : null,
                $misc2 !== '' ? $misc2 : null,
                $caption !== '' ? $caption : null,
                $stock,
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                header('Location: items.php?error=duplicate');
                exit;
            }
            throw $e;
        }
        header('Location: items.php?msg=added');
        exit;
    }

    if ($action === 'delete') {
        // 申込み明細には商品名・価格のスナップショットが保存されているため、
        // 商品を削除しても過去の申込み表示には影響しない。
        $itemId = (int)($_POST['item_id'] ?? 0);
        db()->prepare('DELETE FROM items WHERE id = ?')->execute([$itemId]);
        header('Location: items.php?msg=deleted');
        exit;
    }

    header('Location: items.php');
    exit;
}

$messages = [
    'added'   => '商品を追加しました。',
    'updated' => '商品情報を更新しました。',
    'deleted' => '商品を削除しました。',
    'import_cancelled' => 'CSVの取り込みをキャンセルしました (商品一覧は変更していません)。',
];
$errors = [
    'invalid'     => ITEM_INVALID_MESSAGE,
    'notfound'    => '指定された商品が見つかりません。',
    'duplicate'   => 'その商品コードは既に別の商品に登録されています。',
    'csv_invalid' => 'CSVファイルの読み込みに失敗しました。display_order・picture・name・price列を含むCSVファイルを指定してください。',
    'csv_rows'    => 'CSVに不正な行があるため、取り込みを中止しました (商品一覧は変更していません)。以下の行を修正して再度アップロードしてください。',
    'csv_empty'   => 'CSVに商品の行が1行もないため、取り込みを中止しました (商品一覧は変更していません)。',
    'csv_replace_disabled' => 'CSVアップロード (全置換) は無効になっています。設定画面で有効にしてから実行してください。',
    'csv_stale'   => 'CSVをアップロードした後に商品一覧が変更されたため、取り込みを中止しました (商品一覧は変更していません)。もう一度CSVをアップロードしてください。',
];
$replaceEnabled = setting('items_csv_replace_enabled') === '1';
$message = $messages[$_GET['msg'] ?? ''] ?? null;
$error   = $errors[$_GET['error'] ?? ''] ?? null;

// CSV取込で見つかった不正な行 (1回表示したら消す)
$csvErrors = [];
if (($_GET['error'] ?? '') === 'csv_rows') {
    $csvErrors = $_SESSION['csv_errors'] ?? [];
}
unset($_SESSION['csv_errors']);

$importReplaceSummary = null;
if (($_GET['msg'] ?? '') === 'import_replace') {
    $added   = (int)($_GET['added'] ?? 0);
    $updated = (int)($_GET['updated'] ?? 0);
    $deleted = (int)($_GET['deleted'] ?? 0);
    $importReplaceSummary = "商品一覧をCSVの内容に置き換えました (更新{$updated}件・追加{$added}件・削除{$deleted}件)。";
}

$items = db()->query(
    'SELECT id, itemcode, display_order, picture, brand, name, price, regular_price, misc1, misc2, caption, stock
     FROM items ORDER BY display_order, id'
)->fetchAll();

$stockMap = remaining_stock(db());

// 追加フォームの表示順初期値 (末尾に追加されるように最大値+10)
$nextOrder = 10;
foreach ($items as $item) {
    $nextOrder = max($nextOrder, (int)$item['display_order'] + 10);
}

$pageTitle = '商品管理';
$active    = 'items';
require __DIR__ . '/../lib/admin_header.php';
?>

<h1 class="h4 mb-3">商品管理</h1>

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
<?php if ($importReplaceSummary !== null): ?>
<div class="alert alert-success"><?= h($importReplaceSummary) ?></div>
<?php endif; ?>

<div class="mb-4">
  <a href="items.php?action=export" class="btn btn-outline-secondary">
    <span aria-hidden="true">📥</span> CSVダウンロード
  </a>
</div>

<div class="card shadow-sm mb-4 <?= $replaceEnabled ? 'border-danger' : '' ?>">
  <div class="card-header fw-bold <?= $replaceEnabled ? 'text-danger' : 'text-muted' ?>">CSVアップロード (全置換)<?= $replaceEnabled ? '' : ' (無効)' ?></div>
  <div class="card-body">
    <div class="mb-2">
      <a href="items.php?action=template" class="btn btn-sm btn-outline-secondary">
        <span aria-hidden="true">📄</span> テンプレートファイルをダウンロード
      </a>
    </div>
    <?php if (!$replaceEnabled): ?>
    <div class="alert alert-secondary small py-2 mb-2">
      全置換は無効になっています。使う場合は<a href="settings.php">設定</a>で有効にしてください。
    </div>
    <?php endif; ?>
    <form method="post" action="items_import.php" enctype="multipart/form-data"
          <?= $replaceEnabled ? '' : 'class="opacity-50"' ?>>
      <fieldset <?= $replaceEnabled ? '' : 'disabled' ?>>
      <input type="hidden" name="action" value="upload">
      <div class="mb-2">
        <input type="file" class="form-control" name="csv_file" accept=".csv,text/csv" required>
      </div>
      <div class="form-text mb-2">
        display_order・picture・name・price列を含むCSVファイルを指定してください (id列は無視されます)。
        itemcode列 (商品コード) が既存の商品と一致する行はその商品を更新し (IDは変わりません)、
        一致しない行・itemcodeが空の行は新規追加します。CSVに含まれない商品は削除されます。
        アップロード後に追加・更新・削除される商品の一覧を表示しますので、確認してから反映してください。
        不正な行が1行でもある場合は取り込めません (問題のある行を表示します)。
        Excel の「CSV UTF-8」「CSV (コンマ区切り)」どちらの形式で保存したファイルも読み込めます。
        過去の申込み明細には商品名・価格のスナップショットが保存されているため、置換後も過去の申込み表示には影響しません。
      </div>
      <button type="submit" class="btn btn-danger">アップロードして変更内容を確認</button>
      </fieldset>
    </form>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">商品の追加</div>
  <div class="card-body">
    <form method="post" class="row g-2">
      <input type="hidden" name="action" value="add">
      <div class="col-6 col-md-2">
        <label class="form-label" for="add-itemcode">商品コード <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="add-itemcode" name="itemcode" maxlength="64" autocomplete="off">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="add-order">表示順</label>
        <input type="number" class="form-control" id="add-order" name="display_order"
               value="<?= $nextOrder ?>" required>
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="add-picture">画像パス</label>
        <input type="text" class="form-control" id="add-picture" name="picture" maxlength="255"
               placeholder="assets/img/xxxx.jpg" required autocomplete="off">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="add-brand">メーカー <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="add-brand" name="brand" maxlength="100" autocomplete="off">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="add-name">商品名</label>
        <input type="text" class="form-control" id="add-name" name="name" maxlength="200" required autocomplete="off">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="add-price">販売価格 (円)</label>
        <input type="number" class="form-control" id="add-price" name="price" min="0" required>
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label" for="add-regular-price">通常価格 (円) <span class="text-muted">(任意)</span></label>
        <input type="number" class="form-control" id="add-regular-price" name="regular_price" min="0">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="add-misc1">備考1 <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="add-misc1" name="misc1" maxlength="100" autocomplete="off">
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="add-misc2">備考2 <span class="text-muted">(任意)</span></label>
        <input type="text" class="form-control" id="add-misc2" name="misc2" maxlength="100" autocomplete="off">
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label" for="add-caption">キャプション <span class="text-muted">(任意)</span></label>
        <textarea class="form-control" id="add-caption" name="caption" rows="2"
                  maxlength="255" autocomplete="off"></textarea>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="add-stock">在庫数</label>
        <input type="number" class="form-control" id="add-stock" name="stock" min="0">
        <div class="form-check mt-1">
          <input class="form-check-input" type="checkbox" id="add-stock-unlimited" name="stock_unlimited" checked>
          <label class="form-check-label" for="add-stock-unlimited">無制限</label>
        </div>
      </div>
      <div class="col-12 col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-primary w-100">追加</button>
      </div>
      <div class="col-12">
        <div class="form-text">
          画像は public/assets/img/ にアップロードし、「assets/img/ファイル名」の形式でパスを指定します。
          商品コードはCSV全置換時の照合キーです (重複不可)。商品コード・備考1・備考2は利用者には表示されません。メーカーは商品名の上に、キャプションは商品名の下に表示されます。
          在庫数は「無制限」のチェックを外して数量を入力すると上限を設定できます。
          通常価格を入力すると、商品一覧画面で販売価格の下に取り消し線付きで表示され、割引率も表示されます。
        </div>
      </div>
    </form>
    <script>
      (function () {
        var checkbox = document.getElementById('add-stock-unlimited');
        var input = document.getElementById('add-stock');
        function sync() {
          input.disabled = checkbox.checked;
          if (checkbox.checked) input.value = '';
        }
        checkbox.addEventListener('change', sync);
        sync();
      })();
    </script>
  </div>
</div>

<?php if ($items === []): ?>
<div class="alert alert-info">商品が登録されていません。</div>
<?php else: ?>

<div class="table-responsive">
  <table class="table table-bordered table-hover bg-white align-middle">
    <thead class="table-dark">
      <tr>
        <th style="width: 64px;">ID</th>
        <th class="text-end" style="width: 64px;">表示順</th>
        <th style="width: 90px;">画像</th>
        <th style="width: 130px;">メーカー</th>
        <th style="width: 80px;">商品<br>コード</th>
        <th style="min-width: 300px;">商品名 / キャプション</th>
        <th style="width: 80px;">備考1</th>
        <th style="width: 64px;">備考2</th>
        <th class="text-end" style="width: 110px;">販売価格</th>
        <th class="text-end" style="width: 110px;">通常価格</th>
        <th class="text-end" style="width: 110px;">在庫数</th>
        <th style="width: 150px;"></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td><?= (int)$item['id'] ?></td>
        <td class="text-end"><?= (int)$item['display_order'] ?></td>
        <td class="text-center">
          <img src="../<?= h(asset_url($item['picture'])) ?>" alt="" width="56" height="42"
               style="object-fit: cover;" class="rounded">
        </td>
        <td><small><?= h($item['brand'] ?? '') ?></small></td>
        <td style="word-break: break-all;"><small><?= h($item['itemcode'] ?? '') ?></small></td>
        <td>
          <?= h($item['name']) ?>
          <?php if ((string)($item['caption'] ?? '') !== ''): ?>
          <br><small class="text-muted"><?= nl2br(h($item['caption'])) ?></small>
          <?php endif; ?>
        </td>
        <td style="word-break: break-all;"><small><?= h($item['misc1'] ?? '') ?></small></td>
        <td><small><?= h($item['misc2'] ?? '') ?></small></td>
        <td class="text-end"><?= h(yen((int)$item['price'])) ?></td>
        <td class="text-end"><?= $item['regular_price'] !== null ? h(regular_yen((int)$item['regular_price'])) : '' ?></td>
        <td class="text-end">
          <?php if ($item['stock'] === null): ?>
          無制限
          <?php else: ?>
          <?= (int)$stockMap[$item['id']]['remaining'] ?> / <?= (int)$item['stock'] ?>
          <?php endif; ?>
        </td>
        <td class="text-center">
          <a class="btn btn-sm btn-outline-primary" href="item_edit.php?id=<?= (int)$item['id'] ?>">編集</a>
          <form method="post" class="d-inline"
                onsubmit="return confirm(<?= h(js_string('「' . $item['name'] . '」を削除します。よろしいですか?')) ?>);">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="item_id" value="<?= (int)$item['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger">削除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../lib/footer.php'; ?>
