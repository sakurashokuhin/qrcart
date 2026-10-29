<?php
require_once __DIR__ . '/../lib/bootstrap.php';

// 保存 (POST → リダイレクト)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 改行コードを LF に統一して保存する
    $header = str_replace("\r\n", "\n", (string)($_POST['receipt_header'] ?? ''));
    $footer = str_replace("\r\n", "\n", (string)($_POST['receipt_footer'] ?? ''));
    save_setting('site_title', trim((string)($_POST['site_title'] ?? '')));
    save_setting('login_camera', ($_POST['login_camera'] ?? '') === 'environment' ? 'environment' : 'user');
    // 価格の前後に付ける文言は空白も意味を持つため trim しない (改行のみ除去)
    save_setting('price_prefix', str_replace(["\r", "\n"], '', (string)($_POST['price_prefix'] ?? '')));
    save_setting('price_suffix', str_replace(["\r", "\n"], '', (string)($_POST['price_suffix'] ?? '')));
    save_setting('regular_price_prefix', str_replace(["\r", "\n"], '', (string)($_POST['regular_price_prefix'] ?? '')));
    save_setting('regular_price_suffix', str_replace(["\r", "\n"], '', (string)($_POST['regular_price_suffix'] ?? '')));
    save_setting('receipt_header', trim($header));
    save_setting('receipt_footer', trim($footer));
    // レシート幅: 空欄・不正な値は既定値 (空文字で保存)、範囲外は上限・下限に丸める
    $receiptWidth = parse_receipt_width((string)($_POST['receipt_width_mm'] ?? ''));
    save_setting('receipt_width_mm', $receiptWidth === null ? '' : format_mm($receiptWidth));
    save_setting('receipt_fit_height', isset($_POST['receipt_fit_height']) ? '1' : '0');
    save_setting('chat_webhook_url', trim((string)($_POST['chat_webhook_url'] ?? '')));
    // CSVアップロード (全置換) の有効/無効 (誤操作でデータを消さないよう既定は無効)
    save_setting('users_csv_replace_enabled', isset($_POST['users_csv_replace_enabled']) ? '1' : '');
    save_setting('items_csv_replace_enabled', isset($_POST['items_csv_replace_enabled']) ? '1' : '');
    save_setting('site_base_url', trim((string)($_POST['site_base_url'] ?? '')));
    header('Location: settings.php?msg=saved');
    exit;
}

$message = ($_GET['msg'] ?? '') === 'saved' ? '設定を保存しました。' : null;

$fields = [
    'receipt_header' => ['label' => 'ヘッダー文言', 'note' => 'レシート上部に印刷される文言'],
    'receipt_footer' => ['label' => 'フッター文言', 'note' => 'レシート下部に印刷される文言'],
];

$pageTitle = '設定';
$active    = 'settings';
require __DIR__ . '/../lib/admin_header.php';
?>

<h1 class="h4 mb-3">設定</h1>

<?php if ($message !== null): ?>
<div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>

