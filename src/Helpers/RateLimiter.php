<?php
/**
 * RateLimiter — حماية من Brute Force على صفحات الـ Login
 *
 * الآلية:
 *   - يتابع المحاولات الفاشلة لكل IP في جدول login_attempts
 *   - بعد MAX_ATTEMPTS محاولة فاشلة خلال WINDOW_MINUTES → حظر لـ BLOCK_MINUTES
 *   - عند نجاح الدخول → يحذف سجلات الـ IP
 *   - Cleanup تلقائي للسجلات القديمة عند كل فحص
 *
 * المتطلبات:
 *   CREATE TABLE login_attempts (
 *       id           INT AUTO_INCREMENT PRIMARY KEY,
 *       ip           VARCHAR(45) NOT NULL,
 *       attempted_at DATETIME    NOT NULL DEFAULT NOW(),
 *       INDEX idx_ip      (ip),
 *       INDEX idx_cleanup (attempted_at)
 *   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
 */

namespace MenuPro\Helpers;

use PDO;

class RateLimiter
{
    const MAX_ATTEMPTS    = 10;   // محاولات قبل الحظر
    const WINDOW_MINUTES  = 15;   // نافذة العدّ (دقيقة)
    const BLOCK_MINUTES   = 15;   // مدة الحظر (دقيقة)

    /**
     * فحص إذا كان الـ IP محظوراً.
     * بيحذف السجلات القديمة تلقائياً.
     *
     * @return array ['blocked'=>bool, 'minutes'=>int|null, 'count'=>int]
     */
    public static function check(PDO $pdo, string $ip): array
    {
        // تنظيف السجلات الأقدم من نافذة الحظر
        $pdo->prepare("
            DELETE FROM login_attempts
            WHERE attempted_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ")->execute([self::BLOCK_MINUTES]);

        // عدد المحاولات الفاشلة خلال النافذة
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM login_attempts
            WHERE ip = ?
              AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        $stmt->execute([$ip, self::WINDOW_MINUTES]);
        $count = (int)$stmt->fetchColumn();

        if ($count >= self::MAX_ATTEMPTS) {
            // احسب الوقت المتبقي للحظر
            $stmt2 = $pdo->prepare("
                SELECT MIN(attempted_at) FROM login_attempts
                WHERE ip = ?
                  AND attempted_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $stmt2->execute([$ip, self::WINDOW_MINUTES]);
            $oldest    = $stmt2->fetchColumn();
            $unblockAt = strtotime($oldest) + self::BLOCK_MINUTES * 60;
            $remaining = (int)ceil(($unblockAt - time()) / 60);

            return [
                'blocked' => true,
                'minutes' => max(1, $remaining),
                'count'   => $count,
            ];
        }

        return [
            'blocked'   => false,
            'minutes'   => null,
            'count'     => $count,
            'remaining' => self::MAX_ATTEMPTS - $count,
        ];
    }

    /**
     * تسجيل محاولة فاشلة.
     */
    public static function recordFailure(PDO $pdo, string $ip): void
    {
        $pdo->prepare("
            INSERT INTO login_attempts (ip, attempted_at) VALUES (?, NOW())
        ")->execute([$ip]);
    }

    /**
     * مسح سجلات الـ IP (عند نجاح الدخول).
     */
    public static function clearForIp(PDO $pdo, string $ip): void
    {
        $pdo->prepare("
            DELETE FROM login_attempts WHERE ip = ?
        ")->execute([$ip]);
    }

    /**
     * استخراج الـ IP الحقيقي (يدعم Hostinger reverse proxy).
     */
    public static function getIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR']
           ?? $_SERVER['HTTP_X_REAL_IP']
           ?? $_SERVER['REMOTE_ADDR']
           ?? '0.0.0.0';

        // لو في عدة IPs مفصولة بفاصلة (proxy chain) → خذ الأول
        $ip = trim(explode(',', $ip)[0]);

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
    }
}
