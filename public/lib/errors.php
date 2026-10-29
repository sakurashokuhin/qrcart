<?php
declare(strict_types=1);

/**
 * ログ出力と、捕捉されなかったエラーの表示。
 *
 * - ログはリポジトリ直下 (public/ の1つ上) の log/ に月ごとのファイルで保存する
 *   (例: log/qrcart-2026-10.log)。環境変数 QRCART_LOG_DIR で保存先を変更できる。
 *   log/ に書き込めない場合は PHP のエラーログ (error_log) に出力する。
 * - 捕捉されなかった例外は、利用者画面では簡単なお知らせを、管理画面 (/admin/) では
 *   原因と対処方法を表示する。詳細は常にログに記録する。
 */

define('LOG_DIR', getenv('QRCART_LOG_DIR') ?: dirname(__DIR__, 2) . '/log');

// タイムゾーン (ログの時刻・申込番号の日付に使う)。既定は日本時間。
// 環境変数 QRCART_TIMEZONE で変更できる (例: SetEnv QRCART_TIMEZONE UTC)。
// ※PHP の date.timezone は未設定でも "UTC" になるため、サーバー設定には頼らない。
if (!@date_default_timezone_set(getenv('QRCART_TIMEZONE') ?: 'Asia/Tokyo')) {
    date_default_timezone_set('Asia/Tokyo');
}

/** ログファイルのパス (月ごと) */
function app_log_file(): string
{
    return LOG_DIR . '/qrcart-' . date('Y-m') . '.log';
}

/**
 * ログを1行追記する。$level は 'info' | 'warning' | 'error'。
 * 改行は空白に置き換えて1件1行にする。パスワードなどの秘密情報は渡さないこと。
 */
function app_log(string $level, string $message): void
{
    $line = sprintf(
        "[%s] %s %s | %s %s | %s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        preg_replace('/\s*[\r\n]+\s*/', ' ', $message),
        $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        $_SERVER['REQUEST_URI'] ?? '-',
        $_SERVER['REMOTE_ADDR'] ?? '-'
    );

    // log/ がなければ作成し、Web から見えないように .htaccess も置く
    if (!is_dir(LOG_DIR) && @mkdir(LOG_DIR, 0755, true)) {
        @file_put_contents(LOG_DIR . '/.htaccess', "Require all denied\n");
    }
    if (@file_put_contents(app_log_file(), $line, FILE_APPEND | LOCK_EX) === false) {
        error_log('qrcart: ' . rtrim($line)); // log/ に書き込めない場合は PHP のエラーログへ
    }
}

/**
 * エラーの種類ごとの、管理者向けの見出しと対処方法。
 * キーは DbSetupException::$kind / pdo_error_kind() の値。
 */
const ERROR_HELP = [
    'cnf_missing' => [
        'データベースの接続設定ファイルが見つかりません',
        [
            '<code>qrcart-my.cnf.example</code> をコピーして <code>qrcart-my.cnf</code> を作成し、下の「技術情報」に表示された場所に置いてください。',
            '別の場所に置く場合は、<code>public/.htaccess</code> に <code>SetEnv QRCART_DB_CNF /フルパス/qrcart-my.cnf</code> を追加してください。',
            'README の「1. 接続設定ファイルの作成」も参照してください。',
        ],
    ],
    'cnf_unreadable' => [
        'データベースの接続設定ファイルを読み取れません',
        [
            'ファイルの権限を確認してください (例: <code>chmod 600 qrcart-my.cnf</code>。PHP の実行ユーザーが読み取れる必要があります)。',
        ],
    ],
    'cnf_invalid' => [
        'データベースの接続設定ファイルの書式が正しくありません',
        [
            '<code>[client]</code> の行と <code>名前 = 値</code> の行が正しく書かれているか確認してください。',
            'コメント行は行頭を <code>#</code> または <code>;</code> で始めてください。',
            'パスワードに <code>;</code> や <code>"</code> などの記号が含まれる場合は、<code>password = "パスワード"</code> のようにダブルクォートで囲んでください。',
        ],
    ],
    'cnf_no_client' => [
        'データベースの接続設定ファイルに [client] セクションがありません',
        [
            'ファイルの先頭に <code>[client]</code> の行を書き、その下に host・user・password・database を書いてください。',
        ],
    ],
    'driver_missing' => [
        'PHP の MySQL ドライバー (pdo_mysql) が有効になっていません',
        [
            'PHP の設定で <code>pdo_mysql</code> 拡張を有効にしてください (レンタルサーバーではコントロールパネルの PHP 設定を確認)。',
        ],
    ],
    'access_denied' => [
        'データベースのユーザー名またはパスワードが違います',
        [
            '<code>qrcart-my.cnf</code> の <code>user</code> と <code>password</code> を確認してください。',
            'パスワードに記号が含まれる場合は、ダブルクォートで囲んでください。',
        ],
    ],
    'db_denied' => [
        'このユーザーにはデータベースへのアクセス権限がありません',
        [
            '<code>qrcart-my.cnf</code> の <code>database</code> が正しいか、ユーザーにそのデータベースの権限があるか確認してください。',
        ],
    ],
    'unknown_db' => [
        'データベースが見つかりません',
        [
            '<code>qrcart-my.cnf</code> の <code>database</code> にデータベース名が正しく書かれているか確認してください。',
            'データベースを作成していない場合は、README の「2. データベースとテーブルの作成」を参照してください。',
        ],
    ],
    'cannot_connect' => [
        'データベースサーバーに接続できません',
        [
            '<code>qrcart-my.cnf</code> の <code>host</code> と <code>port</code> を確認してください (レンタルサーバーではコントロールパネルに表示されるホスト名)。',
            'データベースサーバーが起動しているか確認してください。',
        ],
    ],
    'table_missing' => [
        'データベースにテーブルがありません',
        [
            '<code>schema.sql</code> を実行してテーブルを作成してください (README の「2. データベースとテーブルの作成」)。',
        ],
    ],
    'column_missing' => [
        'データベースのテーブルの列が、このバージョンのプログラムと一致しません',
        [
            '古いバージョンの <code>schema.sql</code> で作成したテーブルを使っている可能性があります。',
            '下の「技術情報」に表示された列名を確認し、テーブルを最新の <code>schema.sql</code> に合わせてください。',
        ],
    ],
    'db_other' => [
        'データベースでエラーが発生しました',
        [
            '下の「技術情報」とログファイルの内容を確認してください。',
        ],
    ],
    'other' => [
        '予期しないエラーが発生しました',
        [
            '下の「技術情報」とログファイルの内容を確認してください。',
        ],
    ],
];

