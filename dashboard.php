<?php
/**
 * dashboard.php
 * 
 * Stop Shop Mərkəzi Sistemi üçün daha da təkmilləşdirilmiş Dashboard səhifəsi.
 */

session_start();
require_once 'config.php';

// İcazə yoxlanışı
check_page_permission($_SERVER['PHP_SELF']);

// İstifadəçi məlumatları
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['user_role'] ?? 'guest';

// Aktiv işçiləri çəkmək üçün funksiya (helpers.php-dən istifadə edin)
// Nəzərə alın ki, helpers.php-dəki getActiveEmployees() funksiyası massiv qaytarır, say deyil
$activeEmployeesList = getActiveEmployees($conn); // Aktiv işçilərin siyahısı
$activeEmployees = getActiveEmployeesCount($conn); // Aktiv işçilərin sayı

try {
    // Statistik məlumatları yığmaq
    $totalEmployees      = getTotalEmployees($conn);
    $totalDebts          = getTotalDebts($conn);
    $unpaidDebts         = getUnpaidDebts($conn);
    $totalDifference     = getTotalCashDifference($conn);
    $totalGrossSalary    = getTotalGrossSalary($conn);
    $totalNetSalary      = getTotalNetSalary($conn);
    $averageSalary       = getAverageSalary($conn);
    $debtsByMonth        = getDebtsByMonth($conn);
    $salaryByMonth       = getSalaryByMonth($conn);
    $employeesCategory   = getEmployeesCategoryDistribution($conn);
    $topUnpaidDebts      = getTopUnpaidDebtsEmployees($conn, 5);

    // Son günün kassir məlumatları
    $yesterdayReport = getYesterdayCashierReport($conn);
    
    // Yeni əlavə edilən statistikalar
    $attendanceStats     = getMonthlyAttendanceStats($conn);
    $topPerformers       = getTopPerformanceBonuses($conn, 5);
    $debtAnalysis        = getDebtAnalysis($conn);
    $recentDebts         = getRecentDebts($conn, 5);
    $topSalesPerformers  = getTopSalesPerformers($conn, 5);
    
    // Yeni əlavə ediləcək statistikalar
    $monthlyCashDifference = getMonthlyCashDifference($conn);
    $upcomingSalaryPayments = getUpcomingSalaryPayments($conn);
    $latestAttendance = getLatestAttendance($conn, 5);
    $cashierPerformance = getCashierPerformance($conn);
    $debtPaymentTrends = getDebtPaymentTrends($conn);
    $employeeEfficiency = getEmployeeEfficiency($conn, 5);

} catch (PDOException $e) {
    set_flash_message('danger', 'Verilənlər bazasında xəta: ' . $e->getMessage());
    // Ana səhifəyə yönləndir - xətalı məlumatlar olmadan göstərmək əvəzinə
    // header('Location: dashboard.php');
    // exit();
}

/** 
 * Statistik məlumatları çıxaran funksiyalar 
 */

// 1. Ümumi işçilər
function getTotalEmployees($conn) {
    $stmt = $conn->query("SELECT COUNT(*) FROM employees");
    return (int) $stmt->fetchColumn();
}

// 2. Aktiv işçilər - helpers.php faylından istifadə edirik
// function getActiveEmployees($conn) {
//     $stmt = $conn->query("SELECT COUNT(*) FROM employees WHERE is_active = 1");
//     return (int) $stmt->fetchColumn();
// }

// Aktiv işçilərin sayını almaq üçün helpers.php-dəki funksiyanı istifadə edirik
function getActiveEmployeesCount($conn) {
    $stmt = $conn->query("SELECT COUNT(*) FROM employees WHERE is_active = 1");
    return (int) $stmt->fetchColumn();
}

