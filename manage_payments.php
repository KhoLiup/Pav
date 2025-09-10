<?php

// manage_payments.php

session_start();

require_once 'config.php';

require_once 'includes/flash_messages.php';



// Funksiyalar

function validate_csrf_token($token) {

    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);

}



function sanitize_input($data) {

    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');

}



function redirect_with_message($location, $type, $message) {

    set_flash_message($type, $message);

    header("Location: $location");

    exit();

}



if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();

}



// İcazə səviyyələri

$user_role = $_SESSION['user_role'];

$can_add = in_array($user_role, ['admin', 'manager']);

$can_edit = in_array($user_role, ['admin', 'manager']);

$can_delete = ($user_role === 'admin');



// GET parametri ilə firm_id almaq

$firm_id = isset($_GET['firm_id']) ? filter_var($_GET['firm_id'], FILTER_VALIDATE_INT) : false;



// Formaların işlənməsi



// Əlavə Ödəniş

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_payment'])) {

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');

    }



    $firm_id_post = filter_var($_POST['firm_id'] ?? '', FILTER_VALIDATE_INT);

    $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);

    $reason_id = filter_var($_POST['reason_id'] ?? '', FILTER_VALIDATE_INT);

    $due_day = filter_var($_POST['due_day'] ?? '', FILTER_VALIDATE_INT);

    $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);

    $status = sanitize_input($_POST['status'] ?? 'Ödənilməyib');



    if ($firm_id_post === false || empty($category_id) || $reason_id === false || $due_day === false || $amount === false) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Zəhmət olmasa, bütün tələb olunan sahələri doldurun.');

    }



    // Təyin olunmuş günə əsasən növbəti ödəniş tarixi

    $current_year = date('Y');

    $current_month = date('m');

    $next_due_date = DateTime::createFromFormat('Y-m-d', "$current_year-$current_month-$due_day");



    if (!$next_due_date || $next_due_date->format('d') != $due_day) {

        // Əgər daxil edilmiş gün mövcud deyilsə (məsələn, fevralın 30-u)

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Daxil edilmiş ödəniş günü mövcud deyil.');

    }



    // Əgər `due_date` artıq keçibsə, növbəti aya keçir

    if ($next_due_date->format('Y-m-d') < date('Y-m-d')) {

        $next_due_date->modify('+1 month');

    }



    $due_date = $next_due_date->format('Y-m-d');



    try {

        $stmt = $conn->prepare("INSERT INTO payments (firm_id, category_id, reason_id, due_day, amount, due_date, status) VALUES (:firm_id, :category_id, :reason_id, :due_day, :amount, :due_date, :status)");

        $stmt->execute([

            ':firm_id' => $firm_id_post,

            ':category_id' => $category_id,

            ':reason_id' => $reason_id,

            ':due_day' => $due_day,

            ':amount' => $amount,

            ':due_date' => $due_date,

            ':status' => $status

        ]);

        redirect_with_message($_SERVER['REQUEST_URI'], 'success', 'Ödəniş uğurla əlavə edildi.');

    } catch (PDOException $e) {

        error_log("Ödəniş əlavə edilərkən xəta: " . $e->getMessage());

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');

    }

}



// Ödənişi yeniləmək

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment'])) {

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');

    }



    $payment_id = filter_var($_POST['payment_id'] ?? '', FILTER_VALIDATE_INT);

    $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);

    $reason_id = filter_var($_POST['reason_id'] ?? '', FILTER_VALIDATE_INT);

    $due_day = filter_var($_POST['due_day'] ?? '', FILTER_VALIDATE_INT);

    $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);

    $due_date_input = $_POST['due_date'] ?? '';

    $status = sanitize_input($_POST['status'] ?? '');



    if ($payment_id === false || empty($category_id) || $reason_id === false || $due_day === false || $amount === false || empty($due_date_input)) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Zəhmət olmasa, bütün tələb olunan sahələri doldurun.');

    }



    // Ödəniş tarixi təyin etmək

    $due_date = sanitize_input($due_date_input);



    try {

        $stmt = $conn->prepare("UPDATE payments SET category_id = :category_id, reason_id = :reason_id, due_day = :due_day, amount = :amount, due_date = :due_date, status = :status WHERE id = :id");

        $stmt->execute([

            ':category_id' => $category_id,

            ':reason_id' => $reason_id,

            ':due_day' => $due_day,

            ':amount' => $amount,

            ':due_date' => $due_date,

            ':status' => $status,

            ':id' => $payment_id

        ]);

        redirect_with_message($_SERVER['REQUEST_URI'], 'success', 'Ödəniş uğurla yeniləndi.');

    } catch (PDOException $e) {

        error_log("Ödəniş yenilənərkən xəta: " . $e->getMessage());

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');

    }

}



