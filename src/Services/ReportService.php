<?php
/**
 * MenuPro — Report & Analytics Service
 * 
 * كل استعلامات التقارير والإحصائيات بمكان واحد.
 * 
 * استراتيجية خلال فترة الترحيل:
 *   - يستخدم orders (المصدر الوحيد بعد إسقاط orders_v2) + branch_id column
 *   - branches table للفروع
 *   - restaurant_staff للموظفين (مش users) لأنو هو المستخدم فعلياً بالصفحات الحالية
 */

namespace MenuPro\Services;

use PDO;
use MenuPro\Middleware\TenantMiddleware;

class ReportService
{
    private PDO $db;
    private TenantMiddleware $tenant;

    public function __construct(PDO $db, TenantMiddleware $tenant)
    {
        $this->db     = $db;
        $this->tenant = $tenant;
    }

    // ============================================================
    // Branch Comparison — المرحلة 7
    // ============================================================

    /**
     * مقارنة أداء كل فروع مطعم معين.
     * 
     * @param int $restaurantId  معرّف المطعم
     * @param int $days          عدد الأيام للمقارنة
     * @return array
     */
    public function getBranchComparison(int $restaurantId, int $days = 30): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                b.id,
                b.name,
                b.address,
                b.is_active,
                COUNT(o.id) AS total_orders,
                COALESCE(SUM(o.total_price), 0) AS total_revenue,
                COALESCE(AVG(o.total_price), 0) AS avg_order,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN o.payment_status = 'paid'   THEN 1 ELSE 0 END) AS paid_count,
                COALESCE(SUM(CASE WHEN o.payment_status = 'paid' THEN o.total_price END), 0) AS paid_revenue
            FROM branches b
            LEFT JOIN orders o 
                ON o.branch_id = b.id 
                AND DATE(o.created_at) >= ?
            WHERE b.restaurant_id = ?
            GROUP BY b.id
            ORDER BY total_revenue DESC
        ");
        $stmt->execute([$dateFrom, $restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * توزيع الطلبات بالساعة لكل فرع (لتحسين جدولة الموظفين).
     * 
     * @return array  [branch_id => ['name' => ..., 'data' => [hour => count]]]
     */
    public function getHourlyDistributionByBranch(int $restaurantId, int $days = 30): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                b.id   AS branch_id,
                b.name AS branch_name,
                HOUR(o.created_at) AS hour,
                COUNT(*) AS orders
            FROM orders o
            JOIN branches b ON b.id = o.branch_id
            WHERE b.restaurant_id = ? 
              AND DATE(o.created_at) >= ?
            GROUP BY b.id, HOUR(o.created_at)
            ORDER BY b.id, hour
        ");
        $stmt->execute([$restaurantId, $dateFrom]);
        $rows = $stmt->fetchAll();

        // تنظيم حسب الفرع
        $result = [];
        foreach ($rows as $r) {
            $bid = intval($r['branch_id']);
            if (!isset($result[$bid])) {
                $result[$bid] = [
                    'name' => $r['branch_name'],
                    'data' => []
                ];
            }
            $result[$bid]['data'][intval($r['hour'])] = intval($r['orders']);
        }
        return $result;
    }

    /**
     * أداء الموظفين عبر كل الفروع.
     *
     * يستخدم orders (المصدر الوحيد) مع staff_id/cashier_id columns.
     * restaurant_staff للموظفين لأنو هو المرتبط فعلياً بالـ staff_id / cashier_id.
     * 
     * @return array
     */
    public function getStaffPerformance(int $restaurantId, int $days = 30, int $limit = 20): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                rs.id,
                rs.name AS staff_name,
                rs.role,
                b.name  AS branch_name,
                b.id    AS branch_id,
                COUNT(DISTINCT CASE WHEN o.staff_id   = rs.id THEN o.id END) AS deliveries,
                COUNT(DISTINCT CASE WHEN o.cashier_id = rs.id THEN o.id END) AS payments
            FROM restaurant_staff rs
            LEFT JOIN branches b ON b.id = rs.branch_id
            LEFT JOIN orders o 
                ON (o.staff_id = rs.id OR o.cashier_id = rs.id)
                AND DATE(o.created_at) >= ?
            WHERE rs.restaurant_id = ? 
              AND rs.is_active = 1
            GROUP BY rs.id
            ORDER BY deliveries DESC, payments DESC
            LIMIT " . intval($limit) . "
        ");
        $stmt->execute([$dateFrom, $restaurantId]);
        return $stmt->fetchAll();
    }

    /**
     * إجماليات المطعم الكامل عبر كل الفروع.
     */
    public function getRestaurantTotals(int $restaurantId, int $days = 30): array
    {
        $dateFrom = date('Y-m-d', strtotime("-{$days} days"));

        $stmt = $this->db->prepare("
            SELECT 
                COUNT(o.id) AS total_orders,
                COALESCE(SUM(o.total_price), 0) AS total_revenue,
                COALESCE(AVG(o.total_price), 0) AS avg_order,
                SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN o.status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                (SELECT COUNT(*) FROM branches WHERE restaurant_id = ? AND is_active = 1) AS active_branches
            FROM orders o
            JOIN branches b ON b.id = o.branch_id
            WHERE b.restaurant_id = ? 
              AND DATE(o.created_at) >= ?
        ");
        $stmt->execute([$restaurantId, $restaurantId, $dateFrom]);
        $r = $stmt->fetch();
        
        return $r ?: [
            'total_orders'    => 0,
            'total_revenue'   => 0,
            'avg_order'       => 0,
            'cancelled'       => 0,
            'delivered'       => 0,
            'active_branches' => 0,
        ];
    }
}