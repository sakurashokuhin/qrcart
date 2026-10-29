<?php
declare(strict_types=1);

// エラー表示・ログ (log/) を最初に読み込む。出力はバッファしておき、
// 途中でエラーになったときは出力済みの部分を捨ててエラー画面だけを表示する。
require_once __DIR__ . '/errors.php';
ob_start();

session_start();

require_once __DIR__ . '/db.php';

/**
 * HTML エスケープ。
 * ENT_SUBSTITUTE: 不正な UTF-8 を含む文字列も空にせず、該当部分だけ置換文字にして表示する。
 */
function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * 文字列を JavaScript の文字列リテラル (ダブルクォート付き) にする。
 * onsubmit="..." などの属性に埋め込むときは、さらに h() でエスケープすること。
 * 例: onsubmit="return confirm(<?= h(js_string($message)) ?>);"
 * (名前に ' や " が含まれていてもスクリプトが壊れない)
 */
function js_string(string $s): string
{
    // JSON_INVALID_UTF8_SUBSTITUTE: 文字化けしたデータ (不正な UTF-8) があってもページ全体を止めない
    return json_encode($s, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE);
}

/**
 * 複数行テキストの改行を LF に統一し、前後の空白を除去する。
 * フォーム (textarea) は CRLF、Excel のセル内改行 (Alt+Enter) は LF で送られてくるため、
 * 保存時に揃えておくと CSV エクスポート時に Excel でセル内改行として正しく表示される。
 */
function normalize_multiline(string $s): string
{
    return trim(str_replace(["\r\n", "\r"], "\n", $s));
}

/**
 * public/ からの相対パスに ?v=更新日時 を付けて返す (例: assets/img/a.jpg?v=1760000000)。
 * assets/ はブラウザに長期キャッシュさせているため、同じファイル名で画像を差し替えても
 * URL が変わって新しい画像が読み込まれるようにする。ファイルが見つからない場合はそのまま返す。
 */
function asset_url(string $path): string
{
    if ($path === '' || str_contains($path, '?') || str_contains($path, '://')) {
        return $path;
    }
    $mtime = @filemtime(__DIR__ . '/../' . $path);
    return $mtime === false ? $path : $path . '?v=' . $mtime;
}

/** ログイン中のユーザー情報 (id, qr_code, name) を返す。未ログインなら null */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** 未ログインならログイン画面へリダイレクトして終了 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: index.php');
        exit;
    }
    return $user;
}

/**
 * クライアント (localStorage) から POST された カート JSON を検証して
 * [itemId => quantity] の配列にして返す。
 * 不正なキー・数量 (1〜99 以外) は取り除く。
 */
function parse_posted_cart(string $json): array
{
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $cart = [];
    foreach ($decoded as $id => $qty) {
        $id  = (int)$id;
        $qty = (int)$qty;
        if ($id > 0 && $qty >= 1 && $qty <= 99) {
            $cart[$id] = $qty;
        }
    }
    return $cart;
}

/**
 * 販売価格の金額表示 (例: 1,234円)。設定「価格接頭詞」「価格接尾辞」を前後に付ける。
 * 戻り値はプレーンテキストなので、HTML に出力するときは h() でエスケープすること。
 * ※この関数を変更したら public/cart.php の yen() (JS) も合わせて更新すること。
 */
function yen(int $amount): string
{
    return setting('price_prefix') . number_format($amount) . '円' . setting('price_suffix');
}

/**
 * 通常価格の金額表示 (例: 1,234円)。設定「通常価格接頭詞」「通常価格接尾辞」を前後に付ける。
 * 戻り値はプレーンテキストなので、HTML に出力するときは h() でエスケープすること。
 */
function regular_yen(int $amount): string
{
    return setting('regular_price_prefix') . number_format($amount) . '円' . setting('regular_price_suffix');
}

/**
 * 設定テーブル (settings) の全キー・値を連想配列で返す。1リクエスト内でキャッシュする。
 */
function settings(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT name, value FROM settings') as $row) {
                $cache[$row['name']] = $row['value'];
            }
        } catch (Throwable $e) {
            // settings テーブル未作成 (schema.sql 未適用) でも致命的にしない
            app_log('warning', 'settings() query failed: ' . $e->getMessage());
        }
    }
    return $cache;
}

/** 設定値を1件返す。未設定なら $default */
function setting(string $name, string $default = ''): string
{
    return settings()[$name] ?? $default;
}

/** サイトのタイトル (ナビゲーションバー・ブラウザのタブ・ログイン画面に表示)。未設定なら既定値 */
function site_title(): string
{
    $title = trim(setting('site_title'));
    return $title !== '' ? $title : '申込みシステム';
}

/**
 * ログイン画面で最初に使うカメラ。'user' = インカメラ (前面)、'environment' = アウトカメラ (背面)。
 * 設定 login_camera が未設定・不正ならインカメラ。
 */