/** 例外の種類を ERROR_HELP のキーで返す */
function error_kind(Throwable $e): string
{
    if ($e instanceof DbSetupException) {
        return $e->kind;
    }
    if ($e instanceof PDOException) {
        return pdo_error_kind($e);
    }
    return 'other';
}

/** 捕捉されなかった例外: ログに記録してエラー画面を表示する */
function handle_uncaught_exception(Throwable $e): void
{
    app_log('error', sprintf('%s: %s (%s:%d)', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine()));

    // 途中まで出力したページは捨てて、エラー画面だけを表示する
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    render_error_page(error_kind($e), $e, str_contains($scriptName, '/admin/'));
}

/** 致命的エラー (メモリ不足など、例外にならないもの) をログに記録する */
function log_fatal_error(): void
{
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        app_log('error', "PHP Fatal error: {$err['message']} ({$err['file']}:{$err['line']})");
    }
}

/**
 * エラー画面を出力する。
 * 利用者画面では原因の詳細 (ファイルパスなど) を表示せず、管理画面でのみ原因と対処方法を表示する。
 */
function render_error_page(string $kind, Throwable $e, bool $forAdmin): void
{
    [$title, $hints] = ERROR_HELP[$kind] ?? ERROR_HELP['other'];
    // ENT_SUBSTITUTE: OS のエラーメッセージなど UTF-8 でない文字列も空にせず表示する
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $forAdmin ? $esc($title) : 'エラー' ?></title>
<style>
  body { margin: 0; padding: 24px 16px; background: #f5f5f5; color: #212529;
         font-family: "Hiragino Kaku Gothic ProN", "Yu Gothic", "Meiryo", sans-serif; line-height: 1.7; }
  .box { max-width: 720px; margin: 0 auto; background: #fff; border: 1px solid #dee2e6;
         border-top: 4px solid #dc3545; border-radius: 6px; padding: 20px 24px; }
  h1 { font-size: 1.25rem; margin: 0 0 12px; color: #b02a37; }
  h2 { font-size: 1rem; margin: 20px 0 6px; }
  ul { padding-left: 1.4em; margin: 0; }
  code { background: #f1f3f5; padding: 1px 4px; border-radius: 3px; font-size: .9em; }
  pre { background: #212529; color: #f8f9fa; padding: 12px; border-radius: 4px;
        white-space: pre-wrap; word-break: break-all; font-size: .85rem; margin: 0; }
  .muted { color: #6c757d; font-size: .9rem; }
</style>
</head>
<body>
<div class="box">
<?php if ($forAdmin): ?>
  <h1><?= $esc($title) ?></h1>
  <h2>対処方法</h2>
  <ul>
    <?php foreach ($hints as $hint): ?>
    <li><?= $hint /* ERROR_HELP の固定文言 (HTML) */ ?></li>
    <?php endforeach; ?>
  </ul>
  <h2>技術情報</h2>
  <pre><?= $esc(get_class($e) . ': ' . $e->getMessage()) ?></pre>
  <p class="muted">
    同じ内容がログファイル <code><?= $esc(app_log_file()) ?></code> に記録されています
    (書き込めない場合は PHP のエラーログ)。
  </p>
<?php else: ?>
  <h1>ただいまご利用いただけません</h1>
  <p>システムの設定に問題があるため、処理を完了できませんでした。お手数ですが、係員にお知らせください。</p>
  <p class="muted">管理者の方へ: 管理画面 (/admin/) を開くと原因と対処方法が表示されます。詳細はログファイルに記録されています。</p>
<?php endif; ?>
</div>
</body>
</html>
    <?php
}

set_exception_handler('handle_uncaught_exception');
register_shutdown_function('log_fatal_error');
