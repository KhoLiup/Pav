<?php
// salary.php
session_start();
require 'config.php';

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// İstifadəçi yoxdursa
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

/**
 * Sanitizasiya funksiyası
 */
function sanitize_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

/**
 * İşçinin seçilən ayda işə gəlmədiyi günlərin sayı
 */
function getAbsenceCount($conn, $employee_id, $month) {
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM attendance 
        WHERE employee_id = :employee_id 
          AND DATE_FORMAT(date, '%Y-%m') = :month
          AND status = 0
    ");
    $stmt->execute([
        ':employee_id' => $employee_id,
        ':month'       => $month
    ]);
    return (int)$stmt->fetchColumn();
}

/**
 * Bu ay üçün ödənilməmiş borclar (detallı)
 */
function getMonthlyUnpaidDebtsDetails($conn, $employee_id, $month) {
    $stmt = $conn->prepare("
        SELECT id, reason, amount, date
        FROM debts
        WHERE employee_id = :employee_id
          AND DATE_FORMAT(date, '%Y-%m') = :month
          AND is_paid = 0
        ORDER BY date ASC
    ");
    $stmt->execute([
        ':employee_id' => $employee_id,
        ':month'       => $month
    ]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Bu ay üçün ödənilməmiş borcların cəmi
 */
function getUnpaidDebtsSum($conn, $employee_id, $month) {
    $stmt = $conn->prepare("
        SELECT SUM(amount)
        FROM debts
        WHERE employee_id = :employee_id
          AND DATE_FORMAT(date, '%Y-%m') = :month
          AND is_paid = 0
    ");
    $stmt->execute([
        ':employee_id' => $employee_id,
        ':month'       => $month
    ]);
    $sum = $stmt->fetchColumn();
    return $sum ? (float)$sum : 0.0;
}

/**
 * Maaşı hesablayan funksiya
 */
function calculateSalary($salary, $absence_count, $debt_sum, $additional_payment, $max_vacation_days, $days_employed, $total_days_in_month) {
    $daily_wage = $salary / $total_days_in_month;
    $proportional_salary = $daily_wage * $days_employed;

    $absence_deduction = 0;
    if ($absence_count > $max_vacation_days) {
        $exceed_days = $absence_count - $max_vacation_days;
        $absence_deduction = $exceed_days * $daily_wage;
    }

    $net_salary = $proportional_salary - $absence_deduction - $debt_sum + $additional_payment;

    return [
        'net_salary'        => $net_salary,
        'absence_deduction' => $absence_deduction,
        'total_deduction'   => $absence_deduction + $debt_sum,
        'proportional_salary' => $proportional_salary,
        'daily_wage'        => $daily_wage,
        'exceed_days'       => isset($exceed_days) ? $exceed_days : 0
    ];
}

/**
 * Borcu növbəti aya keçirmək
 */
function carryForwardDebts($conn, $employee_id, $current_month, $amount = null) {
    $next_month      = date('Y-m', strtotime("$current_month +1 month"));
    $next_month_date = $next_month . '-01';

    if (is_null($amount)) {
        $stmt = $conn->prepare("
            SELECT SUM(amount) as total_debt
            FROM debts 
            WHERE employee_id = :employee_id 
              AND DATE_FORMAT(date, '%Y-%m') = :current_month
              AND is_paid = 0
        ");
        $stmt->execute([
            ':employee_id'   => $employee_id,
            ':current_month' => $current_month
        ]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        $total_debt = $res['total_debt'] ? (float)$res['total_debt'] : 0.0;
    } else {
        $total_debt = (float)$amount;
    }

    if ($total_debt > 0) {
        // Eyni məbləğli is_paid=0 borc varsa, təkrarlanmasın
        $stmt_check = $conn->prepare("
            SELECT COUNT(*)
            FROM debts
            WHERE employee_id = :employee_id
              AND DATE_FORMAT(date, '%Y-%m') = :next_month
              AND amount = :amount
              AND is_paid = 0
        ");
        $stmt_check->execute([
            ':employee_id' => $employee_id,
            ':next_month'  => $next_month,
            ':amount'      => $total_debt
        ]);
        $exists = $stmt_check->fetchColumn();

        if (!$exists) {
            $stmt_insert = $conn->prepare("
                INSERT INTO debts (employee_id, amount, date, reason, is_paid, month)
                VALUES (:employee_id, :amount, :date, :reason, 0, :month)
            ");
            $stmt_insert->execute([
                ':employee_id' => $employee_id,
                ':amount'      => $total_debt,
                ':date'        => $next_month_date,
                ':reason'      => 'Növbəti ay üçün borc',
                ':month'       => $next_month
            ]);
        }
    }
}

/**
 * Maaş ödənişləri
 */
function processSalaryPayments($conn, $postData, $month) {
    $payment_date  = sanitize_input($postData['payment_date']);
    $employee_data = isset($postData['employees']) ? $postData['employees'] : [];

    if (empty($employee_data)) {
        $_SESSION['error_message'] = 'Ödəniləcək işçi məlumatları tapılmadı.';
        header('Location: salary.php');
        exit();
    }

    try {
        $conn->beginTransaction();
        
        // Ödəniş məlumatlarını sessiyada saxlayırıq (hesabat üçün)
        $_SESSION['salary_payment_data'] = $employee_data;
        $_SESSION['payment_date'] = $payment_date;
        $_SESSION['month'] = $month;

        foreach ($employee_data as $emp_id => $data) {
            $emp_id         = (int)$emp_id;
            $gross_salary   = (float)$data['gross_salary'];
            $deduction      = (float)$data['deduction']; 
            $debt_total     = (float)$data['debt'];
            $additional_pay = isset($data['additional_payment']) ? (float)$data['additional_payment'] : 0.0;
            $reason         = isset($data['reason']) ? sanitize_input($data['reason']) : '';
            $net_salary     = (float)$data['net_salary'];
            $manual_carry   = isset($data['manual_carry_forward_amount']) ? (float)$data['manual_carry_forward_amount'] : 0.0;

            // salary_payments
            $stmt = $conn->prepare("
                INSERT INTO salary_payments
                (employee_id, gross_salary, deductions, net_salary, payment_date, additional_payment, reason, month)
                VALUES
                (:employee_id, :gross_salary, :deductions, :net_salary, :payment_date, :additional_payment, :reason, :month)
            ");
            $stmt->execute([
                ':employee_id'       => $emp_id,
                ':gross_salary'      => $gross_salary,
                ':deductions'        => $deduction + $debt_total,
                ':net_salary'        => $net_salary,
                ':payment_date'      => $payment_date,
                ':additional_payment'=> $additional_pay,
                ':reason'            => $reason,
                ':month'             => $month
            ]);

            // Maaş Artımı
            if (strtolower($reason) === 'maaş artımı') {
                $new_salary = $gross_salary + $additional_pay;
                $st_upd = $conn->prepare("
                    UPDATE employees SET salary=:salary WHERE id=:employee_id
                ");
                $st_upd->execute([
                    ':salary'      => $new_salary,
                    ':employee_id' => $emp_id
                ]);
            }

            // Seçilmiş borcları ödənilmiş kimi işarələ
            if (isset($data['debts']) && is_array($data['debts'])) {
                foreach ($data['debts'] as $debt_id => $value) {
                    if ($value == 1) {
                        $stmt_debt = $conn->prepare("
                            UPDATE debts
                            SET is_paid=1, payment_date=:payment_date
                            WHERE id=:debt_id AND employee_id=:employee_id
                        ");
                        $stmt_debt->execute([
                            ':payment_date' => $payment_date,
                            ':debt_id'      => $debt_id,
                            ':employee_id'  => $emp_id
                        ]);
                    }
                }
            }

            // net_salary mənfidirsə, qalan borcu növbəti aya keçir
            if ($net_salary < 0) {
                $negative = abs($net_salary);
                carryForwardDebts($conn, $emp_id, $month, $negative);
            }

            // Manual borc
            if ($manual_carry > 0) {
                $next_month = date('Y-m', strtotime("$month +1 month"));
                $next_month_date = $next_month . '-01';
                
                $stmt_insert = $conn->prepare("
                    INSERT INTO debts (employee_id, amount, date, reason, is_paid, month)
                    VALUES (:employee_id, :amount, :date, :reason, 0, :month)
                ");
                $stmt_insert->execute([
                    ':employee_id' => $emp_id,
                    ':amount'      => $manual_carry,
                    ':date'        => $next_month_date,
                    ':reason'      => 'Manual əlavə edilmiş borc',
                    ':month'       => $next_month
                ]);
            }
        }

        $conn->commit();
        // Hesabat səhifəsinə yönləndir
        header('Location: salary_report.php');
        exit();
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = 'Xəta: ' . htmlspecialchars($e->getMessage());
        header('Location: salary.php');
        exit();
    }
}

/**
 * CSV/JSON ixrac
 */
function exportSalaries($conn, $postData) {
    $employee_ids = isset($postData['employee_ids']) ? $postData['employee_ids'] : [];
    $fields       = isset($postData['fields']) ? $postData['fields'] : [];

    if (empty($employee_ids)) {
        $_SESSION['error_message'] = 'Heç bir işçi seçilməyib.';
        header('Location: salary.php');
        exit();
    }
    if (empty($fields)) {
        $_SESSION['error_message'] = 'İxrac üçün heç bir məlumat seçilməyib.';
        header('Location: salary.php');
        exit();
    }

    $allowed_fields  = ['id', 'name', 'salary', 'max_vacation_days', 'start_date'];
    $selected_fields = array_intersect($fields, $allowed_fields);

    if (empty($selected_fields)) {
        $_SESSION['error_message'] = 'Seçilmiş məlumatlar etibarlı deyil.';
        header('Location: salary.php');
        exit();
    }

    try {
        $ph = implode(',', array_fill(0, count($employee_ids), '?'));
        $sql = "SELECT " . implode(', ', $selected_fields) . " 
                FROM employees
                WHERE id IN ($ph) AND is_active=1";
        $stmt = $conn->prepare($sql);
        $stmt->execute($employee_ids);
        $res = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($res)) {
            $_SESSION['error_message'] = 'Seçilmiş işçilər üçün məlumat tapılmadı.';
            header('Location: salary.php');
            exit();
        }

        $format = isset($postData['export_format']) ? $postData['export_format'] : 'csv';

        // CSV
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=salaries_export_' . date('Ymd_His') . '.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, $selected_fields);
            foreach ($res as $r) {
                fputcsv($output, $r);
            }
            fclose($output);
            exit();
        }
        // JSON
        elseif ($format === 'json') {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename=salaries_export_' . date('Ymd_His') . '.json');
            echo json_encode($res, JSON_PRETTY_PRINT);
            exit();
        }
        else {
            $_SESSION['error_message'] = 'Dəstəklənməyən format (yalnız CSV/JSON).';
            header('Location: salary.php');
            exit();
        }

    } catch (PDOException $e) {
        $_SESSION['error_message'] = 'Xəta: ' . htmlspecialchars($e->getMessage());
        header('Location: salary.php');
        exit();
    }
}

/**
 * Manual borc keçirmə
 */
function handleManualCarryForward($conn, $postData, $month) {
    if (!isset($postData['manual_carry_forward'])) {
        return;
    }
    $employee_id = isset($postData['manual_employee_id']) ? (int)$postData['manual_employee_id'] : 0;
    $amount      = isset($postData['manual_amount']) ? (float)$postData['manual_amount'] : 0.0;
    if ($employee_id <= 0 || $amount <= 0) {
        $_SESSION['error_message'] = 'Düzgün işçi və məbləğ seçin.';
        header('Location: salary.php');
        exit();
    }

    try {
        $conn->beginTransaction();
        carryForwardDebts($conn, $employee_id, $month, $amount);
        $conn->commit();
        $_SESSION['success_message'] = 'Borc növbəti aya uğurla keçirildi.';
        header('Location: salary.php');
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = 'Xəta: ' . htmlspecialchars($e->getMessage());
        header('Location: salary.php');
        exit();
    }
}

/**
 * İşçinin performans bonusunu hesablayan funksiya
 * Bu funksiya işçinin kassa hesabatlarına əsasən bonus hesablayır
 */
function calculatePerformanceBonus($conn, $employee_id, $month) {
    try {
        // Ay üçün kassa hesabatlarını əldə edirik
        $stmt = $conn->prepare("
            SELECT SUM(difference) as total_difference, COUNT(*) as report_count
            FROM cash_reports
            WHERE employee_id = :employee_id
              AND DATE_FORMAT(date, '%Y-%m') = :month
        ");
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':month' => $month
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || $result['report_count'] == 0) {
            return 0; // Hesabat yoxdursa, bonus yoxdur
        }
        
        $total_difference = (float)$result['total_difference'];
        $report_count = (int)$result['report_count'];
        
        // Müsbət fərq varsa, bonus hesablayırıq
        if ($total_difference > 0) {
            // Bonus hesablama alqoritmi:
            // 1. Hər hesabat üçün 5 AZN baza bonus
            // 2. Müsbət fərqin 10%-i əlavə bonus
            $base_bonus = $report_count * 5;
            $additional_bonus = $total_difference * 0.1;
            
            return $base_bonus + $additional_bonus;
        }
        
        return 0; // Müsbət fərq yoxdursa, bonus yoxdur
    } catch (PDOException $e) {
        error_log("Performance bonus calculation error: " . $e->getMessage());
        return 0;
    }
}

/**
 * İşçinin satış bonusunu hesablayan funksiya
 * Bu funksiya işçinin satış performansına əsasən bonus hesablayır
 */
function calculateSalesBonus($conn, $employee_id, $month) {
    try {
        // Satış məlumatlarını əldə edirik (bu cədvəl mövcud olmalıdır)
        $stmt = $conn->prepare("
            SELECT SUM(amount) as total_sales
            FROM sales
            WHERE employee_id = :employee_id
              AND DATE_FORMAT(date, '%Y-%m') = :month
        ");
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':month' => $month
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result || empty($result['total_sales'])) {
            return 0; // Satış yoxdursa, bonus yoxdur
        }
        
        $total_sales = (float)$result['total_sales'];
        
        // Satış bonusu hesablama:
        // 1000 AZN-dən aşağı satışlar üçün 1%
        // 1000-5000 AZN arası satışlar üçün 2%
        // 5000 AZN-dən yuxarı satışlar üçün 3%
        if ($total_sales < 1000) {
            return $total_sales * 0.01;
        } elseif ($total_sales < 5000) {
            return $total_sales * 0.02;
        } else {
            return $total_sales * 0.03;
        }
    } catch (PDOException $e) {
        // Cədvəl mövcud deyilsə, xəta vermədən 0 qaytarırıq
        if (strpos($e->getMessage(), "Table") !== false && strpos($e->getMessage(), "doesn't exist") !== false) {
            return 0;
        }
        error_log("Sales bonus calculation error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Uğur və xəta mesajları
 */
function displayMessages() {
    if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php
                echo htmlspecialchars($_SESSION['success_message']);
                unset($_SESSION['success_message']);
            ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
            ?>
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    <?php endif;
}

// POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Yanlış sorğu!';
        header('Location: salary.php');
        exit();
    }

    // Maaş ödənişləri
    if (isset($_POST['pay_salaries'])) {
        $month = isset($_POST['month']) ? sanitize_input($_POST['month']) : date('Y-m');
        processSalaryPayments($conn, $_POST, $month);
    }

    // İxrac
    if (isset($_POST['export_salaries'])) {
        exportSalaries($conn, $_POST);
    }

    // Manual borc
    if (isset($_POST['manual_carry_forward'])) {
        $month = isset($_POST['manual_month']) ? sanitize_input($_POST['manual_month']) : date('Y-m');
        handleManualCarryForward($conn, $_POST, $month);
    }
}

// GET ay seçimi
$month = isset($_GET['month']) ? sanitize_input($_GET['month']) : date('Y-m');

// Aktiv işçilər
$stmt = $conn->prepare("
    SELECT id, name, salary, max_vacation_days, start_date
    FROM employees
    WHERE is_active=1
    ORDER BY name ASC
");
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title>Maaş Hesablama və Çap (Yalnız Ödənilməmiş Borclar)</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
        <!-- Bootstrap 5 CSS -->
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- DataTables CSS (əgər cədvəllər də əlavə etmək istəsəniz) -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Özəl CSS -->
    <style>
        body {
            background: #f8f9fa;
        }
        .no-print {
            /* ekranda görünəcək, amma @media print-də gizlədiləcək */
        }
        .additional-reason { display: none; }
        .net-salary { font-weight: bold; }
        .debt-list {
            max-height: 150px;
            overflow-y: auto;
        }
        .table-sm {
            font-size: 0.85rem;
        }
        .calculation-details {
            background-color: #f8f9fa;
            padding: 8px;
            border-radius: 4px;
        }
        
        @media print {
            @page {
                margin: 15mm;
                size: A4 landscape;
            }
            .no-print, .export-section {
                display: none !important;
            }
            body {
                font-size: 11pt;
                background-color: white !important;
                color: black !important;
            }
            .container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .table {
                width: 100% !important;
                border-collapse: collapse !important;
            }
            .table-bordered td, 
            .table-bordered th {
                border: 1px solid #000 !important;
            }
            .table thead th {
                background-color: #f2f2f2 !important;
                color: #000 !important;
                font-weight: bold !important;
            }
            .debt-list {
                max-height: none !important;
                overflow: visible !important;
            }
            .table-sm td {
                padding: 2px !important;
            }
            .calculation-details {
                display: none !important;
            }
            h2 {
                font-size: 18pt !important;
                margin-bottom: 15px !important;
            }
            .navbar, footer {
                display: none !important;
            }
            .custom-checkbox {
                display: none !important;
            }
            .custom-control-label::after {
                display: none !important;
            }
            .custom-control-label::before {
                display: none !important;
            }
            .custom-control-label {
                margin-left: 0 !important;
                padding-left: 0 !important;
            }
        }
    </style>
</head>
<body>

<?php include 'includes/navbar.php'; ?>

<div class="container mt-4">

    <?php displayMessages(); ?>

    <h2 class="mb-4 text-center">Maaş Hesablama və Çap (Yalnız Ödənilməmiş Borclar)</h2>

    <!-- Çap üçün xüsusi başlıq -->
    <div class="d-none" id="printHeader">
        <h2 class="mb-4 text-center">Seçilmiş İşçilərin Maaş Hesabatı</h2>
        <p class="text-center mb-2">
            Hesabat tarixi: <?php echo date('d.m.Y'); ?><br>
            Dövr: <?php echo date('m.Y', strtotime($month)); ?>
        </p>
    </div>

    <!-- CHAP ET DÜYMƏSİ: no-print sinfi -->
    <div class="mb-3 text-right no-print">
        <button class="btn btn-info" onclick="window.print()">
            <i class="fas fa-print"></i> Çap Et
        </button>
        <button class="btn btn-primary ml-2" id="printSelectedBtn" disabled>
            <i class="fas fa-print"></i> Seçilmişləri Çap Et
        </button>
    </div>

    <!-- Ay seçimi formu: no-print sinfi -->
    <form method="GET" class="form-inline justify-content-center mb-4 no-print">
        <label for="month" class="mr-2">Ay seçin:</label>
        <input type="month" name="month" id="month" 
               class="form-control mr-2" 
               value="<?php echo htmlspecialchars($month); ?>" required>
        <button type="submit" class="btn btn-primary">Göstər</button>
    </form>

    <!-- Maaş Ödəniş Formu: no-print sinfi -->
    <form method="POST" action="salary.php">
        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
        <input type="hidden" name="month" value="<?php echo htmlspecialchars($month); ?>">

        <div class="form-group no-print">
            <label for="payment_date">Ödəniş Tarixi:</label>
            <input type="date" name="payment_date" id="payment_date" 
                   class="form-control"
                   value="<?php echo date('Y-m-d'); ?>" required>
        </div>

        <!-- Cədvəl -->
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="thead-dark">
                    <tr>
                        <th>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="selectAll">
                                <label class="custom-control-label" for="selectAll">İşçi</label>
                            </div>
                        </th>
                        <th>Aylıq Maaş</th>
                        <th>İstirahət Günü</th>
                        <th>İstirahətdən Tutulma</th>
                        <th>Ödənilməmiş Borclar</th>
                        <th>Borcun Cəmi</th>
                        <th>Cəmi Tutulma</th>
                        <th>Əlavə Ödəniş</th>
                        <th>Xalis Maaş</th>
                        <th>Manual Borc</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($employees as $emp):
                    $employee_id = (int)$emp['id'];
                    $start_date  = $month . '-01';
                    $end_date    = date('Y-m-t', strtotime($start_date));

                    $employee_start = new DateTime($emp['start_date']);
                    $month_start    = new DateTime($start_date);
                    $month_end      = new DateTime($end_date);

                    $effective_start = $employee_start > $month_start ? $employee_start : $month_start;
                    $days_employed   = (int)$month_end->format('d') - (int)$effective_start->format('d') + 1;
                    if ($days_employed < 0) $days_employed = 0;

                    $total_days_in_month = (int)$month_end->format('d');
                    $absence_count       = getAbsenceCount($conn, $employee_id, $month);

                    $unpaid_debts = getMonthlyUnpaidDebtsDetails($conn, $employee_id, $month);
                    $debt_sum     = getUnpaidDebtsSum($conn, $employee_id, $month);

                    $calc = calculateSalary(
                        $emp['salary'],
                        $absence_count,
                        $debt_sum,
                        0, 
                        (int)$emp['max_vacation_days'],
                        $days_employed,
                        $total_days_in_month
                    );
                ?>
                    <tr class="employee-row" data-employee-id="<?php echo $employee_id; ?>">
                        <td>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input employee-select" 
                                       id="select-employee-<?php echo $employee_id; ?>" 
                                       data-employee-id="<?php echo $employee_id; ?>">
                                <label class="custom-control-label" for="select-employee-<?php echo $employee_id; ?>">
                                    <?php echo htmlspecialchars($emp['name']); ?>
                                </label>
                            </div>
                            <a href="#" class="show-calculation-details badge badge-info ml-1" data-employee-id="<?php echo $employee_id; ?>">
                                <i class="fas fa-info-circle"></i>
                            </a>
                            <div id="calculation-details-<?php echo $employee_id; ?>" class="calculation-details mt-2" style="display:none;">
                                <small>
                                    <ul class="list-unstyled">
                                        <li>Günlük maaş: <strong><?php echo number_format($calc['daily_wage'], 2); ?> AZN</strong></li>
                                        <li>İşlədiyi gün: <strong><?php echo $days_employed; ?> gün</strong></li>
                                        <li>Proporsional maaş: <strong><?php echo number_format($calc['proportional_salary'], 2); ?> AZN</strong></li>
                                        <li>Maks. istirahət: <strong><?php echo $emp['max_vacation_days']; ?> gün</strong></li>
                                        <li>Artıq istirahət: <strong><?php echo $calc['exceed_days']; ?> gün</strong></li>
                                    </ul>
                                </small>
                            </div>
                        </td>
                        <td>
                            <?php echo number_format($emp['salary'], 2); ?>
                            <input type="hidden" name="employees[<?php echo $employee_id; ?>][gross_salary]" value="<?php echo $emp['salary']; ?>">
                        </td>
                        <td><?php echo $absence_count; ?></td>
                        <td>
                            <?php echo number_format($calc['absence_deduction'], 2); ?>
                            <input type="hidden" name="employees[<?php echo $employee_id; ?>][deduction]" value="<?php echo $calc['absence_deduction']; ?>">
                        </td>
                        <td style="text-align:left;">
                            <?php if (!empty($unpaid_debts)): ?>
                                <div class="debt-list">
                                    <table class="table table-sm table-borderless mb-0">
                                        <tbody>
                                        <?php foreach ($unpaid_debts as $db): ?>
                                            <tr>
                                                <td style="width: 80px"><?php echo date('d.m.Y', strtotime($db['date'])); ?></td>
                                                <td><?php echo htmlspecialchars($db['reason']); ?></td>
                                                <td class="text-right"><?php echo number_format($db['amount'], 2); ?> AZN</td>
                                                <td style="width: 40px">
                                                    <div class="custom-control custom-checkbox">
                                                        <input type="checkbox" class="custom-control-input debt-checkbox" 
                                                               id="debt-<?php echo $db['id']; ?>" 
                                                               data-employee-id="<?php echo $employee_id; ?>"
                                                               data-amount="<?php echo $db['amount']; ?>"
                                                               name="employees[<?php echo $employee_id; ?>][debts][<?php echo $db['id']; ?>]" 
                                                               value="1" checked>
                                                        <label class="custom-control-label" for="debt-<?php echo $db['id']; ?>"></label>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <em>Bu ay üçün ödənilməmiş borc yoxdur.</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span id="debt-sum-<?php echo $employee_id; ?>"><?php echo number_format($debt_sum, 2); ?></span>
                            <input type="hidden" id="debt-sum-input-<?php echo $employee_id; ?>" name="employees[<?php echo $employee_id; ?>][debt]" value="<?php echo $debt_sum; ?>">
                        </td>
                        <td><?php echo number_format($calc['total_deduction'], 2); ?></td>
                        <td>
                            <input type="number" step="0.01" class="form-control additional-payment"
                                   data-employee-id="<?php echo $employee_id; ?>"
                                   name="employees[<?php echo $employee_id; ?>][additional_payment]"
                                   value="0.00">
                            <select name="employees[<?php echo $employee_id; ?>][reason]" class="form-control mt-2 additional-reason" id="reason-<?php echo $employee_id; ?>">
                                <option value="">Səbəb seçin</option>
                                <option value="Maaş artımı">Maaş artımı</option>
                                <option value="Bonus">Bonus</option>
                                <option value="Mükafat">Mükafat</option>
                                <option value="Digər">Digər</option>
                            </select>
                        </td>
                        <td class="net-salary" id="net-salary-<?php echo $employee_id; ?>">
                            <?php echo number_format($calc['net_salary'], 2); ?>
                        </td>
                        <td>
                            <input type="number" step="0.01" class="form-control manual-carry-forward"
                                   data-employee-id="<?php echo $employee_id; ?>"
                                   name="employees[<?php echo $employee_id; ?>][manual_carry_forward_amount]"
                                   placeholder="0.00">
                        </td>
                        <input type="hidden" id="net-salary-input-<?php echo $employee_id; ?>"
                               name="employees[<?php echo $employee_id; ?>][net_salary]"
                               value="<?php echo $calc['net_salary']; ?>">
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="text-center mt-3">
            <button type="submit" name="pay_salaries" class="btn btn-success"
                    onclick="return confirm('Maaş ödənişlərini təsdiqləmək istəyirsinizmi?')">
                Maaşları Ödə
            </button>
        </div>
    </form>

    <!-- CSV & JSON ixrac bölməsi -->
    <div class="export-section no-print">
        <h4 class="text-center">Maaş Məlumatlarını İxrac Et (CSV/JSON)</h4>
        <form method="POST" action="salary.php" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <div class="form-group">
                <label><strong>İşçiləri Seçin:</strong></label><br>
                <?php foreach ($employees as $emp): ?>
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox"
                               name="employee_ids[]"
                               value="<?php echo htmlspecialchars($emp['id']); ?>"
                               id="emp-<?php echo $emp['id']; ?>">
                        <label class="form-check-label" for="emp-<?php echo $emp['id']; ?>">
                            <?php echo htmlspecialchars($emp['name']); ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="form-group">
                <label><strong>İxrac üçün sahələri seçin:</strong></label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="fields[]" value="id" id="fieldId" checked>
                    <label class="form-check-label" for="fieldId">ID</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="fields[]" value="name" id="fieldName" checked>
                    <label class="form-check-label" for="fieldName">Ad</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="fields[]" value="salary" id="fieldSalary" checked>
                    <label class="form-check-label" for="fieldSalary">Aylıq Maaş</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="fields[]" value="max_vacation_days" id="fieldVac" checked>
                    <label class="form-check-label" for="fieldVac">Maks İstirahət</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="fields[]" value="start_date" id="fieldStart" checked>
                    <label class="form-check-label" for="fieldStart">İşə Başlama Tarixi</label>
                </div>
            </div>
            <div class="form-group">
                <label><strong>Format:</strong></label><br>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="export_format" value="csv" id="formatCsv" checked>
                    <label class="form-check-label" for="formatCsv">CSV</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="export_format" value="json" id="formatJson">
                    <label class="form-check-label" for="formatJson">JSON</label>
                </div>
            </div>
            <div class="text-center">
                <button type="submit" name="export_salaries" class="btn btn-secondary">
                    İxrac Et
                </button>
            </div>
        </form>
    </div>

</div>

<!-- jQuery + Bootstrap JS -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<script>
$(document).ready(function(){
  // Əlavə ödəniş dəyişdikdə xalis maaşı yenilə
  $('.additional-payment').on('input', function(){
    updateNetSalary($(this).data('employee-id'));
  });

  // Manual borc dəyişdikdə xalis maaşı yenilə
  $('.manual-carry-forward').on('input', function(){
    updateNetSalary($(this).data('employee-id'));
  });

  // Borc checkbox dəyişdikdə borc cəmini və xalis maaşı yenilə
  $('.debt-checkbox').on('change', function(){
    var empId = $(this).data('employee-id');
    updateDebtSum(empId);
    updateNetSalary(empId);
  });

  // Başlanğıcda reason sahələrini gizlə
  $('.additional-reason').hide();
  
  // Maaş hesablama detallarını göstərmək üçün
  $('.show-calculation-details').on('click', function(e) {
    e.preventDefault();
    var empId = $(this).data('employee-id');
    $('#calculation-details-'+empId).toggle();
  });

  // Hamısını seç checkbox
  $('#selectAll').on('change', function() {
    var isChecked = $(this).prop('checked');
    $('.employee-select').prop('checked', isChecked);
    updatePrintButtonState();
  });

  // İşçi checkboxları dəyişdikdə
  $('.employee-select').on('change', function() {
    updatePrintButtonState();
    
    // Əgər bütün işçilər seçilmiş deyilsə, hamısını seç checkbox'u deaktiv et
    if (!$('.employee-select:checked').length == $('.employee-select').length) {
      $('#selectAll').prop('checked', false);
    }
    // Əgər bütün işçilər seçilibsə, hamısını seç checkbox'u aktiv et
    else if ($('.employee-select:checked').length == $('.employee-select').length) {
      $('#selectAll').prop('checked', true);
    }
  });

  // Seçilmişləri çap et düyməsi
  $('#printSelectedBtn').on('click', function() {
    printSelectedEmployees();
    return false;
  });

  // Çap düyməsinin aktivliyini yeniləyən funksiya
  function updatePrintButtonState() {
    var selectedCount = $('.employee-select:checked').length;
    $('#printSelectedBtn').prop('disabled', selectedCount === 0);
  }

  // Seçilmiş işçiləri çap edən funksiya
  function printSelectedEmployees() {
    // Seçilməmiş işçiləri gizlət
    $('.employee-row').each(function() {
      var empId = $(this).data('employee-id');
      if (!$('#select-employee-' + empId).prop('checked')) {
        $(this).addClass('d-none');
      }
    });
    
    // Normal başlığı gizlət və çap üçün xüsusi başlığı göstər
    $('h2.mb-4.text-center').first().addClass('d-none');
    $('#printHeader').removeClass('d-none');
    
    // Çap et düyməsini və seçim sahələrini gizlət
    $('.no-print').addClass('d-none');
    
    // Çap pəncərəsini aç
    window.print();
    
    // Çapdan sonra gizlədilmiş elementləri bərpa et
    setTimeout(function() {
      $('.employee-row').removeClass('d-none');
      $('.no-print').removeClass('d-none');
      $('h2.mb-4.text-center').first().removeClass('d-none');
      $('#printHeader').addClass('d-none');
    }, 1000);
  }

  // Borc cəmini yenilə
  function updateDebtSum(empId) {
    var totalDebt = 0;
    $('input[name^="employees['+empId+'][debts]"]:checked').each(function(){
      totalDebt += parseFloat($(this).data('amount')) || 0;
    });
    
    $('#debt-sum-'+empId).text(totalDebt.toFixed(2));
    $('#debt-sum-input-'+empId).val(totalDebt);
    
    return totalDebt;
  }

  // Xalis maaşı yenilə
  function updateNetSalary(empId) {
    var gross = parseFloat($('input[name="employees['+empId+'][gross_salary]"]').val()) || 0;
    var deduc = parseFloat($('input[name="employees['+empId+'][deduction]"]').val()) || 0;
    var debt  = parseFloat($('#debt-sum-input-'+empId).val()) || 0;
    var addPay = parseFloat($('input[name="employees['+empId+'][additional_payment]"]').val()) || 0;

    var net = (gross - deduc - debt + addPay).toFixed(2);

    $('#net-salary-'+empId).text(net);
    $('#net-salary-input-'+empId).val(net);

    // Əlavə ödəniş > 0 isə reason dropdown-u göstərək
    if (addPay > 0) {
      $('#reason-'+empId).show();
    } else {
      $('#reason-'+empId).hide().val('');
    }
    
    return net;
  }
});
</script>
</body>
</html>
