<?php
/**
 * MenuPro — Demo Progression Helper
 *
 * يدير "Lazy Progression" للطلبات في وضع الديمو:
 *   - يحسب الحالة المتوقعة بناءً على عمر الطلب
 *   - يحدّث الـ DB لما تتغير الحالة (مع تسجيل بـ order_status_log)
 *   - يمسح الطلبات القديمة (≥ 5 دقايق) للحفاظ على نظافة الديمو
 *
 * الفائدة: لا يحتاج cron jobs ولا background processes.
 *          الحالة تتحدث لما حدا يفتح أي صفحة (زبون/مطبخ/نادل/كاشير).
 *
 * Usage:
 *   if (DemoProgression::isDemoRestaurant($pdo, $rid)) {
 *       DemoProgression::cleanupOldOrders($pdo, $rid);
 *       $order = DemoProgression::progressOrder($pdo, $order);
 *   }
 *
 * أو على مجموعة طلبات (kitchen/waiter polling):
 *   $orders = DemoProgression::progressMany($pdo, $rid, $orders);
 */

namespace MenuPro\Helpers;

use PDO;

class DemoProgression
{
    /**
     * تسلسل المراحل مع المدة (بالثواني) لكل مرحلة.
     * المجموع: 8×6 = 48 ثانية حتى يصبح الطلب paid.
     */
    private const STAGES = [
        ['status' => 'pending',   'duration' => 8],   //  0-8s
        ['status' => 'confirmed', 'duration' => 8],   //  8-16s
        ['status' => 'preparing', 'duration' => 8],   // 16-24s
        ['status' => 'ready',     'duration' => 8],   // 24-32s
        ['status' => 'delivered', 'duration' => 8],   // 32-40s
        ['status' => 'paid',      'duration' => 8],   // 40-48s+
    ];

    /** عمر الطلب (بالدقائق) قبل الحذف التلقائي. */
    private const CLEANUP_AGE_MINUTES = 5;

    /** Cache: restaurant_id → bool (هل demo) */
    private static array $isDemoCache = [];

    /**
     * هل المطعم في وضع الديمو؟ (cached per-request)
     */
    public static function isDemoRestaurant(PDO $pdo, int $rid): bool
    {
        if (!isset(self::$isDemoCache[$rid])) {
            try {
                $stmt = $pdo->prepare("SELECT is_demo FROM restaurants WHERE id = ?");
                $stmt->execute([$rid]);
                self::$isDemoCache[$rid] = (bool) $stmt->fetchColumn();
            } catch (\Throwable $e) {
                // عمود is_demo قد لا يكون موجود (migration ما اشتغل بعد)
                self::$isDemoCache[$rid] = false;
            }
        }
        return self::$isDemoCache[$rid];
    }

    /**
     * يحسب الحالة المتوقعة من عمر الطلب (بدون تحديث DB).
     */
    public static function expectedStatus(string $createdAt): string
    {
        $age = time() - strtotime($createdAt);
        if ($age < 0) return self::STAGES[0]['status'];

        $cumulative = 0;
        foreach (self::STAGES as $stage) {
            $cumulative += $stage['duration'];
            if ($age < $cumulative) {
                return $stage['status'];
            }
        }
        // بعد آخر stage → يبقى آخر حالة (paid)
        return self::STAGES[count(self::STAGES) - 1]['status'];
    }

    /**
     * يطبّق progression على طلب واحد.
     * يحدّث DB لو الحالة تغيّرت + يضيف log + يضع payment_status لو وصل paid.
     *
     * @param array $order  row من orders (لازم يحتوي id, status, created_at)
     * @return array        نفس الـ row مع الحالة المحدّثة
     */
    public static function progressOrder(PDO $pdo, array $order): array
    {
        if (empty($order['created_at']) || empty($order['status'])) {
            return $order;
        }

        $expected = self::expectedStatus($order['created_at']);
        if ($expected === $order['status']) {
            return $order; // لا تغيير
        }

        // لا تتراجع — لو الحالة الحالية أبعد من المتوقعة، خلّيها
        $currentRank  = self::statusRank($order['status']);
        $expectedRank = self::statusRank($expected);
        if ($currentRank >= $expectedRank) {
            return $order;
        }

        try {
            $pdo->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$expected, $order['id']]);