<form method="post" style="max-width: 760px;">

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">基本設定</div>
  <div class="card-body">
    <div class="mb-1">
      <label class="form-label" for="site_title">タイトル</label>
      <input type="text" class="form-control" id="site_title" name="site_title" maxlength="100"
             value="<?= h(setting('site_title')) ?>" placeholder="申込みシステム">
      <div class="form-text">
        利用者画面のナビゲーションバー・ブラウザのタブ・ログイン画面に表示されます。
        空欄の場合は「申込みシステム」と表示されます。
      </div>
    </div>
    <div class="mt-3 mb-1">
      <div class="form-label">ログイン画面のカメラ</div>
      <div class="btn-group" role="group" aria-label="ログイン画面のカメラ">
        <input type="radio" class="btn-check" name="login_camera" id="login_camera_user" value="user"
               autocomplete="off" <?= login_camera() === 'user' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary" for="login_camera_user">インカメラ (前面)</label>
        <input type="radio" class="btn-check" name="login_camera" id="login_camera_environment" value="environment"
               autocomplete="off" <?= login_camera() === 'environment' ? 'checked' : '' ?>>
        <label class="btn btn-outline-primary" for="login_camera_environment">アウトカメラ (背面)</label>
      </div>
      <div class="form-text">
        会員が QR コードをかざすときに最初に起動するカメラです。受付端末を据え置いて会員側に画面を向ける場合はインカメラ、
        スタッフが端末を持って会員証を読み取る場合はアウトカメラが便利です。
        指定したカメラがない端末では、もう一方のカメラに自動的に切り替わります。ログイン画面の切替ボタンでいつでも変更できます。
      </div>
    </div>
    <div class="row g-3 mt-2">
      <div class="col-sm-6">
        <label class="form-label" for="price_prefix">価格接頭詞</label>
        <input type="text" class="form-control" id="price_prefix" name="price_prefix" maxlength="20"
               value="<?= h(setting('price_prefix')) ?>" placeholder="例: 税込 ">
      </div>
      <div class="col-sm-6">
        <label class="form-label" for="price_suffix">価格接尾辞</label>
        <input type="text" class="form-control" id="price_suffix" name="price_suffix" maxlength="20"
               value="<?= h(setting('price_suffix')) ?>" placeholder="例: (税別)">
      </div>
      <div class="col-12">
        <div class="form-text">
          販売価格 (および小計・合計) の前後に付ける文言です。通常価格には付きません (空白も含めてそのまま表示されます)。
          表示例: <span class="fw-bold text-danger" id="price-preview"></span>
        </div>
      </div>
    </div>
    <div class="row g-3 mt-2">
      <div class="col-sm-6">
        <label class="form-label" for="regular_price_prefix">通常価格接頭詞</label>
        <input type="text" class="form-control" id="regular_price_prefix" name="regular_price_prefix" maxlength="20"
               value="<?= h(setting('regular_price_prefix')) ?>" placeholder="例: 標準価格 ">
      </div>
      <div class="col-sm-6">
        <label class="form-label" for="regular_price_suffix">通常価格接尾辞</label>
        <input type="text" class="form-control" id="regular_price_suffix" name="regular_price_suffix" maxlength="20"
               value="<?= h(setting('regular_price_suffix')) ?>" placeholder="例: (税別)">
      </div>
      <div class="col-12">
        <div class="form-text">
          通常価格の前後に付ける文言です (空白も含めてそのまま表示されます)。
          表示例: <span class="text-muted text-decoration-line-through" id="regular-price-preview"></span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">CSVアップロード (全置換)</div>
  <div class="card-body">
    <div class="form-check mb-2">
      <input class="form-check-input" type="checkbox" id="users_csv_replace_enabled" name="users_csv_replace_enabled" value="1"
             <?= setting('users_csv_replace_enabled') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="users_csv_replace_enabled">会員管理の「CSVアップロード (全置換)」を有効にする</label>
    </div>
    <div class="form-check mb-1">
      <input class="form-check-input" type="checkbox" id="items_csv_replace_enabled" name="items_csv_replace_enabled" value="1"
             <?= setting('items_csv_replace_enabled') === '1' ? 'checked' : '' ?>>
      <label class="form-check-label" for="items_csv_replace_enabled">商品管理の「CSVアップロード (全置換)」を有効にする</label>
    </div>
    <div class="form-text">
      全置換はCSVに含まれないデータを削除します。誤って削除しないよう、使うときだけ有効にしてください。
      無効の間はアップロード欄がグレーアウトされ、取り込みできません。
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">レシートの用紙</div>
  <div class="card-body">
    <label class="form-label" for="receipt_width_mm">用紙の幅</label>
    <div class="input-group" style="max-width: 200px;">
      <input type="number" class="form-control" id="receipt_width_mm" name="receipt_width_mm"
             min="<?= format_mm(RECEIPT_WIDTH_MIN) ?>" max="<?= format_mm(RECEIPT_WIDTH_MAX) ?>" step="0.1"
             value="<?= h(format_mm(receipt_width_mm())) ?>" placeholder="<?= format_mm(RECEIPT_WIDTH_DEFAULT) ?>">
      <span class="input-group-text">mm</span>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-receipt-width="58">58mm (ロール紙)</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-receipt-width="62">62mm (ラベル紙)</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-receipt-width="80">80mm (ロール紙)</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-receipt-width="210">A4 (210mm)</button>
    </div>
    <div class="form-text">
      <?= format_mm(RECEIPT_WIDTH_MIN) ?>〜<?= format_mm(RECEIPT_WIDTH_MAX) ?>mm (A4 の幅) の範囲で指定します。
      空欄の場合は <?= format_mm(RECEIPT_WIDTH_DEFAULT) ?>mm です。プリンタードライバーの用紙幅も同じ値に設定してください。
    </div>

    <div class="form-check mt-3 mb-1">
      <input class="form-check-input" type="checkbox" id="receipt_fit_height" name="receipt_fit_height" value="1"
             <?= receipt_fit_height() ? 'checked' : '' ?>>
      <label class="form-check-label" for="receipt_fit_height">用紙の長さをレシートの内容に合わせる (連続紙・ロール紙向け)</label>
    </div>
    <div class="form-text">
      印刷時にレシートの長さを測り、用紙の長さをちょうどに指定します。
      A4 などの定形用紙やカット済みラベルに印刷する場合はオフにしてください (プリンタードライバーの用紙設定に従います)。
      どちらの場合も、複数ページにまたがるときは行の途中で切れないように印刷されます。
    </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">レシートの文言</div>
  <div class="card-body">
      <?php foreach ($fields as $name => $f): ?>
      <div class="mb-4 receipt-field">
        <label class="form-label" for="<?= h($name) ?>"><?= h($f['label']) ?></label>

        <div class="btn-toolbar gap-1 mb-1" role="toolbar">
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-wrap="bold" title="太字"><b>B</b></button>
            <button type="button" class="btn btn-outline-secondary" data-wrap="large" title="大きい文字">大</button>
            <button type="button" class="btn btn-outline-secondary" data-wrap="xlarge" title="特大の文字">特大</button>
            <button type="button" class="btn btn-outline-secondary" data-wrap="small" title="小さい文字">小</button>
          </div>
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" data-align="left" title="左寄せ">左</button>
            <button type="button" class="btn btn-outline-secondary" data-align="center" title="中央">中</button>
            <button type="button" class="btn btn-outline-secondary" data-align="right" title="右寄せ">右</button>
          </div>
        </div>

        <textarea class="form-control receipt-src" id="<?= h($name) ?>" name="<?= h($name) ?>" rows="4"
                  data-preview="preview-<?= h($name) ?>"
                  placeholder="<?= h($f['note']) ?>"><?= h(setting($name)) ?></textarea>

        <div class="mt-2">
          <div class="form-text mb-1">プレビュー (<span class="receipt-width-label"><?= h(format_mm(receipt_width_mm())) ?></span>mm 幅)</div>
          <div class="receipt-preview" id="preview-<?= h($name) ?>"></div>
        </div>
      </div>
      <?php endforeach; ?>

      <div class="alert alert-light border small mb-0">
        <div class="fw-bold mb-1">書式タグ (大文字小文字どちらでも可・ボタンでも挿入できます)</div>
        <ul class="mb-1 ps-3">
          <li><code>&lt;left&gt;</code> <code>&lt;center&gt;</code> <code>&lt;right&gt;</code>
            … 行の先頭に置くと、その行から下の配置が変わります (既定は中央)。</li>
          <li><code>&lt;bold&gt;…&lt;/bold&gt;</code> (<code>&lt;b&gt;</code> も可) … 太字。</li>
          <li><code>&lt;large&gt;…&lt;/large&gt;</code> / <code>&lt;xlarge&gt;…&lt;/xlarge&gt;</code>
            / <code>&lt;small&gt;…&lt;/small&gt;</code> … 文字サイズ。</li>
        </ul>
        <div class="text-muted">
          例: <code>&lt;center&gt;&lt;xlarge&gt;&lt;bold&gt;サンプル商店 特売会場&lt;/bold&gt;&lt;/xlarge&gt;</code><br>
          改行はそのままレシートに反映されます。全ての申込みのレシートで共通です。
        </div>
      </div>
  </div>