// 3. Ümumi borc
function getTotalDebts($conn) {
    $stmt = $conn->query("SELECT SUM(amount) FROM debts");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 4. Ödənilməmiş borc
function getUnpaidDebts($conn) {
    $stmt = $conn->query("SELECT SUM(amount) FROM debts WHERE is_paid = 0");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 5. Kassa fərqlərinin cəmi
function getTotalCashDifference($conn) {
    $stmt = $conn->query("SELECT SUM(difference) FROM cash_reports");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 6. Ümumi gross maaş
function getTotalGrossSalary($conn) {
    $stmt = $conn->query("SELECT SUM(gross_salary) FROM salary_payments");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 7. Ümumi net maaş
function getTotalNetSalary($conn) {
    $stmt = $conn->query("SELECT SUM(net_salary) FROM salary_payments");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 8. Orta maaş (aktiv işçilər üzrə)
function getAverageSalary($conn) {
    $stmt = $conn->query("SELECT AVG(salary) FROM employees WHERE is_active = 1");
    $res = $stmt->fetchColumn();
    return (float)($res ?: 0);
}

// 9. Borcların aylara görə paylanması
function getDebtsByMonth($conn) {
    $stmt = $conn->query("
        SELECT DATE_FORMAT(date, '%Y-%m') AS month, SUM(amount) as total_debt
        FROM debts
        GROUP BY month
        ORDER BY month ASC
    ");
    return $stmt->fetchAll();
}

// 10. Maaşların aylara görə dəyişməsi
function getSalaryByMonth($conn) {
    $stmt = $conn->query("
        SELECT DATE_FORMAT(payment_date, '%Y-%m') as month, 
               SUM(gross_salary) as total_gross, 
               SUM(net_salary)   as total_net
        FROM salary_payments
        GROUP BY month
        ORDER BY month ASC
    ");
    return $stmt->fetchAll();
}

// 11. İşçilərin kateqoriya üzrə paylanması
function getEmployeesCategoryDistribution($conn) {
    $stmt = $conn->query("SELECT category, COUNT(*) as count FROM employees GROUP BY category");
    return $stmt->fetchAll();
}

// 12. Ən çox **ödənilməmiş** borcu qalan işçilər (ilk 5)
function getTopUnpaidDebtsEmployees($conn, $limit = 5) {
    $stmt = $conn->prepare("
        SELECT e.name, SUM(d.amount) AS total_debt
        FROM debts d
        JOIN employees e ON d.employee_id = e.id
        WHERE d.is_paid = 0       -- Yalnız ödənilməmiş borclar
        GROUP BY e.id, e.name
        ORDER BY total_debt DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':lim', (int) $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// 13. Dünənki kassa hesabatı
function getYesterdayCashierReport($conn) {
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $stmt = $conn->prepare("
        SELECT cr.*, e.name as cashier_name
        FROM cash_reports cr
        JOIN employees e ON cr.employee_id = e.id
        WHERE DATE(cr.date) = :date
        ORDER BY cr.id DESC
        LIMIT 5
    ");
    $stmt->execute([':date' => $yesterday]);
    return $stmt->fetchAll();
}

// 14. Bu ay üçün davamiyyət statistikaları
function getMonthlyAttendanceStats($conn) {
    $current_month = date('Y-m');
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN a.status = 1 THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN a.status = 0.5 THEN 1 ELSE 0 END) as half_days,
            SUM(CASE WHEN a.status = 0 THEN 1 ELSE 0 END) as absent_days,
            COUNT(a.id) as total_days
        FROM attendance a
        WHERE DATE_FORMAT(a.date, '%Y-%m') = :month
    ");
    $stmt->execute([':month' => $current_month]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 15. İşçilərin performans bonusları
function getTopPerformanceBonuses($conn, $limit = 5) {
    $current_month = date('Y-m');
    $stmt = $conn->prepare("
        SELECT e.name, 
               COUNT(cr.id) as report_count, 
               SUM(cr.difference) as total_difference
        FROM employees e
        JOIN cash_reports cr ON e.id = cr.employee_id
        WHERE DATE_FORMAT(cr.date, '%Y-%m') = :month
          AND cr.difference > 0
        GROUP BY e.id, e.name
        ORDER BY total_difference DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':month', $current_month, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 16. Borc analizi - ödənilmiş və ödənilməmiş borcların nisbəti
function getDebtAnalysis($conn) {
    $stmt = $conn->query("
        SELECT 
            SUM(CASE WHEN is_paid = 1 THEN amount ELSE 0 END) as paid_debt,
            SUM(CASE WHEN is_paid = 0 THEN amount ELSE 0 END) as unpaid_debt,
            SUM(amount) as total_debt
        FROM debts
    ");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 17. Son əlavə edilən borclar
function getRecentDebts($conn, $limit = 5) {
    $stmt = $conn->prepare("
        SELECT d.id, d.amount, d.date, d.reason, d.is_paid, e.name as employee_name
        FROM debts d
        JOIN employees e ON d.employee_id = e.id
        ORDER BY d.id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 18. Bu ay üçün ən yaxşı satış performansı göstərən işçilər
function getTopSalesPerformers($conn, $limit = 5) {
    $current_month = date('Y-m');
    try {
        $stmt = $conn->prepare("
            SELECT e.name, SUM(s.amount) as total_sales
            FROM employees e
            JOIN sales s ON e.id = s.employee_id
            WHERE DATE_FORMAT(s.date, '%Y-%m') = :month
            GROUP BY e.id, e.name
            ORDER BY total_sales DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':month', $current_month, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Cədvəl mövcud deyilsə, boş massiv qaytarırıq
        return [];
    }
}

// 19. Aylıq kassa fərqləri
function getMonthlyCashDifference($conn) {
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            SUM(difference) as total_difference,
            COUNT(*) as report_count
        FROM cash_reports
        GROUP BY month
        ORDER BY month DESC
        LIMIT 6
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 20. Yaxınlaşan maaş ödənişləri
function getUpcomingSalaryPayments($conn) {
    $current_month = date('Y-m');
    $stmt = $conn->prepare("
        SELECT 
            COUNT(e.id) as employee_count,
            SUM(e.salary) as total_salary
        FROM employees e
        WHERE e.is_active = 1
          AND NOT EXISTS (
              SELECT 1 FROM salary_payments sp
              WHERE sp.employee_id = e.id
                AND DATE_FORMAT(sp.payment_date, '%Y-%m') = :month
          )
    ");
    $stmt->execute([':month' => $current_month]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// 21. Son davamiyyət qeydləri
function getLatestAttendance($conn, $limit = 5) {
    $stmt = $conn->prepare("
        SELECT a.date, a.status, e.name as employee_name, a.reason
        FROM attendance a
        JOIN employees e ON a.employee_id = e.id
        ORDER BY a.date DESC, a.id DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 22. Kassir performansı
function getCashierPerformance($conn) {
    $current_month = date('Y-m');
    $stmt = $conn->prepare("
        SELECT 
            e.name,
            COUNT(cr.id) as report_count,
            SUM(cr.cash_given) as total_cash,
            SUM(cr.pos_amount) as total_pos,
            SUM(cr.difference) as total_difference,
            AVG(cr.difference) as avg_difference
        FROM employees e
        JOIN cash_reports cr ON e.id = cr.employee_id
        WHERE e.category = 'kassir'
          AND DATE_FORMAT(cr.date, '%Y-%m') = :month
        GROUP BY e.id, e.name
        ORDER BY total_difference DESC
    ");
    $stmt->execute([':month' => $current_month]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 23. Borc ödəmə tendensiyaları
function getDebtPaymentTrends($conn) {
    $stmt = $conn->query("
        SELECT 
            DATE_FORMAT(date, '%Y-%m') as month,
            COUNT(*) as total_debts,
            SUM(CASE WHEN is_paid = 1 THEN 1 ELSE 0 END) as paid_count,
            SUM(CASE WHEN is_paid = 0 THEN 1 ELSE 0 END) as unpaid_count,
            SUM(amount) as total_amount,
            SUM(CASE WHEN is_paid = 1 THEN amount ELSE 0 END) as paid_amount
        FROM debts
        GROUP BY month
        ORDER BY month DESC
        LIMIT 6
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 24. İşçi effektivliyi
function getEmployeeEfficiency($conn, $limit = 5) {
    $current_month = date('Y-m');
    try {
        $stmt = $conn->prepare("
            WITH employee_stats AS (
                SELECT 
                    e.id,
                    e.name,
                    e.salary,
                    COUNT(DISTINCT a.date) as attendance_days,
                    SUM(CASE WHEN cr.difference > 0 THEN 1 ELSE 0 END) as positive_reports,
                    COUNT(cr.id) as total_reports,
                    COALESCE(SUM(s.amount), 0) as sales_amount
                FROM employees e
                LEFT JOIN attendance a ON e.id = a.employee_id AND a.status > 0 AND DATE_FORMAT(a.date, '%Y-%m') = :month
                LEFT JOIN cash_reports cr ON e.id = cr.employee_id AND DATE_FORMAT(cr.date, '%Y-%m') = :month
                LEFT JOIN sales s ON e.id = s.employee_id AND DATE_FORMAT(s.date, '%Y-%m') = :month
                WHERE e.is_active = 1
                GROUP BY e.id, e.name, e.salary
            )
            SELECT 
                name,
                attendance_days,
                positive_reports,
                total_reports,
                sales_amount,
                CASE 
                    WHEN total_reports > 0 THEN (positive_reports / total_reports) * 100
                    ELSE 0
                END as success_rate,
                CASE
                    WHEN salary > 0 AND attendance_days > 0 THEN (sales_amount / (salary / 30 * attendance_days))
                    ELSE 0
                END as efficiency_ratio
            FROM employee_stats
            ORDER BY efficiency_ratio DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':month', $current_month, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Xəta baş verərsə, boş massiv qaytarırıq
        return [];
    }
}

?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Stop Shop Mərkəzi Sistemi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            margin-bottom: 20px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
            border: none;
            overflow: hidden;
        }
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0,0,0,0.1);
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .quick-stats .card {
            border-left: 5px solid;
        }
        .alert-custom {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }
        .stat-icon {
            font-size: 2rem;
            opacity: 0.7;
        }
        .dashboard-header {
            padding: 20px 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #dee2e6;
        }
        .dashboard-title {
            font-weight: 700;
            color: #343a40;
        }
        .quick-links {
            margin-bottom: 20px;
        }
        .quick-link-btn {
            padding: 15px;
            text-align: center;
            border-radius: 10px;
            margin-bottom: 10px;
            color: #fff;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            transition: all 0.3s;
        }
        .quick-link-btn:hover {
            transform: scale(1.05);
            text-decoration: none;
            color: #fff;
        }
        .quick-link-btn i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        .bg-gradient-primary {
            background: linear-gradient(45deg, #4e73df, #224abe);
        }
        .bg-gradient-success {
            background: linear-gradient(45deg, #1cc88a, #13855c);
        }
        .bg-gradient-info {
            background: linear-gradient(45deg, #36b9cc, #258391);
        }
        .bg-gradient-warning {
            background: linear-gradient(45deg, #f6c23e, #dda20a);
        }
        .bg-gradient-danger {
            background: linear-gradient(45deg, #e74a3b, #be2617);
        }
        .bg-gradient-purple {
            background: linear-gradient(45deg, #6f42c1, #4e31a5);
        }
        .stat-card {
            background: white;
            border-radius: 10px;
            overflow: hidden;
        }
        .stat-card .card-body {
            padding: 1.5rem;
        }
        .stat-card .card-title {
            text-transform: uppercase;
            font-size: 0.9rem;
            font-weight: 700;
            color: #5a5c69;
        }
        .stat-card .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
        }
        .mini-stat {
            font-size: 0.85rem;
            color: #6c757d;
        }
        .mini-stat .badge {
            font-size: 0.75rem;
        }
        .trend-up {
            color: #1cc88a;
        }
        .trend-down {
            color: #e74a3b;
        }
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>

<div class="container mt-4">
    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h2 class="dashboard-title">Stop Shop İdarəetmə Paneli</h2>
                <p class="text-muted">Xoş gəlmisiniz, <?php echo $_SESSION['user_role'] ?? 'istifadəçi'; ?>!</p>
            </div>
            <div class="col-md-6 text-md-end">
                <span class="badge bg-primary">Tarix: <?php echo date('d.m.Y'); ?></span>
                <span class="badge bg-secondary ms-2">Vaxt: <?php echo date('H:i'); ?></span>
            </div>
        </div>
    </div>

    <?php display_flash_messages(); ?>

    <!-- Cəld Keçidlər -->
    <div class="quick-links">
        <div class="row">
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="salary.php" class="quick-link-btn bg-gradient-primary">
                    <i class="fas fa-money-check-alt"></i>
                    Maaş Hesablama
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="add_employee.php" class="quick-link-btn bg-gradient-success">
                    <i class="fas fa-user-plus"></i>
                    İşçi Əlavə Et
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="attendance.php" class="quick-link-btn bg-gradient-info">
                    <i class="fas fa-user-clock"></i>
                    Davamiyyət
                </a>
            </div>
            <div class="col-md-3 col-sm-6 mb-3">
                <a href="reports.php" class="quick-link-btn bg-gradient-warning">
                    <i class="fas fa-chart-line"></i>
                    Hesabatlar
                </a>
            </div>
        </div>
    </div>

    <!-- Statistik Kartlar (1-ci sıra) -->
    <div class="row quick-stats">
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #4e73df;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ÜMUMİ İŞÇİLƏR</h6>
                            <div class="stat-value"><?php echo $totalEmployees; ?></div>
                            <div class="mini-stat">
                                <span class="badge bg-success"><?php echo $activeEmployees; ?> aktiv</span>
                                <span class="badge bg-secondary"><?php echo $totalEmployees - $activeEmployees; ?> qeyri-aktiv</span>
                            </div>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-users text-primary stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #1cc88a;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">AKTİV İŞÇİLƏR</h6>
                            <div class="stat-value"><?php echo $activeEmployees; ?></div>
                            <?php if (!empty($attendanceStats)): ?>
                            <div class="mini-stat">
                                <span class="badge bg-success"><?php echo $attendanceStats['present_days'] ?? 0; ?> işdə</span>
                                <span class="badge bg-warning"><?php echo $attendanceStats['half_days'] ?? 0; ?> yarım gün</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-check text-success stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #f6c23e;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ÜMUMİ BORC</h6>
                            <div class="stat-value"><?php echo number_format($totalDebts, 2); ?> ₼</div>
                            <?php if (!empty($debtAnalysis)): ?>
                            <div class="mini-stat">
                                <span class="badge bg-success"><?php echo number_format($debtAnalysis['paid_debt'] ?? 0, 2); ?> ₼ ödənilib</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-hand-holding-usd text-warning stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #e74a3b;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ÖDƏNİLMƏMİŞ BORC</h6>
                            <div class="stat-value"><?php echo number_format($unpaidDebts, 2); ?> ₼</div>
                            <?php if (!empty($debtAnalysis) && $debtAnalysis['total_debt'] > 0): ?>
                            <div class="mini-stat">
                                <span class="badge bg-danger">
                                    <?php echo number_format(($unpaidDebts / $debtAnalysis['total_debt']) * 100, 1); ?>% ödənilməyib
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-exclamation-circle text-danger stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistik Kartlar (2-ci sıra) -->
    <div class="row quick-stats">
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #36b9cc;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">KASSA FƏRQLƏRİ</h6>
                            <div class="stat-value"><?php echo number_format($totalDifference, 2); ?> ₼</div>
                            <?php if (!empty($monthlyCashDifference) && isset($monthlyCashDifference[0])): ?>
                            <div class="mini-stat">
                                <span class="badge bg-info">Bu ay: <?php echo number_format($monthlyCashDifference[0]['total_difference'] ?? 0, 2); ?> ₼</span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-cash-register text-info stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #6f42c1;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ÜMUMİ MAAŞ</h6>
                            <div class="stat-value"><?php echo number_format($totalGrossSalary, 2); ?> ₼</div>
                            <?php if (!empty($upcomingSalaryPayments)): ?>
                            <div class="mini-stat">
                                <span class="badge bg-purple" style="background-color: #6f42c1;">
                                    <?php echo $upcomingSalaryPayments['employee_count'] ?? 0; ?> işçi gözləyir
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-money-bill-wave text-purple stat-icon" style="color: #6f42c1;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #5a5c69;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ÜMUMİ NET MAAŞ</h6>
                            <div class="stat-value"><?php echo number_format($totalNetSalary, 2); ?> ₼</div>
                            <?php if ($totalGrossSalary > 0): ?>
                            <div class="mini-stat">
                                <span class="badge bg-secondary">
                                    <?php echo number_format(($totalNetSalary / $totalGrossSalary) * 100, 1); ?>% ödənilib
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-money-check-alt text-dark stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #1cc88a;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">ORTA MAAŞ</h6>
                            <div class="stat-value"><?php echo number_format($averageSalary, 2); ?> ₼</div>
                            <?php if (!empty($upcomingSalaryPayments) && $upcomingSalaryPayments['employee_count'] > 0): ?>
                            <div class="mini-stat">
                                <span class="badge bg-success">
                                    <?php echo number_format($upcomingSalaryPayments['total_salary'] / $upcomingSalaryPayments['employee_count'], 2); ?> ₼ gözləyən
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-chart-line text-success stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Yeni Statistik Kartlar (3-cü sıra) -->
    <div class="row quick-stats">
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #e74a3b;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">DAVAMIYYƏT</h6>
                            <?php if (!empty($attendanceStats)): ?>
                            <div class="stat-value"><?php echo number_format(($attendanceStats['present_days'] / max(1, $attendanceStats['total_days'])) * 100, 1); ?>%</div>
                            <div class="mini-stat">
                                <span class="badge bg-danger"><?php echo $attendanceStats['absent_days'] ?? 0; ?> işə gəlməyən</span>
                            </div>
                            <?php else: ?>
                            <div class="stat-value">0%</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-check text-danger stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #f6c23e;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">YAXINLAŞAN MAAŞLAR</h6>
                            <?php if (!empty($upcomingSalaryPayments)): ?>
                            <div class="stat-value"><?php echo number_format($upcomingSalaryPayments['total_salary'] ?? 0, 2); ?> ₼</div>
                            <div class="mini-stat">
                                <span class="badge bg-warning"><?php echo $upcomingSalaryPayments['employee_count'] ?? 0; ?> işçi</span>
                            </div>
                            <?php else: ?>
                            <div class="stat-value">0.00 ₼</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-calendar-alt text-warning stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #4e73df;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">KASSİR PERFORMANSI</h6>
                            <?php if (!empty($cashierPerformance)): ?>
                            <div class="stat-value"><?php echo count($cashierPerformance); ?> kassir</div>
                            <div class="mini-stat">
                                <span class="badge bg-primary">
                                    <?php 
                                        $totalReports = 0;
                                        foreach ($cashierPerformance as $cashier) {
                                            $totalReports += $cashier['report_count'];
                                        }
                                        echo $totalReports; 
                                    ?> hesabat
                                </span>
                            </div>
                            <?php else: ?>
                            <div class="stat-value">0 kassir</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-user-tie text-primary stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card stat-card" style="border-left-color: #6f42c1;">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col">
                            <h6 class="card-title">İŞÇİ EFFEKTİVLİYİ</h6>
                            <?php if (!empty($employeeEfficiency)): ?>
                            <div class="stat-value"><?php echo number_format($employeeEfficiency[0]['efficiency_ratio'] ?? 0, 2); ?></div>
                            <div class="mini-stat">
                                <span class="badge bg-purple" style="background-color: #6f42c1;">
                                    <?php echo $employeeEfficiency[0]['name'] ?? 'Yoxdur'; ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <div class="stat-value">0.00</div>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <i class="fas fa-bolt text-purple stat-icon" style="color: #6f42c1;"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Qrafiklər: Borclar və Maaşlar -->
    <div class="row">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-primary text-white">
                    <h5 class="card-title mb-0">Borcların Aylara Görə Artımı</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="debtsChart"></canvas>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="debts.php" class="btn btn-sm btn-outline-primary">Borcları Göstər</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-success text-white">
                    <h5 class="card-title mb-0">Maaş Ödənişlərinin Aylara Görə Dəyişməsi</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="salaryChart"></canvas>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="salary_history.php" class="btn btn-sm btn-outline-success">Maaş Tarixçəsi</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Qrafiklər: Kateqoriya Paylanması və Kassa Fərqləri -->
    <div class="row mt-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-info text-white">
                    <h5 class="card-title mb-0">Kateqoriya üzrə İşçi Paylanması</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="employees.php" class="btn btn-sm btn-outline-info">İşçiləri Göstər</a>
                </div>
            </div>
        </div>

        <!-- Aylıq Kassa Fərqləri -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-warning text-white">
                    <h5 class="card-title mb-0">Aylıq Kassa Fərqləri</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="cashDifferenceChart"></canvas>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="cash_report_history.php" class="btn btn-sm btn-outline-warning">Kassa Tarixçəsi</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Ən Çox Ödənilməmiş Borcu Qalan İşçilər və Son Davamiyyət Qeydləri -->
    <div class="row mt-4">
        <!-- Ən Çox Ödənilməmiş Borcu Qalan İşçilər (ilk 5) -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-danger text-white">
                    <h5 class="card-title mb-0">Ən Çox Ödənilməmiş Borcu Qalan İşçilər</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>İşçi</th>
                                    <th>Borc (AZN)</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($topUnpaidDebts)): ?>
                                    <?php foreach ($topUnpaidDebts as $index => $row): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo number_format($row['total_debt'], 2); ?></td>
                                            <td>
                                                <a href="employee_debts.php?employee_name=<?php echo urlencode($row['name']); ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="fas fa-eye"></i> Ətraflı
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center">Ödənilməmiş borc qeydi yoxdur.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="debts.php" class="btn btn-sm btn-outline-danger">Bütün Borclar</a>
                </div>
            </div>
        </div>

        <!-- Son Davamiyyət Qeydləri -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-info text-white">
                    <h5 class="card-title mb-0">Son Davamiyyət Qeydləri</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Tarix</th>
                                    <th>İşçi</th>
                                    <th>Status</th>
                                    <th>Səbəb</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($latestAttendance)): ?>
                                    <?php foreach ($latestAttendance as $record): ?>
                                        <tr>
                                            <td><?php echo date('d.m.Y', strtotime($record['date'])); ?></td>
                                            <td><?php echo htmlspecialchars($record['employee_name']); ?></td>
                                            <td>
                                                <?php if ($record['status'] == 1): ?>
                                                    <span class="badge bg-success">İşdə</span>
                                                <?php elseif ($record['status'] == 0.5): ?>
                                                    <span class="badge bg-warning">Yarım gün</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Yoxdur</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($record['reason'] ?: '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center">Davamiyyət qeydi yoxdur.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="attendance.php" class="btn btn-sm btn-outline-info">Davamiyyət Səhifəsi</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Dünənki Kassa Hesabatları -->
    <?php if (!empty($yesterdayReport)): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-gradient-dark text-white">
                    <h5 class="card-title mb-0">Dünənki Kassa Hesabatları (<?php echo date('d.m.Y', strtotime('-1 day')); ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>Kassir</th>
                                    <th>Tarix</th>
                                    <th>Nağd (₼)</th>
                                    <th>POS (₼)</th>
                                    <th>Cəmi (₼)</th>
                                    <th>Fərq (₼)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($yesterdayReport as $report): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($report['cashier_name']); ?></td>
                                        <td><?php echo date('d.m.Y', strtotime($report['date'])); ?></td>
                                        <td><?php echo number_format($report['cash_given'], 2); ?></td>
                                        <td><?php echo number_format($report['pos_amount'], 2); ?></td>
                                        <td><?php echo number_format($report['total_amount'], 2); ?></td>
                                        <td class="<?php echo $report['difference'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                            <?php echo number_format($report['difference'], 2); ?>
                                        </td>
                                        <td>
                                            <?php if ($report['difference'] < 0): ?>
                                                <span class="badge bg-danger">Kəsir</span>
                                            <?php elseif ($report['difference'] > 0): ?>
                                                <span class="badge bg-success">Artıq</span>
                                            <?php else: ?>
                                                <span class="badge bg-info">Düzgün</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="cash_report_history.php" class="btn btn-sm btn-outline-dark">Kassa Tarixçəsi</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bu Ayın Davamiyyət Statistikaları -->
    <?php if (!empty($attendanceStats)): ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-gradient-info text-white">
                    <h5 class="card-title mb-0">Bu Ayın Davamiyyət Statistikaları (<?php echo date('F Y'); ?>)</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <canvas id="attendanceChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card mb-3 border-success">
                                <div class="card-body text-center">
                                    <h5 class="card-title">İşdə Günlər</h5>
                                    <h2 class="text-success"><?php echo $attendanceStats['present_days'] ?? 0; ?></h2>
                                    <p class="text-muted">Ümumi günlərin <?php 
                                        $total = $attendanceStats['total_days'] ?? 1; // Sıfıra bölməmək üçün
                                        $percent = ($attendanceStats['present_days'] ?? 0) / $total * 100;
                                        echo number_format($percent, 1);
                                    ?>%-i</p>
                                </div>
                            </div>
                            <div class="card mb-3 border-warning">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Yarım Günlər</h5>
                                    <h2 class="text-warning"><?php echo $attendanceStats['half_days'] ?? 0; ?></h2>
                                    <p class="text-muted">Ümumi günlərin <?php 
                                        $percent = ($attendanceStats['half_days'] ?? 0) / $total * 100;
                                        echo number_format($percent, 1);
                                    ?>%-i</p>
                                </div>
                            </div>
                            <div class="card border-danger">
                                <div class="card-body text-center">
                                    <h5 class="card-title">İşdə Olmayan Günlər</h5>
                                    <h2 class="text-danger"><?php echo $attendanceStats['absent_days'] ?? 0; ?></h2>
                                    <p class="text-muted">Ümumi günlərin <?php 
                                        $percent = ($attendanceStats['absent_days'] ?? 0) / $total * 100;
                                        echo number_format($percent, 1);
                                    ?>%-i</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="attendance.php" class="btn btn-sm btn-outline-info">Davamiyyət Səhifəsinə Keç</a>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Kassir Performansı və Borc Ödəmə Tendensiyaları -->
    <div class="row mt-4">
        <!-- Kassir Performansı -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-primary text-white">
                    <h5 class="card-title mb-0">Bu Ayın Kassir Performansı</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($cashierPerformance)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Kassir</th>
                                        <th>Hesabat Sayı</th>
                                        <th>Ümumi Nağd (₼)</th>
                                        <th>Ümumi POS (₼)</th>
                                        <th>Ümumi Fərq (₼)</th>
                                        <th>Orta Fərq (₼)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cashierPerformance as $cashier): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($cashier['name']); ?></td>
                                            <td><?php echo $cashier['report_count']; ?></td>
                                            <td><?php echo number_format($cashier['total_cash'], 2); ?></td>
                                            <td><?php echo number_format($cashier['total_pos'], 2); ?></td>
                                            <td class="<?php echo $cashier['total_difference'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo number_format($cashier['total_difference'], 2); ?>
                                            </td>
                                            <td class="<?php echo $cashier['avg_difference'] < 0 ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo number_format($cashier['avg_difference'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Bu ay üçün kassir performansı məlumatı yoxdur.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light">
                    <a href="cash_reports.php" class="btn btn-sm btn-outline-primary">Kassa Hesabatları</a>
                </div>
            </div>
        </div>

        <!-- Borc Ödəmə Tendensiyaları -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-warning text-white">
                    <h5 class="card-title mb-0">Borc Ödəmə Tendensiyaları</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($debtPaymentTrends)): ?>
                        <div class="chart-container">
                            <canvas id="debtPaymentTrendsChart"></canvas>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>Ay</th>
                                        <th>Ümumi Borc (₼)</th>
                                        <th>Ödənilmiş (₼)</th>
                                        <th>Ödəmə Nisbəti</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($debtPaymentTrends as $trend): ?>
                                        <tr>
                                            <td><?php echo $trend['month']; ?></td>
                                            <td><?php echo number_format($trend['total_amount'], 2); ?></td>
                                            <td><?php echo number_format($trend['paid_amount'], 2); ?></td>
                                            <td>
                                                <?php 
                                                    $ratio = $trend['total_amount'] > 0 ? ($trend['paid_amount'] / $trend['total_amount']) * 100 : 0;
                                                    echo number_format($ratio, 1) . '%';
                                                ?>
                                                <div class="progress" style="height: 5px;">
                                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo $ratio; ?>%" aria-valuenow="<?php echo $ratio; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Borc ödəmə tendensiyaları məlumatı yoxdur.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light">
                    <a href="debts.php" class="btn btn-sm btn-outline-warning">Borclar Səhifəsinə Keç</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Performans Bonusları və Borc Analizi -->
    <div class="row mt-4">
        <!-- Performans Bonusları -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-success text-white">
                    <h5 class="card-title mb-0">Bu Ayın Ən Yaxşı Performans Göstərən İşçiləri</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($topPerformers)): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>İşçi</th>
                                        <th>Hesabat Sayı</th>
                                        <th>Ümumi Fərq (₼)</th>
                                        <th>Təxmini Bonus (₼)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($topPerformers as $index => $performer): ?>
                                        <?php 
                                            // Bonus hesablama
                                            $base_bonus = $performer['report_count'] * 5;
                                            $additional_bonus = $performer['total_difference'] * 0.1;
                                            $total_bonus = $base_bonus + $additional_bonus;
                                        ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo htmlspecialchars($performer['name']); ?></td>
                                            <td><?php echo $performer['report_count']; ?></td>
                                            <td><?php echo number_format($performer['total_difference'], 2); ?></td>
                                            <td class="text-success"><?php echo number_format($total_bonus, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Bu ay üçün performans bonusu məlumatı yoxdur.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light">
                    <a href="reports.php?type=employee_performance" class="btn btn-sm btn-outline-success">Ətraflı Hesabat</a>
                </div>
            </div>
        </div>

        <!-- Borc Analizi -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-warning text-white">
                    <h5 class="card-title mb-0">Borc Analizi</h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($debtAnalysis)): ?>
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <div class="chart-container">
                                    <canvas id="debtAnalysisChart"></canvas>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card mb-3 border-success">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Ödənilmiş Borclar</h5>
                                        <h2 class="text-success"><?php echo number_format($debtAnalysis['paid_debt'] ?? 0, 2); ?> ₼</h2>
                                        <p class="text-muted">Ümumi borcun <?php 
                                            $total_debt = $debtAnalysis['total_debt'] ?? 1; // Sıfıra bölməmək üçün
                                            $percent = ($debtAnalysis['paid_debt'] ?? 0) / $total_debt * 100;
                                            echo number_format($percent, 1);
                                        ?>%-i</p>
                                    </div>
                                </div>
                                <div class="card border-danger">
                                    <div class="card-body text-center">
                                        <h5 class="card-title">Ödənilməmiş Borclar</h5>
                                        <h2 class="text-danger"><?php echo number_format($debtAnalysis['unpaid_debt'] ?? 0, 2); ?> ₼</h2>
                                        <p class="text-muted">Ümumi borcun <?php 
                                            $percent = ($debtAnalysis['unpaid_debt'] ?? 0) / $total_debt * 100;
                                            echo number_format($percent, 1);
                                        ?>%-i</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <h6 class="mt-4">Son Əlavə Edilən Borclar</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>İşçi</th>
                                        <th>Tarix</th>
                                        <th>Məbləğ (₼)</th>
                                        <th>Səbəb</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentDebts as $debt): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($debt['employee_name']); ?></td>
                                            <td><?php echo date('d.m.Y', strtotime($debt['date'])); ?></td>
                                            <td><?php echo number_format($debt['amount'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($debt['reason']); ?></td>
                                            <td>
                                                <?php if ($debt['is_paid']): ?>
                                                    <span class="badge bg-success">Ödənilib</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Ödənilməyib</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">Borc analizi məlumatı yoxdur.</div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-light">
                    <a href="debts.php" class="btn btn-sm btn-outline-warning">Borclar Səhifəsinə Keç</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Satış Performansı və İşçi Effektivliyi -->
    <div class="row mt-4">
        <!-- Satış Performansı -->
        <?php if (!empty($topSalesPerformers)): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-gradient-purple text-white" style="background: linear-gradient(45deg, #6f42c1, #4e31a5);">
                    <h5 class="card-title mb-0">Bu Ayın Ən Yaxşı Satış Performansı Göstərən İşçiləri</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="chart-container">
                                <canvas id="salesChart"></canvas>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover table-sm">
                                    <thead class="table-light">
                                        <tr>
                                            <th>#</th>
                                            <th>İşçi</th>
                                            <th>Ümumi Satış (₼)</th>
                                            <th>Təxmini Bonus (₼)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($topSalesPerformers as $index => $performer): ?>
                                            <?php 
                                                // Satış bonusu hesablama
                                                $total_sales = (float)$performer['total_sales'];
                                                if ($total_sales < 1000) {
                                                    $bonus = $total_sales * 0.01;
                                                } elseif ($total_sales < 5000) {
                                                    $bonus = $total_sales * 0.02;
                                                } else {
                                                    $bonus = $total_sales * 0.03;
                                                }
                                            ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td><?php echo htmlspecialchars($performer['name']); ?></td>
                                                <td><?php echo number_format($total_sales, 2); ?></td>
                                                <td class="text-success"><?php echo number_format($bonus, 2); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="reports.php?type=sales_performance" class="btn btn-sm btn-outline-primary">Ətraflı Hesabat</a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- İşçi Effektivliyi -->
        <?php if (!empty($employeeEfficiency)): ?>
        <div class="col-<?php echo empty($topSalesPerformers) ? '12' : '6'; ?>">
            <div class="card">
                <div class="card-header bg-gradient-success text-white">
                    <h5 class="card-title mb-0">İşçi Effektivliyi Reytinqi</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover table-sm">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>İşçi</th>
                                    <th>İşdə Günlər</th>
                                    <th>Uğurlu Hesabatlar</th>
                                    <th>Satış Məbləği (₼)</th>
                                    <th>Uğur Nisbəti</th>
                                    <th>Effektivlik</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employeeEfficiency as $index => $employee): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>
                                        <td><?php echo htmlspecialchars($employee['name']); ?></td>
                                        <td><?php echo $employee['attendance_days']; ?></td>
                                        <td>
                                            <?php echo $employee['positive_reports']; ?> / <?php echo $employee['total_reports']; ?>
                                        </td>
                                        <td><?php echo number_format($employee['sales_amount'], 2); ?></td>
                                        <td>
                                            <?php echo number_format($employee['success_rate'], 1); ?>%
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-info" role="progressbar" style="width: <?php echo $employee['success_rate']; ?>%" aria-valuenow="<?php echo $employee['success_rate']; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php echo number_format($employee['efficiency_ratio'], 2); ?>
                                            <div class="progress" style="height: 5px;">
                                                <div class="progress-bar bg-success" role="progressbar" style="width: <?php echo min(100, $employee['efficiency_ratio'] * 20); ?>%" aria-valuenow="<?php echo $employee['efficiency_ratio']; ?>" aria-valuemin="0" aria-valuemax="5"></div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer bg-light">
                    <a href="reports.php?type=employee_efficiency" class="btn btn-sm btn-outline-success">Ətraflı Hesabat</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div> <!-- container -->
<script>
    // Borcların Aylara Görə Artımı Chart
    const debtsByMonth = <?php echo json_encode($debtsByMonth); ?>;
    const debtsLabels = debtsByMonth.map(item => item.month);
    const debtsData   = debtsByMonth.map(item => parseFloat(item.total_debt || 0));

    const ctxDebts = document.getElementById('debtsChart').getContext('2d');
    new Chart(ctxDebts, {
        type: 'bar',
        data: {
            labels: debtsLabels,
            datasets: [{
                label: 'Borclar (₼)',
                data: debtsData,
                backgroundColor: 'rgba(78, 115, 223, 0.6)',
                borderColor:   'rgba(78, 115, 223, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Məbləğ (₼)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Ay'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });

    // Maaşların Aylara Görə Dəyişməsi Chart
    const salaryByMonth = <?php echo json_encode($salaryByMonth); ?>;
    const salaryLabels  = salaryByMonth.map(item => item.month);
    const grossData     = salaryByMonth.map(item => parseFloat(item.total_gross || 0));
    const netData       = salaryByMonth.map(item => parseFloat(item.total_net || 0));

    const ctxSalary = document.getElementById('salaryChart').getContext('2d');
    new Chart(ctxSalary, {
        type: 'line',
        data: {
            labels: salaryLabels,
            datasets: [
                {
                    label: 'Ümumi Maaş (₼)',
                    data: grossData,
                    borderColor: 'rgba(28, 200, 138, 1)',
                    backgroundColor: 'rgba(28, 200, 138, 0.2)',
                    fill: true,
                    tension: 0.4
                },
                {
                    label: 'Net Maaş (₼)',
                    data: netData,
                    borderColor: 'rgba(54, 185, 204, 1)',
                    backgroundColor: 'rgba(54, 185, 204, 0.2)',
                    fill: true,
                    tension: 0.4
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Məbləğ (₼)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Ay'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });

    // İşçilərin Kateqoriya üzrə Paylanması Chart (Pie)
    const employeesCategory = <?php echo json_encode($employeesCategory); ?>;
    const categoryLabels = employeesCategory.map(item => item.category || 'Naməlum');
    const categoryData   = employeesCategory.map(item => parseInt(item.count));

    const ctxCategory = document.getElementById('categoryChart').getContext('2d');
    new Chart(ctxCategory, {
        type: 'doughnut',
        data: {
            labels: categoryLabels,
            datasets: [{
                data: categoryData,
                backgroundColor: [
                    '#4e73df', '#1cc88a', '#36b9cc', 
                    '#f6c23e', '#e74a3b', '#5a5c69'
                ],
                hoverOffset: 6,
                borderWidth: 1,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'right',
                }
            },
            cutout: '60%'
        }
    });

    // Aylıq Kassa Fərqləri Chart
    <?php if (!empty($monthlyCashDifference)): ?>
    const cashDifferenceData = <?php echo json_encode($monthlyCashDifference); ?>;
    const cashDiffLabels = cashDifferenceData.map(item => item.month);
    const cashDiffValues = cashDifferenceData.map(item => parseFloat(item.total_difference || 0));
    const reportCounts   = cashDifferenceData.map(item => parseInt(item.report_count || 0));

    const ctxCashDiff = document.getElementById('cashDifferenceChart').getContext('2d');
    new Chart(ctxCashDiff, {
        type: 'bar',
        data: {
            labels: cashDiffLabels,
            datasets: [
                {
                    label: 'Kassa Fərqi (₼)',
                    data: cashDiffValues,
                    backgroundColor: 'rgba(246, 194, 62, 0.6)',
                    borderColor: 'rgba(246, 194, 62, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Hesabat Sayı',
                    data: reportCounts,
                    type: 'line',
                    borderColor: 'rgba(78, 115, 223, 1)',
                    backgroundColor: 'rgba(78, 115, 223, 0.2)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Kassa Fərqi (₼)'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Hesabat Sayı'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Ay'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });
    <?php endif; ?>

    // Davamiyyət Statistikaları Chart
    <?php if (!empty($attendanceStats)): ?>
    const attendanceData = [
        <?php echo $attendanceStats['present_days'] ?? 0; ?>,
        <?php echo $attendanceStats['half_days'] ?? 0; ?>,
        <?php echo $attendanceStats['absent_days'] ?? 0; ?>
    ];
    
    const ctxAttendance = document.getElementById('attendanceChart').getContext('2d');
    new Chart(ctxAttendance, {
        type: 'bar',
        data: {
            labels: ['İşdə Günlər', 'Yarım Günlər', 'İşdə Olmayan Günlər'],
            datasets: [{
                data: attendanceData,
                backgroundColor: [
                    'rgba(28, 200, 138, 0.8)',  // İşdə - yaşıl
                    'rgba(246, 194, 62, 0.8)',  // Yarım gün - sarı
                    'rgba(231, 74, 59, 0.8)'    // İşdə deyil - qırmızı
                ],
                borderColor: [
                    'rgba(28, 200, 138, 1)',
                    'rgba(246, 194, 62, 1)',
                    'rgba(231, 74, 59, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Gün Sayı'
                    }
                }
            },
            plugins: {
                legend: {
                    display: false
                }
            }
        }
    });
    <?php endif; ?>

    // Borc Analizi Chart
    <?php if (!empty($debtAnalysis)): ?>
    const debtAnalysisData = [
        <?php echo $debtAnalysis['paid_debt'] ?? 0; ?>,
        <?php echo $debtAnalysis['unpaid_debt'] ?? 0; ?>
    ];
    
    const ctxDebtAnalysis = document.getElementById('debtAnalysisChart').getContext('2d');
    new Chart(ctxDebtAnalysis, {
        type: 'pie',
        data: {
            labels: ['Ödənilmiş Borclar', 'Ödənilməmiş Borclar'],
            datasets: [{
                data: debtAnalysisData,
                backgroundColor: [
                    'rgba(28, 200, 138, 0.8)',  // Ödənilmiş - yaşıl
                    'rgba(231, 74, 59, 0.8)'    // Ödənilməmiş - qırmızı
                ],
                borderColor: [
                    'rgba(28, 200, 138, 1)',
                    'rgba(231, 74, 59, 1)'
                ],
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
    <?php endif; ?>

    // Borc Ödəmə Tendensiyaları Chart
    <?php if (!empty($debtPaymentTrends)): ?>
    const debtTrendsData = <?php echo json_encode($debtPaymentTrends); ?>;
    const trendLabels = debtTrendsData.map(item => item.month);
    const totalAmounts = debtTrendsData.map(item => parseFloat(item.total_amount || 0));
    const paidAmounts = debtTrendsData.map(item => parseFloat(item.paid_amount || 0));
    const paymentRatios = debtTrendsData.map(item => {
        const total = parseFloat(item.total_amount || 0);
        const paid = parseFloat(item.paid_amount || 0);
        return total > 0 ? (paid / total) * 100 : 0;
    });

    const ctxDebtTrends = document.getElementById('debtPaymentTrendsChart').getContext('2d');
    new Chart(ctxDebtTrends, {
        type: 'bar',
        data: {
            labels: trendLabels,
            datasets: [
                {
                    label: 'Ümumi Borc (₼)',
                    data: totalAmounts,
                    backgroundColor: 'rgba(231, 74, 59, 0.6)',
                    borderColor: 'rgba(231, 74, 59, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Ödənilmiş Borc (₼)',
                    data: paidAmounts,
                    backgroundColor: 'rgba(28, 200, 138, 0.6)',
                    borderColor: 'rgba(28, 200, 138, 1)',
                    borderWidth: 1,
                    yAxisID: 'y'
                },
                {
                    label: 'Ödəmə Nisbəti (%)',
                    data: paymentRatios,
                    type: 'line',
                    borderColor: 'rgba(54, 185, 204, 1)',
                    backgroundColor: 'rgba(54, 185, 204, 0.2)',
                    borderWidth: 2,
                    fill: false,
                    tension: 0.4,
                    yAxisID: 'y1'
                }
            ]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Məbləğ (₼)'
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    max: 100,
                    grid: {
                        drawOnChartArea: false
                    },
                    title: {
                        display: true,
                        text: 'Ödəmə Nisbəti (%)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Ay'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top',
                }
            }
        }
    });
    <?php endif; ?>

    // Satış Performansı Chart
    <?php if (!empty($topSalesPerformers)): ?>
    const salesPerformers = <?php echo json_encode($topSalesPerformers); ?>;
    const salesLabels = salesPerformers.map(item => item.name);
    const salesData   = salesPerformers.map(item => parseFloat(item.total_sales || 0));
    
    const ctxSales = document.getElementById('salesChart').getContext('2d');
    new Chart(ctxSales, {
        type: 'bar',
        data: {
            labels: salesLabels,
            datasets: [{
                label: 'Ümumi Satış (₼)',
                data: salesData,
                backgroundColor: 'rgba(111, 66, 193, 0.6)',
                borderColor: 'rgba(111, 66, 193, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Satış Məbləği (₼)'
                    }
                }
            },
            plugins: {
                legend: {
                    position: 'top'
                }
            }
        }
    });
    <?php endif; ?>

    // Auto hide alerts after 5 seconds
    setTimeout(function() {
        $('.alert-success, .alert-danger').fadeOut('slow');
    }, 5000);
</script>

</body>
</html>