<?php
// cash_register_management.php

// Xətaların göstərilməsi
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sessiya başlatmaq
session_start();

// Verilənlər bazası bağlantısı
require 'config.php';

// İstifadəçi yoxlaması
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Kassa əlavə etmək
if (isset($_POST['add_register'])) {
    $name = trim($_POST['name']);
    $location = trim($_POST['location']);
    
    if (empty($name)) {
        $error_message = 'Kassa adı daxil edilməlidir.';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO cash_registers (name, location) VALUES (:name, :location)");
            $stmt->execute([
                ':name' => $name,
                ':location' => $location
            ]);
            $success_message = 'Kassa uğurla əlavə edildi.';
        } catch (PDOException $e) {
            $error_message = 'Xəta baş verdi: ' . $e->getMessage();
        }
    }
}

// Kassa redaktə etmək
if (isset($_POST['edit_register'])) {
    $id = (int)$_POST['register_id'];
    $name = trim($_POST['name']);
    $location = trim($_POST['location']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    if (empty($name)) {
        $error_message = 'Kassa adı daxil edilməlidir.';
    } else {
        try {
            $stmt = $conn->prepare("UPDATE cash_registers SET name = :name, location = :location, is_active = :is_active WHERE id = :id");
            $stmt->execute([
                ':id' => $id,
                ':name' => $name,
                ':location' => $location,
                ':is_active' => $is_active
            ]);
            $success_message = 'Kassa məlumatları uğurla yeniləndi.';
        } catch (PDOException $e) {
            $error_message = 'Xəta baş verdi: ' . $e->getMessage();
        }
    }
}

// Kassanı silmək (soft delete)
if (isset($_POST['delete_register'])) {
    $id = (int)$_POST['register_id'];
    
    try {
        // Əvvəlcə bu kassaya bağlı işçilərin sayını yoxlayaq
        $stmt = $conn->prepare("SELECT COUNT(*) FROM employees WHERE cash_register_id = :id");
        $stmt->execute([':id' => $id]);
        $cashier_count = $stmt->fetchColumn();
        
        if ($cashier_count > 0) {
            $error_message = 'Bu kassa hələ də ' . $cashier_count . ' işçi tərəfindən istifadə olunur. Silmədən əvvəl işçiləri digər kassalara təyin edin.';
        } else {
            $stmt = $conn->prepare("UPDATE cash_registers SET is_active = 0 WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $success_message = 'Kassa uğurla deaktiv edildi.';
        }
    } catch (PDOException $e) {
        $error_message = 'Xəta baş verdi: ' . $e->getMessage();
    }
}

// Kassaların siyahısını əldə etmək
$registers = [];
try {
    $stmt = $conn->query("SELECT * FROM cash_registers ORDER BY name");
    $registers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = 'Kassa siyahısını əldə edərkən xəta baş verdi: ' . $e->getMessage();
}

// Bayraklar və xəta mesajları
$success_message = $success_message ?? '';
$error_message = $error_message ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kassa İdarəetməsi</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="container mt-4">
        <h1>Kassa İdarəetməsi</h1>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <?php echo $success_message; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger">
                <?php echo $error_message; ?>
            </div>
        <?php endif; ?>
        
        <!-- Yeni Kassa Əlavə Etmək Formu -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                Yeni Kassa Əlavə Et
            </div>
            <div class="card-body">
                <form method="post" action="cash_register_management.php">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label for="name" class="form-label">Kassa Adı:</label>
                            <input type="text" class="form-control" id="name" name="name" required>
                        </div>
                        <div class="col-md-5 mb-3">
                            <label for="location" class="form-label">Yerləşmə:</label>
                            <input type="text" class="form-control" id="location" name="location">
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" name="add_register" class="btn btn-primary w-100">Əlavə Et</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Kassaların Siyahısı -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                Kassaların Siyahısı
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Adı</th>
                                <th>Yerləşmə</th>
                                <th>Status</th>
                                <th>Yaradılıb</th>
                                <th>Əməliyyatlar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registers as $register): ?>
                                <tr>
                                    <td><?php echo $register['id']; ?></td>
                                    <td><?php echo htmlspecialchars($register['name']); ?></td>
                                    <td><?php echo htmlspecialchars($register['location'] ?? ''); ?></td>
                                    <td>
                                        <?php if ($register['is_active']): ?>
                                            <span class="badge bg-success">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">Deaktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('d.m.Y H:i', strtotime($register['created_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary edit-register" 
                                                data-id="<?php echo $register['id']; ?>" 
                                                data-name="<?php echo htmlspecialchars($register['name']); ?>" 
                                                data-location="<?php echo htmlspecialchars($register['location'] ?? ''); ?>"
                                                data-active="<?php echo $register['is_active']; ?>">
                                            Redaktə
                                        </button>
                                        <?php if ($register['is_active']): ?>
                                            <button type="button" class="btn btn-sm btn-danger delete-register" 
                                                    data-id="<?php echo $register['id']; ?>" 
                                                    data-name="<?php echo htmlspecialchars($register['name']); ?>">
                                                Deaktiv Et
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($registers)): ?>
                                <tr>
                                    <td colspan="6" class="text-center">Sistemdə heç bir kassa tapılmadı.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Redaktə Modalı -->
    <div class="modal fade" id="editRegisterModal" tabindex="-1" aria-labelledby="editRegisterModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editRegisterModalLabel">Kassanı Redaktə Et</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="post" action="cash_register_management.php">
                    <div class="modal-body">
                        <input type="hidden" name="register_id" id="edit_register_id">
                        <div class="mb-3">
                            <label for="edit_name" class="form-label">Kassa Adı:</label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="mb-3">
                            <label for="edit_location" class="form-label">Yerləşmə:</label>
                            <input type="text" class="form-control" id="edit_location" name="location">
                        </div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                            <label class="form-check-label" for="edit_is_active">
                                Aktiv
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                        <button type="submit" name="edit_register" class="btn btn-primary">Yadda Saxla</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Silmə Modalı -->
    <div class="modal fade" id="deleteRegisterModal" tabindex="-1" aria-labelledby="deleteRegisterModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deleteRegisterModalLabel">Kassanı Deaktiv Et</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>Bu kassanı deaktiv etmək istədiyinizə əminsiniz? Bu əməliyyat geri qaytarıla bilər.</p>
                    <p class="fw-bold register-name"></p>
                </div>
                <div class="modal-footer">
                    <form method="post" action="cash_register_management.php">
                        <input type="hidden" name="register_id" id="delete_register_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                        <button type="submit" name="delete_register" class="btn btn-danger">Deaktiv Et</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Redaktə düyməsinə klik olunduqda
        document.querySelectorAll('.edit-register').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const location = this.getAttribute('data-location');
                const active = this.getAttribute('data-active') === '1';
                
                document.getElementById('edit_register_id').value = id;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_location').value = location;
                document.getElementById('edit_is_active').checked = active;
                
                const editModal = new bootstrap.Modal(document.getElementById('editRegisterModal'));
                editModal.show();
            });
        });
        
        // Silmə düyməsinə klik olunduqda
        document.querySelectorAll('.delete-register').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                
                document.getElementById('delete_register_id').value = id;
                document.querySelector('#deleteRegisterModal .register-name').textContent = name;
                
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteRegisterModal'));
                deleteModal.show();
            });
        });
    </script>
</body>
</html> 