</div>

<div class="card shadow-sm mb-4">
  <div class="card-header fw-bold">通知 (Google Chat)</div>
  <div class="card-body">
    <p class="small text-muted">
      申込みが確定 (新規・変更) されるたびに、Google Chat のスペースへレシート印刷用リンク付きの
      メッセージを送信します。印刷担当者はスマホ/PC の通知からリンクを開き、そのまま印刷できます。
    </p>
    <div class="mb-3">
      <label class="form-label" for="chat_webhook_url">Webhook URL</label>
      <input type="url" class="form-control" id="chat_webhook_url" name="chat_webhook_url"
             value="<?= h(setting('chat_webhook_url')) ?>" autocomplete="off"
             placeholder="https://chat.googleapis.com/v1/spaces/XXXX/messages?key=...&token=...">
      <div class="form-text">
        Google Chat のスペース → アプリと統合 → Webhook で作成した URL を貼り付けます。
        空欄にすると通知は送信されません。
      </div>
    </div>
    <div class="mb-1">
      <label class="form-label" for="site_base_url">サイトの URL <span class="text-muted">(省略可)</span></label>
      <input type="url" class="form-control" id="site_base_url" name="site_base_url"
             value="<?= h(setting('site_base_url')) ?>" autocomplete="off"
             placeholder="例: https://shop.example.com">
      <div class="form-text">
        通知メッセージ内のリンクに使う、サイトの公開ディレクトリの URL です。
        空欄の場合はアクセス時の URL から自動判定します。
      </div>
    </div>
  </div>
