# Security Policy

## 脆弱性の報告 / Reporting a Vulnerability

脆弱性を発見した場合は、公開 Issue ではなく GitHub の
**Security → Report a vulnerability** (Private vulnerability reporting) から非公開で報告してください。

Please do **not** open a public issue for security problems. Use GitHub's
**Security → Report a vulnerability** (private vulnerability reporting) instead.

報告には次の情報を含めてください / Please include:

- 影響を受けるファイル・画面 / affected file or page
- 再現手順 / steps to reproduce
- 想定される影響 / expected impact

## 既知の設計上の前提 / Known Design Assumptions

- 管理画面 (`public/admin/`) にはアプリケーション自体の認証がありません。
  Web サーバー側で Basic 認証・IP 制限等を設定することを前提としています
  (`public/.htaccess.example` を参照)。
  The admin panel has no built-in authentication by design; protect it at the
  web-server level (see `public/.htaccess.example`).
- 会員の QR コード値はログイン用の秘密情報として扱ってください。
  Treat member QR code values as login secrets.
