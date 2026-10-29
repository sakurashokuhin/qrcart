<?php
declare(strict_types=1);

/**
 * MySQL 接続設定ファイルのパス (Web 公開ディレクトリの外に置くこと)。
 * 環境変数 QRCART_DB_CNF があればそれを使い、なければリポジトリ直下
 * (public/ の1つ上) の qrcart-my.cnf を読む。
 */
define('DB_CNF_PATH', getenv('QRCART_DB_CNF') ?: dirname(__DIR__, 2) . '/qrcart-my.cnf');

/**
 * DB 接続の準備段階 (設定ファイル・接続) の問題を表す例外。
 * $kind で原因を区別し、エラー画面で原因ごとの対処方法を表示する (lib/errors.php)。
 */
final class DbSetupException extends RuntimeException
{
    public function __construct(public string $kind, string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

/**
 * 設定ファイルを読み込んで PDO 接続を返す。
 * 接続は同一リクエスト内で使い回す。
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $cnfPath = DB_CNF_PATH;
    if (!@is_file($cnfPath)) {
        throw new DbSetupException('cnf_missing', "接続設定ファイルが見つかりません: {$cnfPath}");
    }
    if (!@is_readable($cnfPath)) {
        throw new DbSetupException('cnf_unreadable', "接続設定ファイルを読み取る権限がありません: {$cnfPath}");
    }

    $content = @file_get_contents($cnfPath);
    if ($content === false) {
        throw new DbSetupException('cnf_unreadable', "接続設定ファイルを読み取れません: {$cnfPath}");
    }
    // MySQL の設定ファイルと同じく「#」で始まる行をコメントとして扱う (PHP の ini 形式は「;」のみ)。
    // MySQL 独自の !include / !includedir の行も読み飛ばす。行番号がずれないよう空行に置き換える。
    // 先頭の BOM (Windows のメモ帳などが付ける) も取り除く。
    $content = preg_replace(['/^\xEF\xBB\xBF/', '/^[ \t]*[#!].*$/m'], '', $content);

    // INI_SCANNER_RAW: パスワードの記号 (! = など)・先頭の 0・yes/on/none などを変換せずにそのまま読む
    $config = @parse_ini_string($content, true, INI_SCANNER_RAW);
    if ($config === false) {
        $reason = trim(error_get_last()['message'] ?? '');
        throw new DbSetupException('cnf_invalid', "接続設定ファイルの書式が正しくありません: {$cnfPath} ({$reason})");
    }
    if (!isset($config['client']) || !is_array($config['client'])) {
        throw new DbSetupException('cnf_no_client', "接続設定ファイルに [client] セクションがありません: {$cnfPath}");
    }
    $c = $config['client'];

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? '127.0.0.1',
        (int)($c['port'] ?? 3306),
        $c['database'] ?? 'qrcart'
    );

    try {
        $pdo = new PDO($dsn, (string)($c['user'] ?? ''), (string)($c['password'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        throw new DbSetupException(pdo_error_kind($e), 'データベースに接続できません: ' . $e->getMessage(), $e);
    }

    // MySQL の NOW()・CURRENT_TIMESTAMP (申込日時・キャンセル日時) を PHP のタイムゾーン
    // (申込番号の日付・ログの時刻。lib/errors.php で設定) に合わせる。
    // DB サーバーが UTC でも、申込番号の日付と申込日時の日付がずれないようにするため。
    $pdo->exec("SET time_zone = '" . date('P') . "'");

    return $pdo;
}

/**
 * PDOException の原因を分類する (エラー画面のヒント表示用)。
 * MySQL のエラー番号: https://dev.mysql.com/doc/mysql-errors/8.0/en/
 */
function pdo_error_kind(PDOException $e): string
{
    if (stripos($e->getMessage(), 'could not find driver') !== false) {
        return 'driver_missing';
    }
    $code = (int)($e->errorInfo[1] ?? 0);
    if ($code === 0 && preg_match('/\[(\d{4})\]/', $e->getMessage(), $m)) {
        $code = (int)$m[1];
    }
    return match ($code) {
        1045                   => 'access_denied',
        1044                   => 'db_denied',
        1049                   => 'unknown_db',
        2002, 2003, 2005, 2006 => 'cannot_connect',
        1146                   => 'table_missing',
        1054                   => 'column_missing',
        default                => 'db_other',
    };
}
