-- =====================================================================
-- qrcart  テーブル初期化スクリプト (MySQL 8.0)
--
-- データベースと接続ユーザーは事前に作成しておいてください
-- (接続情報は qrcart-my.cnf を参照)。このスクリプトはテーブルと
-- サンプルデータのみを作成します。
--
-- 実行方法 (qrcart-my.cnf の接続情報をそのまま利用):
--   mysql --defaults-extra-file=qrcart-my.cnf < schema.sql
-- または接続情報を指定して:
--   mysql -h <ホスト> -u <ユーザー> -p <データベース名> < schema.sql
-- =====================================================================

-- ---------------------------------------------------------------------
-- 会員 (QRコード値と表示名)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    qr_code VARCHAR(255) NOT NULL COMMENT 'QRコードに埋め込む値',
    name    VARCHAR(100) NOT NULL COMMENT '表示名',
    PRIMARY KEY (id),
    UNIQUE KEY uk_users_qr_code (qr_code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 商品 (表示順・画像パス・名前・価格)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    itemcode      VARCHAR(64)  NULL COMMENT '商品コード (任意・CSV全置換時の照合キー)',
    display_order INT          NOT NULL DEFAULT 0 COMMENT '表示順 (小さいほど先頭)',
    picture       VARCHAR(255) NOT NULL COMMENT '画像パス (public/ からの相対パス)',
    brand         VARCHAR(100) NULL COMMENT 'メーカー (任意・商品名の上に表示)',
    name          VARCHAR(200) NOT NULL COMMENT '商品名',
    price         INT UNSIGNED NOT NULL COMMENT '販売価格 (円)',
    regular_price INT UNSIGNED NULL COMMENT '通常価格 (円・任意。設定時は割引表示に使用)',
    misc1         VARCHAR(100) NULL COMMENT '備考1 (任意・利用者には非表示)',
    misc2         VARCHAR(100) NULL COMMENT '備考2 (任意・利用者には非表示)',
    caption       VARCHAR(255) NULL COMMENT 'キャプション (任意・商品名の下に表示)',
    stock         INT UNSIGNED NULL COMMENT '在庫数 (NULLは無制限)',
    PRIMARY KEY (id),
    UNIQUE KEY uk_items_itemcode (itemcode),
    KEY idx_items_display_order (display_order)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 申込み
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_number VARCHAR(32)  NOT NULL COMMENT '申込番号 (例: 20260610-00012)',
    user_id      INT UNSIGNED NOT NULL,
    remarks      TEXT         NULL COMMENT '備考欄',
    cancelled_at DATETIME     NULL COMMENT 'キャンセル日時 (NULLは未キャンセル)',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '申込日時',
    PRIMARY KEY (id),
    KEY idx_orders_user_id (user_id),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 申込み明細 (商品名・価格は申込み時点の値を保存)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id  INT UNSIGNED NOT NULL,
    item_id   INT UNSIGNED NOT NULL,
    item_name VARCHAR(200) NOT NULL COMMENT '申込み時点の商品名',
    price     INT UNSIGNED NOT NULL COMMENT '申込み時点の単価 (円)',
    quantity  INT UNSIGNED NOT NULL COMMENT '数量',
    PRIMARY KEY (id),
    KEY idx_order_items_order_id (order_id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 設定 (キー・値。レシートのヘッダー/フッター文言など)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS settings (
    name  VARCHAR(64) NOT NULL COMMENT '設定キー',
    value TEXT        NOT NULL COMMENT '設定値',
    PRIMARY KEY (name)
) ENGINE=InnoDB;

-- =====================================================================
-- サンプルデータ
-- =====================================================================

-- 会員: qr_code の文字列をそのまま QR コード化して配布してください。
INSERT INTO users (qr_code, name) VALUES
    ('MEMBER-0001-7f3a9c', '山田 太郎'),
    ('MEMBER-0002-b81d4e', '佐藤 花子'),
    ('MEMBER-0003-2c6f0a', '鈴木 一郎');

INSERT INTO items (display_order, picture, brand, name, price, misc1, misc2, caption) VALUES
    (10, 'assets/img/onigiri.svg',   'ひかり食品',   'おにぎり (梅)',  150, 'SC-0010', '個包装',       '国産米使用'),
    (20, 'assets/img/sandwich.svg',  'ひかり食品',   'サンドイッチ',   320, 'SC-0020', 'パック',       'たまご・ハム・レタス'),
    (30, 'assets/img/bento.svg',     'みどり弁当',   'からあげ弁当',   580, 'SC-0030', '弁当容器',     'ボリューム満点の人気No.1'),
    (40, 'assets/img/curry.svg',     'みどり弁当',   'カレーライス',   550, 'SC-0040', '弁当容器',     '中辛・福神漬け付き'),
    (50, 'assets/img/tea.svg',       'あおぞら飲料', 'お茶 (500ml)',   120, 'SC-0050', 'ペットボトル', NULL),
    (60, 'assets/img/coffee.svg',    'あおぞら飲料', 'コーヒー',       180, 'SC-0060', 'カップ',       'ホット/アイス選択可');

-- レシートのヘッダー/フッター文言 (管理画面 > 設定 で変更できます)
INSERT INTO settings (name, value) VALUES
    ('receipt_header', '<center><large><bold>サンプル商店 特売会場</bold></large>\nお申し込みありがとうございます'),
    ('receipt_footer', '<center>商品は後日お届けいたします\nお問い合わせ: 000-000-0000');
