-- Run in phpMyAdmin on the live database if pages still 500 after uploading code.
-- Safe to run more than once on MariaDB / MySQL 8+ (IF NOT EXISTS).

ALTER TABLE customers ADD COLUMN IF NOT EXISTS birthday DATE NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS last_birthday_reward_year SMALLINT UNSIGNED NULL;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS account_bonus_awarded TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customers ADD COLUMN IF NOT EXISTS newsletter_bonus_awarded TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE point_transactions ADD COLUMN IF NOT EXISTS available_at TIMESTAMP NULL;

ALTER TABLE rewards ADD COLUMN IF NOT EXISTS slug VARCHAR(255) NULL;

ALTER TABLE redemptions ADD COLUMN IF NOT EXISTS shopify_object_type VARCHAR(255) NOT NULL DEFAULT 'discount_code';
ALTER TABLE redemptions ADD COLUMN IF NOT EXISTS product_gid VARCHAR(255) NULL;
