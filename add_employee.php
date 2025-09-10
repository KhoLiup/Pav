<?php
/**
 * add_employee.php
 * İşçilərin əlavə edilməsi, redaktə və silinməsi
 */
session_start();
require_once 'config.php';
require_once 'includes/helpers.php'; // Köməkçi funksiyaları daxil edirik

// İcazə yoxlanışı
check_page_permission($_SERVER['PHP_SELF']);

// CSRF token yaradılması
$csrf_token = generate_csrf_token();

// Əgər validateFinCode() funksiyası helpers.php-də mövcud deyilsə, burada təyin edirik
if (!function_exists('validateFinCode')) {
    function validateFinCode($fin) {
        // FIN kod 7 simvoldan ibarət olmalıdır (hərflər və rəqəmlər)
        return preg_match('/^[A-Z0-9]{7}$/', strtoupper($fin));
    }
}

// Əgər validateDate() funksiyası helpers.php-də mövcud deyilsə, burada təyin edirik
if (!function_exists('validateDate')) {
    function validateDate($date) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }
}

// POST əməliyyatı üçün yoxlama
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF token yoxlanması
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash_message('danger', 'Təhlükəsizlik xətası: Geçersiz token.');
        header('Location: add_employee.php');
        exit();
    }

    // Əməliyyat növünü müəyyənləşdiririk
    $action = $_POST['action'] ?? '';

    // CSV idxalı əməliyyatı
    if ($action === 'import_csv') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['csv_file']['tmp_name'];
            
            // CSV faylını oxuyuruq
            if (($handle = fopen($file, "r")) !== FALSE) {
                $row = 0;
                $success_count = 0;
                $error_count = 0;
                $errors = [];
                
                try {
                    $conn->beginTransaction();
                    
                    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                        $row++;
                        
                        // Başlıq sətrini keçirik
                        if ($row === 1) {
                            continue;
                        }
                        
                        // CSV formatı: Ad, Telefon, Maaş, Kateqoriya, Aktiv (1/0), Maksimum istirahət günləri, Başlanğıc tarixi
                        if (count($data) < 7) {
                            $error_count++;
                            $errors[] = "Sətir $row: Kifayət qədər məlumat yoxdur.";
                            continue;
                        }
                        
                        $name = sanitize($data[0]);
                        $phone_number = sanitize($data[1]);
                        $salary = (float)$data[2];
                        $category = sanitize($data[3]);
                        $is_active = (int)$data[4];
                        $max_vacation_days = (int)$data[5];
                        $start_date = sanitize($data[6]);
                        
                        // Doğrulama
                        if (empty($name) || !preg_match('/^994[0-9]{9}$/', $phone_number) || 
                            $salary <= 0 || empty($category) || $max_vacation_days < 0 || 
                            empty($start_date) || !validateDate($start_date)) {
                            $error_count++;
                            $errors[] = "Sətir $row: Məlumatlar düzgün formatda deyil.";
                            continue;
                        }
                        
                        // İşçini əlavə edirik
                        $stmt = $conn->prepare("INSERT INTO employees (name, phone_number, salary, category, is_active, max_vacation_days, start_date) 
                                                VALUES (:name, :phone_number, :salary, :category, :is_active, :max_vacation_days, :start_date)");
                        $stmt->execute([
                            ':name' => $name,
                            ':phone_number' => $phone_number,
                            ':salary' => $salary,
                            ':category' => $category,
                            ':is_active' => $is_active,
                            ':max_vacation_days' => $max_vacation_days,
                            ':start_date' => $start_date
                        ]);
                        
                        $new_employee_id = $conn->lastInsertId();
                        
                        $success_count++;
                    }
                    
                    $conn->commit();
                    
                    if ($success_count > 0) {
                        $message = "$success_count işçi uğurla idxal edildi.";
                        if ($error_count > 0) {
                            $message .= " $error_count sətr xəta səbəbindən idxal edilmədi.";
                        }
                        set_flash_message('success', $message);
                    } else {
                        set_flash_message('danger', 'Heç bir işçi idxal edilmədi. Xətalar: ' . implode(', ', $errors));
                    }
                    
                } catch (PDOException $e) {
                    $conn->rollBack();
                    set_flash_message('danger', 'İdxal zamanı xəta baş verdi: ' . $e->getMessage());
                }
                
                fclose($handle);
            } else {
                set_flash_message('danger', 'CSV faylı oxuna bilmədi.');
            }
        } else {
            set_flash_message('danger', 'Fayl yüklənmədi və ya xəta baş verdi.');
        }
        
        header('Location: add_employee.php');
        exit();
    }

    // İşçini Sil (Soft Delete)
    if ($action === 'delete') {
        $employee_id = (int)$_POST['employee_id'];

        try {
            // İşçinin mövcud olub olmadığını yoxlayırıq
            $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
            $stmt->execute([':id' => $employee_id]);
            $employee = $stmt->fetch();

            if ($employee) {
                // Yumuşaq silmə (Soft Delete) tətbiq edirik
                $stmt = $conn->prepare("UPDATE employees SET is_active = 0 WHERE id = :id");
                $stmt->execute([':id' => $employee_id]);

                // Silmə qeydini əlavə edirik
                $stmt = $conn->prepare("INSERT INTO employee_logs (employee_id, action, action_by, details) 
                                       VALUES (:employee_id, 'delete', :action_by, 'Soft delete applied')");
                $stmt->execute([
                    ':employee_id' => $employee_id,
                    ':action_by' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null
                ]);

                set_flash_message('success', 'İşçi uğurla silindi.');
            } else {
                set_flash_message('danger', 'Seçilmiş işçi tapılmadı.');
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
        }

        header('Location: add_employee.php');
        exit();
    }

    // İşçi Əlavə Et və ya Redaktə Et əməliyyatı
    if (in_array($action, ['add', 'edit'])) {
        // Məlumatların alınması və doğrulanması
        $full_name = sanitize($_POST['name'] ?? '');
        
        // Ad soyad sahəsini uyğun hissələrə bölürük
        $name_parts = explode(' ', $full_name, 3); // Maksimum 3 hissəyə bölürük: ad, soyad, ata adı
        
        $name = isset($name_parts[0]) ? $name_parts[0] : '';
        $surname = isset($name_parts[1]) ? $name_parts[1] : '';
        $father_name = isset($name_parts[2]) ? $name_parts[2] : '';
        
        $id_card_fin_code = sanitize($_POST['id_card_fin_code'] ?? '');
        $id_card_serial_number = sanitize($_POST['id_card_serial_number'] ?? '');
        $phone_number = sanitize($_POST['phone_number'] ?? '');
        $salary = (float)($_POST['salary'] ?? 0);
        $category = sanitize($_POST['category'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $employee_id = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : null;

        // Yeni sahələr: max_vacation_days və start_date
        $max_vacation_days = isset($_POST['max_vacation_days']) ? (int)$_POST['max_vacation_days'] : 0;
        $start_date = sanitize($_POST['start_date'] ?? '');
        $official_registration_date = sanitize($_POST['official_registration_date'] ?? '');
        $cash_register_id = ($category === 'kassir' && !empty($_POST['cash_register_id'])) ? (int)$_POST['cash_register_id'] : null;

        // Doğrulama xətası bayrağı
        $has_error = false;
        $error_message = '';

        // Doğrulama
        if (empty($full_name) || count($name_parts) < 3) {
            $has_error = true;
            $error_message = 'Ad, soyad və ata adı daxil edilməlidir (boşluqla ayrılmış şəkildə).';
        } elseif (!validateFinCode($id_card_fin_code)) {
            $has_error = true;
            $error_message = 'FIN kod düzgün formatda deyil. 7 simvoldan ibarət olmalıdır.';
        } elseif (empty($id_card_serial_number)) {
            $has_error = true;
            $error_message = 'Şəxsiyyət vəsiqəsinin seriya nömrəsi daxil edilməlidir.';
        } elseif (!empty($phone_number) && !validatePhoneNumber($phone_number)) {
            $has_error = true;
            $error_message = 'Telefon nömrəsi düzgün formatda deyil.';
        } elseif ($salary <= 0) {
            $has_error = true;
            $error_message = 'Maaş müsbət bir rəqəm olmalıdır.';
        } elseif (empty($category)) {
            $has_error = true;
            $error_message = 'Kateqoriya seçilməlidir.';
        } elseif ($category === 'kassir' && empty($cash_register_id)) {
            $has_error = true;
            $error_message = 'Kassir üçün kassa seçilməlidir.';
        } elseif ($max_vacation_days < 0) {
            $has_error = true;
            $error_message = 'Maksimum məzuniyyət günləri mənfi ola bilməz.';
        } elseif (empty($start_date) || !validateDate($start_date)) {
            $has_error = true;
            $error_message = 'Başlanğıc tarixi düzgün formatda deyil.';
        } elseif (empty($official_registration_date) || !validateDate($official_registration_date)) {
            $has_error = true;
            $error_message = 'Rəsmi qeydiyyat tarixi düzgün formatda deyil.';
        }

        // Əgər xəta yoxdursa, sorğunu icra edirik
        if (!$has_error) {
            try {
                $conn->beginTransaction();
                
                if ($action === 'add') {
                    // Yeni işçi əlavə edirik
                    $stmt = $conn->prepare("
                        INSERT INTO employees (
                            name, surname, father_name, id_card_fin_code, id_card_serial_number, 
                            phone_number, salary, category, max_vacation_days, start_date, 
                            official_registration_date, cash_register_id
                        ) VALUES (
                            :name, :surname, :father_name, :id_card_fin_code, :id_card_serial_number, 
                            :phone_number, :salary, :category, :max_vacation_days, :start_date, 
                            :official_registration_date, :cash_register_id
                        )
                    ");
                    $stmt->execute([
                        ':name' => $name,
                        ':surname' => $surname,
                        ':father_name' => $father_name,
                        ':id_card_fin_code' => $id_card_fin_code,
                        ':id_card_serial_number' => $id_card_serial_number,
                        ':phone_number' => $phone_number,
                        ':salary' => $salary,
                        ':category' => $category,
                        ':max_vacation_days' => $max_vacation_days,
                        ':start_date' => $start_date,
                        ':official_registration_date' => $official_registration_date,
                        ':cash_register_id' => $cash_register_id
                    ]);
                    
                    $new_employee_id = $conn->lastInsertId();
                    
                    set_flash_message('success', 'İşçi uğurla əlavə edildi.');
                } elseif ($action === 'edit' && $employee_id) {
                    // Mövcud işçi məlumatlarını əldə edirik
                    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
                    $stmt->execute([':id' => $employee_id]);
                    $old_data = $stmt->fetch();
                    
                    // Mövcud işçinin məlumatlarını yeniləyirik
                    $stmt = $conn->prepare("UPDATE employees 
                                            SET name = :name, surname = :surname, father_name = :father_name, id_card_fin_code = :id_card_fin_code, id_card_serial_number = :id_card_serial_number,
                                                phone_number = :phone_number, salary = :salary, category = :category, 
                                                is_active = :is_active, max_vacation_days = :max_vacation_days, start_date = :start_date, 
                                                official_registration_date = :official_registration_date, cash_register_id = :cash_register_id
                                            WHERE id = :id");
                    $stmt->execute([
                        ':name' => $name,
                        ':surname' => $surname,
                        ':father_name' => $father_name,
                        ':id_card_fin_code' => $id_card_fin_code,
                        ':id_card_serial_number' => $id_card_serial_number,
                        ':phone_number' => $phone_number,
                        ':salary' => $salary,
                        ':category' => $category,
                        ':is_active' => $is_active,
                        ':max_vacation_days' => $max_vacation_days,
                        ':start_date' => $start_date,
                        ':official_registration_date' => $official_registration_date,
                        ':cash_register_id' => $cash_register_id,
                        ':id' => $employee_id
                    ]);

                    set_flash_message('success', 'İşçi məlumatları uğurla yeniləndi.');
                }
                
                $conn->commit();
                header('Location: add_employee.php');
                exit();
            } catch (PDOException $e) {
                $conn->rollBack();
                set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
                header('Location: add_employee.php' . ($action === 'edit' && $employee_id ? "?action=edit&id=$employee_id" : ''));
                exit();
            }
        } else {
            // Xəta mesajını sessiyada saxlayırıq
            set_flash_message('danger', $error_message);
            header('Location: add_employee.php' . ($action === 'edit' && $employee_id ? "?action=edit&id=$employee_id" : ''));
            exit();
        }
    }

    // İşçi arxivləmə əməliyyatı
    if ($action === 'archive_employee') {
        $employee_id = (int)$_POST['employee_id'];
        
        try {
            // İşçinin mövcud olub olmadığını yoxlayırıq
            $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
            $stmt->execute([':id' => $employee_id]);
            $employee = $stmt->fetch();
            
            if ($employee) {
                // Arxivləmə əməliyyatı (is_active = 0)
                $stmt = $conn->prepare("UPDATE employees SET is_active = 0 WHERE id = :id");
                $stmt->execute([':id' => $employee_id]);
                
                set_flash_message('success', $employee['name'] . ' ' . ($employee['surname'] ?? '') . ' adlı işçi uğurla arxivləşdirildi.');
            } else {
                set_flash_message('danger', 'Seçilmiş işçi tapılmadı.');
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Arxivləmə zamanı xəta baş verdi: ' . $e->getMessage());
        }
        
        header('Location: add_employee.php');
        exit();
    }

    // İşçi aktivləşdirmə əməliyyatı
    if ($action === 'activate_employee') {
        $employee_id = (int)$_POST['employee_id'];
        
        try {
            // İşçinin mövcud olub olmadığını yoxlayırıq
            $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
            $stmt->execute([':id' => $employee_id]);
            $employee = $stmt->fetch();
            
            if ($employee) {
                // Aktivləşdirmə əməliyyatı (is_active = 1)
                $stmt = $conn->prepare("UPDATE employees SET is_active = 1 WHERE id = :id");
                $stmt->execute([':id' => $employee_id]);
                
                set_flash_message('success', $employee['name'] . ' ' . ($employee['surname'] ?? '') . ' adlı işçi uğurla aktivləşdirildi.');
            } else {
                set_flash_message('danger', 'Seçilmiş işçi tapılmadı.');
            }
        } catch (PDOException $e) {
            set_flash_message('danger', 'Aktivləşdirmə zamanı xəta baş verdi: ' . $e->getMessage());
        }
        
        header('Location: add_employee.php');
        exit();
    }
}

// Redaktə etmək üçün işçi ID-ni GET sorğusundan alırıq
$edit_employee = null;
if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id'])) {
    $employee_id = (int)$_GET['id'];

    try {
        // İşçinin məlumatlarını alırıq
        $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
        $stmt->execute([':id' => $employee_id]);
        $edit_employee = $stmt->fetch();

        if (!$edit_employee) {
            set_flash_message('danger', 'Seçilmiş işçi tapılmadı.');
            header('Location: add_employee.php');
            exit();
        }
    } catch (PDOException $e) {
        set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
        header('Location: add_employee.php');
        exit();
    }
}

// Mövcud işçiləri əldə edirik
try {
    // Status filtri
    $status = isset($_GET['status']) ? $_GET['status'] : 'active';
    
    // Where şərti
    $where_clause = "";
    if ($status === 'active') {
        $where_clause = "WHERE is_active = 1";
    } elseif ($status === 'passive') {
        $where_clause = "WHERE is_active = 0";
    }
    
    $stmt = $conn->prepare("SELECT id, name, surname, father_name, phone_number, salary, category, is_active, max_vacation_days, start_date 
                            FROM employees 
                            $where_clause 
                            ORDER BY name ASC");
    $stmt->execute();
    $employees = $stmt->fetchAll();
} catch (PDOException $e) {
    set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
    $employees = [];
}

// Bütün kateqoriyaları əldə edirik
try {
    // Əvvəlcə employee_categories cədvəli var mı yoxlayırıq
    $stmt = $conn->query("SHOW TABLES LIKE 'employee_categories'");
    if ($stmt->rowCount() > 0) {
        // Kateqoriyaları əldə edirik
        $stmt = $conn->prepare("SELECT category_name FROM employee_categories WHERE is_active = 1 ORDER BY category_name");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } else {
        // Kateqoriya cədvəli yoxdursa, employees cədvəlindən unique kateqoriyaları alırıq
        $stmt = $conn->prepare("SELECT DISTINCT category FROM employees WHERE category IS NOT NULL AND category != '' ORDER BY category");
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Default kateqoriyaları əlavə edirik əgər cədvəldə azdırsa
        if (count($categories) < 3) {
            $default_categories = ['kassir', 'menecer', 'satici'];
            foreach ($default_categories as $cat) {
                if (!in_array($cat, $categories)) {
                    $categories[] = $cat;
                }
            }
        }
    }
} catch (PDOException $e) {
    set_flash_message('danger', 'Kateqoriyaları əldə edərkən xəta baş verdi: ' . $e->getMessage());
    $categories = ['kassir', 'menecer', 'satici']; // Default kateqoriyalar
}

// Sehife başlığı
$page_title = "İşçi İdarəetmə Sistemi - İşçi Əlavə Et/Düzəliş Et";

// DataTables inisializasiyasını deaktiv etmək üçün
$disable_auto_datatables = true;

// Script
$page_specific_script = "
// İşçi adı filtirləməsi
$(document).ready(function() {
    $('#searchInput').on('keyup', function() {
        var value = $(this).val().toLowerCase();
        $('#employeeTable tbody tr').filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(value) > -1)
        });
    });
    
    // DataTable inisializasiyası
    $('#employeeTable').DataTable({
        'language': {
            'url': '//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json'
        },
        'pageLength': 25
    });
    
    // Status dəyişdikdə form submit edirik
    $('#status-filter').on('change', function() {
        this.form.submit();
    });
});
";

// CSS
$page_specific_css = "
.action-buttons .btn {
    margin-right: 5px;
}
";

// Aktiv kassaların siyahısını alırıq
$cash_registers = [];
try {
    $stmt = $conn->prepare("SELECT id, name FROM cash_registers WHERE is_active = 1 ORDER BY name ASC");
    $stmt->execute();
    $cash_registers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    set_flash_message('danger', 'Kassaların siyahısını əldə edərkən xəta baş verdi: ' . $e->getMessage());
}
?>

<?php include 'includes/header.php'; ?>

<?php if (isset($_SESSION['flash_message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show" role="alert">
        <i class="fas fa-<?php echo $_SESSION['flash_message']['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
        <?php echo $_SESSION['flash_message']['message']; ?>
        <?php if ($_SESSION['flash_message']['type'] === 'danger' && strpos($_SESSION['flash_message']['message'], 'Ad, soyad və ata adı daxil edilməlidir') !== false): ?>
        <hr>
        <p class="mb-0"><strong>Qeyd:</strong> "Ad Soyad" sahəsinə ad, soyad və ata adını boşluqla ayıraraq daxil etməlisiniz. <br>Məsələn: <em>Əli Məmmədov Aslan</em></p>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Bağla"></button>
    </div>
    <?php unset($_SESSION['flash_message']); ?>
<?php endif; ?>

<div class="row mb-4">
    <div class="col-md-6">
        <h2><?php echo $edit_employee ? 'İşçi Məlumatlarını Yenilə' : 'Yeni İşçi Əlavə Et'; ?></h2>
    </div>
    <div class="col-md-6 text-md-end">
        <a href="employees.php" class="btn btn-primary">
            <i class="fas fa-list me-1"></i> İşçi Siyahısı
        </a>
    </div>
</div>

<!-- Status filtr düymələri -->
<div class="card mb-4">
    <div class="card-header bg-light">
        <h5 class="mb-0"><i class="fas fa-filter me-1"></i> Filtrlər</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-center">
            <div class="col-md-4">
                <label for="status-filter" class="form-label">Status</label>
                <select class="form-select" id="status-filter" name="status">
                    <option value="active" <?php echo ($status === 'active') ? 'selected' : ''; ?>>Aktiv İşçilər</option>
                    <option value="passive" <?php echo ($status === 'passive') ? 'selected' : ''; ?>>Passiv İşçilər (Arxiv)</option>
                    <option value="all" <?php echo ($status === 'all') ? 'selected' : ''; ?>>Bütün İşçilər</option>
                </select>
            </div>
            <div class="col-md-4">
                <label for="search-input" class="form-label">Ad ilə axtar</label>
                <input type="text" id="searchInput" class="form-control" placeholder="İşçi adı ilə axtar...">
            </div>
            <div class="col-md-4 text-end">
                <a href="add_category.php" class="btn btn-outline-primary">
                    <i class="fas fa-tags me-1"></i> Kateqoriyaları İdarə Et
                </a>
            </div>
        </form>
    </div>
</div>

<!-- İşçi əlavə etmə və redaktə etmə forması -->
<div class="card mb-4">
    <div class="card-header bg-primary text-white">
        <h5 class="card-title mb-0"><?php echo $edit_employee ? 'İşçi Məlumatlarını Yenilə' : 'Yeni İşçi Əlavə Et'; ?></h5>
    </div>
    <div class="card-body">
        <form method="POST" action="add_employee.php" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="<?php echo $edit_employee ? 'edit' : 'add'; ?>">
            <?php if ($edit_employee): ?>
                <input type="hidden" name="employee_id" value="<?php echo htmlspecialchars($edit_employee['id']); ?>">
            <?php endif; ?>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="name" class="form-label">Ad Soyad:</label>
                    <input type="text" name="name" id="name" class="form-control" required 
                           pattern="[A-Za-zƏəĞğÇçŞşÜüÖöİı\s]+" 
                           title="Yalnız hərflər və boşluqlar daxil edilə bilər" 
                           placeholder="Ad Soyad Ata adı" 
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['name'] . ' ' . $edit_employee['surname'] . ' ' . $edit_employee['father_name']) : ''; ?>">
                    <div class="invalid-feedback">
                        Ad daxil edilməlidir və yalnız hərflərdən ibarət olmalıdır.
                    </div>
                    <div class="form-text">
                        Ad, soyad və ata adını boşluqla ayıraraq daxil edin. Məsələn: Əli Məmmədov Aslan
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="phone_number" class="form-label">Telefon Nömrəsi:</label>
                    <div class="input-group">
                        <span class="input-group-text">+</span>
                        <input type="text" name="phone_number" id="phone_number" class="form-control" required 
                               pattern="^994[0-9]{9}$" 
                               title="Telefon nömrəsi 994 ilə başlamalı və 9 rəqəm olmalıdır" 
                               placeholder="994501234567" 
                               data-type="phone"
                               value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['phone_number']) : ''; ?>">
                    </div>
                    <div class="form-text">Telefon nömrəsi 994 ilə başlamalı və 9 rəqəm olmalıdır (məsələn, 994501234567).</div>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="id_card_fin_code" class="form-label">FIN Kod:</label>
                    <input type="text" name="id_card_fin_code" id="id_card_fin_code" class="form-control" required 
                           pattern="[A-Za-z0-9]{7}" 
                           title="FIN kod 7 simvoldan ibarət olmalıdır" 
                           placeholder="5AZRBJ7" 
                           maxlength="7"
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['id_card_fin_code']) : ''; ?>">
                    <div class="form-text">FIN kod 7 simvoldan ibarət olmalıdır və şəxsiyyət vəsiqəsində qeyd olunub.</div>
                </div>
                <div class="col-md-6">
                    <label for="id_card_serial_number" class="form-label">Şəxsiyyət vəsiqəsinin seriya nömrəsi:</label>
                    <input type="text" name="id_card_serial_number" id="id_card_serial_number" class="form-control" required 
                           placeholder="AZE12345678" 
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['id_card_serial_number']) : ''; ?>">
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="salary" class="form-label">Maaş (AZN):</label>
                    <div class="input-group">
                        <input type="number" step="0.01" name="salary" id="salary" class="form-control" required 
                               min="0" title="Maaş sıfırdan böyük olmalıdır" 
                               placeholder="Maaşı daxil edin" 
                               value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['salary']) : ''; ?>">
                        <span class="input-group-text">AZN</span>
                    </div>
                </div>
                <div class="col-md-6">
                    <label for="category" class="form-label">Kateqoriya:</label>
                    <select name="category" id="category" class="form-select" required>
                        <option value="">Seçin</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat; ?>" <?php echo ($edit_employee && $edit_employee['category'] === $cat) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cat)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3 kassir-options" <?php echo ($edit_employee && $edit_employee['category'] !== 'kassir') ? 'style="display:none;"' : ''; ?>>
                <label for="cash_register_id" class="col-sm-3 col-form-label">Kassa:</label>
                <div class="col-sm-9">
                    <select class="form-select" id="cash_register_id" name="cash_register_id">
                        <option value="">Seçin</option>
                        <?php foreach ($cash_registers as $register): ?>
                            <option value="<?php echo $register['id']; ?>" <?php echo (isset($edit_employee['cash_register_id']) && $edit_employee['cash_register_id'] == $register['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($register['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="max_vacation_days" class="form-label">Maksimum İstirahət Günləri:</label>
                    <input type="number" name="max_vacation_days" id="max_vacation_days" class="form-control" required 
                           min="0" title="Maksimum istirahət günləri sıfır və ya daha böyük olmalıdır" 
                           placeholder="Maksimum istirahət günləri" 
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['max_vacation_days']) : 0; ?>">
                </div>
                <div class="col-md-6">
                    <label for="start_date" class="form-label">Başlanğıc Tarixi:</label>
                    <input type="date" name="start_date" id="start_date" class="form-control" required 
                           placeholder="Başlanğıc tarixini seçin" 
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['start_date']) : ''; ?>">
                </div>
            </div>

            <div class="row mb-3">
                <div class="col-md-6">
                    <label for="official_registration_date" class="form-label">Rəsmi Qeydiyyat Tarixi:</label>
                    <input type="date" name="official_registration_date" id="official_registration_date" class="form-control" required 
                           placeholder="Rəsmi qeydiyyat tarixini seçin" 
                           value="<?php echo $edit_employee ? htmlspecialchars($edit_employee['official_registration_date']) : ''; ?>">
                    <div class="form-text">İşçinin rəsmi iş müqaviləsinin tarixini daxil edin</div>
                </div>
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" name="is_active" id="is_active" class="form-check-input" 
                    <?php 
                        if ($edit_employee) {
                            echo $edit_employee['is_active'] ? 'checked' : '';
                        } else {
                            echo 'checked';
                        }
                    ?>
                >
                <label for="is_active" class="form-check-label">Aktivdir</label>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-1"></i> <?php echo $edit_employee ? 'Yenilə' : 'Əlavə Et'; ?>
                </button>
                <?php if ($edit_employee): ?>
                    <a href="add_employee.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i> İmtina
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- CSV İdxalı üçün form -->
<div class="card mb-4">
    <div class="card-header bg-success text-white">
        <h5 class="card-title mb-0">CSV Faylından İşçiləri İdxal Et</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="add_employee.php" enctype="multipart/form-data" class="needs-validation" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="import_csv">
            
            <div class="mb-3">
                <label for="csv_file" class="form-label">CSV Faylı Seçin:</label>
                <input type="file" name="csv_file" id="csv_file" class="form-control" accept=".csv" required>
                <div class="form-text">
                    CSV faylı aşağıdakı başlıqlarla olmalıdır: Ad Soyad, Telefon Nömrəsi, Maaş, Kateqoriya, Aktiv (1/0), Maksimum istirahət günləri, Başlanğıc tarixi
                </div>
            </div>
            
            <div class="mb-3">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-file-import me-1"></i> İdxal Et
                </button>
                <a href="#" class="btn btn-outline-secondary ms-2" onclick="downloadSampleCSV()">
                    <i class="fas fa-download me-1"></i> Nümunə CSV Yüklə
                </a>
            </div>
        </form>
    </div>
</div>

<script>
function downloadSampleCSV() {
    // CSV nümunəsi yaradırıq
    const csvContent = "Ad Soyad,Telefon Nömrəsi,Maaş,Kateqoriya,Aktiv,Maksimum istirahət günləri,Başlanğıc tarixi\n" +
                      "Əli Məmmədov,994501234567,800,Kassir,1,20,2023-01-15\n" +
                      "Aygün Əliyeva,994551234567,900,Satıcı,1,20,2023-02-01";
    
    // CSV faylını yükləmək üçün link yaradırıq
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    const url = URL.createObjectURL(blob);
    
    link.setAttribute("href", url);
    link.setAttribute("download", "isci_numune.csv");
    link.style.visibility = 'hidden';
    
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Kateqoriya dəyişdikdə kassa seçim sahəsini göstər/gizlət
document.getElementById('category').addEventListener('change', function() {
    const kassirOptions = document.querySelector('.kassir-options');
    if (this.value === 'kassir') {
        kassirOptions.style.display = 'block';
        document.getElementById('cash_register_id').setAttribute('required', 'required');
    } else {
        kassirOptions.style.display = 'none';
        document.getElementById('cash_register_id').removeAttribute('required');
    }
});

// Bootstrap validation və custom validation
(function() {
    'use strict';
    
    // Validation stillərini aktivləşdiririk
    const forms = document.querySelectorAll('.needs-validation');
    
    // FIN kod validasiyası
    const finCodeInput = document.getElementById('id_card_fin_code');
    if (finCodeInput) {
        finCodeInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
            const isValid = /^[A-Z0-9]{7}$/.test(this.value);
            if (this.value.length > 0) {
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            }
        });
    }
    
    // Telefon nömrəsi validasiyası
    const phoneInput = document.getElementById('phone_number');
    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            // Rəqəmlərdən başqa hər şeyi təmizləyirik
            this.value = this.value.replace(/[^\d]/g, '');
            
            // 994 ilə başlamadığını aşkarlayırıq
            if (this.value.length > 0 && !this.value.startsWith('994')) {
                // 994 ilə başlamadığı halda əlavə edirik
                if (this.value.startsWith('0')) {
                    this.value = '994' + this.value.substr(1);
                } else {
                    this.value = '994' + this.value;
                }
            }
            
            const isValid = /^994[0-9]{9}$/.test(this.value);
            if (this.value.length > 0) {
                if (isValid) {
                    this.classList.remove('is-invalid');
                    this.classList.add('is-valid');
                } else {
                    this.classList.remove('is-valid');
                    this.classList.add('is-invalid');
                }
            }
        });
    }
    
    // Form submit olduqda validation
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                
                // İlk xətalı sahəyə fokus edirik
                const invalidInputs = form.querySelectorAll(':invalid');
                if (invalidInputs.length > 0) {
                    invalidInputs[0].focus();
                }
            }
            
            form.classList.add('was-validated');
        }, false);
    });
})();
</script>

