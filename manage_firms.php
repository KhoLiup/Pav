<?php
// manage_firms.php
session_start();
require_once 'config.php';
require_once 'includes/flash_messages.php';

// Function to validate CSRF token
function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Function to sanitize input data
function sanitize_input($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

// Function to handle redirection with flash messages
function redirect_with_message($location, $type, $message) {
    set_flash_message($type, $message);
    header("Location: $location");
    exit();
}

// Ensure the user is authenticated
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Define user permissions based on roles
$user_role = $_SESSION['user_role'];
$can_add = in_array($user_role, ['admin', 'manager']);
$can_edit = in_array($user_role, ['admin', 'manager']);
$can_delete = ($user_role === 'admin');

// Handle Add Firm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_firm'])) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        redirect_with_message('manage_firms.php', 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');
    }

    // Sanitize and validate input data
    $firm_name = sanitize_input($_POST['firm_name'] ?? '');
    $contact_phone = sanitize_input($_POST['contact_phone'] ?? '');
    $contact_email = filter_var($_POST['contact_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $address = sanitize_input($_POST['address'] ?? '');
    $contract_start = $_POST['contract_start'] ?? '';
    $contract_duration = filter_var($_POST['contract_duration'] ?? '', FILTER_VALIDATE_INT);
    $status = sanitize_input($_POST['status'] ?? '');

    // Check required fields
    if (empty($firm_name) || empty($contract_start) || $contract_duration === false || empty($status)) {
        redirect_with_message('manage_firms.php', 'danger', 'Zəhmət olmasa, bütün tələb olunan sahələri doldurun.');
    }

    try {
        // Insert new firm
        $stmt = $conn->prepare("INSERT INTO firms (firm_name, contact_phone, contact_email, address, contract_start, contract_duration, status) VALUES (:firm_name, :contact_phone, :contact_email, :address, :contract_start, :contract_duration, :status)");
        $stmt->execute([
            ':firm_name' => $firm_name,
            ':contact_phone' => $contact_phone,
            ':contact_email' => $contact_email,
            ':address' => $address,
            ':contract_start' => $contract_start,
            ':contract_duration' => $contract_duration,
            ':status' => $status
        ]);
        redirect_with_message('manage_firms.php', 'success', 'Firma uğurla əlavə edildi.');
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            redirect_with_message('manage_firms.php', 'danger', 'Bu firma adı artıq mövcuddur.');
        } else {
            error_log("Firma əlavə edilərkən xəta: " . $e->getMessage());
            redirect_with_message('manage_firms.php', 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');
        }
    }
}

// Handle Update Firm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_firm'])) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        redirect_with_message('manage_firms.php', 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');
    }

    // Sanitize and validate input data
    $firm_id = filter_var($_POST['firm_id'] ?? '', FILTER_VALIDATE_INT);
    $firm_name = sanitize_input($_POST['firm_name'] ?? '');
    $contact_phone = sanitize_input($_POST['contact_phone'] ?? '');
    $contact_email = filter_var($_POST['contact_email'] ?? '', FILTER_SANITIZE_EMAIL);
    $address = sanitize_input($_POST['address'] ?? '');
    $contract_start = $_POST['contract_start'] ?? '';
    $contract_duration = filter_var($_POST['contract_duration'] ?? '', FILTER_VALIDATE_INT);
    $status = sanitize_input($_POST['status'] ?? '');

    // Check required fields
    if (empty($firm_name) || empty($contract_start) || $contract_duration === false || empty($status) || $firm_id === false) {
        redirect_with_message('manage_firms.php', 'danger', 'Zəhmət olmasa, bütün tələb olunan sahələri doldurun.');
    }

    try {
        // Update firm
        $stmt = $conn->prepare("UPDATE firms SET firm_name = :firm_name, contact_phone = :contact_phone, contact_email = :contact_email, address = :address, contract_start = :contract_start, contract_duration = :contract_duration, status = :status WHERE id = :id");
        $stmt->execute([
            ':firm_name' => $firm_name,
            ':contact_phone' => $contact_phone,
            ':contact_email' => $contact_email,
            ':address' => $address,
            ':contract_start' => $contract_start,
            ':contract_duration' => $contract_duration,
            ':status' => $status,
            ':id' => $firm_id
        ]);
        redirect_with_message('manage_firms.php', 'success', 'Firma uğurla yeniləndi.');
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            redirect_with_message('manage_firms.php', 'danger', 'Bu firma adı artıq mövcuddur.');
        } else {
            error_log("Firma yenilənərkən xəta: " . $e->getMessage());
            redirect_with_message('manage_firms.php', 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');
        }
    }
}