function login_camera(): string
{
    return setting('login_camera') === 'environment' ? 'environment' : 'user';
}

/**
 * 商品の文字列項目の長さが schema.sql の列の長さに収まっているか (追加・編集フォーム用)。
 * CSV 取込 (admin/items_import.php) も同じ上限で検証している。
 */
function item_lengths_valid(
    string $itemcode, string $picture, string $brand, string $name,
    string $misc1, string $misc2, string $caption
): bool {
    return mb_strlen($itemcode) <= 64 && mb_strlen($picture) <= 255 && mb_strlen($brand) <= 100
        && mb_strlen($name) <= 200 && mb_strlen($misc1) <= 100 && mb_strlen($misc2) <= 100
        && mb_strlen($caption) <= 255;
}

/** 商品の追加・編集フォームの入力エラーメッセージ */
const ITEM_INVALID_MESSAGE = '画像パス (255文字以内)・商品名 (200文字以内) を入力し、商品コードは64文字以内、'
    . 'メーカー・備考1・備考2は100文字以内、キャプションは255文字以内で、販売価格は0以上の数値を、'
    . '通常価格は空欄か0以上の整数を、在庫数は0以上の整数か「無制限」を指定してください。';

/**
 * 会員の QR コード値・氏名の長さが schema.sql の列の長さに収まっているか (追加・編集フォーム用)。
 * CSV 取込 (admin/users_import.php) も同じ上限で検証している。
 */
function user_lengths_valid(string $qrCode, string $name): bool
{
    return mb_strlen($qrCode) <= 255 && mb_strlen($name) <= 100;
}

/** 会員の追加・編集フォームの文字数エラーメッセージ */
const USER_TOO_LONG_MESSAGE = 'QRコード値は255文字以内、氏名は100文字以内で入力してください。';

/** レシート用紙の幅 (mm) の既定値・下限・上限 (上限は A4 の幅) */
const RECEIPT_WIDTH_DEFAULT = 62.0;
const RECEIPT_WIDTH_MIN     = 20.0;
const RECEIPT_WIDTH_MAX     = 210.0;

/**
 * 入力されたレシート幅 (mm) を検証して返す。全角数字も受け付ける。
 * 数値でなければ null、範囲外は上限・下限に丸める (小数第1位まで)。
 */
function parse_receipt_width(string $raw): ?float
{
    $raw = trim(strtr($raw, [
        '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
        '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9', '．' => '.',
    ]));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return round(min(RECEIPT_WIDTH_MAX, max(RECEIPT_WIDTH_MIN, (float)$raw)), 1);
}

/** レシート用紙の幅 (mm)。設定 receipt_width_mm が未設定・不正なら既定値 */
function receipt_width_mm(): float
{
    return parse_receipt_width(setting('receipt_width_mm')) ?? RECEIPT_WIDTH_DEFAULT;
}

/**
 * レシート用紙の長さを内容に合わせるか (連続紙・ロール紙向け。既定: 有効)。
 * 無効の場合はプリンタードライバーの用紙設定に従う。
 */
function receipt_fit_height(): bool
{
    return setting('receipt_fit_height', '1') === '1';
}

/** mm 値を表示・CSS 用の文字列にする (例: 62.0 → "62", 57.5 → "57.5") */
function format_mm(float $mm): string
{
    return rtrim(rtrim(number_format($mm, 1, '.', ''), '0'), '.');
}

/** 設定値を保存する (存在すれば更新)。 */
function save_setting(string $name, string $value): void
{
    db()->prepare(
        'INSERT INTO settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    )->execute([$name, $value]);
}

/**
 * このリクエストの URL から、サイトの公開ディレクトリの絶対 URL を推測して返す
 * (例: https://example.com/qrcart)。設定 site_base_url があればそれを優先する。
 */
function site_base_url(): string
{
    $configured = trim(setting('site_base_url'));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return $scheme . '://' . $host . $dir;
}

/**
 * Google Chat の Incoming Webhook にテキストを送信する。
 * 設定 chat_webhook_url が未設定なら何もしない。失敗しても例外は投げず false を返す。
 * (呼び出し側の処理 — 申込み確定など — を通知失敗で止めないため)
 */
function notify_chat(string $text): bool
{
    $url = trim(setting('chat_webhook_url'));
    if ($url === '' || stripos($url, 'https://') !== 0) {
        return false;
    }

    $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

    try {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=UTF-8'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
            ]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err  = curl_error($ch);
            curl_close($ch);
            if ($body === false || $code >= 400) {
                app_log('warning', "notify_chat failed: http={$code} err={$err}");
                return false;
            }
            return true;
        }

        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json; charset=UTF-8\r\n",
            'content'       => $payload,
            'timeout'       => 5,
            'ignore_errors' => true,
        ]]);
        return @file_get_contents($url, false, $ctx) !== false;
    } catch (Throwable $e) {
        app_log('warning', 'notify_chat exception: ' . $e->getMessage());
        return false;
    }
}