<!-- Mövcud işçilərin siyahısı -->
<div class="card">
    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">
            <i class="fas fa-list me-1"></i> 
            <?php 
                if ($status === 'active') echo 'Aktiv İşçilər';
                elseif ($status === 'passive') echo 'Passiv İşçilər (Arxiv)';
                else echo 'Bütün İşçilər';
            ?>
            <span class="badge bg-light text-dark ms-2"><?php echo count($employees); ?></span>
        </h5>
        <div>
            <?php if ($status === 'active' || $status === 'all'): ?>
            <a href="employees.php" class="btn btn-sm btn-info">
                <i class="fas fa-external-link-alt me-1"></i> Ətraflı Göstər
            </a>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <input type="text" id="searchInput" class="form-control" placeholder="İşçi adı ilə axtar...">
        </div>
        
        <?php if (count($employees) > 0): ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped datatable" id="employeeTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Ad</th>
                            <th>Telefon Nömrəsi</th>
                            <th>Maaş (AZN)</th>
                            <th>Kateqoriya</th>
                            <th>Maks. İstirahət Günləri</th>
                            <th>Başlanğıc Tarixi</th>
                            <th>Status</th>
                            <th class="text-center">Əməliyyatlar</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($employees as $emp): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($emp['name']); ?></td>
                                <td><?php echo htmlspecialchars($emp['phone_number']); ?></td>
                                <td class="text-end"><?php echo number_format($emp['salary'], 2); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($emp['category'])); ?></td>
                                <td class="text-center"><?php echo htmlspecialchars($emp['max_vacation_days']); ?></td>
                                <td><?php echo formatDate($emp['start_date']); ?></td>
                                <td class="text-center">
                                    <?php if ($emp['is_active']): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Passiv</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center action-buttons">
                                    <a href="add_employee.php?action=edit&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-warning" data-bs-toggle="tooltip" title="Düzəliş Et">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    
                                    <a href="employee_payments.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="Ödənişlər">
                                        <i class="fas fa-money-check-alt"></i>
                                    </a>
                                    
                                    <a href="employee_debts.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-primary" data-bs-toggle="tooltip" title="Borclar">
                                        <i class="fas fa-hand-holding-usd"></i>
                                    </a>
                                    
                                    <?php if ($emp['is_active']): ?>
                                    <!-- İşçini Arxivlə -->
                                    <form method="POST" action="add_employee.php" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="archive_employee">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-danger" data-bs-toggle="tooltip" title="Arxivlə" 
                                               onclick="return confirm('<?php echo htmlspecialchars($emp['name']); ?> adlı işçini arxivləmək istədiyinizə əminsiniz?');">
                                            <i class="fas fa-archive"></i>
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <!-- İşçini Aktivləşdir -->
                                    <form method="POST" action="add_employee.php" style="display:inline-block;">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                        <input type="hidden" name="action" value="activate_employee">
                                        <input type="hidden" name="employee_id" value="<?php echo $emp['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-success" data-bs-toggle="tooltip" title="Aktivləşdir" 
                                               onclick="return confirm('<?php echo htmlspecialchars($emp['name']); ?> adlı işçini aktivləşdirmək istədiyinizə əminsiniz?');">
                                            <i class="fas fa-check-circle"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i> Mövcud işçi yoxdur.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!isset($disable_auto_datatables) || !$disable_auto_datatables): ?>
// DataTables inisializasiyası
<?php endif; ?>