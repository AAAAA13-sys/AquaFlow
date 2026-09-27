CREATE DATABASE IF NOT EXISTS `aquaflow_db`;
USE `aquaflow_db`;

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `production_queue`;
DROP TABLE IF EXISTS `container_custody_logs`;
DROP TABLE IF EXISTS `transaction_items`;
DROP TABLE IF EXISTS `transactions`;
DROP TABLE IF EXISTS `demand_forecasts`;
DROP TABLE IF EXISTS `inventory`;
DROP TABLE IF EXISTS `suppliers`;
DROP TABLE IF EXISTS `customers`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `system_settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- 1. USERS & ACCESS CONTROL (RBAC)
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('cashier', 'admin') NOT NULL DEFAULT 'cashier',
  `name` VARCHAR(100) NOT NULL,
  `pin` VARCHAR(4) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`role`)
);

-- 2. CUSTOMERS & RECEIVABLES LEDGER
CREATE TABLE `customers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `address` VARCHAR(255) NOT NULL DEFAULT '-',
  `contact` VARCHAR(50) NOT NULL DEFAULT '-',
  `issued_slim` INT NOT NULL DEFAULT 0,
  `returned_slim` INT NOT NULL DEFAULT 0,
  `issued_round` INT NOT NULL DEFAULT 0,
  `returned_round` INT NOT NULL DEFAULT 0,
  `debt_balance` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_transactions` INT NOT NULL DEFAULT 0,
  `last_visit` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`name`),
  INDEX (`contact`)
);

-- 3. PRODUCTS & POS CATALOG
CREATE TABLE `products` (
  `id` VARCHAR(30) PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `category` ENUM('refill', 'consumable', 'cleaning', 'container') NOT NULL,
  `container_kind` ENUM('S', 'R', 'X') NOT NULL DEFAULT 'X',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (`category`)
);

-- 4. SUPPLIERS DIRECTORY
CREATE TABLE `suppliers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(120) NOT NULL,
  `supplied_items` VARCHAR(255) NOT NULL,
  `lead_time_days` INT NOT NULL DEFAULT 2,
  `contact` VARCHAR(50) NOT NULL DEFAULT '-',
  `last_delivery` DATE NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 5. CONSUMABLE INVENTORY & DYNAMIC ROP THRESHOLDS
CREATE TABLE `inventory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `item_name` VARCHAR(150) NOT NULL UNIQUE,
  `category` ENUM('Consumable', 'Filtration', 'Cleaning', 'Asset') NOT NULL,
  `stock_on_hand` INT NOT NULL DEFAULT 0,
  `unit` VARCHAR(20) NOT NULL DEFAULT 'pcs',
  `safety_stock` INT NOT NULL DEFAULT 0,
  `reorder_point` INT NOT NULL DEFAULT 0,
  `lead_time_days` INT NOT NULL DEFAULT 2,
  `supplier_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE SET NULL ON UPDATE CASCADE
);

-- 6. POS TRANSACTIONS AUDIT LOG
CREATE TABLE `transactions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `receipt_number` VARCHAR(30) NOT NULL UNIQUE,
  `customer_id` INT NOT NULL,
  `cashier_id` INT NOT NULL,
  `order_type` ENUM('Walk-in', 'Delivery', 'Debt Payment') NOT NULL DEFAULT 'Walk-in',
  `gallons_volume` VARCHAR(30) NOT NULL DEFAULT '0 gal',
  `subtotal_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `auto_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `manual_discount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('Cash', 'GCash', 'Account') NOT NULL DEFAULT 'Cash',
  `cash_tendered` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `cash_change` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `transaction_date` DATE NOT NULL,
  `transaction_time` TIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  FOREIGN KEY (`cashier_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  INDEX (`transaction_date`),
  INDEX (`order_type`)
);

-- 7. TRANSACTION LINE ITEMS
CREATE TABLE `transaction_items` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT NOT NULL,
  `product_id` VARCHAR(30) NOT NULL,
  `item_name` VARCHAR(100) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `unit_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `line_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- 8. CIRCULAR CONTAINER CUSTODY AUDIT LOGS
CREATE TABLE `container_custody_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `transaction_id` INT NOT NULL,
  `customer_id` INT NOT NULL,
  `slim_out` INT NOT NULL DEFAULT 0,
  `slim_in` INT NOT NULL DEFAULT 0,
  `round_out` INT NOT NULL DEFAULT 0,
  `round_in` INT NOT NULL DEFAULT 0,
  `deficit_slim` INT NOT NULL DEFAULT 0,
  `deficit_round` INT NOT NULL DEFAULT 0,
  `damaged_reported` TINYINT(1) NOT NULL DEFAULT 0,
  `logged_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`transaction_id`) REFERENCES `transactions`(`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- 9. PRODUCTION LINE QUEUE
CREATE TABLE `production_queue` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `receipt_number` VARCHAR(30) NOT NULL,
  `customer_name` VARCHAR(150) NOT NULL,
  `stage` INT NOT NULL DEFAULT 0,
  `elapsed_minutes` INT NOT NULL DEFAULT 0,
  `items_description` VARCHAR(100) NOT NULL DEFAULT '5 gal',
  `order_type` ENUM('Walk-in', 'Delivery') NOT NULL DEFAULT 'Walk-in',
  `is_completed` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- 10. ARIMA DEMAND FORECAST LOGS
CREATE TABLE `demand_forecasts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `series_name` VARCHAR(50) NOT NULL DEFAULT 'Refill Gallons',
  `horizon_type` ENUM('Daily', 'Weekly', 'Monthly') NOT NULL DEFAULT 'Daily',
  `forecast_date` DATE NOT NULL,
  `historical_data` JSON NOT NULL,
  `forecasted_data` JSON NOT NULL,
  `model_order` VARCHAR(30) NOT NULL DEFAULT 'ARIMA(1,1,1)',
  `aic_score` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `mape_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `mae_score` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `rmse_score` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- 11. SYSTEM SETTINGS & CONFIGURATION
CREATE TABLE `system_settings` (
  `setting_key` VARCHAR(50) PRIMARY KEY,
  `setting_value` TEXT NOT NULL,
  `description` VARCHAR(255) NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