// Handle Delete Firm
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_firm'])) {
    // Validate CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        redirect_with_message('manage_firms.php', 'danger', 'Doğrulama xətası. Yenidən cəhd edin.');
    }

    // Sanitize and validate firm ID
    $firm_id = filter_var($_POST['firm_id'] ?? '', FILTER_VALIDATE_INT);
    if ($firm_id === false) {
        redirect_with_message('manage_firms.php', 'danger', 'Müvafiq firma tapılmadı.');
    }

    try {
        // Delete firm
        $stmt = $conn->prepare("DELETE FROM firms WHERE id = :id");
        $stmt->execute([':id' => $firm_id]);
        redirect_with_message('manage_firms.php', 'success', 'Firma uğurla silindi.');
    } catch (PDOException $e) {
        error_log("Firma silinərkən xəta: " . $e->getMessage());
        redirect_with_message('manage_firms.php', 'danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');
    }
}

// Fetch all firms
try {
    $stmt = $conn->prepare("SELECT * FROM firms ORDER BY firm_name ASC");
    $stmt->execute();
    $firms = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Firmalar alınarkən xəta: " . $e->getMessage());
    set_flash_message('danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');
    $firms = [];
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title>Firmaların İdarə Edilməsi</title>
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="text-primary">Firmaların İdarə Edilməsi</h2>
            <?php if ($can_add): ?>
                <!-- Yeni Firma Əlavə Et Buttonu -->
                <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addFirmModal">
                    <i class="fas fa-plus btn-icon"></i> Yeni Firma Əlavə Et
                </button>
            <?php endif; ?>
        </div>

        <!-- Flash Mesajları -->
        <?php display_flash_messages(); ?>

        <!-- Firmalar Cədvəli -->
        <div class="card shadow-sm">
            <div class="card-body">
                <div class="table-responsive">
                    <table id="firmsTable" class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Firma Adı</th>
                                <th>Telefon</th>
                                <th>Email</th>
                                <th>Ünvan</th>
                                <th>Müqavilə Başlama</th>
                                <th>Müddət (Ay)</th>
                                <th>Status</th>
                                <?php if ($can_edit || $can_delete): ?>
                                    <th>Əməliyyatlar</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($firms as $firm): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($firm['id']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['firm_name']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['contact_phone']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['contact_email']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['address']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['contract_start']); ?></td>
                                    <td><?php echo htmlspecialchars($firm['contract_duration']); ?></td>
                                    <td>
                                        <?php if ($firm['status'] === 'Aktiv'): ?>
                                            <span class="badge bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">İnaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($can_edit || $can_delete): ?>
                                        <td>
                                            <div class="d-flex gap-2">
                                                <?php if ($can_edit): ?>
                                                    <!-- Redaktə Et Buttonu -->
                                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editFirmModal<?php echo $firm['id']; ?>">
                                                        <i class="fas fa-edit"></i> Redaktə Et
                                                    </button>
                                                    <!-- Ödənişlərə Bax Buttonu -->
                                                    <a href="manage_payments.php?firm_id=<?php echo urlencode($firm['id']); ?>" class="btn btn-info btn-sm">
                                                        <i class="fas fa-dollar-sign"></i> Ödənişlərə Bax
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($can_delete): ?>
                                                    <!-- Sil Buttonu -->
                                                    <button class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteFirmModal<?php echo $firm['id']; ?>">
                                                        <i class="fas fa-trash-alt"></i> Sil
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    <?php endif; ?>
                                </tr>

                                <!-- Redaktə Et Modali -->
                                <?php if ($can_edit): ?>
                                    <div class="modal fade" id="editFirmModal<?php echo $firm['id']; ?>" tabindex="-1" aria-labelledby="editFirmModalLabel<?php echo $firm['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-centered">
                                            <div class="modal-content">
                                                <form method="POST" action="manage_firms.php">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="editFirmModalLabel<?php echo $firm['id']; ?>">Firma Redaktə Et</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="firm_id" value="<?php echo htmlspecialchars($firm['id']); ?>">

                                                        <div class="row g-3">
                                                            <div class="col-md-6">
                                                                <label for="firm_name<?php echo $firm['id']; ?>" class="form-label">Firma Adı:</label>
                                                                <input type="text" name="firm_name" id="firm_name<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['firm_name']); ?>" required>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="contact_phone<?php echo $firm['id']; ?>" class="form-label">Telefon:</label>
                                                                <input type="text" name="contact_phone" id="contact_phone<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['contact_phone']); ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="contact_email<?php echo $firm['id']; ?>" class="form-label">Email:</label>
                                                                <input type="email" name="contact_email" id="contact_email<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['contact_email']); ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="address<?php echo $firm['id']; ?>" class="form-label">Ünvan:</label>
                                                                <input type="text" name="address" id="address<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['address']); ?>">
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="contract_start<?php echo $firm['id']; ?>" class="form-label">Müqavilə Başlama:</label>
                                                                <input type="date" name="contract_start" id="contract_start<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['contract_start']); ?>" required>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="contract_duration<?php echo $firm['id']; ?>" class="form-label">Müddət (Ay):</label>
                                                                <input type="number" name="contract_duration" id="contract_duration<?php echo $firm['id']; ?>" class="form-control" value="<?php echo htmlspecialchars($firm['contract_duration']); ?>" required>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <label for="status<?php echo $firm['id']; ?>" class="form-label">Status:</label>
                                                                <select name="status" id="status<?php echo $firm['id']; ?>" class="form-select" required>
                                                                    <option value="Aktiv" <?php if($firm['status'] == 'Aktiv') echo 'selected'; ?>>Aktiv</option>
                                                                    <option value="İnaktiv" <?php if($firm['status'] == 'İnaktiv') echo 'selected'; ?>>İnaktiv</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                                                        <button type="submit" name="update_firm" class="btn btn-warning"><i class="fas fa-edit btn-icon"></i> Yenilə</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Sil Modali -->
                                <?php if ($can_delete): ?>
                                    <div class="modal fade" id="deleteFirmModal<?php echo $firm['id']; ?>" tabindex="-1" aria-labelledby="deleteFirmModalLabel<?php echo $firm['id']; ?>" aria-hidden="true">
                                        <div class="modal-dialog modal-dialog-centered">
                                            <div class="modal-content">
                                                <form method="POST" action="manage_firms.php">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title" id="deleteFirmModalLabel<?php echo $firm['id']; ?>">Firma Sil</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Firma <strong><?php echo htmlspecialchars($firm['firm_name']); ?></strong> silinsinmi?</p>
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                        <input type="hidden" name="firm_id" value="<?php echo htmlspecialchars($firm['id']); ?>">
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                                                        <button type="submit" name="delete_firm" class="btn btn-danger"><i class="fas fa-trash-alt btn-icon"></i> Sil</button>
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

        <!-- Əlavə Firma Modali -->
        <?php if ($can_add): ?>
            <div class="modal fade" id="addFirmModal" tabindex="-1" aria-labelledby="addFirmModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form method="POST" action="manage_firms.php">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addFirmModalLabel">Yeni Firma Əlavə Et</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                            </div>
                            <div class="modal-body">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="firm_name" class="form-label">Firma Adı:</label>
                                        <input type="text" name="firm_name" id="firm_name" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="contact_phone" class="form-label">Telefon:</label>
                                        <input type="text" name="contact_phone" id="contact_phone" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="contact_email" class="form-label">Email:</label>
                                        <input type="email" name="contact_email" id="contact_email" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="address" class="form-label">Ünvan:</label>
                                        <input type="text" name="address" id="address" class="form-control">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="contract_start" class="form-label">Müqavilə Başlama:</label>
                                        <input type="date" name="contract_start" id="contract_start" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="contract_duration" class="form-label">Müddət (Ay):</label>
                                        <input type="number" name="contract_duration" id="contract_duration" class="form-control" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="status" class="form-label">Status:</label>
                                        <select name="status" id="status" class="form-select" required>
                                            <option value="Aktiv">Aktiv</option>
                                            <option value="İnaktiv">İnaktiv</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                                <button type="submit" name="add_firm" class="btn btn-success"><i class="fas fa-plus btn-icon"></i> Əlavə Et</button>
                            </div>
                        </form>
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
            // Initialize DataTables with Bootstrap 5 styling
            $('#firmsTable').DataTable({
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
                "columnDefs": [
                    { "orderable": false, "targets": <?php echo ($can_edit || $can_delete) ? '-1' : '-1'; ?> }
                ]
            });

            // Auto-hide flash messages after 5 seconds
            setTimeout(function() {
                $('.alert').fadeOut('slow');
            }, 5000);
        });
    </script>
</body>
</html>
