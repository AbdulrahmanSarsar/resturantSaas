-- ======================================================
-- Migration: إضافة جدول login_attempts
-- الغرض: حماية Brute Force على صفحات الـ Login
-- التاريخ: 2026-05-07
-- ======================================================

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45)  NOT NULL,
    attempted_at DATETIME     NOT NULL DEFAULT NOW(),
    INDEX idx_ip      (ip),
    INDEX idx_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
