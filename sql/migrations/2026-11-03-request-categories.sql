-- ============================================================================
-- Migration: 2026-11-03-request-categories.sql
-- Two-level hierarchical categories for requests (Category A -> Category B).
-- Target: MySQL 5.7+ / MariaDB 10.4+
--
-- Additive and re-runnable: every table, column and foreign key is checked
-- before creation so applying it again is a safe no-op.
-- ============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- 1. Create request_categories table
-- parent_id = NULL means top-level category (level 1).
-- parent_id NOT NULL points to a level-1 category (level 2).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `request_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id`   INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL = top-level category (level 1); NOT NULL = subcategory (level 2)',
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `sort_order`  INT NOT NULL DEFAULT 0,
  `created_at`  DATETIME NOT NULL,
  `updated_at`  DATETIME NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cat_parent` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraint for parent_id if not present
SET @has_fk_parent := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                       WHERE CONSTRAINT_SCHEMA = DATABASE()
                         AND TABLE_NAME = 'request_categories'
                         AND CONSTRAINT_NAME = 'fk_req_cat_parent');
SET @ddl_parent := IF(@has_fk_parent = 0,
  'ALTER TABLE `request_categories` ADD CONSTRAINT `fk_req_cat_parent` FOREIGN KEY (`parent_id`) REFERENCES `request_categories` (`id`) ON DELETE CASCADE',
  'DO 0');
PREPARE stmt_parent FROM @ddl_parent;
EXECUTE stmt_parent;
DEALLOCATE PREPARE stmt_parent;

-- ---------------------------------------------------------------------------
-- 2. Add category_id column to requests table
-- ---------------------------------------------------------------------------
SET @has_cat_col := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'requests'
                       AND COLUMN_NAME = 'category_id');
SET @ddl_col := IF(@has_cat_col = 0,
  'ALTER TABLE `requests` ADD COLUMN `category_id` INT UNSIGNED NULL DEFAULT NULL AFTER `connection_id`',
  'DO 0');
PREPARE stmt_col FROM @ddl_col;
EXECUTE stmt_col;
DEALLOCATE PREPARE stmt_col;

-- Add index on category_id if not present
SET @has_cat_idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'requests'
                       AND INDEX_NAME = 'idx_requests_category');
SET @ddl_idx := IF(@has_cat_idx = 0,
  'ALTER TABLE `requests` ADD KEY `idx_requests_category` (`category_id`)',
  'DO 0');
PREPARE stmt_idx FROM @ddl_idx;
EXECUTE stmt_idx;
DEALLOCATE PREPARE stmt_idx;

-- Add foreign key on requests.category_id if not present
SET @has_fk_req_cat := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
                        WHERE CONSTRAINT_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'requests'
                          AND CONSTRAINT_NAME = 'fk_requests_category');
SET @ddl_fk_req_cat := IF(@has_fk_req_cat = 0,
  'ALTER TABLE `requests` ADD CONSTRAINT `fk_requests_category` FOREIGN KEY (`category_id`) REFERENCES `request_categories` (`id`) ON DELETE SET NULL',
  'DO 0');
PREPARE stmt_fk_req_cat FROM @ddl_fk_req_cat;
EXECUTE stmt_fk_req_cat;
DEALLOCATE PREPARE stmt_fk_req_cat;