</div>

<button type="submit" class="btn btn-primary">保存</button>
</form>

<style>
  .receipt-preview {
    width: <?= format_mm(receipt_width_mm()) ?>mm;
    max-width: 100%;
    border: 1px solid #ccc;
    background: #fff;
    padding: 3mm 3.5mm;
    font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif;
    font-size: 12px;
    line-height: 1.5;
    color: #000;
    word-break: break-word;
  }
  .receipt-preview:empty::before { content: "(未入力)"; color: #999; }
  .receipt-preview strong { font-weight: 700; }
  .receipt-preview .rs-s  { font-size: 10px; }
  .receipt-preview .rs-l  { font-size: 15px; }
  .receipt-preview .rs-xl { font-size: 19px; }
</style>

<script>
  // public/lib/bootstrap.php の receipt_markup() と同じ変換ルール
  (function () {
    var INLINE = {
      b:      ['<strong>', '</strong>'],
      bold:   ['<strong>', '</strong>'],
      large:  ['<span class="rs-l">', '</span>'],
      xlarge: ['<span class="rs-xl">', '</span>'],
      small:  ['<span class="rs-s">', '</span>']
    };

    function escapeHtml(s) {
      return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
              .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function markupLine(line, align) {
      var safe = escapeHtml(line);
      var m = safe.match(/^\s*&lt;(left|center|right)&gt;\s*/i);
      if (m) {
        align.value = m[1].toLowerCase();
        safe = safe.replace(/^\s*&lt;(left|center|right)&gt;\s*/i, '');
      }
      safe = safe.replace(/&lt;\/?(left|center|right)&gt;/ig, '');

      var stack = [];
      safe = safe.replace(/&lt;(\/?)(b|bold|large|xlarge|small)&gt;/ig, function (_, slash, name) {
        name = name.toLowerCase();
        if (slash !== '/') { stack.push(name); return INLINE[name][0]; }
        if (stack.indexOf(name) === -1) return '';
        var reopen = [], html = '', t;
        while ((t = stack.pop()) !== name) { html += INLINE[t][1]; reopen.push(t); }
        html += INLINE[name][1];
        reopen.reverse().forEach(function (r) { stack.push(r); html += INLINE[r][0]; });
        return html;
      });
      while (stack.length) { safe += INLINE[stack.pop()][1]; }

      return '<div style="text-align:' + align.value + '">' + (safe !== '' ? safe : '&nbsp;') + '</div>';
    }

    function markup(text) {
      var align = { value: 'center' };
      return text.split(/\r\n|\r|\n/).map(function (line) { return markupLine(line, align); }).join('');
    }

    // --- ツールバー操作 ---
    function fireInput(ta) { ta.dispatchEvent(new Event('input')); }

    function wrapSelection(ta, tag) {
      var s = ta.selectionStart, e = ta.selectionEnd;
      var before = '<' + tag + '>', after = '</' + tag + '>';
      ta.value = ta.value.slice(0, s) + before + ta.value.slice(s, e) + after + ta.value.slice(e);
      ta.focus();
      ta.selectionStart = s + before.length;
      ta.selectionEnd = e + before.length;
      fireInput(ta);
    }

    function setLineAlign(ta, align) {
      var s = ta.selectionStart;
      var lineStart = ta.value.lastIndexOf('\n', s - 1) + 1;
      // 既存の行頭配置タグを置き換える
      var rest = ta.value.slice(lineStart);
      var stripped = rest.replace(/^\s*<(left|center|right)>\s*/i, '');
      var removed = rest.length - stripped.length;
      var tag = '<' + align + '>';
      ta.value = ta.value.slice(0, lineStart) + tag + stripped;
      var caret = Math.max(lineStart + tag.length, s - removed + tag.length);
      ta.focus();
      ta.selectionStart = ta.selectionEnd = caret;
      fireInput(ta);
    }

    document.querySelectorAll('.receipt-field').forEach(function (field) {
      var src = field.querySelector('.receipt-src');
      var target = document.getElementById(src.dataset.preview);
      function render() { target.innerHTML = markup(src.value); }
      src.addEventListener('input', render);
      render();

      field.querySelectorAll('[data-wrap]').forEach(function (btn) {
        btn.addEventListener('click', function () { wrapSelection(src, btn.dataset.wrap); });
      });
      field.querySelectorAll('[data-align]').forEach(function (btn) {
        btn.addEventListener('click', function () { setLineAlign(src, btn.dataset.align); });
      });
    });
  })();
</script>

<script>
  // レシート幅: プリセットボタンとプレビュー幅の連動 (範囲は public/lib/bootstrap.php の parse_receipt_width() と同じ)
  (function () {
    var MIN = <?= format_mm(RECEIPT_WIDTH_MIN) ?>, MAX = <?= format_mm(RECEIPT_WIDTH_MAX) ?>, DEF = <?= format_mm(RECEIPT_WIDTH_DEFAULT) ?>;
    var input = document.getElementById('receipt_width_mm');

    function currentWidth() {
      var v = parseFloat(input.value);
      if (isNaN(v)) { return DEF; }
      return Math.round(Math.min(MAX, Math.max(MIN, v)) * 10) / 10;
    }
    function render() {
      var w = currentWidth();
      document.querySelectorAll('.receipt-preview').forEach(function (p) { p.style.width = w + 'mm'; });
      document.querySelectorAll('.receipt-width-label').forEach(function (l) { l.textContent = w; });
    }

    input.addEventListener('input', render);
    document.querySelectorAll('[data-receipt-width]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        input.value = btn.dataset.receiptWidth;
        render();
      });
    });
    render();
  })();
</script>

<script>
  // 価格接頭詞・接尾辞の表示例 (public/lib/bootstrap.php の yen() / regular_yen() と同じ書式)
  [['price_prefix', 'price_suffix', 'price-preview', '1,234円'],
   ['regular_price_prefix', 'regular_price_suffix', 'regular-price-preview', '1,500円']].forEach(function (ids) {
    var prefix = document.getElementById(ids[0]);
    var suffix = document.getElementById(ids[1]);
    var out = document.getElementById(ids[2]);
    function render() { out.textContent = prefix.value + ids[3] + suffix.value; }
    prefix.addEventListener('input', render);
    suffix.addEventListener('input', render);
    render();
  });
</script>

<?php require __DIR__ . '/../lib/footer.php'; ?>
