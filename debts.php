<?php
// debts.php
session_start();
require 'config.php';

// Display errors during development (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Check user authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// CSRF token generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Sanitize input data
 */
function sanitize_input($data) {
    return htmlspecialchars(stripslashes(trim($data)));
}

/**
 * Display success and error messages
 */
function displayMessages() {
    if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
                echo htmlspecialchars($_SESSION['success_message'], ENT_QUOTES, 'UTF-8');
                unset($_SESSION['success_message']); 
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
                echo htmlspecialchars($_SESSION['error_message'], ENT_QUOTES, 'UTF-8');
                unset($_SESSION['error_message']); 
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif;
}

/**
 * Fetch filtered and sorted debts data
 */
function fetchDebts($conn, $filters, $sort, $order, $limit, $offset) {
    $where_clauses = [];
    $params = [];

    // Search by employee name
    if (!empty($filters['search'])) {
        $where_clauses[] = "e.name LIKE :search";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    // Date range filter
    if (!empty($filters['from_date'])) {
        $where_clauses[] = "d.date >= :from_date";
        $params[':from_date'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where_clauses[] = "d.date <= :to_date";
        $params[':to_date'] = $filters['to_date'];
    }

    // Paid status filter
    if ($filters['is_paid'] !== '') {
        $where_clauses[] = "d.is_paid = :is_paid";
        $params[':is_paid'] = (int)$filters['is_paid'];
    }

    // Month filter
    if (!empty($filters['month'])) {
        $where_clauses[] = "DATE_FORMAT(d.date, '%Y-%m') = :month";
        $params[':month'] = $filters['month'];
    }

    $where_sql = '';
    if (count($where_clauses) > 0) {
        $where_sql = "WHERE " . implode(' AND ', $where_clauses);
    }

    // Allowed sorting columns
    $allowed_sorts = [
        'name' => 'e.name',
        'total_debt' => 'SUM(d.amount)',
        'paid_debt' => 'SUM(CASE WHEN d.is_paid = 1 THEN d.amount ELSE 0 END)',
        'remaining_debt' => '(SUM(d.amount) - SUM(CASE WHEN d.is_paid = 1 THEN d.amount ELSE 0 END))'
    ];

    $sort_column = isset($allowed_sorts[$sort]) ? $allowed_sorts[$sort] : 'e.name';
    $order = ($order === 'DESC') ? 'DESC' : 'ASC';

    $sql = "SELECT e.id, e.name,
                   SUM(d.amount) AS total_debt,
                   SUM(CASE WHEN d.is_paid = 1 THEN d.amount ELSE 0 END) AS paid_debt,
                   (SUM(d.amount) - SUM(CASE WHEN d.is_paid = 1 THEN d.amount ELSE 0 END)) AS remaining_debt
            FROM employees e
            JOIN debts d ON e.id = d.employee_id
            $where_sql
            GROUP BY e.id, e.name
            ORDER BY $sort_column $order
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => &$value) {
        $stmt->bindParam($key, $value);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Count total debts based on filters
 */
function countDebts($conn, $filters) {
    $where_clauses = [];
    $params = [];

    // Search by employee name
    if (!empty($filters['search'])) {
        $where_clauses[] = "e.name LIKE :search";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    // Date range filter
    if (!empty($filters['from_date'])) {
        $where_clauses[] = "d.date >= :from_date";
        $params[':from_date'] = $filters['from_date'];
    }
    if (!empty($filters['to_date'])) {
        $where_clauses[] = "d.date <= :to_date";
        $params[':to_date'] = $filters['to_date'];
    }

    // Paid status filter
    if ($filters['is_paid'] !== '') {
        $where_clauses[] = "d.is_paid = :is_paid";
        $params[':is_paid'] = (int)$filters['is_paid'];
    }

    // Month filter
    if (!empty($filters['month'])) {
        $where_clauses[] = "DATE_FORMAT(d.date, '%Y-%m') = :month";
        $params[':month'] = $filters['month'];
    }

    $where_sql = '';
    if (count($where_clauses) > 0) {
        $where_sql = "WHERE " . implode(' AND ', $where_clauses);
    }

    $sql = "SELECT COUNT(DISTINCT e.id) AS total
            FROM employees e
            JOIN debts d ON e.id = d.employee_id
            $where_sql";

    $stmt = $conn->prepare($sql);
    foreach ($params as $key => &$value) {
        $stmt->bindParam($key, $value);
    }
    $stmt->execute();

    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'] ?? 0;
}

/**
 * Fetch overall debt statistics for the charts
 */
function fetchDebtStats($conn, $filters) {
    $where_clauses = [];
    $params = [];

    // Apply same filters as main query
    if (!empty($filters['search'])) {
        $where_clauses[] = "e.name LIKE :search";
        $params[':search'] = '%' . $filters['search'] . '%';
    }

    if (!empty($filters['from_date'])) {
        $where_clauses[] = "d.date >= :from_date";
        $params[':from_date'] = $filters['from_date'];
    }

    if (!empty($filters['to_date'])) {
        $where_clauses[] = "d.date <= :to_date";
        $params[':to_date'] = $filters['to_date'];
    }

    if ($filters['is_paid'] !== '') {
        $where_clauses[] = "d.is_paid = :is_paid";
        $params[':is_paid'] = (int)$filters['is_paid'];
    }

    if (!empty($filters['month'])) {
        $where_clauses[] = "DATE_FORMAT(d.date, '%Y-%m') = :month";
        $params[':month'] = $filters['month'];
    }

    $where_sql = '';
    if (count($where_clauses) > 0) {
        $where_sql = "WHERE " . implode(' AND ', $where_clauses);
    }

    // Total Debts
    $sql_total = "SELECT SUM(d.amount) AS total_debt 
                  FROM debts d 
                  JOIN employees e ON e.id = d.employee_id 
                  $where_sql";
    $stmt_total = $conn->prepare($sql_total);
    foreach ($params as $key => &$value) {
        $stmt_total->bindParam($key, $value);
    }
    $stmt_total->execute();
    $total_debt = $stmt_total->fetch(PDO::FETCH_ASSOC)['total_debt'] ?? 0;

    // Total Paid
    $sql_paid = "SELECT SUM(d.amount) AS total_paid 
                 FROM debts d 
                 JOIN employees e ON e.id = d.employee_id 
                 $where_sql AND d.is_paid = 1";
    $stmt_paid = $conn->prepare($sql_paid);
    foreach ($params as $key => &$value) {
        $stmt_paid->bindParam($key, $value);
    }
    $stmt_paid->execute();
    $total_paid = $stmt_paid->fetch(PDO::FETCH_ASSOC)['total_paid'] ?? 0;

    // Total Remaining
    $total_remaining = $total_debt - $total_paid;

    // Debts per Month
    $sql_monthly = "SELECT DATE_FORMAT(d.date, '%Y-%m') AS month, SUM(d.amount) AS amount
                   FROM debts d
                   JOIN employees e ON e.id = d.employee_id
                   $where_sql
                   GROUP BY month
                   ORDER BY month ASC";
    $stmt_monthly = $conn->prepare($sql_monthly);
    foreach ($params as $key => &$value) {
        $stmt_monthly->bindParam($key, $value);
    }
    $stmt_monthly->execute();
    $monthly_debts = $stmt_monthly->fetchAll(PDO::FETCH_ASSOC);

    // Top 5 Employees with Highest Debts
    $sql_top = "SELECT e.name, SUM(d.amount) AS total_debt
                FROM debts d
                JOIN employees e ON e.id = d.employee_id
                $where_sql
                GROUP BY e.id, e.name
                ORDER BY total_debt DESC
                LIMIT 5";
    $stmt_top = $conn->prepare($sql_top);
    foreach ($params as $key => &$value) {
        $stmt_top->bindParam($key, $value);
    }
    $stmt_top->execute();
    $top_employees = $stmt_top->fetchAll(PDO::FETCH_ASSOC);

    return [
        'total_debt' => $total_debt,
        'total_paid' => $total_paid,
        'total_remaining' => $total_remaining,
        'monthly_debts' => $monthly_debts,
        'top_employees' => $top_employees
    ];
}

/**
 * Fetch detailed debts for a specific employee
 */
function fetchEmployeeDebts($conn, $employee_id) {
    $sql = "SELECT id, amount, date, reason, is_paid
            FROM debts
            WHERE employee_id = :employee_id
            ORDER BY date DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Export debts to CSV
 */
function exportToCSV($debts) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=debts_export_' . date('Ymd_His') . '.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'İşçi Adı', 'Ümumi Borc (AZN)', 'Ödənilmiş Borc (AZN)', 'Qalan Borc (AZN)']);

    foreach ($debts as $debt) {
        fputcsv($output, [
            $debt['id'],
            htmlspecialchars($debt['name'], ENT_QUOTES, 'UTF-8'),
            number_format($debt['total_debt'], 2),
            number_format($debt['paid_debt'], 2),
            number_format($debt['remaining_debt'], 2)
        ]);
    }

    fclose($output);
    exit();
}

/**
 * Handle POST actions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'CSRF token etibarsızdır.';
        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }

    // Toggle Paid Status
    if (isset($_POST['toggle_paid']) && isset($_POST['debt_id'])) {
        $debt_id = (int)$_POST['debt_id'];

        try {
            // Fetch current debt status and employee
            $stmt = $conn->prepare("SELECT is_paid, employee_id FROM debts WHERE id = :debt_id");
            $stmt->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
            $stmt->execute();
            $debt = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($debt) {
                $new_status = $debt['is_paid'] ? 0 : 1;
                $stmt_update = $conn->prepare("UPDATE debts SET is_paid = :new_status WHERE id = :debt_id");
                $stmt_update->bindParam(':new_status', $new_status, PDO::PARAM_INT);
                $stmt_update->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
                $stmt_update->execute();

                // Fetch employee details
                $stmt_emp = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
                $stmt_emp->bindParam(':employee_id', $debt['employee_id'], PDO::PARAM_INT);
                $stmt_emp->execute();
                $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

                $employee_name = $employee['name'] ?? 'İşçi';

                // Prepare WhatsApp message
                if ($new_status) {
                    $message = "Salam, borcunuz ödənilib olaraq işarələndi.\nİşçi: {$employee_name}\nBorc ID: {$debt_id}.";
                } else {
                    $message = "Salam, borcunuz ödənilməyib olaraq işarələndi.\nİşçi: {$employee_name}\nBorc ID: {$debt_id}.";
                }

                // Send WhatsApp message
                $result = sendWhatsAppMessage(
                    $whatsapp_config['owner_phone_number'],
                    $message
                );
                if ($result['success']) {
                    $_SESSION['success_message'] = 'Borc statusu uğurla dəyişdirildi və mesaj göndərildi.';
                } else {
                    $_SESSION['error_message'] = 'Borc statusu dəyişdirildi, amma mesaj göndərilmədi: ' . $result['error'];
                }
            } else {
                $_SESSION['error_message'] = 'Borc tapılmadı.';
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Xəta: ' . $e->getMessage();
        }

        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }

    // Delete Debt
    if (isset($_POST['delete_debt']) && isset($_POST['debt_id'])) {
        $debt_id = (int)$_POST['debt_id'];

        try {
            // Fetch debt details before deletion
            $stmt = $conn->prepare("SELECT employee_id, amount, reason FROM debts WHERE id = :debt_id");
            $stmt->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
            $stmt->execute();
            $debt = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($debt) {
                // Delete the debt
                $stmt_del = $conn->prepare("DELETE FROM debts WHERE id = :debt_id");
                $stmt_del->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
                $stmt_del->execute();

                // Fetch employee details
                $stmt_emp = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
                $stmt_emp->bindParam(':employee_id', $debt['employee_id'], PDO::PARAM_INT);
                $stmt_emp->execute();
                $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

                $employee_name = $employee['name'] ?? 'İşçi';
                $formatted_amount = number_format($debt['amount'], 2);
                $reason = sanitize_input($debt['reason']);

                // Prepare WhatsApp message
                $message = "Salam, borcunuz silindi.\nİşçi: {$employee_name}\nMəbləğ: {$formatted_amount} AZN\nSəbəb: {$reason}\nBorc ID: {$debt_id}.";

                // Send WhatsApp message
                $result = sendWhatsAppMessage(
                    $whatsapp_config['owner_phone_number'],
                    $message
                );

                if ($result['success']) {
                    $_SESSION['success_message'] = 'Borc uğurla silindi və mesaj göndərildi.';
                } else {
                    $_SESSION['error_message'] = 'Borc silindi, amma mesaj göndərilmədi: ' . $result['error'];
                }
            } else {
                $_SESSION['error_message'] = 'Borc tapılmadı.';
            }
        } catch (PDOException $e) {
            $_SESSION['error_message'] = 'Xəta: ' . $e->getMessage();
        }

        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }

    // Add New Debt (Single Entry)
    if (isset($_POST['add_debt'])) {
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
        $date = isset($_POST['date']) ? sanitize_input($_POST['date']) : '';
        $reason = isset($_POST['reason']) ? sanitize_input($_POST['reason']) : '';
        $month = isset($_POST['month']) ? sanitize_input($_POST['month']) : '';

        if ($employee_id > 0 && $amount > 0 && !empty($date) && !empty($reason) && !empty($month)) {
            try {
                // Insert the new debt
                $stmt = $conn->prepare("INSERT INTO debts (employee_id, amount, date, reason, is_paid, month) 
                                        VALUES (:employee_id, :amount, :date, :reason, 0, :month)");
                $stmt->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
                $stmt->bindParam(':amount', $amount);
                $stmt->bindParam(':date', $date);
                $stmt->bindParam(':reason', $reason);
                $stmt->bindParam(':month', $month);
                $stmt->execute();

                $new_debt_id = $conn->lastInsertId();

                // Fetch employee details
                $stmt_emp = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
                $stmt_emp->bindParam(':employee_id', $employee_id, PDO::PARAM_INT);
                $stmt_emp->execute();
                $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

                $employee_name = $employee['name'] ?? 'İşçi';
                $formatted_amount = number_format($amount, 2);

                // Prepare WhatsApp message
                $message = "Salam, yeni borc əlavə edildi.\nİşçi: {$employee_name}\nMəbləğ: {$formatted_amount} AZN\nSəbəb: {$reason}\nBorc ID: {$new_debt_id}.";

                // Send WhatsApp message
                $result = sendWhatsAppMessage(
                    $whatsapp_config['owner_phone_number'],
                    $message
                );
                if ($result['success']) {
                    $_SESSION['success_message'] = 'Borc uğurla əlavə edildi və mesaj göndərildi.';
                } else {
                    $_SESSION['error_message'] = 'Borc əlavə edildi, amma mesaj göndərilmədi: ' . $result['error'];
                }
            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Borc əlavə edilməsində xəta baş verdi: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = 'Borc məlumatları tam doldurulmalıdır.';
        }

        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }

    // <!-- BULK ADD DEBTS START -->
    // Add Multiple Debts (Bulk)
    if (isset($_POST['add_bulk_debts'])) {
        // Eyni tarix və ay bütün sətirlərə şamil ediləcək
        $bulk_date = isset($_POST['bulk_date']) ? sanitize_input($_POST['bulk_date']) : '';
        $bulk_month = isset($_POST['bulk_month']) ? sanitize_input($_POST['bulk_month']) : '';

        // Bu input-lar array şəklində gəlir: employee_id[], amount[], reason[]
        $employee_ids = isset($_POST['employee_id_bulk']) ? $_POST['employee_id_bulk'] : [];
        $amounts = isset($_POST['amount_bulk']) ? $_POST['amount_bulk'] : [];
        $reasons = isset($_POST['reason_bulk']) ? $_POST['reason_bulk'] : [];

        if (empty($bulk_date) || empty($bulk_month)) {
            $_SESSION['error_message'] = 'Tarix və ay boş ola bilməz.';
            header('Location: debts.php?' . http_build_query($_GET));
            exit();
        }

        // Sayı eyni olan massivlər
        // Hər bir indeks üçün bir borc qeydi əlavə ediləcək
        $insert_count = 0;  // Uğurla əlavə edilən borcların sayını saxlayacağıq

        foreach ($employee_ids as $index => $emp_id) {
            $emp_id = (int)$emp_id; // Tip dönüşümü
            $amt = isset($amounts[$index]) ? (float)$amounts[$index] : 0;
            $rsn = isset($reasons[$index]) ? sanitize_input($reasons[$index]) : '';

            // Əgər dəyər boşdursa, növbəti sətrə keçək
            if ($emp_id <= 0 || $amt <= 0 || empty($rsn)) {
                continue; 
            }

            try {
                // Insert the new debt
                $stmt = $conn->prepare("INSERT INTO debts (employee_id, amount, date, reason, is_paid, month) 
                                        VALUES (:employee_id, :amount, :date, :reason, 0, :month)");
                $stmt->bindParam(':employee_id', $emp_id, PDO::PARAM_INT);
                $stmt->bindParam(':amount', $amt);
                $stmt->bindParam(':date', $bulk_date);
                $stmt->bindParam(':reason', $rsn);
                $stmt->bindParam(':month', $bulk_month);
                $stmt->execute();

                $new_debt_id = $conn->lastInsertId();
                $insert_count++;

                // Fetch employee details
                $stmt_emp = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
                $stmt_emp->bindParam(':employee_id', $emp_id, PDO::PARAM_INT);
                $stmt_emp->execute();
                $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

                $employee_name = $employee['name'] ?? 'İşçi';
                $formatted_amount = number_format($amt, 2);

                // Prepare WhatsApp message
                $message = "Salam, yeni borc əlavə edildi.\nİşçi: {$employee_name}\nMəbləğ: {$formatted_amount} AZN\nSəbəb: {$rsn}\nBorc ID: {$new_debt_id}.";

                // Send WhatsApp message
                $result = sendWhatsAppMessage(
                    $whatsapp_config['owner_phone_number'],
                    $message
                );
                
                // WhatsApp uğursuz olsa da, əlavə olunmuş say silinmir.
                if (!$result['success']) {
                    $_SESSION['error_message'] = 'Bəzi borclar əlavə edildi, amma mesaj göndərilmədi: ' . $result['error'];
                }

            } catch (PDOException $e) {
                $_SESSION['error_message'] = 'Borc əlavə edilməsində xəta baş verdi: ' . $e->getMessage();
                header('Location: debts.php?' . http_build_query($_GET));
                exit();
            }
        }

        if ($insert_count > 0) {
            $_SESSION['success_message'] = $insert_count . ' ədəd borc uğurla əlavə edildi.';
        } else {
            $_SESSION['error_message'] = 'Toplu borc əlavə etmə zamanı heç bir borc daxil edilmədi. (Məlumatlar tam doldurulmayıb)';
        }

        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }
    // <!-- BULK ADD DEBTS END -->

    // Provide Specific Payment (for full or partial payments)
    if (isset($_POST['provide_payment']) && isset($_POST['debt_id']) && isset($_POST['payment_amount'])) {
        $debt_id = (int)$_POST['debt_id'];
        $payment_amount = (float)$_POST['payment_amount'];

        if ($payment_amount > 0) {
            try {
                // Start transaction
                $conn->beginTransaction();

                // Fetch current debt details for the specific debt record
                $stmt = $conn->prepare("SELECT employee_id, amount, is_paid FROM debts WHERE id = :debt_id FOR UPDATE");
                $stmt->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
                $stmt->execute();
                $debt = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($debt) {
                    if ($debt['is_paid']) {
                        $_SESSION['error_message'] = 'Bu borc artıq ödənilib.';
                    } elseif ($payment_amount > $debt['amount']) {
                        $_SESSION['error_message'] = 'Ödəniləcək məbləğ borcun ümumi məbləğindən çox ola bilməz.';
                    } else {
                        // Update debt amount
                        $new_amount = $debt['amount'] - $payment_amount;
                        $new_status = $new_amount == 0 ? 1 : 0;

                        $stmt_update = $conn->prepare("UPDATE debts SET amount = :new_amount, is_paid = :new_status WHERE id = :debt_id");
                        $stmt_update->bindParam(':new_amount', $new_amount);
                        $stmt_update->bindParam(':new_status', $new_status, PDO::PARAM_INT);
                        $stmt_update->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
                        $stmt_update->execute();

                        // If debt is fully paid, set payment_date
                        if ($new_status) {
                            $payment_date = date('Y-m-d');
                            $stmt_date = $conn->prepare("UPDATE debts SET payment_date = :payment_date WHERE id = :debt_id");
                            $stmt_date->bindParam(':payment_date', $payment_date);
                            $stmt_date->bindParam(':debt_id', $debt_id, PDO::PARAM_INT);
                            $stmt_date->execute();
                        }

                        // Commit transaction
                        $conn->commit();

                        // Fetch employee details
                        $stmt_emp = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
                        $stmt_emp->bindParam(':employee_id', $debt['employee_id'], PDO::PARAM_INT);
                        $stmt_emp->execute();
                        $employee = $stmt_emp->fetch(PDO::FETCH_ASSOC);

                        $employee_name = $employee['name'] ?? 'İşçi';

                        // Prepare WhatsApp message
                        $message = "Salam, borcunuzdan {$payment_amount} AZN ödənildi.\nİşçi: {$employee_name}\nBorc ID: {$debt_id}.\nQalan Borc: " . number_format($new_amount, 2) . " AZN.";

                        // Send WhatsApp message
                        $result = sendWhatsAppMessage(
                            $whatsapp_config['owner_phone_number'],
                            $message
                        );

                        if ($result['success']) {
                            $_SESSION['success_message'] = 'Ödəniş uğurla həyata keçirildi və mesaj göndərildi.';
                        } else {
                            $_SESSION['error_message'] = 'Ödəniş həyata keçirildi, amma mesaj göndərilmədi: ' . $result['error'];
                        }
                    }
                } else {
                    $_SESSION['error_message'] = 'Borc tapılmadı.';
                }

                // Rollback if there was an error message set
                if (isset($_SESSION['error_message'])) {
                    $conn->rollBack();
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $_SESSION['error_message'] = 'Xəta: ' . $e->getMessage();
            }
        } else {
            $_SESSION['error_message'] = 'Ödəniləcək məbləğ müsbət olmalıdır.';
        }

        header('Location: debts.php?' . http_build_query($_GET));
        exit();
    }

    // Export CSV
    if (isset($_POST['export_csv'])) {
        // Fetch filtered debts
        $filters = [
            'search' => isset($_POST['search']) ? sanitize_input($_POST['search']) : '',
            'from_date' => isset($_POST['from_date']) ? sanitize_input($_POST['from_date']) : '',
            'to_date' => isset($_POST['to_date']) ? sanitize_input($_POST['to_date']) : '',
            'is_paid' => isset($_POST['is_paid']) ? sanitize_input($_POST['is_paid']) : '',
            'month' => isset($_POST['month']) ? sanitize_input($_POST['month']) : ''
        ];

        // For exporting all matching debts, set limit and offset appropriately
        $total = countDebts($conn, $filters);
        $page_size = $total; // Export all
        $offset = 0;

        $debts = fetchDebts($conn, $filters, 'name', 'ASC', $page_size, $offset);

        // Export to CSV
        exportToCSV($debts);
    }
}

/**
 * Fetch filters from GET parameters
 */
$filters = [
    'search' => isset($_GET['search']) ? sanitize_input($_GET['search']) : '',
    'from_date' => isset($_GET['from_date']) ? sanitize_input($_GET['from_date']) : '',
    'to_date' => isset($_GET['to_date']) ? sanitize_input($_GET['to_date']) : '',
    'is_paid' => isset($_GET['is_paid']) ? sanitize_input($_GET['is_paid']) : '',
    'month' => isset($_GET['month']) ? sanitize_input($_GET['month']) : ''
];

/**
 * Pagination parameters
 */
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$page_size = isset($_GET['page_size']) && is_numeric($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
$page_size = ($page_size > 0) ? $page_size : 20;
$offset = ($page - 1) * $page_size;

/**
 * Sorting parameters
 */
$sort = isset($_GET['sort']) ? sanitize_input($_GET['sort']) : 'name';
$order = isset($_GET['order']) ? strtoupper(sanitize_input($_GET['order'])) : 'ASC';
$order = ($order === 'DESC') ? 'DESC' : 'ASC';

/**
 * Fetch debts and total count
 */
$debts = fetchDebts($conn, $filters, $sort, $order, $page_size, $offset);
$total_debts = countDebts($conn, $filters);
$total_pages = ceil($total_debts / $page_size);

/**
 * Fetch overall debt statistics
 */
$stats = fetchDebtStats($conn, $filters);
$overall_total_debt = $stats['total_debt'] ?? 0;
$overall_total_paid = $stats['total_paid'] ?? 0;
$overall_total_remaining = $stats['total_remaining'] ?? 0;
$monthly_debts = $stats['monthly_debts'] ?? [];
$top_employees = $stats['top_employees'] ?? [];

/**
 * Fetch active employees for the "Add Debt" form and "Bulk Add"
 */
try {
    $stmt_employees = $conn->prepare("SELECT id, name FROM employees WHERE is_active = 1 ORDER BY name ASC");
    $stmt_employees->execute();
    $employees_list = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $_SESSION['error_message'] = 'Xəta: ' . $e->getMessage();
    header('Location: debts.php');
    exit();
}

/**
 * Function to generate sorting URLs
 */
function generateSortURL($column, $current_sort, $current_order, $filters, $page_size) {
    $order = 'ASC';
    if ($current_sort === $column && $current_order === 'ASC') {
        $order = 'DESC';
    }

    $query = http_build_query(array_merge($filters, [
        'sort' => $column,
        'order' => $order,
        'page' => 1, // Reset to first page on sort
        'page_size' => $page_size
    ]));

    return "debts.php?$query";
}

/**
 * Function to generate pagination URLs
 */
function generatePageURL($page, $filters, $sort, $order, $page_size) {
    $query = http_build_query(array_merge($filters, [
        'page' => $page,
        'sort' => $sort,
        'order' => $order,
        'page_size' => $page_size
    ]));

    return "debts.php?$query";
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title>Borclar - İdarəetmə Paneli</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #4361ee;
            --secondary-color: #3f37c9;
            --success-color: #4cc9f0;
            --warning-color: #f72585;
            --info-color: #4895ef;
            --danger-color: #e63946;
            --light-color: #f8f9fa;
            --dark-color: #212529;
            --border-radius: 12px;
            --box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            --transition: all 0.3s ease;
        }
        
        body {
            background-color: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }
        
        .container-fluid {
            padding: 0 30px;
        }
        
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-weight: 600;
            padding: 16px 20px;
        }
        
        .card-body {
            padding: 20px;
        }
        
        .action-btn {
            margin: 0 4px;
            border-radius: 50px;
            padding: 6px 12px;
            font-size: 0.85rem;
            transition: var(--transition);
        }
        
        .action-btn:hover {
            transform: scale(1.05);
        }
        
        /* Dashboard Cards */
        .dashboard-card {
            border: none;
            border-radius: var(--border-radius);
            color: #fff;
            padding: 24px;
            margin-bottom: 24px;
            text-align: center;
            box-shadow: var(--box-shadow);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .dashboard-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0));
            z-index: -1;
            border-radius: var(--border-radius);
        }
        
        .dashboard-card h3 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.1);
        }
        
        .dashboard-card p {
            font-size: 1.1rem;
            margin: 0;
            opacity: 0.9;
        }
        
        .dashboard-card i {
            position: absolute;
            bottom: 15px;
            right: 15px;
            font-size: 3rem;
            opacity: 0.2;
        }
        
        #debtsDistributionChart {
            max-width: 400px;
            max-height: 400px;
            margin: 0 auto;
        }
        
        /* Employee Cards */
        .employee-card {
            cursor: pointer;
            position: relative;
            border-radius: var(--border-radius);
            padding: 24px;
            color: #fff;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: 2px solid transparent;
            height: 100%;
        }
        
        .employee-card:hover {
            transform: scale(1.03);
            box-shadow: 0 15px 25px rgba(0, 0, 0, 0.2);
        }
        
        .employee-card.remaining {
            background: linear-gradient(135deg, #ff9a9e, #fad0c4);
        }
        
        .employee-card.partial {
            background: linear-gradient(135deg, #a18cd1, #fbc2eb);
        }
        
        .employee-card.paid {
            background: linear-gradient(135deg, #84fab0, #8fd3f4);
        }
        
        .employee-card .card-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            padding-bottom: 10px;
        }
        
        .employee-card .card-text {
            margin-bottom: 12px;
            font-size: 1.05rem;
        }
        
        .employee-card .badge-status {
            position: absolute;
            top: 15px;
            right: 15px;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.85rem;
            font-weight: bold;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .employee-card .card-footer {
            background: rgba(255, 255, 255, 0.15);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding: 12px;
            text-align: center;
            font-size: 0.95rem;
            font-weight: bold;
            color: #fff;
            border-radius: 0 0 var(--border-radius) var(--border-radius);
            transition: background 0.3s ease;
        }
        
        .employee-card .card-footer:hover {
            background: rgba(0, 0, 0, 0.2);
        }
        
        /* Form Controls */
        .form-control, .form-select {
            border-radius: 8px;
            padding: 10px 15px;
            border: 1px solid #e0e0e0;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.03);
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.25rem rgba(67, 97, 238, 0.15);
        }
        
        /* Buttons */
        .btn {
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 500;
            transition: var(--transition);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-color);
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-success:hover {
            background-color: #3db8df;
            border-color: #3db8df;
            transform: translateY(-2px);
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-info {
            background-color: var(--info-color);
            border-color: var(--info-color);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        /* Badges */
        .badge {
            padding: 6px 10px;
            border-radius: 6px;
            font-weight: 600;
        }
        
        /* Alerts */
        .alert {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: var(--box-shadow);
        }
        
        /* Tables */
        .table {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }
        
        .table thead th {
            background-color: #f8f9fa;
            border-bottom: 2px solid #e9ecef;
            font-weight: 600;
            color: #495057;
        }
        
        .table-hover tbody tr:hover {
            background-color: rgba(67, 97, 238, 0.05);
        }
        
        /* Pagination */
        .pagination {
            margin-top: 20px;
            justify-content: center;
        }
        
        .page-item .page-link {
            border-radius: 8px;
            margin: 0 3px;
            color: var(--primary-color);
            border: 1px solid #dee2e6;
            transition: var(--transition);
        }
        
        .page-item.active .page-link {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .page-item .page-link:hover {
            background-color: #e9ecef;
            transform: translateY(-2px);
        }
        
        /* Modal */
        .modal-content {
            border-radius: var(--border-radius);
            border: none;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1);
        }
        
        .modal-header {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            background-color: #f8f9fa;
        }
        
        .modal-footer {
            border-top: 1px solid rgba(0, 0, 0, 0.05);
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .container-fluid {
                padding: 0 15px;
            }
            
            .dashboard-card {
                padding: 15px;
            }
            
            .dashboard-card h3 {
                font-size: 1.8rem;
            }
            
            .employee-card {
                padding: 15px;
                font-size: 0.9rem;
            }
            
            .employee-card .badge-status {
                top: 10px;
                right: 10px;
                padding: 4px 8px;
                font-size: 0.7rem;
            }
            
            .employee-card .card-footer {
                font-size: 0.8rem;
                padding: 8px;
            }
            
            .btn {
                padding: 6px 12px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container-fluid mt-4">
        <!-- Page Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="mb-0"><i class="fas fa-money-bill-wave me-2 text-primary"></i>Borclar İdarəetmə Paneli</h2>
                            <p class="text-muted mb-0">İşçilərin borc məlumatlarını idarə edin və izləyin</p>
                        </div>
                        <div>
                            <a href="index.php" class="btn btn-outline-secondary me-2">
                                <i class="fas fa-home me-1"></i> Ana Səhifə
                            </a>
                            <a href="employees.php" class="btn btn-outline-primary">
                                <i class="fas fa-users me-1"></i> İşçilər
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Display Messages -->
        <?php displayMessages(); ?>

        <!-- Dashboard Statistics Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="dashboard-card bg-primary">
                    <h3><?php echo number_format($overall_total_debt, 2); ?> AZN</h3>
                    <p>Ümumi Borc</p>
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-card bg-success">
                    <h3><?php echo number_format($overall_total_paid, 2); ?> AZN</h3>
                    <p>Ödənilmiş Borc</p>
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-card bg-warning text-dark">
                    <h3><?php echo number_format($overall_total_remaining, 2); ?> AZN</h3>
                    <p>Qalan Borc</p>
                    <i class="fas fa-exclamation-circle"></i>
                </div>
            </div>
            <div class="col-md-3">
                <div class="dashboard-card bg-danger">
                    <h3><?php echo count($top_employees); ?> İşçi</h3>
                    <p>Ən Çox Borc Alan İşçilər</p>
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>

        <!-- Additional Statistics -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Zamanla Borcların Trend Qrafiki
                    </div>
                    <div class="card-body">
                        <canvas id="debtsTrendChart"></canvas>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        Ən Çox Borc Alan 5 İşçi
                    </div>
                    <div class="card-body">
                        <canvas id="topEmployeesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter and Search Form -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-filter me-2"></i>Filtr və Axtarış</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCollapse" aria-expanded="true" aria-controls="filterCollapse">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="collapse show" id="filterCollapse">
                <div class="card-body">
                    <form method="GET" action="debts.php" class="row g-3">
                        <div class="col-md-4">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" name="search" class="form-control" placeholder="İşçi adı ilə axtar" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" name="from_date" class="form-control" placeholder="Başlanğıc Tarix" value="<?php echo htmlspecialchars($filters['from_date'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                                <input type="date" name="to_date" class="form-control" placeholder="Bitmə Tarix" value="<?php echo htmlspecialchars($filters['to_date'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-money-bill"></i></span>
                                <select name="is_paid" class="form-select">
                                    <option value="" <?php if($filters['is_paid'] === '') echo 'selected'; ?>>Hamısı</option>
                                    <option value="0" <?php if($filters['is_paid'] === '0') echo 'selected'; ?>>Ödənilməyib</option>
                                    <option value="1" <?php if($filters['is_paid'] === '1') echo 'selected'; ?>>Ödənilib</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-calendar-day"></i></span>
                                <input type="month" name="month" class="form-control" value="<?php echo htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-list-ol"></i></span>
                                <select name="page_size" class="form-select">
                                    <option value="10" <?php if($page_size === 10) echo 'selected'; ?>>10 sətir</option>
                                    <option value="20" <?php if($page_size === 20) echo 'selected'; ?>>20 sətir</option>
                                    <option value="50" <?php if($page_size === 50) echo 'selected'; ?>>50 sətir</option>
                                    <option value="100" <?php if($page_size === 100) echo 'selected'; ?>>100 sətir</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i> Axtar</button>
                                <a href="debts.php" class="btn btn-secondary"><i class="fas fa-redo me-1"></i> Sıfırla</a>
                                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#helpModal">
                                    <i class="fas fa-question-circle me-1"></i> Kömək
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Sorting and Actions -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-cogs me-2"></i>Əməliyyatlar</span>
                <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#actionsCollapse" aria-expanded="true" aria-controls="actionsCollapse">
                    <i class="fas fa-chevron-down"></i>
                </button>
            </div>
            <div class="collapse show" id="actionsCollapse">
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="d-flex flex-wrap gap-2">
                                <a href="#addDebtModal" class="btn btn-success" data-bs-toggle="modal">
                                    <i class="fas fa-plus me-1"></i> Yeni Borc Əlavə Et
                                </a>
                                <a href="#bulkAddDebtModal" class="btn btn-dark" data-bs-toggle="modal">
                                    <i class="fas fa-layer-group me-1"></i> Toplu Borc Əlavə Et
                                </a>
                                <button type="button" class="btn btn-info" data-bs-toggle="modal" data-bs-target="#helpModal">
                                    <i class="fas fa-question-circle me-1"></i> Kömək
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 text-md-end mt-3 mt-md-0">
                            <div class="d-flex flex-wrap gap-2 justify-content-md-end">
                                <form method="POST" action="debts.php" class="d-inline">
                                    <input type="hidden" name="export_csv" value="1">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <!-- Preserve current filters in export -->
                                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($filters['search'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="from_date" value="<?php echo htmlspecialchars($filters['from_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="to_date" value="<?php echo htmlspecialchars($filters['to_date'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="is_paid" value="<?php echo htmlspecialchars($filters['is_paid'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="month" value="<?php echo htmlspecialchars($filters['month'], ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="page_size" value="<?php echo htmlspecialchars($page_size, ENT_QUOTES, 'UTF-8'); ?>">
                                    <button type="submit" class="btn btn-info">
                                        <i class="fas fa-file-csv me-1"></i> CSV-ə İxrac Et
                                    </button>
                                </form>
                                <div class="dropdown">
                                    <button class="btn btn-outline-secondary dropdown-toggle" type="button" id="sortDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                        <i class="fas fa-sort me-1"></i> Sırala
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="sortDropdown">
                                        <li><a class="dropdown-item <?php if($sort === 'name' && $order === 'ASC') echo 'active'; ?>" href="<?php echo generateSortURL('name', $sort, $order, $filters, $page_size); ?>">Ad (A-Z)</a></li>
                                        <li><a class="dropdown-item <?php if($sort === 'name' && $order === 'DESC') echo 'active'; ?>" href="<?php echo generateSortURL('name', $sort, $order, $filters, $page_size); ?>">Ad (Z-A)</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item <?php if($sort === 'total_debt' && $order === 'DESC') echo 'active'; ?>" href="<?php echo generateSortURL('total_debt', $sort, $order, $filters, $page_size); ?>">Ümumi Borc (Çoxdan Aza)</a></li>
                                        <li><a class="dropdown-item <?php if($sort === 'total_debt' && $order === 'ASC') echo 'active'; ?>" href="<?php echo generateSortURL('total_debt', $sort, $order, $filters, $page_size); ?>">Ümumi Borc (Azdan Çoxa)</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><a class="dropdown-item <?php if($sort === 'remaining_debt' && $order === 'DESC') echo 'active'; ?>" href="<?php echo generateSortURL('remaining_debt', $sort, $order, $filters, $page_size); ?>">Qalan Borc (Çoxdan Aza)</a></li>
                                        <li><a class="dropdown-item <?php if($sort === 'remaining_debt' && $order === 'ASC') echo 'active'; ?>" href="<?php echo generateSortURL('remaining_debt', $sort, $order, $filters, $page_size); ?>">Qalan Borc (Azdan Çoxa)</a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Debt Statistics Chart -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        Borcların Paylanması
                    </div>
                    <div class="card-body">
                         <canvas id="debtsDistributionChart" width="300" height="300"></canvas>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employees List in Card Layout -->
        <div class="row">
            <?php if (count($debts) > 0): ?>
                <?php foreach ($debts as $index => $debt): 
                    // Determine card class based on debt status
                    $cardClass = 'employee-card';
                    if ($debt['remaining_debt'] <= 0) {
                        $cardClass .= ' paid';
                        $statusBadge = '<span class="badge-status bg-success">Tam Ödənilib</span>';
                    } elseif ($debt['paid_debt'] > 0) {
                        $cardClass .= ' partial';
                        $statusBadge = '<span class="badge-status bg-info">Qismən Ödənilib</span>';
                    } else {
                        $cardClass .= ' remaining';
                        $statusBadge = '<span class="badge-status bg-warning">Ödənilməyib</span>';
                    }
                    
                    // Calculate payment percentage
                    $paymentPercentage = 0;
                    if ($debt['total_debt'] > 0) {
                        $paymentPercentage = ($debt['paid_debt'] / $debt['total_debt']) * 100;
                    }
                ?>
                    <div class="col-md-4 mb-4">
                        <div class="<?php echo $cardClass; ?>" data-bs-toggle="modal" data-bs-target="#detailDebtModal<?php echo $debt['id']; ?>">
                            <?php echo $statusBadge; ?>
                            <div class="card-body">
                                <h5 class="card-title">
                                    <i class="fas fa-user me-2"></i>
                                    <?php echo htmlspecialchars($debt['name'], ENT_QUOTES, 'UTF-8'); ?>
                                </h5>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-money-bill-wave me-2"></i>Ümumi:</span>
                                    <strong><?php echo number_format($debt['total_debt'], 2); ?> AZN</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span><i class="fas fa-check-circle me-2"></i>Ödənilib:</span>
                                    <strong><?php echo number_format($debt['paid_debt'], 2); ?> AZN</strong>
                                </div>
                                <div class="d-flex justify-content-between mb-3">
                                    <span><i class="fas fa-exclamation-circle me-2"></i>Qalıq:</span>
                                    <strong><?php echo number_format($debt['remaining_debt'], 2); ?> AZN</strong>
                                </div>
                                
                                <!-- Progress bar for payment status -->
                                <div class="progress mb-3" style="height: 10px;">
                                    <div class="progress-bar bg-light" role="progressbar" style="width: <?php echo $paymentPercentage; ?>%;" 
                                        aria-valuenow="<?php echo $paymentPercentage; ?>" aria-valuemin="0" aria-valuemax="100">
                                    </div>
                                </div>
                                <div class="text-center">
                                    <small><?php echo round($paymentPercentage); ?>% ödənilib</small>
                                </div>
                            </div>
                            <div class="card-footer">
                                <i class="fas fa-info-circle me-1"></i> Detalları görmək üçün klikləyin
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i> Heç bir borc tapılmadı. Filtri dəyişdirməyi və ya yeni borc əlavə etməyi sınayın.
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="card mb-4">
                <div class="card-body py-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div class="text-muted">
                            <span class="fw-bold"><?php echo $total_debts; ?></span> nəticədən 
                            <span class="fw-bold"><?php echo ($page - 1) * $page_size + 1; ?></span> - 
                            <span class="fw-bold"><?php echo min($page * $page_size, $total_debts); ?></span> arası göstərilir
                        </div>
                        <nav aria-label="Page navigation">
                            <ul class="pagination mb-0">
                                <!-- First Page Link -->
                                <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                    <a class="page-link" href="<?php echo ($page <= 1) ? '#' : generatePageURL(1, $filters, $sort, $order, $page_size); ?>" aria-label="İlk">
                                        <i class="fas fa-angle-double-left"></i>
                                    </a>
                                </li>
                                
                                <!-- Previous Page Link -->
                                <li class="page-item <?php if($page <= 1) echo 'disabled'; ?>">
                                    <a class="page-link" href="<?php echo ($page <= 1) ? '#' : generatePageURL($page - 1, $filters, $sort, $order, $page_size); ?>" aria-label="Əvvəlki">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>

                                <!-- Page Number Links -->
                                <?php
                                    $max_links = 5;
                                    $start = max(1, $page - floor($max_links / 2));
                                    $end = min($total_pages, $start + $max_links - 1);

                                    if ($end - $start + 1 < $max_links) {
                                        $start = max(1, $end - $max_links + 1);
                                    }

                                    // Show ellipsis for first pages if needed
                                    if ($start > 1): 
                                ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?php if($i == $page) echo 'active'; ?>">
                                        <a class="page-link" href="<?php echo generatePageURL($i, $filters, $sort, $order, $page_size); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <!-- Show ellipsis for last pages if needed -->
                                <?php if ($end < $total_pages): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>

                                <!-- Next Page Link -->
                                <li class="page-item <?php if($page >= $total_pages) echo 'disabled'; ?>">
                                    <a class="page-link" href="<?php echo ($page >= $total_pages) ? '#' : generatePageURL($page + 1, $filters, $sort, $order, $page_size); ?>" aria-label="Sonrakı">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                                
                                <!-- Last Page Link -->
                                <li class="page-item <?php if($page >= $total_pages) echo 'disabled'; ?>">
                                    <a class="page-link" href="<?php echo ($page >= $total_pages) ? '#' : generatePageURL($total_pages, $filters, $sort, $order, $page_size); ?>" aria-label="Son">
                                        <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Add Debt Modal (Single) -->
        <div class="modal fade" id="addDebtModal" tabindex="-1" aria-labelledby="addDebtModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <form method="POST" action="debts.php">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="addDebtModalLabel">Yeni Borc Əlavə Et</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="add_debt" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="mb-3">
                                <label for="employee_id" class="form-label">İşçi Seçin</label>
                                <select name="employee_id" id="employee_id" class="form-select" required>
                                    <option value="">İşçi seçin</option>
                                    <?php foreach ($employees_list as $emp): ?>
                                        <option value="<?php echo htmlspecialchars($emp['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="amount" class="form-label">Məbləğ (AZN)</label>
                                <input type="number" step="0.01" name="amount" id="amount" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="date" class="form-label">Tarix</label>
                                <input type="date" name="date" id="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>

                            <div class="mb-3">
                                <label for="reason" class="form-label">Səbəb</label>
                                <textarea name="reason" id="reason" class="form-control" rows="3" required></textarea>
                            </div>

                            <div class="mb-3">
                                <label for="month" class="form-label">Ay (YYYY-MM)</label>
                                <input type="month" name="month" id="month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                            <button type="submit" class="btn btn-primary">Əlavə Et</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- BULK ADD MODAL START -->
        <div class="modal fade" id="bulkAddDebtModal" tabindex="-1" aria-labelledby="bulkAddDebtModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <form method="POST" action="debts.php">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="bulkAddDebtModalLabel">Toplu Borc Əlavə Et</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" name="add_bulk_debts" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">

                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label for="bulk_date" class="form-label">Tarix</label>
                                    <input type="date" name="bulk_date" id="bulk_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label for="bulk_month" class="form-label">Ay (YYYY-MM)</label>
                                    <input type="month" name="bulk_month" id="bulk_month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                                </div>
                            </div>

                            <!-- Dinamik sətirlərin göstərilməsi üçün cədvəl -->
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="bulkDebtsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>İşçi</th>
                                            <th>Məbləğ (AZN)</th>
                                            <th>Səbəb</th>
                                            <th class="text-center">Sil</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- İlk sətir nümunəsi -->
                                        <tr>
                                            <td>
                                                <select name="employee_id_bulk[]" class="form-select" required>
                                                    <option value="">Seçin</option>
                                                    <?php foreach ($employees_list as $emp): ?>
                                                        <option value="<?php echo htmlspecialchars($emp['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="number" step="0.01" name="amount_bulk[]" class="form-control" placeholder="Məbləğ" required>
                                            </td>
                                            <td>
                                                <input type="text" name="reason_bulk[]" class="form-control" placeholder="Səbəb" required>
                                            </td>
                                            <td class="text-center">
                                                <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)"><i class="fas fa-trash-alt"></i></button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Sətir əlavə etmək üçün düymə -->
                            <button type="button" class="btn btn-secondary" onclick="addNewRow()"><i class="fas fa-plus"></i> Sətir Əlavə Et</button>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                            <button type="submit" class="btn btn-primary">Toplu Əlavə Et</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <!-- BULK ADD MODAL END -->

    </div>

    <script>
        // JS funksiyası: yeni sətir əlavə etmək
        function addNewRow() {
            const tableBody = document.querySelector('#bulkDebtsTable tbody');
            const newRow = document.createElement('tr');
            newRow.innerHTML = `
                <td>
                    <select name="employee_id_bulk[]" class="form-select" required>
                        <option value="">Seçin</option>
                        <?php foreach ($employees_list as $emp): ?>
                            <option value="<?php echo htmlspecialchars($emp['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars($emp['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td>
                    <input type="number" step="0.01" name="amount_bulk[]" class="form-control" placeholder="Məbləğ" required>
                </td>
                <td>
                    <input type="text" name="reason_bulk[]" class="form-control" placeholder="Səbəb" required>
                </td>
                <td class="text-center">
                    <button type="button" class="btn btn-sm btn-danger" onclick="removeRow(this)"><i class="fas fa-trash-alt"></i></button>
                </td>
            `;
            tableBody.appendChild(newRow);
        }

        // JS funksiyası: sətir silmək
        function removeRow(button) {
            const row = button.closest('tr');
            row.remove();
        }
    </script>

    <!-- Debt Statistics Charts Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Debts Trend Chart
            const debtsTrendCtx = document.getElementById('debtsTrendChart').getContext('2d');
            const debtsTrendLabels = <?php echo json_encode(array_column($monthly_debts, 'month')); ?>;
            const debtsTrendData = <?php echo json_encode(array_column($monthly_debts, 'amount')); ?>;

            const debtsTrendChart = new Chart(debtsTrendCtx, {
                type: 'line',
                data: {
                    labels: debtsTrendLabels,
                    datasets: [{
                        label: 'Ümumi Borc (AZN)',
                        data: debtsTrendData,
                        fill: false,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Zamanla Borcların Trend Qrafiki'
                        },
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Top 5 Employees with Highest Debts Chart
            const topEmployeesCtx = document.getElementById('topEmployeesChart').getContext('2d');
            const topEmployeesLabels = <?php echo json_encode(array_column($top_employees, 'name')); ?>;
            const topEmployeesData = <?php echo json_encode(array_column($top_employees, 'total_debt')); ?>;

            const topEmployeesChart = new Chart(topEmployeesCtx, {
                type: 'bar',
                data: {
                    labels: topEmployeesLabels,
                    datasets: [{
                        label: 'Ümumi Borc (AZN)',
                        data: topEmployeesData,
                        backgroundColor: 'rgba(255, 99, 132, 0.7)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Ən Çox Borc Alan 5 İşçi'
                        },
                        legend: {
                            display: false,
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });

            // Debts Distribution Chart
            const debtsDistributionCtx = document.getElementById('debtsDistributionChart').getContext('2d');
            const debtsDistributionData = [<?php echo $overall_total_paid; ?>, <?php echo $overall_total_remaining; ?>];

            const debtsDistributionChart = new Chart(debtsDistributionCtx, {
                type: 'pie',
                data: {
                    labels: ['Ödənilmiş Borc', 'Qalan Borc'],
                    datasets: [{
                        data: debtsDistributionData,
                        backgroundColor: [
                            'rgba(40, 167, 69, 0.7)',
                            'rgba(255, 193, 7, 0.7)'
                        ],
                        borderColor: [
                            'rgba(40, 167, 69, 1)',
                            'rgba(255, 193, 7, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Borcların Paylanması'
                        },
                        legend: {
                            position: 'bottom',
                        }
                    }
                }
            });
        });
    </script>

    <!-- Debt Details Modal -->
    <?php foreach ($debts as $debt): ?>
    <div class="modal fade" id="detailDebtModal<?php echo $debt['id']; ?>" tabindex="-1" aria-labelledby="detailDebtModalLabel<?php echo $debt['id']; ?>" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailDebtModalLabel<?php echo $debt['id']; ?>">
                        <i class="fas fa-file-invoice-dollar me-2"></i>
                        Borc Detalları - <?php echo htmlspecialchars($debt['name'], ENT_QUOTES, 'UTF-8'); ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                </div>
                <div class="modal-body">
                    <!-- Summary Card -->
                    <div class="card mb-4 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4 text-center border-end">
                                    <h6 class="text-muted mb-1">Ümumi Borc</h6>
                                    <h4 class="text-primary"><?php echo number_format($debt['total_debt'], 2); ?> AZN</h4>
                                </div>
                                <div class="col-md-4 text-center border-end">
                                    <h6 class="text-muted mb-1">Ödənilmiş</h6>
                                    <h4 class="text-success"><?php echo number_format($debt['paid_debt'], 2); ?> AZN</h4>
                                </div>
                                <div class="col-md-4 text-center">
                                    <h6 class="text-muted mb-1">Qalan</h6>
                                    <h4 class="text-danger"><?php echo number_format($debt['remaining_debt'], 2); ?> AZN</h4>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                        $employee_debts = fetchEmployeeDebts($conn, $debt['id']);
                        if (count($employee_debts) > 0):
                    ?>
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Məbləğ (AZN)</th>
                                        <th>Tarix</th>
                                        <th>Səbəb</th>
                                        <th>Status</th>
                                        <th>Əməliyyatlar</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($employee_debts as $d_index => $d_detail): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($d_index + 1, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo number_format($d_detail['amount'], 2); ?> AZN</td>
                                            <td><?php echo htmlspecialchars(date('d.m.Y', strtotime($d_detail['date'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td><?php echo htmlspecialchars($d_detail['reason'], ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <?php if ($d_detail['is_paid']): ?>
                                                    <span class="badge bg-success">Ödənilib</span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Ödənilməyib</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group">
                                                    <!-- Toggle Paid Status Button -->
                                                    <form method="POST" action="debts.php" class="d-inline">
                                                        <input type="hidden" name="debt_id" value="<?php echo htmlspecialchars($d_detail['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="toggle_paid" value="1">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit" class="btn btn-sm <?php echo $d_detail['is_paid'] ? 'btn-warning' : 'btn-success'; ?> action-btn" title="<?php echo $d_detail['is_paid'] ? 'Borc ödənilməyib kimi işarələ' : 'Borc ödənilib kimi işarələ'; ?>">
                                                            <?php echo $d_detail['is_paid'] ? '<i class="fas fa-undo-alt"></i>' : '<i class="fas fa-check-circle"></i>'; ?>
                                                        </button>
                                                    </form>

                                                    <!-- Delete Debt Button -->
                                                    <form method="POST" action="debts.php" class="d-inline" onsubmit="return confirm('Bu borcu silmək istədiyinizə əminsinizmi?');">
                                                        <input type="hidden" name="debt_id" value="<?php echo htmlspecialchars($d_detail['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <input type="hidden" name="delete_debt" value="1">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <button type="submit" class="btn btn-sm btn-danger action-btn" title="Borc sil">
                                                            <i class="fas fa-trash-alt"></i>
                                                        </button>
                                                    </form>

                                                    <!-- Qismen Ödəniş Button -->
                                                    <?php if (!$d_detail['is_paid'] && $d_detail['amount'] > 0): ?>
                                                    <button type="button" class="btn btn-sm btn-primary action-btn" data-bs-toggle="modal" data-bs-target="#providePaymentModalDetail<?php echo $d_detail['id']; ?>" title="Qismen Ödəniş Et">
                                                        <i class="fas fa-money-bill-wave"></i>
                                                    </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i> Bu işçi üçün borc qeydi yoxdur.
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i> Bağla
                    </button>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDebtModal" data-bs-dismiss="modal">
                        <i class="fas fa-plus me-1"></i> Yeni Borc Əlavə Et
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Kömək Modal -->
    <div class="modal fade" id="helpModal" tabindex="-1" aria-labelledby="helpModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="helpModalLabel">
                        <i class="fas fa-question-circle me-2"></i> Kömək
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                </div>
                <div class="modal-body">
                    <div class="accordion" id="helpAccordion">
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingOne">
                                <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseOne" aria-expanded="true" aria-controls="collapseOne">
                                    Borc necə əlavə edilir?
                                </button>
                            </h2>
                            <div id="collapseOne" class="accordion-collapse collapse show" aria-labelledby="headingOne" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>Yeni borc əlavə etmək üçün "Yeni Borc Əlavə Et" düyməsini klikləyin. Açılan pəncərədə işçini, məbləği, tarixi və səbəbi qeyd edin.</p>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingTwo">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseTwo" aria-expanded="false" aria-controls="collapseTwo">
                                    Borc statusu necə dəyişdirilir?
                                </button>
                            </h2>
                            <div id="collapseTwo" class="accordion-collapse collapse" aria-labelledby="headingTwo" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>İşçi kartına klikləyərək detalları açın. Açılan pəncərədə hər bir borc üçün "Ödənilib" və ya "Ödənilməyib" statusunu dəyişmək üçün <i class="fas fa-check-circle"></i> düyməsini istifadə edin.</p>
                                </div>
                            </div>
                        </div>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="headingThree">
                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseThree" aria-expanded="false" aria-controls="collapseThree">
                                    Qismən ödəniş necə edilir?
                                </button>
                            </h2>
                            <div id="collapseThree" class="accordion-collapse collapse" aria-labelledby="headingThree" data-bs-parent="#helpAccordion">
                                <div class="accordion-body">
                                    <p>İşçi kartına klikləyərək detalları açın. Açılan pəncərədə hər bir borc üçün <i class="fas fa-money-bill-wave"></i> düyməsini klikləyin və ödəniş məbləğini daxil edin.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
