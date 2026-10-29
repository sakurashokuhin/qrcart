# Contributing to qrcart

Issue・Pull Request を歓迎します。 / Issues and pull requests are welcome.

## 開発環境 / Development Setup

1. PHP 8.3 (拡張 `pdo_mysql`・`mbstring` を有効にする) と MySQL 8.0 を用意します。
2. `qrcart-my.cnf.example` を `qrcart-my.cnf` にコピーし、ローカルの DB 接続情報を記入します。
3. `mysql --defaults-extra-file=qrcart-my.cnf < schema.sql` でテーブルとサンプルデータを作成します。
4. `php -S localhost:8080 -t public` で起動し、`http://localhost:8080/` を開きます。

## ガイドライン / Guidelines

- 外部への通信 (CDN など) を追加しないでください。閉域網で動作することを前提としています。
  Do not add runtime dependencies on external networks/CDNs; the app must work offline.
- フレームワークや Composer 依存は追加せず、素の PHP で記述します。
- `declare(strict_types=1);` を維持し、SQL は必ずプリペアドステートメントを使用します。
- HTML への出力は `h()` でエスケープします。
- DB スキーマを変更する場合は `schema.sql` を更新し、Pull Request に既存環境向けの
  `ALTER TABLE` 文を記載してください。
- 実在の個人名・企業名・URL・認証情報をコミットに含めないでください。
- PHP 側の `yen()` / `receipt_markup()` を変更した場合は、対応する JS 実装
  (`public/cart.php` / `public/admin/settings.php`) も更新してください。

## Pull Request

- 変更の目的と動作確認の内容を記載してください。
- 1つの Pull Request には1つの目的の変更をまとめてください。