// Ödənişi silmək

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_payment'])) {

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');

    }



    $payment_id = filter_var($_POST['payment_id'] ?? '', FILTER_VALIDATE_INT);

    if ($payment_id === false) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Müvafiq ödəniş tapılmadı.');

    }



    try {

        $stmt = $conn->prepare("DELETE FROM payments WHERE id = :id");

        $stmt->execute([':id' => $payment_id]);

        redirect_with_message($_SERVER['REQUEST_URI'], 'success', 'Ödəniş uğurla silindi.');

    } catch (PDOException $e) {

        error_log("Ödəniş silinərkən xəta: " . $e->getMessage());

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');

    }

}



// Yeni Səbəb Əlavə Etmək

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reason'])) {

    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');

    }



    $firm_id_post = filter_var($_POST['firm_id'] ?? '', FILTER_VALIDATE_INT);

    $category_id = filter_var($_POST['category_id'] ?? '', FILTER_VALIDATE_INT);

    $reason_name = sanitize_input($_POST['reason_name'] ?? '');



    if ($firm_id_post === false || $category_id === false || empty($reason_name)) {

        redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Zəhmət olmasa, bütün tələb olunan sahələri doldurun.');

    }



    try {

        // Yeni səbəb əlavə et

        $stmt = $conn->prepare("INSERT INTO payment_reasons (category_id, firm_id, reason_name) VALUES (:category_id, :firm_id, :reason_name)");

        $stmt->execute([

            ':category_id' => $category_id,

            ':firm_id' => $firm_id_post,

            ':reason_name' => $reason_name

        ]);

        redirect_with_message($_SERVER['REQUEST_URI'], 'success', 'Yeni səbəb uğurla əlavə edildi.');

    } catch (PDOException $e) {

        if ($e->getCode() == 23000) { // Duplicate entry

            redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Bu səbəb artıq mövcuddur.');

        } else {

            error_log("Səbəb əlavə edilərkən xəta: " . $e->getMessage());

            redirect_with_message($_SERVER['REQUEST_URI'], 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');

        }

    }

}



// Verilənləri Çəkmək



// Ödənişləri çəkmək

