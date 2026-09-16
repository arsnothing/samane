--
-- upgrade_v6.1.sql
-- تب «متقاضیان برگزاری آموزش»: فیلد شماره قائد روی پرونده عنصر + جدول نشان بازآموزی.
-- این فایل را روی دیتابیس موجود اجرا کنید (phpMyAdmin یا خط فرمان mysql).
-- اجرای دوباره آن بی‌خطر است: اگر ستون/جدول از قبل باشد، خطای بی‌ضرر می‌دهد یا رد می‌شود.
--

-- شماره قائدِ عنصر (اختیاری). اگر این ستون از قبل وجود دارد، این خط را حذف کنید و ادامه فایل را اجرا کنید.
ALTER TABLE `personnel`
  ADD COLUMN `commander_number` VARCHAR(30) DEFAULT NULL AFTER `city_id`;

-- نشان «نیاز به بازآموزی» برای هر (دوره، عنصر).
-- تا وقتی این جدول ساخته نشود، تب متقاضیان فقط وضعیت «گذرانده/نگذرانده» را نشان می‌دهد.
CREATE TABLE IF NOT EXISTS `training_applicants` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `course_key` varchar(100) NOT NULL,
  `personnel_id` int(10) UNSIGNED NOT NULL,
  `applicant_type` varchar(20) NOT NULL DEFAULT 'retraining',
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_training_applicant` (`course_key`,`personnel_id`),
  KEY `idx_training_applicants_person` (`personnel_id`),
  KEY `fk_training_applicants_created` (`created_by`),
  CONSTRAINT `fk_training_applicants_person` FOREIGN KEY (`personnel_id`) REFERENCES `personnel` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_training_applicants_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
