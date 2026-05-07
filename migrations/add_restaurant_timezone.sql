-- ======================================================
-- Migration: إضافة عمود timezone لجدول restaurants
-- الغرض: دعم منطقة زمنية مخصصة لكل مطعم
-- التاريخ: 2026-05-07
-- ======================================================

ALTER TABLE restaurants
ADD COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Damascus'
AFTER currency_decimals;