function fetch_payments($conn, $firm_id) {

    try {

        $stmt = $conn->prepare("

            SELECT p.*, pc.category_name, pr.reason_name 

            FROM payments p

            JOIN payment_categories pc ON p.category_id = pc.id

            JOIN payment_reasons pr ON p.reason_id = pr.id

            WHERE p.firm_id = :firm_id

            ORDER BY p.due_date DESC

        ");

        $stmt->execute([':firm_id' => $firm_id]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log("Ödənişlər alınarkən xəta: " . $e->getMessage());

        return [];

    }

}



// Bütün firmaların ödənişlərinin ümumi görünüşü

function fetch_all_firms_with_payments($conn) {

    try {

        $stmt = $conn->prepare("

            SELECT 

                f.id, 

                f.firm_name, 

                SUM(CASE WHEN p.category_id IS NOT NULL AND pc.category_name = 'Aylıq' THEN p.amount ELSE 0 END) AS total_monthly,

                SUM(CASE WHEN p.category_id IS NOT NULL AND pc.category_name = 'Kvartal' THEN p.amount ELSE 0 END) AS total_quarterly

            FROM firms f

            LEFT JOIN payments p ON f.id = p.firm_id

            LEFT JOIN payment_categories pc ON p.category_id = pc.id

            GROUP BY f.id, f.firm_name

            ORDER BY f.firm_name ASC

        ");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log("Firmalar və ödənişlər alınarkən xəta: " . $e->getMessage());

        return [];

    }

}



// Mövcud kateqoriyaları çəkmək

function fetch_payment_categories($conn) {

    try {

        $stmt = $conn->prepare("SELECT id, category_name FROM payment_categories ORDER BY category_name ASC");

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {

        error_log("Ödəniş kateqoriyaları alınarkən xəta: " . $e->getMessage());

        return [];

    }

}



// Əgər firm_id varsa, ödənişləri çəkin

if ($firm_id !== false) {

    try {

        // Firmanın məlumatlarını çəkin

        $stmt = $conn->prepare("SELECT * FROM firms WHERE id = :id");

        $stmt->execute([':id' => $firm_id]);

        $firm = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$firm) {

            redirect_with_message('manage_payments.php', 'danger', 'Müvafiq firma tapılmadı.');

        }



        // Firmanın ödənişlərini çəkin

        $payments = fetch_payments($conn, $firm_id);



        // Firmanın ödəniş kateqoriyalarını çəkin

        $categories = fetch_payment_categories($conn);

    } catch (PDOException $e) {

        error_log("Firma və ödənişlər alınarkən xəta: " . $e->getMessage());

        redirect_with_message('manage_payments.php', 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');

    }

} else {

    // Bütün firmaların ödənişlərini çəkin

    $firms_with_payments = fetch_all_firms_with_payments($conn);

    $categories = fetch_payment_categories($conn);

}

?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title>Ödənişlərin İdarə Edilməsi</title>

    <!-- Bootstrap 5 CSS -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Font Awesome 6 -->

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- DataTables CSS -->

    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">

    <!-- Custom CSS -->

    <style>

        body {

            background-color: #f8f9fa;

        }

        .modal .form-control, .modal .form-select {

            border-radius: 0.5rem;

        }

        .btn-icon {

            margin-right: 5px;

        }

        .table-responsive {

            overflow-x: auto;

        }

        /* Smooth transition for buttons */

        .btn {

            transition: background-color 0.3s, transform 0.2s;

        }

        .btn:hover {

            transform: scale(1.05);

        }

        /* Custom DataTables styles */

        table.dataTable thead th {

            background-color: #343a40;

            color: white;

        }

        /* Flash messages styling */

        .alert {

            border-radius: 0.5rem;

            transition: opacity 0.5s ease-in-out;

        }

    </style>

</head>

<body>

    <!-- Naviqasiya Panelini Daxil Et -->

    <?php include 'includes/navbar.php'; ?>



    <div class="container my-5">

        <?php if ($firm_id !== false): ?>

            <!-- Spesifik Firmanın Ödənişlərini İdarə Et -->

            <div class="d-flex justify-content-between align-items-center mb-4">

                <h2 class="text-primary">Firma: <?php echo htmlspecialchars($firm['firm_name']); ?> - Ödənişlərin İdarə Edilməsi</h2>

                <?php if ($can_add): ?>

                    <!-- Yeni Ödəniş Əlavə Et Buttonu -->

                    <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPaymentModal<?php echo $firm['id']; ?>">

                        <i class="fas fa-plus btn-icon"></i> Yeni Ödəniş Əlavə Et

                    </button>

                <?php endif; ?>

            </div>



            <!-- Flash Mesajları -->

            <?php display_flash_messages(); ?>



            <!-- Ödənişlər Cədvəli -->

            <div class="card shadow-sm">

                <div class="card-body">

                    <div class="table-responsive">

                        <table id="paymentsTable" class="table table-striped table-bordered">

                            <thead>

                                <tr>

                                    <th>ID</th>

                                    <th>Kateqoriya</th>

                                    <th>Səbəb</th>

                                    <th>Ödəniş Növü</th>

                                    <th>Məbləğ</th>

                                    <th>Son Ödəniş Tarixi</th>

                                    <th>Status</th>

                                    <?php if ($can_edit || $can_delete): ?>

                                        <th>Əməliyyatlar</th>

                                    <?php endif; ?>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($payments as $payment): ?>

                                    <tr>

                                        <td><?php echo htmlspecialchars($payment['id']); ?></td>

                                        <td><?php echo htmlspecialchars($payment['category_name']); ?></td>

                                        <td><?php echo htmlspecialchars($payment['reason_name']); ?></td>

                                        <td><?php echo htmlspecialchars($payment['category_name']); ?></td>

                                        <td><?php echo htmlspecialchars(number_format($payment['amount'], 2)); ?> AZN</td>

                                        <td><?php echo htmlspecialchars($payment['due_date']); ?></td>

                                        <td>

                                            <?php

                                                if ($payment['status'] === 'Ödənilib') {

                                                    echo '<span class="badge bg-success">Ödənilib</span>';

                                                } elseif ($payment['status'] === 'Gecikmiş') {

                                                    echo '<span class="badge bg-warning text-dark">Gecikmiş</span>';

                                                } else {

                                                    echo '<span class="badge bg-danger">Ödənilməyib</span>';

                                                }

                                            ?>

                                        </td>

                                        <?php if ($can_edit || $can_delete): ?>

                                            <td>

                                                <div class="d-flex gap-2">

                                                    <?php if ($can_edit): ?>

                                                        <!-- Ödəniş Redaktə Et Buttonu -->

                                                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editPaymentModal<?php echo $payment['id']; ?>">

                                                            <i class="fas fa-edit"></i>

                                                        </button>

                                                    <?php endif; ?>

                                                    <?php if ($can_delete): ?>

                                                        <!-- Ödəniş Sil Buttonu -->

                                                        <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deletePaymentModal<?php echo $payment['id']; ?>">

                                                            <i class="fas fa-trash-alt"></i>

                                                        </button>

                                                    <?php endif; ?>

                                                </div>

                                            </td>

                                        <?php endif; ?>

                                    </tr>



                                    <!-- Ödəniş Redaktə Et Modali -->

                                    <?php if ($can_edit): ?>

                                        <div class="modal fade" id="editPaymentModal<?php echo $payment['id']; ?>" tabindex="-1" aria-labelledby="editPaymentModalLabel<?php echo $payment['id']; ?>" aria-hidden="true">

                                            <div class="modal-dialog modal-lg modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST" action="manage_payments.php<?php echo '?firm_id=' . urlencode($firm_id); ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title" id="editPaymentModalLabel<?php echo $payment['id']; ?>">Ödəniş Redaktə Et</h5>

                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                                            <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($payment['id']); ?>">



                                                            <div class="row g-3">

                                                                <div class="col-md-6">

                                                                    <label for="category_id<?php echo $payment['id']; ?>" class="form-label">Ödəniş Növü:</label>

                                                                    <select name="category_id" id="category_id<?php echo $payment['id']; ?>" class="form-select" required>

                                                                        <option value="">-- Ödəniş Növü Seçin --</option>

                                                                        <?php

                                                                            foreach ($categories as $category) {

                                                                                $selected = ($category['id'] == $payment['category_id']) ? 'selected' : '';

                                                                                echo '<option value="' . htmlspecialchars($category['id']) . '" ' . $selected . '>' . htmlspecialchars($category['category_name']) . '</option>';

                                                                            }

                                                                        ?>

                                                                    </select>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label for="reason_id<?php echo $payment['id']; ?>" class="form-label">Ödəniş Səbəbi:</label>

                                                                    <select name="reason_id" id="reason_id<?php echo $payment['id']; ?>" class="form-select" required>

                                                                        <option value="">-- Səbəb Seçin --</option>

                                                                        <?php

                                                                            // Mövcud səbəbləri əldə et

                                                                            $stmt = $conn->prepare("SELECT id, reason_name FROM payment_reasons WHERE category_id = :category_id AND firm_id = :firm_id ORDER BY reason_name ASC");

                                                                            $stmt->execute([':category_id' => $payment['category_id'], ':firm_id' => $firm_id]);

                                                                            $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

                                                                            foreach ($reasons as $reason) {

                                                                                $selected = ($reason['id'] == $payment['reason_id']) ? 'selected' : '';

                                                                                echo '<option value="' . htmlspecialchars($reason['id']) . '" ' . $selected . '>' . htmlspecialchars($reason['reason_name']) . '</option>';

                                                                            }

                                                                        ?>

                                                                    </select>

                                                                    <small class="form-text text-muted">Mövcud səbəb yoxdursa, yeni səbəb əlavə edə bilərsiniz.</small>

                                                                    <button type="button" class="btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#addReasonModal<?php echo $firm['id']; ?>">Yeni Səbəb Əlavə Et</button>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label for="due_day<?php echo $payment['id']; ?>" class="form-label">Ödəniş Günü:</label>

                                                                    <input type="number" name="due_day" id="due_day<?php echo $payment['id']; ?>" class="form-control" min="1" max="31" value="<?php echo htmlspecialchars(date('j', strtotime($payment['due_date']))); ?>" required>

                                                                    <small class="form-text text-muted">Hər ayın hansı günündə ödəniş olunacağını daxil edin (1-31).</small>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label for="amount<?php echo $payment['id']; ?>" class="form-label">Məbləğ (AZN):</label>

                                                                    <input type="number" step="0.01" name="amount" id="amount<?php echo $payment['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($payment['amount']); ?>" required>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label for="due_date<?php echo $payment['id']; ?>" class="form-label">Son Ödəniş Tarixi:</label>

                                                                    <input type="date" name="due_date" id="due_date<?php echo $payment['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($payment['due_date']); ?>" required>

                                                                </div>

                                                                <div class="col-md-6">

                                                                    <label for="status<?php echo $payment['id']; ?>" class="form-label">Status:</label>

                                                                    <select name="status" id="status<?php echo $payment['id']; ?>" class="form-select" required>

                                                                        <option value="Ödənilib" <?php if($payment['status'] == 'Ödənilib') echo 'selected'; ?>>Ödənilib</option>

                                                                        <option value="Ödənilməyib" <?php if($payment['status'] == 'Ödənilməyib') echo 'selected'; ?>>Ödənilməyib</option>

                                                                        <option value="Gecikmiş" <?php if($payment['status'] == 'Gecikmiş') echo 'selected'; ?>>Gecikmiş</option>

                                                                    </select>

                                                                </div>

                                                            </div>

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>

                                                            <button type="submit" name="update_payment" class="btn btn-warning"><i class="fas fa-edit btn-icon"></i> Yenilə</button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>



                                    <!-- Ödəniş Sil Modali -->

                                    <?php if ($can_delete): ?>

                                        <div class="modal fade" id="deletePaymentModal<?php echo $payment['id']; ?>" tabindex="-1" aria-labelledby="deletePaymentModalLabel<?php echo $payment['id']; ?>" aria-hidden="true">

                                            <div class="modal-dialog modal-dialog-centered">

                                                <div class="modal-content">

                                                    <form method="POST" action="manage_payments.php<?php echo '?firm_id=' . urlencode($firm_id); ?>">

                                                        <div class="modal-header">

                                                            <h5 class="modal-title" id="deletePaymentModalLabel<?php echo $payment['id']; ?>">Ödəniş Sil</h5>

                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>

                                                        </div>

                                                        <div class="modal-body">

                                                            <p>Ödəniş <strong><?php echo htmlspecialchars($payment['category_name']); ?> - <?php echo htmlspecialchars($payment['reason_name']); ?> - <?php echo htmlspecialchars(number_format($payment['amount'], 2)); ?> AZN</strong> silinsinmi?</p>

                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                                            <input type="hidden" name="payment_id" value="<?php echo htmlspecialchars($payment['id']); ?>">

                                                        </div>

                                                        <div class="modal-footer">

                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>

                                                            <button type="submit" name="delete_payment" class="btn btn-danger"><i class="fas fa-trash-alt btn-icon"></i> Sil</button>

                                                        </div>

                                                    </form>

                                                </div>

                                            </div>

                                        </div>

                                    <?php endif; ?>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>



            <!-- Əlavə Ödəniş Modali -->

            <?php if ($can_add): ?>

                <div class="modal fade" id="addPaymentModal<?php echo $firm['id']; ?>" tabindex="-1" aria-labelledby="addPaymentModalLabel<?php echo $firm['id']; ?>" aria-hidden="true">

                    <div class="modal-dialog modal-lg modal-dialog-centered">

                        <div class="modal-content">

                            <form method="POST" action="manage_payments.php<?php echo '?firm_id=' . urlencode($firm_id); ?>">

                                <div class="modal-header">

                                    <h5 class="modal-title" id="addPaymentModalLabel<?php echo $firm['id']; ?>">Yeni Ödəniş Əlavə Et - <?php echo htmlspecialchars($firm['firm_name']); ?></h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>

                                </div>

                                <div class="modal-body">

                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                    <input type="hidden" name="firm_id" value="<?php echo htmlspecialchars($firm['id']); ?>">



                                    <div class="row g-3">

                                        <div class="col-md-6">

                                            <label for="category_id<?php echo $firm['id']; ?>" class="form-label">Ödəniş Növü:</label>

                                            <select name="category_id" id="category_id<?php echo $firm['id']; ?>" class="form-select" required>

                                                <option value="">-- Ödəniş Növü Seçin --</option>

                                                <?php

                                                    foreach ($categories as $category) {

                                                        echo '<option value="' . htmlspecialchars($category['id']) . '">' . htmlspecialchars($category['category_name']) . '</option>';

                                                    }

                                                ?>

                                            </select>

                                        </div>

                                        <div class="col-md-6">

                                            <label for="reason_id<?php echo $firm['id']; ?>" class="form-label">Ödəniş Səbəbi:</label>

                                            <select name="reason_id" id="reason_id<?php echo $firm['id']; ?>" class="form-select" required>

                                                <option value="">-- Səbəb Seçin --</option>

                                                <!-- Səbəblər JavaScript ilə yüklənəcək -->

                                            </select>

                                            <small class="form-text text-muted">Mövcud səbəb yoxdursa, yeni səbəb əlavə edə bilərsiniz.</small>

                                            <button type="button" class="btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#addReasonModal<?php echo $firm['id']; ?>">Yeni Səbəb Əlavə Et</button>

                                        </div>

                                        <div class="col-md-6">

                                            <label for="due_day<?php echo $firm['id']; ?>" class="form-label">Ödəniş Günü:</label>

                                            <input type="number" name="due_day" id="due_day<?php echo $firm['id']; ?>" class="form-control" min="1" max="31" required>

                                            <small class="form-text text-muted">Hər ayın hansı günündə ödəniş olunacağını daxil edin (1-31).</small>

                                        </div>

                                        <div class="col-md-6">

                                            <label for="amount<?php echo $firm['id']; ?>" class="form-label">Məbləğ (AZN):</label>

                                            <input type="number" step="0.01" name="amount" id="amount<?php echo $firm['id']; ?>" class="form-control" required>

                                        </div>

                                        <div class="col-md-6">

                                            <label for="due_date<?php echo $firm['id']; ?>" class="form-label">Son Ödəniş Tarixi:</label>

                                            <input type="date" name="due_date" id="due_date<?php echo $firm['id']; ?>" class="form-control" required>

                                        </div>

                                        <div class="col-md-6">

                                            <label for="status<?php echo $firm['id']; ?>" class="form-label">Status:</label>

                                            <select name="status" id="status<?php echo $firm['id']; ?>" class="form-select" required>

                                                <option value="Ödənilməyib">Ödənilməyib</option>

                                                <option value="Ödənilib">Ödənilib</option>

                                            </select>

                                        </div>

                                    </div>

                                </div>

                                <div class="modal-footer">

                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>

                                    <button type="submit" name="add_payment" class="btn btn-success"><i class="fas fa-plus btn-icon"></i> Əlavə Et</button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>



                <!-- Yeni Səbəb Əlavə Et Modalı -->

                <div class="modal fade" id="addReasonModal<?php echo $firm['id']; ?>" tabindex="-1" aria-labelledby="addReasonModalLabel<?php echo $firm['id']; ?>" aria-hidden="true">

                    <div class="modal-dialog">

                        <div class="modal-content">

                            <form method="POST" action="manage_payments.php<?php echo '?firm_id=' . urlencode($firm_id); ?>">

                                <div class="modal-header">

                                    <h5 class="modal-title" id="addReasonModalLabel<?php echo $firm['id']; ?>">Yeni Ödəniş Səbəbi Əlavə Et</h5>

                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>

                                </div>

                                <div class="modal-body">

                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

                                    <input type="hidden" name="firm_id" value="<?php echo htmlspecialchars($firm['id']); ?>">

                                    <div class="mb-3">

                                        <label for="category_id<?php echo $firm['id']; ?>" class="form-label">Ödəniş Kateqoriyası:</label>

                                        <select name="category_id" id="category_id<?php echo $firm['id']; ?>" class="form-select" required>

                                            <option value="">-- Kateqoriya Seçin --</option>

                                            <?php

                                                foreach ($categories as $category) {

                                                    echo '<option value="' . htmlspecialchars($category['id']) . '">' . htmlspecialchars($category['category_name']) . '</option>';

                                                }

                                            ?>

                                        </select>

                                    </div>

                                    <div class="mb-3">

                                        <label for="reason_name<?php echo $firm['id']; ?>" class="form-label">Səbəb Adı:</label>

                                        <input type="text" name="reason_name" id="reason_name<?php echo $firm['id']; ?>" class="form-control" required>

                                    </div>

                                </div>

                                <div class="modal-footer">

                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>

                                    <button type="submit" name="add_reason" class="btn btn-primary"><i class="fas fa-plus btn-icon"></i> Əlavə Et</button>

                                </div>

                            </form>

                        </div>

                    </div>

                </div>

            <?php endif; ?>



        <?php else: ?>

            <!-- Bütün Firmaların Ödənişlərinin Ümumi Görünüşü -->

            <div class="d-flex justify-content-between align-items-center mb-4">

                <h2 class="text-primary">Bütün Firmaların Ödənişləri</h2>

            </div>



            <!-- Flash Mesajları -->

            <?php display_flash_messages(); ?>



            <!-- Firmaların Ödənişləri Cədvəli -->

            <div class="card shadow-sm">

                <div class="card-body">

                    <div class="table-responsive">

                        <table id="allPaymentsTable" class="table table-striped table-bordered">

                            <thead>

                                <tr>

                                    <th>ID</th>

                                    <th>Firma Adı</th>

                                    <th>Toplam Aylıq Ödəniş (AZN)</th>

                                    <th>Toplam Kvartal Ödəniş (AZN)</th>

                                    <th>Ümumi Ödəniş (AZN)</th>

                                </tr>

                            </thead>

                            <tbody>

                                <?php foreach ($firms_with_payments as $firm): ?>

                                    <tr>

                                        <td><?php echo htmlspecialchars($firm['id']); ?></td>

                                        <td>

                                            <a href="manage_payments.php?firm_id=<?php echo urlencode($firm['id']); ?>">

                                                <?php echo htmlspecialchars($firm['firm_name']); ?>

                                            </a>

                                        </td>

                                        <td><?php echo htmlspecialchars(number_format($firm['total_monthly'], 2)); ?> AZN</td>

                                        <td><?php echo htmlspecialchars(number_format($firm['total_quarterly'], 2)); ?> AZN</td>

                                        <td><?php echo htmlspecialchars(number_format($firm['total_monthly'] + $firm['total_quarterly'], 2)); ?> AZN</td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </div>

        <?php endif; ?>



    </div>



    <!-- Bootstrap 5 JS Bundle (Includes Popper) -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- jQuery (Required for DataTables) -->

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- DataTables JS -->

    <script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>

    <script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>



    <!-- Custom JS for Smooth Transitions and Enhanced UX -->

    <script>

        $(document).ready(function() {

            <?php if ($firm_id !== false): ?>

                // Initialize DataTables for paymentsTable

                $('#paymentsTable').DataTable({

                    "language": {

                        "url": "//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json"

                    },

                    "paging": true,

                    "lengthChange": true,

                    "searching": true,

                    "ordering": true,

                    "info": true,

                    "autoWidth": false,

                    "responsive": true,

                    "order": [[1, "asc"]], // Order by Category by default

                    "columnDefs": [

                        { "orderable": false, "targets": <?php echo ($can_edit || $can_delete) ? '-1' : '-1'; ?> }

                    ]

                });

            <?php else: ?>

                // Initialize DataTables for allPaymentsTable

                $('#allPaymentsTable').DataTable({

                    "language": {

                        "url": "//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json"

                    },

                    "paging": true,

                    "lengthChange": true,

                    "searching": true,

                    "ordering": true,

                    "info": true,

                    "autoWidth": false,

                    "responsive": true,

                    "order": [[1, "asc"]], // Order by Firm Name by default

                });

            <?php endif; ?>



            // Auto-hide flash messages after 5 seconds

            setTimeout(function() {

                $('.alert').fadeOut('slow');

            }, 5000);



            <?php if ($firm_id !== false && $can_add): ?>

                // Əlavə Ödəniş Modalında ödəniş növü seçildikdə səbəbləri yüklə

                $('#category_id<?php echo $firm['id']; ?>').on('change', function() {

                    var category_id = $(this).val();

                    var firm_id = <?php echo json_encode($firm['id']); ?>;

                    var reasonSelect = $('#reason_id<?php echo $firm['id']; ?>');

                    reasonSelect.empty();

                    reasonSelect.append('<option value="">-- Səbəb Seçin --</option>');

                    if(category_id) {

                        $.ajax({

                            url: 'get_payment_reasons.php',

                            method: 'GET',

                            data: { category_id: category_id, firm_id: firm_id },

                            dataType: 'json',

                            success: function(data) {

                                if(data.length === 0){

                                    reasonSelect.append('<option value="">Yeni səbəb əlavə etmək üçün yuxarıdakı düyməyə klikləyin.</option>');

                                } else {

                                    $.each(data, function(index, reason) {

                                        reasonSelect.append('<option value="' + reason.id + '">' + reason.reason_name + '</option>');

                                    });

                                }

                            },

                            error: function() {

                                alert('Səbəbləri yükləməkdə xəta baş verdi.');

                            }

                        });

                    }

                });



                // Modal açıldığında category_id seçimini tetikle

                $('#addPaymentModal<?php echo $firm['id']; ?>').on('shown.bs.modal', function () {

                    $('#category_id<?php echo $firm['id']; ?>').trigger('change');

                });



                // Redaktə Et modalında ödəniş növü seçildikdə səbəbləri yüklə

                <?php if ($can_edit): ?>

                    <?php foreach ($payments as $payment): ?>

                        $('#category_id<?php echo $payment['id']; ?>').on('change', function() {

                            var category_id = $(this).val();

                            var firm_id = <?php echo json_encode($firm['id']); ?>;

                            var reasonSelect = $('#reason_id<?php echo $payment['id']; ?>');

                            reasonSelect.empty();

                            reasonSelect.append('<option value="">-- Səbəb Seçin --</option>');

                            if(category_id) {

                                $.ajax({

                                    url: 'get_payment_reasons.php',

                                    method: 'GET',

                                    data: { category_id: category_id, firm_id: firm_id },

                                    dataType: 'json',

                                    success: function(data) {

                                        if(data.length === 0){

                                            reasonSelect.append('<option value="">Yeni səbəb əlavə etmək üçün yuxarıdakı düyməyə klikləyin.</option>');

                                        } else {

                                            $.each(data, function(index, reason) {

                                                reasonSelect.append('<option value="' + reason.id + '">' + reason.reason_name + '</option>');

                                            });

                                        }

                                    },

                                    error: function() {

                                        alert('Səbəbləri yükləməkdə xəta baş verdi.');

                                    }

                                });

                            }

                        });



                        // Redaktə Et modalı açıldığında category_id seçimini tetikle

                        $('#editPaymentModal<?php echo $payment['id']; ?>').on('shown.bs.modal', function () {

                            $('#category_id<?php echo $payment['id']; ?>').trigger('change');

                        });

                    <?php endforeach; ?>

                <?php endif; ?>

            <?php endif; ?>

        });

    </script>

</body>

</html>

