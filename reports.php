<?php
// reports.php
session_start();
require 'config.php';

// Xətaları göstərmək üçün (test mərhələsində istifadə edin)
// İsteğe bağlı olaraq bu sətrləri canlı sayt üçün söndürə bilərsiniz
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// İstifadəçi girişi yoxlanılır
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Filter parametrlərini alırıq
$filter_date = isset($_GET['filter_date']) ? $_GET['filter_date'] : '';
$filter_category = isset($_GET['filter_category']) ? $_GET['filter_category'] : '';
$filter_search = isset($_GET['filter_search']) ? $_GET['filter_search'] : '';

// Hesabat növünü təyin edin (default olaraq "standard")
$report_type = isset($_GET['type']) ? $_GET['type'] : 'standard';

/**
 * Hesabat məlumatlarını əldə etmək üçün funksiya
 */
function getReportData($conn, $report_type, $filters = []) {
    $data = [];
    
    switch ($report_type) {
        case 'employee_performance':
            // İşçi performans hesabatı
            $query = "
                SELECT e.name, 
                       COUNT(cr.id) as report_count, 
                       SUM(cr.difference) as total_difference,
                       AVG(cr.difference) as avg_difference,
                       MAX(cr.date) as last_report_date
                FROM employees e
                LEFT JOIN cash_reports cr ON e.id = cr.employee_id
                WHERE e.is_active = 1
            ";
            
            $params = [];
            
            // Kateqoriya filtri əlavə edirik
            if (!empty($filters['category'])) {
                $query .= " AND e.category = :category";
                $params[':category'] = $filters['category'];
            }
            
            // Tarix filtri əlavə edirik
            if (!empty($filters['date'])) {
                $date = date('Y-m', strtotime($filters['date']));
                $query .= " AND DATE_FORMAT(cr.date, '%Y-%m') = :date";
                $params[':date'] = $date;
            }
            
            // Ad filtri əlavə edirik
            if (!empty($filters['search'])) {
                $query .= " AND e.name LIKE :search";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            $query .= " GROUP BY e.id, e.name ORDER BY total_difference DESC";
            
            $stmt = $conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'monthly_attendance':
            // Aylıq davamiyyət hesabatı
            $current_month = !empty($filters['date']) ? date('Y-m', strtotime($filters['date'])) : date('Y-m');
            
            $query = "
                SELECT e.name, e.category,
                       SUM(CASE WHEN a.status = 1 THEN 1 ELSE 0 END) as present_days,
                       SUM(CASE WHEN a.status = 0.5 THEN 1 ELSE 0 END) as half_days,
                       SUM(CASE WHEN a.status = 0 THEN 1 ELSE 0 END) as absent_days,
                       COUNT(a.id) as total_days,
                       MAX(a.date) as last_attendance_date
                FROM employees e
                LEFT JOIN attendance a ON e.id = a.employee_id AND DATE_FORMAT(a.date, '%Y-%m') = :month
                WHERE e.is_active = 1
            ";
            
            $params = [':month' => $current_month];
            
            // Kateqoriya filtri əlavə edirik
            if (!empty($filters['category'])) {
                $query .= " AND e.category = :category";
                $params[':category'] = $filters['category'];
            }
            
            // Ad filtri əlavə edirik
            if (!empty($filters['search'])) {
                $query .= " AND e.name LIKE :search";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            $query .= " GROUP BY e.id, e.name, e.category ORDER BY e.name";
            
            $stmt = $conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'salary_comparison':
            // Maaş müqayisəsi hesabatı
            $query = "
                SELECT e.name, e.category, e.salary as current_salary,
                       (SELECT sp.gross_salary 
                        FROM salary_payments sp 
                        WHERE sp.employee_id = e.id 
                        ORDER BY sp.payment_date DESC 
                        LIMIT 1) as last_payment,
                       (SELECT sp.payment_date 
                        FROM salary_payments sp 
                        WHERE sp.employee_id = e.id 
                        ORDER BY sp.payment_date DESC 
                        LIMIT 1) as last_payment_date,
                       (SELECT COUNT(*) 
                        FROM salary_payments sp 
                        WHERE sp.employee_id = e.id) as payment_count
                FROM employees e
                WHERE e.is_active = 1
            ";
            
            $params = [];
            
            // Kateqoriya filtri əlavə edirik
            if (!empty($filters['category'])) {
                $query .= " AND e.category = :category";
                $params[':category'] = $filters['category'];
            }
            
            // Ad filtri əlavə edirik
            if (!empty($filters['search'])) {
                $query .= " AND e.name LIKE :search";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            $query .= " ORDER BY e.salary DESC";
            
            $stmt = $conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'debt_analysis':
            // Borc analizi hesabatı
            $query = "
                SELECT e.name, e.category,
                       SUM(d.amount) as total_debt,
                       SUM(CASE WHEN d.is_paid = 1 THEN d.amount ELSE 0 END) as paid_debt,
                       SUM(CASE WHEN d.is_paid = 0 THEN d.amount ELSE 0 END) as unpaid_debt,
                       COUNT(d.id) as debt_count,
                       MAX(d.date) as last_debt_date
                FROM employees e
                LEFT JOIN debts d ON e.id = d.employee_id
                WHERE e.is_active = 1
            ";
            
            $params = [];
            
            // Kateqoriya filtri əlavə edirik
            if (!empty($filters['category'])) {
                $query .= " AND e.category = :category";
                $params[':category'] = $filters['category'];
            }
            
            // Tarix filtri əlavə edirik
            if (!empty($filters['date'])) {
                $date = date('Y-m', strtotime($filters['date']));
                $query .= " AND DATE_FORMAT(d.date, '%Y-%m') = :date";
                $params[':date'] = $date;
            }
            
            // Ad filtri əlavə edirik
            if (!empty($filters['search'])) {
                $query .= " AND e.name LIKE :search";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            $query .= " GROUP BY e.id, e.name, e.category ORDER BY unpaid_debt DESC";
            
            $stmt = $conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'category_analysis':
            // Kateqoriya analizi hesabatı
            $stmt = $conn->query("
                SELECT e.category,
                       COUNT(e.id) as employee_count,
                       AVG(e.salary) as avg_salary,
                       MIN(e.salary) as min_salary,
                       MAX(e.salary) as max_salary,
                       SUM(e.salary) as total_salary
                FROM employees e
                WHERE e.is_active = 1
                GROUP BY e.category
                ORDER BY employee_count DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'sales_performance':
            // Satış performansı hesabatı (əgər sales cədvəli varsa)
            try {
                $stmt = $conn->query("
                    SELECT e.name,
                           SUM(s.amount) as total_sales,
                           COUNT(s.id) as sale_count,
                           AVG(s.amount) as avg_sale,
                           MAX(s.date) as last_sale_date
                    FROM employees e
                    LEFT JOIN sales s ON e.id = s.employee_id
                    WHERE e.is_active = 1
                    GROUP BY e.id, e.name
                    ORDER BY total_sales DESC
                ");
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Cədvəl mövcud deyilsə, ya da başqa xəta varsa
                $data = [];
                error_log("Sales performance report error: " . $e->getMessage());
            }
            break;
            
        default:
            // Standart hesabat
            $query = "
                SELECT e.name, e.salary, e.category, e.start_date,
                       (SELECT COUNT(*) FROM attendance a WHERE a.employee_id = e.id AND a.status = 0) as absence_count
                FROM employees e
                WHERE e.is_active = 1
            ";
            
            $params = [];
            
            // Kateqoriya filtri əlavə edirik
            if (!empty($filters['category'])) {
                $query .= " AND e.category = :category";
                $params[':category'] = $filters['category'];
            }
            
            // Ad filtri əlavə edirik
            if (!empty($filters['search'])) {
                $query .= " AND e.name LIKE :search";
                $params[':search'] = '%' . $filters['search'] . '%';
            }
            
            $query .= " ORDER BY e.name";
            
            $stmt = $conn->prepare($query);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    return $data;
}

/**
 * Hesabat məlumatlarını CSV formatında ixrac etmək üçün funksiya
 */
function exportReportToCSV($data, $report_type) {
    // CSV başlıqlarını təyin edirik
    $headers = [];
    
    switch ($report_type) {
        case 'employee_performance':
            $headers = ['İşçi Adı', 'Hesabat Sayı', 'Ümumi Fərq', 'Ortalama Fərq'];
            break;
        case 'monthly_attendance':
            $headers = ['İşçi Adı', 'İşdə Günlər', 'Yarım Günlər', 'İşdə Olmayan Günlər', 'Ümumi Günlər'];
            break;
        case 'salary_comparison':
            $headers = ['İşçi Adı', 'Cari Maaş', 'Son Ödəniş', 'Son Ödəniş Tarixi'];
            break;
        case 'debt_analysis':
            $headers = ['İşçi Adı', 'Ümumi Borc', 'Ödənilmiş Borc', 'Ödənilməmiş Borc', 'Borc Sayı'];
            break;
        case 'category_analysis':
            $headers = ['Kateqoriya', 'İşçi Sayı', 'Ortalama Maaş', 'Minimum Maaş', 'Maksimum Maaş', 'Ümumi Maaş'];
            break;
        case 'sales_performance':
            $headers = ['İşçi Adı', 'Ümumi Satış', 'Satış Sayı', 'Ortalama Satış'];
            break;
        default:
            $headers = ['İşçi Adı', 'Maaş', 'Kateqoriya', 'Başlanğıc Tarixi', 'İşə Gəlməmə Sayı'];
            break;
    }
    
    // CSV faylını yaradırıq
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $report_type . '_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM əlavə edirik (Excel üçün)
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Başlıqları yazırıq
    fputcsv($output, $headers);
    
    // Məlumatları yazırıq
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// Mövcud kateqoriyaları əldə edirik
function getEmployeeCategories($conn) {
    $stmt = $conn->query("SELECT DISTINCT category FROM employees WHERE is_active = 1 ORDER BY category");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Kateqoriyaları alırıq
try {
    $categories = getEmployeeCategories($conn);
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Filtrləri hazırlayırıq
$filters = [
    'date' => $filter_date,
    'category' => $filter_category,
    'search' => $filter_search
];

// Hesabat məlumatlarını əldə edirik
$report_data = getReportData($conn, $report_type, $filters);

// CSV ixrac əməliyyatı
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // CSV ixrac parametrlərini əldə edirik
    $export_filters = [
        'date' => isset($_GET['filter_date']) ? $_GET['filter_date'] : '',
        'category' => isset($_GET['filter_category']) ? $_GET['filter_category'] : '',
        'search' => isset($_GET['filter_search']) ? $_GET['filter_search'] : ''
    ];
    
    // Filterlər tətbiq olunmuş məlumatları əldə edirik
    $export_data = getReportData($conn, $report_type, $export_filters);
    
    // CSV formatında ixrac edirik
    exportReportToCSV($export_data, $report_type);
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title>Hesabatlar</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <style>
        body {
            background-color: #f8f9fa;
        }
        .chart-container {
            position: relative;
            margin: auto;
            height: 60vh;
            width: 80vw;
        }
        .table th, .table td {
            vertical-align: middle;
            text-align: center;
        }
        .nav-tabs .nav-link.active {
            background-color: #007bff;
            color: white;
        }
        .nav-tabs .nav-link {
            color: #007bff;
        }
        .report-count {
            font-size: 0.9rem;
            color: #6c757d;
            font-weight: normal;
            margin-left: 10px;
        }
    </style>
</head>
<body>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php include 'includes/navbar.php'; ?>

    <div class="container mt-4">
        <h2 class="mb-4">Hesabatlar</h2>
        
        <!-- Hesabat Növləri Naviqasiyası -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'standard' ? 'active' : ''; ?>" href="?type=standard">
                    <i class="fas fa-list"></i> Standart
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'employee_performance' ? 'active' : ''; ?>" href="?type=employee_performance">
                    <i class="fas fa-chart-line"></i> İşçi Performansı
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'monthly_attendance' ? 'active' : ''; ?>" href="?type=monthly_attendance">
                    <i class="fas fa-calendar-check"></i> Davamiyyət
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'salary_comparison' ? 'active' : ''; ?>" href="?type=salary_comparison">
                    <i class="fas fa-money-bill-wave"></i> Maaş Müqayisəsi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'debt_analysis' ? 'active' : ''; ?>" href="?type=debt_analysis">
                    <i class="fas fa-hand-holding-usd"></i> Borc Analizi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'category_analysis' ? 'active' : ''; ?>" href="?type=category_analysis">
                    <i class="fas fa-users"></i> Kateqoriya Analizi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $report_type == 'sales_performance' ? 'active' : ''; ?>" href="?type=sales_performance">
                    <i class="fas fa-shopping-cart"></i> Satış Performansı
                </a>
            </li>
        </ul>

        <!-- İxrac Düyməsi -->
        <div class="mt-3 mb-3 text-end">
            <a href="?type=<?php echo $report_type; ?>&export=csv" class="btn btn-success">
                <i class="fas fa-file-csv me-1"></i> CSV İxrac Et
            </a>
        </div>

        <!-- Hesabat Başlığı və Kart -->
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">
                    <?php
                    $report_titles = [
                        'standard' => 'Standart Hesabat',
                        'employee_performance' => 'İşçi Performans Hesabatı',
                        'monthly_attendance' => 'Aylıq Davamiyyət Hesabatı',
                        'salary_comparison' => 'Maaş Müqayisəsi Hesabatı',
                        'debt_analysis' => 'Borc Analizi Hesabatı',
                        'category_analysis' => 'Kateqoriya Analizi Hesabatı',
                        'sales_performance' => 'Satış Performansı Hesabatı'
                    ];
                    echo $report_titles[$report_type] ?? 'Hesabat';
                    ?>
                    <?php if (!empty($report_data)): ?>
                        <span class="badge bg-light text-dark ms-2">
                            <?php echo count($report_data); ?> nəticə
                        </span>
                    <?php endif; ?>
                </h5>
                <div>
                    <?php if (!empty($filter_date) || !empty($filter_category) || !empty($filter_search)): ?>
                        <span class="badge bg-warning text-dark">
                            <i class="fas fa-filter"></i> Filtrələnmiş
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($report_data)): ?>
                    <div class="alert alert-info">Bu hesabat üçün məlumat tapılmadı.</div>
                <?php else: ?>
                    <!-- Filterleme Bölümü -->
                    <div class="card mb-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0">Filterlər</h6>
                        </div>
                        <div class="card-body">
                            <form method="GET" action="" class="row g-3">
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($report_type); ?>">
                                
                                <div class="col-md-4">
                                    <label for="filter_date" class="form-label">Tarix</label>
                                    <input type="month" id="filter_date" name="filter_date" class="form-control" 
                                           value="<?php echo htmlspecialchars($filter_date); ?>">
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="filter_category" class="form-label">Kateqoriya</label>
                                    <select id="filter_category" name="filter_category" class="form-select">
                                        <option value="">Bütün Kateqoriyalar</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo htmlspecialchars($category); ?>" 
                                                    <?php echo $filter_category === $category ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="col-md-4">
                                    <label for="filter_search" class="form-label">Ad Axtar</label>
                                    <input type="text" id="filter_search" name="filter_search" class="form-control" 
                                           value="<?php echo htmlspecialchars($filter_search); ?>" 
                                           placeholder="İşçi adı...">
                                </div>
                                
                                <div class="col-12 text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-filter"></i> Filtrələ
                                    </button>
                                    <a href="?type=<?php echo urlencode($report_type); ?>" class="btn btn-secondary">
                                        <i class="fas fa-undo"></i> Sıfırla
                                    </a>
                                    <a href="?type=<?php echo urlencode($report_type); ?>&export=csv<?php 
                                            echo !empty($filter_date) ? '&filter_date=' . urlencode($filter_date) : ''; 
                                            echo !empty($filter_category) ? '&filter_category=' . urlencode($filter_category) : ''; 
                                            echo !empty($filter_search) ? '&filter_search=' . urlencode($filter_search) : ''; 
                                        ?>" class="btn btn-success">
                                        <i class="fas fa-file-csv"></i> CSV İxrac
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <?php if ($report_type == 'employee_performance'): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>İşçi Adı</th>
                                        <th>Hesabat Sayı</th>
                                        <th>Ümumi Fərq</th>
                                        <th>Ortalama Fərq</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['report_count'] ?? 0); ?></td>
                                            <td><?php echo number_format($row['total_difference'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo number_format($row['avg_difference'] ?? 0, 2); ?> AZN</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="chart-container mt-4">
                            <canvas id="performanceChart"></canvas>
                        </div>
                    <?php elseif ($report_type == 'monthly_attendance'): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>İşçi Adı</th>
                                        <th>İşdə Günlər</th>
                                        <th>Yarım Günlər</th>
                                        <th>İşdə Olmayan Günlər</th>
                                        <th>Ümumi Günlər</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['present_days']); ?></td>
                                            <td><?php echo htmlspecialchars($row['half_days']); ?></td>
                                            <td><?php echo htmlspecialchars($row['absent_days']); ?></td>
                                            <td><?php echo htmlspecialchars($row['total_days']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="chart-container mt-4">
                            <canvas id="attendanceChart"></canvas>
                        </div>
                    <?php elseif ($report_type == 'salary_comparison'): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>İşçi Adı</th>
                                        <th>Cari Maaş</th>
                                        <th>Son Ödəniş</th>
                                        <th>Son Ödəniş Tarixi</th>
                                        <th>Fərq</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <?php 
                                            $current_salary = (float)$row['current_salary'];
                                            $last_payment = (float)($row['last_payment'] ?? 0);
                                            $difference = $current_salary - $last_payment;
                                            $difference_class = $difference > 0 ? 'text-success' : ($difference < 0 ? 'text-danger' : '');
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo number_format($current_salary ?? 0, 2); ?> AZN</td>
                                            <td><?php echo ($last_payment ? number_format($last_payment, 2) . ' AZN' : 'Yoxdur'); ?></td>
                                            <td><?php echo $row['last_payment_date'] ?? 'Yoxdur'; ?></td>
                                            <td class="<?php echo $difference_class; ?>">
                                                <?php if ($difference != 0): ?>
                                                    <?php echo number_format(abs($difference), 2); ?> AZN
                                                    <?php echo $difference > 0 ? '<i class="fas fa-arrow-up"></i>' : '<i class="fas fa-arrow-down"></i>'; ?>
                                                <?php else: ?>
                                                    0.00 AZN
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php elseif ($report_type == 'debt_analysis'): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>İşçi Adı</th>
                                        <th>Ümumi Borc</th>
                                        <th>Ödənilmiş Borc</th>
                                        <th>Ödənilməmiş Borc</th>
                                        <th>Borc Sayı</th>
                                        <th>Ödəniş Faizi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <?php 
                                            $total_debt = (float)($row['total_debt'] ?? 0);
                                            $paid_debt = (float)($row['paid_debt'] ?? 0);
                                            $payment_percentage = $total_debt > 0 ? ($paid_debt / $total_debt) * 100 : 0;
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo number_format($total_debt, 2); ?> AZN</td>
                                            <td><?php echo number_format($paid_debt, 2); ?> AZN</td>
                                            <td><?php echo number_format($row['unpaid_debt'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo htmlspecialchars($row['debt_count'] ?? 0); ?></td>
                                            <td>
                                                <div class="progress">
                                                    <div class="progress-bar bg-success" role="progressbar" 
                                                         style="width: <?php echo $payment_percentage; ?>%"
                                                         aria-valuenow="<?php echo $payment_percentage; ?>" 
                                                         aria-valuemin="0" aria-valuemax="100">
                                                        <?php echo number_format($payment_percentage, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="chart-container mt-4">
                            <canvas id="debtChart"></canvas>
                        </div>
                    <?php elseif ($report_type == 'category_analysis'): ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Kateqoriya</th>
                                        <th>İşçi Sayı</th>
                                        <th>Ortalama Maaş</th>
                                        <th>Minimum Maaş</th>
                                        <th>Maksimum Maaş</th>
                                        <th>Ümumi Maaş</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['category'] ?? 'Təyin edilməyib'); ?></td>
                                            <td><?php echo htmlspecialchars($row['employee_count'] ?? 0); ?></td>
                                            <td><?php echo number_format($row['avg_salary'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo number_format($row['min_salary'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo number_format($row['max_salary'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo number_format($row['total_salary'] ?? 0, 2); ?> AZN</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="chart-container mt-4">
                            <canvas id="categoryChart"></canvas>
                        </div>
                    <?php elseif ($report_type == 'sales_performance'): ?>
                        <?php if (empty($report_data)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                Satış məlumatları tapılmadı. Satış cədvəli mövcud olmaya bilər.
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>İşçi Adı</th>
                                            <th>Ümumi Satış</th>
                                            <th>Satış Sayı</th>
                                            <th>Ortalama Satış</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($report_data as $row): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                                <td><?php echo number_format($row['total_sales'] ?? 0, 2); ?> AZN</td>
                                                <td><?php echo htmlspecialchars($row['sale_count'] ?? 0); ?></td>
                                                <td><?php echo number_format($row['avg_sale'] ?? 0, 2); ?> AZN</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="chart-container mt-4">
                                <canvas id="salesChart"></canvas>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <!-- Standart hesabat -->
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>İşçi Adı</th>
                                        <th>Maaş</th>
                                        <th>Kateqoriya</th>
                                        <th>Başlanğıc Tarixi</th>
                                        <th>İşə Gəlməmə Sayı</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($report_data as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo number_format($row['salary'] ?? 0, 2); ?> AZN</td>
                                            <td><?php echo htmlspecialchars($row['category'] ?? 'Təyin edilməyib'); ?></td>
                                            <td><?php echo htmlspecialchars($row['start_date'] ?? 'Təyin edilməyib'); ?></td>
                                            <td><?php echo htmlspecialchars($row['absence_count'] ?? 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- JavaScript: Chartların Yaradılması -->
        <script>
            <?php if($report_type == 'employees' && count($report_data) > 0): ?>
                // Kateqoriyalara Görə İşçi Paylanması Chartı
                var ctxEmployees = document.getElementById('employeesChart').getContext('2d');
                var employeesChart = new Chart(ctxEmployees, {
                    type: 'pie',
                    data: {
                        labels: <?php echo json_encode(array_column($report_data, 'category')); ?>,
                        datasets: [{
                            label: 'İşçi Paylanması',
                            data: <?php echo json_encode(array_column($report_data, 'count')); ?>,
                            backgroundColor: [
                                'rgba(54, 162, 235, 0.6)',
                                'rgba(255, 99, 132, 0.6)',
                                'rgba(255, 206, 86, 0.6)',
                                'rgba(75, 192, 192, 0.6)',
                                'rgba(153, 102, 255, 0.6)',
                                'rgba(255, 159, 64, 0.6)'
                            ],
                            borderColor: [
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 99, 132, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true
                    }
                });
            <?php endif; ?>

            <?php if($report_type == 'debts' && count($report_data) > 0): ?>
                // Borcların Aylara Görə Artımı Chartı
                var ctxDebts = document.getElementById('debtsChart').getContext('2d');
                var debtsChart = new Chart(ctxDebts, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode(array_map(function($row) { return date('F Y', strtotime($row['month'] . '-01')); }, $report_data)); ?>,
                        datasets: [{
                            label: 'Borclar (AZN)',
                            data: <?php echo json_encode(array_column($report_data, 'total_debt')); ?>,
                            backgroundColor: 'rgba(255, 99, 132, 0.6)',
                            borderColor: 'rgba(255, 99, 132, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            <?php endif; ?>

            <?php if($report_type == 'salaries' && count($report_data) > 0): ?>
                // Maaş Ödənişlərinin Aylara Görə Dəyişməsi Chartı
                var ctxSalaries = document.getElementById('salariesChart').getContext('2d');
                var salariesChart = new Chart(ctxSalaries, {
                    type: 'line',
                    data: {
                        labels: <?php echo json_encode(array_map(function($row) { return date('F Y', strtotime($row['month'] . '-01')); }, $report_data)); ?>,
                        datasets: [
                            {
                                label: 'Ümumi Maaşlar (AZN)',
                                data: <?php echo json_encode(array_column($report_data, 'total_gross')); ?>,
                                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                                borderColor: 'rgba(54, 162, 235, 1)',
                                fill: false,
                                tension: 0.1
                            },
                            {
                                label: 'Ümumi Xalis Maaşlar (AZN)',
                                data: <?php echo json_encode(array_column($report_data, 'total_net')); ?>,
                                backgroundColor: 'rgba(75, 192, 192, 0.6)',
                                borderColor: 'rgba(75, 192, 192, 1)',
                                fill: false,
                                tension: 0.1
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
            <?php endif; ?>
        </script>

        <!-- Bootstrap JS və jQuery (əvvəlcədən əlavə edilmişdir) -->
    </div>
</body>
</html>