            // log الحالة (table: order_status_log)
            $pdo->prepare("INSERT INTO order_status_log (order_id, status, changed_at) VALUES (?, ?, NOW())")
                ->execute([$order['id'], $expected]);

            // لما يصير paid، ثبّت payment_status
            if ($expected === 'paid' && empty($order['payment_status'])) {
                $pdo->prepare("UPDATE orders SET payment_status = 'paid', payment_method = 'cash', paid_at = NOW() WHERE id = ?")
                    ->execute([$order['id']]);
                $order['payment_status'] = 'paid';
                $order['payment_method'] = 'cash';
            }

            $order['status'] = $expected;
        } catch (\Throwable $e) {
            // log فقط — لا تكسر الـ flow لو فشل update
            error_log('DemoProgression::progressOrder failed: ' . $e->getMessage());
        }

        return $order;
    }

    /**
     * يطبّق progression على مجموعة طلبات دفعة وحدة.
     */
    public static function progressMany(PDO $pdo, int $rid, array $orders): array
    {
        if (!self::isDemoRestaurant($pdo, $rid)) return $orders;
        return array_map(fn($o) => self::progressOrder($pdo, $o), $orders);
    }

    /**
     * يحذف طلبات الـ demo الأقدم من 5 دقايق + كل توابعها (items/log/ratings).
     * يستدعى تلقائياً عند فتح أي صفحة demo (lazy cleanup).
     */
    public static function cleanupOldOrders(PDO $pdo, int $rid): void
    {
        if (!self::isDemoRestaurant($pdo, $rid)) return;

        $minutes = self::CLEANUP_AGE_MINUTES;

        try {
            // أولاً: جيب الـ ids للحذف
            $ids = $pdo->prepare("
                SELECT id FROM orders
                WHERE restaurant_id = ?
                  AND created_at < DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $ids->execute([$rid, $minutes]);
            $orderIds = $ids->fetchAll(PDO::FETCH_COLUMN);

            if (empty($orderIds)) return;

            $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

            // احذف الـ items + log + ratings + الطلب
            $pdo->prepare("DELETE FROM order_items WHERE order_id IN ($placeholders)")
                ->execute($orderIds);
            $pdo->prepare("DELETE FROM order_status_log WHERE order_id IN ($placeholders)")
                ->execute($orderIds);
            // order_ratings قد لا يكون فيه rows — استعلام آمن
            try {
                $pdo->prepare("DELETE FROM order_ratings WHERE order_id IN ($placeholders)")
                    ->execute($orderIds);
            } catch (\Throwable $e) { /* ignore */ }

            $pdo->prepare("DELETE FROM orders WHERE id IN ($placeholders)")
                ->execute($orderIds);

        } catch (\Throwable $e) {
            error_log('DemoProgression::cleanupOldOrders failed: ' . $e->getMessage());
        }
    }

    /**
     * ترتيب الحالات لمنع التراجع.
     */
    private static function statusRank(string $status): int
    {
        $order = ['pending' => 1, 'confirmed' => 2, 'preparing' => 3, 'ready' => 4, 'delivered' => 5, 'paid' => 6, 'cancelled' => 0];
        return $order[$status] ?? 0;
    }

    /**
     * هل الطلب الحالي بـ demo mode (للـ frontend polling).
     */
    public static function progressionMetadata(string $createdAt): array
    {
        $age = time() - strtotime($createdAt);
        $cumulative = 0;
        $currentStage = null;
        foreach (self::STAGES as $i => $stage) {
            $cumulative += $stage['duration'];
            if ($age < $cumulative) {
                $currentStage = $i;
                $secondsToNext = $cumulative - $age;
                return [
                    'current_stage'  => $i,
                    'current_status' => $stage['status'],
                    'total_stages'   => count(self::STAGES),
                    'age_seconds'    => $age,
                    'seconds_to_next'=> $secondsToNext,
                    'is_complete'    => false,
                ];
            }
        }
        return [
            'current_stage'  => count(self::STAGES) - 1,
            'current_status' => 'paid',
            'total_stages'   => count(self::STAGES),
            'age_seconds'    => $age,
            'seconds_to_next'=> 0,
            'is_complete'    => true,
        ];
    }
}
