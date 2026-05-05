-- Migration: إضافة الباقة المجانية
-- Date: 2026-05-05
-- Description: إضافة قيمة 'free' لـ ENUM في restaurants.subscription_plan

ALTER TABLE restaurants
    MODIFY COLUMN subscription_plan
        ENUM('free','basic','advanced','premium')
        NOT NULL DEFAULT 'basic';

-- مطاعم بدون تاريخ انتهاء (subscription_expiry IS NULL)
-- تعتبر على الباقة free من هلق
-- لا تغيير تلقائي على البيانات الموجودة — الأدمن يعيّن يدوياً
