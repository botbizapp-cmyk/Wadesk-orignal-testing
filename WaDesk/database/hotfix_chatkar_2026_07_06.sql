-- =====================================================================
-- WaDesk / ChatKar hot-fix — run ONCE on the `chatkar` database via
-- phpMyAdmin, Adminer, or any SQL runner (no shell needed).
--
-- Fixes two "unknown / not-null column" crashes caused by Laravel
-- migrations that were never applied on this server:
--
--   1) contacts.mobile_hash MISSING
--        -> campaigns resolved 0 recipients ("Unknown column 'mobile_hash'")
--        -> also breaks chat / scheduled / template contact-capture.
--   2) wa_orders.customer_phone was NOT NULL
--        -> Shopify order sync crashed ("Column 'customer_phone' cannot be null")
--        -> phone-less orders (guest checkout / POS) never mirrored into the CRM.
--
-- SAFE TO RUN MORE THAN ONCE — every statement checks first / is idempotent.
-- Works on MySQL 5.7+/8.x and MariaDB.
--
-- LATER, when you have shell access, ALSO run:  php artisan migrate
--   It will backfill mobile_hash for EXISTING contacts and apply any other
--   pending migrations. The statements below will simply be skipped there.
-- =====================================================================

-- 1a) Add contacts.mobile_hash (VARCHAR(64), nullable) only if it's missing.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `contacts` ADD COLUMN `mobile_hash` VARCHAR(64) NULL AFTER `mobile`',
    'SELECT ''skip: contacts.mobile_hash already exists''')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'contacts'
    AND COLUMN_NAME  = 'mobile_hash'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1b) Add the lookup index on contacts.mobile_hash only if it's missing.
SET @sql := (
  SELECT IF(COUNT(*) = 0,
    'ALTER TABLE `contacts` ADD INDEX `contacts_mobile_hash_index` (`mobile_hash`)',
    'SELECT ''skip: contacts_mobile_hash_index already exists''')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'contacts'
    AND INDEX_NAME   = 'contacts_mobile_hash_index'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Make wa_orders.customer_phone nullable (idempotent — safe if already null).
ALTER TABLE `wa_orders` MODIFY `customer_phone` VARCHAR(32) NULL;

-- Done. Re-run the campaign and re-trigger the Shopify sync — both should work.