/**
 * レシートのヘッダー/フッター用の簡易マークアップを安全な HTML に変換する。
 * 対応タグ (大文字小文字は問わない):
 *   <left> <center> <right>          行頭に置くとその行以降の配置を切り替える (既定は中央)
 *   <bold>...</bold> / <b>...</b>    太字
 *   <large>...</large>               大きい文字
 *   <xlarge>...</xlarge>             特大の文字
 *   <small>...</small>               小さい文字
 * 太字・サイズ系タグの閉じ忘れは行末で自動的に閉じる。
 * それ以外の `<` `>` はそのまま文字として表示される。
 * ※この関数を変更したら public/admin/settings.php のプレビュー用 JS も合わせて更新すること。
 */
function receipt_markup(string $text): string
{
    // タグ名 => [開きタグHTML, 閉じタグHTML]
    static $inline = [
        'b'      => ['<strong>', '</strong>'],
        'bold'   => ['<strong>', '</strong>'],
        'large'  => ['<span class="rs-l">', '</span>'],
        'xlarge' => ['<span class="rs-xl">', '</span>'],
        'small'  => ['<span class="rs-s">', '</span>'],
    ];

    $align = 'center';
    $out   = [];

    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        $safe = h($line); // 先に全体をエスケープ (エスケープ後に許可タグだけ復元する)

        // 行頭の配置タグ: 以降の行に継続する
        if (preg_match('#^\s*&lt;(left|center|right)&gt;\s*#i', $safe, $m)) {
            $align = strtolower($m[1]);
            $safe  = preg_replace('#^\s*&lt;(left|center|right)&gt;\s*#i', '', $safe);
        }
        // 行の途中に現れた配置タグは無視 (削除)
        $safe = preg_replace('#&lt;/?(left|center|right)&gt;#i', '', $safe);

        // 太字・サイズ系タグをスタックで対応付けながら変換する
        $stack = [];
        $safe = preg_replace_callback(
            '#&lt;(/?)(b|bold|large|xlarge|small)&gt;#i',
            function ($m) use (&$stack, $inline) {
                $name = strtolower($m[2]);
                if ($m[1] !== '/') {
                    $stack[] = $name;
                    return $inline[$name][0];
                }
                if (!in_array($name, $stack, true)) {
                    return ''; // 対応する開きタグがない閉じタグは無視
                }
                // 対象タグまでの入れ子を一旦閉じ、対象を閉じ、残りを開き直す
                $reopen = [];
                $html   = '';
                while (($t = array_pop($stack)) !== $name) {
                    $html .= $inline[$t][1];
                    $reopen[] = $t;
                }
                $html .= $inline[$name][1];
                foreach (array_reverse($reopen) as $t) {
                    $stack[] = $t;
                    $html .= $inline[$t][0];
                }
                return $html;
            },
            $safe
        );
        // 閉じ忘れを行末で閉じる
        while (($t = array_pop($stack)) !== null) {
            $safe .= $inline[$t][1];
        }

        $out[] = '<div style="text-align:' . $align . '">' . ($safe !== '' ? $safe : '&nbsp;') . '</div>';
    }

    return implode('', $out);
}

/**
 * 全商品の在庫状況を返す。
 * [item_id => ['stock' => ?int, 'ordered' => int, 'remaining' => ?int]]
 * stock/remaining が null の場合は無制限。
 * $excludeOrderId を指定すると、その申込みの明細は「申込み済み」の集計から除外する
 * (自分の既存申込みを編集中に、自分の申込み分を在庫不足として扱わないようにするため)。
 * キャンセル済みの申込み (orders.cancelled_at が NOT NULL) の明細は常に集計から除外する
 * (キャンセルにより在庫が解放されるため)。
 */
function remaining_stock(PDO $pdo, ?int $excludeOrderId = null): array
{
    $stmt = $pdo->prepare(
        'SELECT i.id, i.stock,
                COALESCE(SUM(
                    CASE WHEN oi.order_id = ? OR o.cancelled_at IS NOT NULL THEN 0 ELSE oi.quantity END
                ), 0) AS ordered
         FROM items i
         LEFT JOIN order_items oi ON oi.item_id = i.id
         LEFT JOIN orders o ON o.id = oi.order_id
         GROUP BY i.id, i.stock'
    );
    $stmt->execute([$excludeOrderId ?? 0]);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $stock   = $row['stock'] !== null ? (int)$row['stock'] : null;
        $ordered = (int)$row['ordered'];
        $result[(int)$row['id']] = [
            'stock'     => $stock,
            'ordered'   => $ordered,
            'remaining' => $stock === null ? null : max(0, $stock - $ordered),
        ];
    }
    return $result;
}
