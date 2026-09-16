--
-- upgrade_v5.3.sql
-- ماژول «آماد»: افزودن ستون وضعیت به تجهیزات واگذارشده + جدول انبار آماد.
-- این فایل را روی دیتابیس موجود اجرا کنید (phpMyAdmin یا خط فرمان mysql).
-- اجرای دوباره آن بی‌خطر است: اگر ستون/جدول از قبل باشد، خطای بی‌ضرر می‌دهد یا رد می‌شود.
--

-- وضعیت آمادی هر قلم تجهیز واگذارشده به یک عنصر (سالم/نیاز به تعمیر/در دست تعمیر/تعمیر شده).
-- اگر این ستون از قبل وجود دارد، این خط را حذف کنید و ادامه فایل را اجرا کنید.
ALTER TABLE `personnel_equipment`
  ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'healthy' AFTER `plate`;

-- انبار آماد: واحدهای ثبت‌شده از تب «ثبت آماد» که هنوز به هیچ عنصری واگذار نشده‌اند.
-- serial_number برای اقلام غیرخودرویی و plate برای خودرو/موتور به‌کار می‌رود (هر واحد فقط یکی از این دو را دارد).
CREATE TABLE IF NOT EXISTS `equipment_stock` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `equipment_type` varchar(100) NOT NULL,
  `serial_number` varchar(120) DEFAULT NULL,
  `plate` varchar(30) DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `color` varchar(80) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'healthy',
  `notes` text DEFAULT NULL,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_equipment_stock_serial` (`serial_number`),
  UNIQUE KEY `uq_equipment_stock_plate` (`plate`),
  KEY `idx_equipment_stock_type` (`equipment_type`),
  KEY `idx_equipment_stock_status` (`status`),
  KEY `fk_equipment_stock_created` (`created_by`),
  KEY `fk_equipment_stock_updated` (`updated_by`),
  CONSTRAINT `fk_equipment_stock_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_equipment_stock_updated` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
