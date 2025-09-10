<?php

// employees.php

session_start();

require_once 'config.php';
require_once 'includes/helpers.php'; // Köməkçi funksiyaları daxil edirik

// İcazə yoxlanışı
check_page_permission($_SERVER['PHP_SELF']);

// CSRF token yaradılması
$csrf_token = generate_csrf_token();

// İstifadəçi yoxlaması
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Toplu əməliyyat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    // CSRF token yoxlanması
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        set_flash_message('danger', 'Təhlükəsizlik xətası: Geçersiz token.');
        header('Location: employees.php');
        exit();
    }

    $action = $_POST['bulk_action'];
    $selected_ids = isset($_POST['selected_ids']) ? $_POST['selected_ids'] : [];

    if (!empty($selected_ids)) {
        try {
            $conn->beginTransaction();
            
            if ($action === 'archive') {
                // İşçiləri arxivləşdir (passiv et)
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $stmt = $conn->prepare("UPDATE employees SET is_active = 0 WHERE id IN ($placeholders)");
                
                // Parametrləri bağla
                foreach ($selected_ids as $i => $id) {
                    $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
                }
                
                $stmt->execute();
                
                set_flash_message('success', count($selected_ids) . ' işçi uğurla arxivləşdirildi.');
            } elseif ($action === 'activate') {
                // İşçiləri aktivləşdir
                $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
                $stmt = $conn->prepare("UPDATE employees SET is_active = 1 WHERE id IN ($placeholders)");
                
                // Parametrləri bağla
                foreach ($selected_ids as $i => $id) {
                    $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
                }
                
                $stmt->execute();
                
                set_flash_message('success', count($selected_ids) . ' işçi uğurla aktivləşdirildi.');
            }
            
            $conn->commit();
        } catch (PDOException $e) {
            $conn->rollBack();
            set_flash_message('danger', 'Xəta baş verdi: ' . $e->getMessage());
        }
    } else {
        set_flash_message('warning', 'Heç bir işçi seçilməyib.');
    }
    
    header('Location: employees.php?' . http_build_query($_GET));
    exit();
}

// Axtarış sorğusunu emal edin
$search = '';
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Status filtri (default olaraq aktiv)
$status = isset($_GET['status']) ? $_GET['status'] : 'active';

// Kateqoriya filtri
$category = isset($_GET['category']) ? $_GET['category'] : '';

// Səhifələmə parametrlərini təyin edin
$limit = 20; // Hər səhifədə göstərilən işçi sayı
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// SQL sorğusu üçün where şərtlərini hazırlayırıq
$where_conditions = [];
$params = [];

// Status filtiri
if ($status === 'active') {
    $where_conditions[] = "is_active = 1";
} elseif ($status === 'passive') {
    $where_conditions[] = "is_active = 0";
}

// Axtarış filtiri
if ($search !== '') {
    $where_conditions[] = "(name LIKE :search OR surname LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

// Kateqoriya filtiri
if ($category !== '') {
    $where_conditions[] = "category = :category";
    $params[':category'] = $category;
}

// SQL where şərtini formalaşdırırıq
$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Toplam işçi sayını əldə edin
try {
    $sql = "SELECT COUNT(*) FROM employees $where_clause";
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    $total_employees = $stmt->fetchColumn();
} catch (PDOException $e) {
    set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
    header('Location: dashboard.php');
    exit();
}

// Toplam səhifə sayını hesablayın
$total_pages = ceil($total_employees / $limit);

// İşçilərin siyahısını əldə edin
try {
    $sql = "SELECT id, name, surname, father_name, phone_number, salary, category, is_active, start_date 
            FROM employees $where_clause 
            ORDER BY name ASC 
            LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    set_flash_message('danger', 'Bir xəta baş verdi: ' . $e->getMessage());
    header('Location: dashboard.php');
    exit();
}

// Bütün kateqoriyaları əldə et
try {
    $stmt = $conn->query("SELECT DISTINCT category FROM employees ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    set_flash_message('danger', 'Kateqoriyaları əldə edərkən xəta baş verdi: ' . $e->getMessage());
    $categories = [];
}

// Səhifə başlığı
$page_title = "İşçi İdarəetmə Sistemi - İşçilərin Siyahısı";

// DataTables və digər JS/CSS
$page_specific_css = "
.action-buttons .btn {
    margin-right: 5px;
}
.status-badge {
    font-size: 0.85rem;
    padding: 0.35em 0.65em;
}
";

$page_specific_script = "
$(document).ready(function() {
    // DataTables inisializasiyası
    var dataTable = $('#employeeTable').DataTable({
        'language': {
            'url': '//cdn.datatables.net/plug-ins/1.13.5/i18n/Azerbaijan.json'
        },
        'pageLength': 25,
        'order': [[1, 'asc']],
        'columnDefs': [
            { 'orderable': false, 'targets': [0, 8] }
        ],
        'dom': '<\"row\"<\"col-sm-12 col-md-6\"l><\"col-sm-12 col-md-6\"f>>' +
               '<\"row\"<\"col-sm-12\"tr>>' +
               '<\"row\"<\"col-sm-12 col-md-5\"i><\"col-sm-12 col-md-7\"p>>',
        'responsive': true
    });
    
    // Status filtrini dəyişdikdə formanı göndərin
    $('#status-filter').change(function() {
        $(this).closest('form').submit();
    });
    
    // Seçilən checkbox sayına görə toplu əməliyyat düymələrini aktivləşdirən/deaktivləşdirən funksiya
    function updateBulkActionButtons() {
        var checkedCount = $('.employee-checkbox:checked').length;
        if (checkedCount > 0) {
            $('.bulk-action-btn').prop('disabled', false);
            $('#selected-count').text(checkedCount + ' işçi seçildi');
            $('#selected-count-wrapper').show();
        } else {
            $('.bulk-action-btn').prop('disabled', true);
            $('#selected-count-wrapper').hide();
        }
    }
    
    // Hamısını seç/ləğv et checkboxu
    $('#select-all').change(function() {
        $('.employee-checkbox').prop('checked', $(this).prop('checked'));
        updateBulkActionButtons();
    });
    
    // Hər hansı checkbox dəyişdikdə toplu əməliyyat düymələrini yenilə
    $('.employee-checkbox').change(function() {
        updateBulkActionButtons();
    });
    
    // Checkbox dəyişdikdə selected-ids hidden inputunu yenilə
    $(document).on('change', '.employee-checkbox, #select-all', function() {
        var selectedIds = $('.employee-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        
        $('#selected-ids').val(selectedIds.join(','));
        updateBulkActionButtons();
    });
    
    // Arxivləşdirmə və aktivləşdirmə modallarını göstər
    $('.show-archive-modal').click(function(e) {
        e.preventDefault();
        var employeeId = $(this).data('id');
        var employeeName = $(this).data('name');
        $('#archive-employee-id').val(employeeId);
        $('#archive-employee-name').text(employeeName);
        $('#archiveModal').modal('show');
    });
    
    $('.show-activate-modal').click(function(e) {
        e.preventDefault();
        var employeeId = $(this).data('id');
        var employeeName = $(this).data('name');
        $('#activate-employee-id').val(employeeId);
        $('#activate-employee-name').text(employeeName);
        $('#activateModal').modal('show');
    });
    
    // Toplu arxivləşdirmə modalını göstər
    $('#show-bulk-archive-modal').click(function(e) {
        e.preventDefault();
        var selectedCount = $('.employee-checkbox:checked').length;
        $('#bulk-archive-count').text(selectedCount);
        
        // Seçilmiş işçilərin ID-lərini hidden inputa əlavə et
        var selectedIds = $('.employee-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        $('#bulk-selected-ids').val(selectedIds.join(','));
        
        $('#bulkArchiveModal').modal('show');
    });
    
    // Toplu aktivləşdirmə modalını göstər
    $('#show-bulk-activate-modal').click(function(e) {
        e.preventDefault();
        var selectedCount = $('.employee-checkbox:checked').length;
        $('#bulk-activate-count').text(selectedCount);
        
        // Seçilmiş işçilərin ID-lərini hidden inputa əlavə et
        var selectedIds = $('.employee-checkbox:checked').map(function() {
            return $(this).val();
        }).get();
        $('#bulk-selected-ids').val(selectedIds.join(','));
        
        $('#bulkActivateModal').modal('show');
    });
    
    // İşçi adı ilə axtarış
    $('#searchInput').on('keyup', function() {
        dataTable.search($(this).val()).draw();
    });
    
    // İlk yüklənmədə düymələri yenilə
    updateBulkActionButtons();
});
";

include 'includes/header.php';
?>

<div class="container-fluid px-4">
    <?php if (isset($_SESSION['flash_message'])): ?>
        <div class="alert alert-<?php echo $_SESSION['flash_message']['type']; ?> alert-dismissible fade show mt-3" role="alert">
            <i class="fas fa-<?php echo $_SESSION['flash_message']['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <?php echo $_SESSION['flash_message']['message']; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Bağla"></button>
        </div>
        <?php unset($_SESSION['flash_message']); ?>
    <?php endif; ?>

    <div class="row mb-3">
        <div class="col-md-6">
            <h2><i class="fas fa-users me-2"></i>İşçilər</h2>
        </div>
        <div class="col-md-6 text-md-end">
            <a href="add_employee.php" class="btn btn-success">
                <i class="fas fa-plus me-1"></i> Yeni İşçi Əlavə Et
            </a>
            <a href="add_category.php" class="btn btn-primary">
                <i class="fas fa-tags me-1"></i> Kateqoriya İdarə Et
            </a>
        </div>
    </div>

    <!-- Filtr paneli -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0"><i class="fas fa-filter me-1"></i> Filtrlər</h5>
        </div>
        <div class="card-body">
            <form method="GET" action="employees.php" class="row g-3">
                <div class="col-md-3">
                    <label for="search" class="form-label">Ad ilə axtar</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" name="search" id="search" class="form-control" placeholder="Ad və ya soyad" value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <label for="category" class="form-label">Kateqoriya</label>
                    <select name="category" id="category" class="form-select">
                        <option value="">Bütün kateqoriyalar</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucfirst($cat)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select" onchange="this.form.submit()">
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Aktiv</option>
                        <option value="passive" <?php echo $status === 'passive' ? 'selected' : ''; ?>>Passiv (Arxiv)</option>
                        <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>Hamısı</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary me-2">
                        <i class="fas fa-search me-1"></i> Axtar
                    </button>
                    <a href="employees.php" class="btn btn-outline-secondary">
                        <i class="fas fa-sync-alt me-1"></i> Sıfırla
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- İşçilərin Siyahısı -->
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-1"></i> 
                <?php 
                    if ($status === 'active') echo 'Aktiv İşçilər';
                    elseif ($status === 'passive') echo 'Passiv İşçilər (Arxiv)';
                    else echo 'Bütün İşçilər';
                ?>
                <span class="badge bg-primary ms-2"><?php echo $total_employees; ?></span>
            </h5>
            <div class="btn-group">
                <a href="add_employee.php" class="btn btn-success btn-sm">
                    <i class="fas fa-plus me-1"></i> Yeni İşçi
                </a>
                <a href="add_employee.php?action=import" class="btn btn-primary btn-sm">
                    <i class="fas fa-file-import me-1"></i> Excel İmport
                </a>
            </div>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <div id="selected-count-wrapper" style="display: none;" class="alert alert-info py-2">
                        <i class="fas fa-info-circle me-1"></i> <span id="selected-count">0 işçi seçildi</span>
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <button type="button" id="show-bulk-archive-modal" class="btn btn-warning btn-sm bulk-action-btn" disabled>
                        <i class="fas fa-archive me-1"></i> Seçilənləri Arxivlə
                    </button>
                    <?php if ($status === 'passive' || $status === 'all'): ?>
                    <button type="button" id="show-bulk-activate-modal" class="btn btn-info btn-sm bulk-action-btn" disabled>
                        <i class="fas fa-check-circle me-1"></i> Seçilənləri Aktivləşdir
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Toplu əməliyyat üçün gizli form -->
            <form id="bulk-action-form" method="POST" action="" class="d-none">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="bulk_action" id="bulk-action-type" value="">
                <input type="hidden" name="selected_ids" id="selected-ids" value="">
            </form>

            <?php if (count($employees) > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover table-striped table-sm" id="employeeTable">
                        <thead class="table-dark">
                            <tr>
                                <th class="text-center" width="40">
                                    <input type="checkbox" id="selectAll" class="form-check-input">
                                </th>
                                <th>Ad Soyad</th>
                                <th>Telefon Nömrəsi</th>
                                <th>Maaş (AZN)</th>
                                <th>Kateqoriya</th>
                                <th>Başlanğıc Tarixi</th>
                                <th>Status</th>
                                <th class="text-center">Əməliyyatlar</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($employees as $emp): ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" class="form-check-input employee-checkbox" name="selected_ids[]" value="<?php echo $emp['id']; ?>" form="bulk-form">
                                    </td>
                                    <td>
                                        <?php 
                                            $full_name = implode(' ', array_filter([
                                                htmlspecialchars($emp['name'] ?? ''),
                                                htmlspecialchars($emp['surname'] ?? ''),
                                                htmlspecialchars($emp['father_name'] ?? '')
                                            ]));
                                            echo $full_name;
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($emp['phone_number'] ?? ''); ?></td>
                                    <td class="text-end"><?php echo number_format($emp['salary'], 2); ?> ₼</td>
                                    <td><?php echo htmlspecialchars(ucfirst($emp['category'] ?? '')); ?></td>
                                    <td><?php echo !empty($emp['start_date']) ? date('d.m.Y', strtotime($emp['start_date'])) : ''; ?></td>
                                    <td class="text-center">
                                        <?php if ($emp['is_active']): ?>
                                            <span class="badge bg-success status-badge">Aktiv</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary status-badge">Passiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center action-buttons">
                                        <a href="add_employee.php?action=edit&id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-warning" data-bs-toggle="tooltip" title="Düzəliş Et">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        
                                        <a href="employee_payments.php?employee_id=<?php echo $emp['id']; ?>" class="btn btn-sm btn-info" data-bs-toggle="tooltip" title="Ödənişlər">
                                            <i class="fas fa-money-check-alt"></i>
                                        </a>
                                        
                                        <?php if ($emp['is_active']): ?>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#archiveModal<?php echo $emp['id']; ?>" title="Arxivlə">
                                                <i class="fas fa-archive"></i>
                                            </button>
                                            
                                            <!-- Arxivləmə Modal -->
                                            <div class="modal fade" id="archiveModal<?php echo $emp['id']; ?>" tabindex="-1" aria-labelledby="archiveModalLabel<?php echo $emp['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="archiveModalLabel<?php echo $emp['id']; ?>">İşçini Arxivlə</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p><strong><?php echo $full_name; ?></strong> adlı işçini arxivləmək istədiyinizə əminsiniz?</p>
                                                            <p class="text-warning"><small>Bu əməliyyat işçini passiv vəziyyətə çevirəcək.</small></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İmtina</button>
                                                            <form method="POST" action="employees.php">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                                <input type="hidden" name="bulk_action" value="archive">
                                                                <input type="hidden" name="selected_ids[]" value="<?php echo $emp['id']; ?>">
                                                                <button type="submit" class="btn btn-danger">Arxivlə</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#activateModal<?php echo $emp['id']; ?>" title="Aktivləşdir">
                                                <i class="fas fa-check-circle"></i>
                                            </button>
                                            
                                            <!-- Aktivləşdirmə Modal -->
                                            <div class="modal fade" id="activateModal<?php echo $emp['id']; ?>" tabindex="-1" aria-labelledby="activateModalLabel<?php echo $emp['id']; ?>" aria-hidden="true">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header">
                                                            <h5 class="modal-title" id="activateModalLabel<?php echo $emp['id']; ?>">İşçini Aktivləşdir</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p><strong><?php echo $full_name; ?></strong> adlı işçini aktivləşdirmək istədiyinizə əminsiniz?</p>
                                                            <p class="text-success"><small>Bu əməliyyat işçini aktiv vəziyyətə çevirəcək.</small></p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İmtina</button>
                                                            <form method="POST" action="employees.php">
                                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                                <input type="hidden" name="bulk_action" value="activate">
                                                                <input type="hidden" name="selected_ids[]" value="<?php echo $emp['id']; ?>">
                                                                <button type="submit" class="btn btn-success">Aktivləşdir</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Hidden form for bulk actions -->
                <form id="bulk-form" method="POST" action="employees.php?<?php echo http_build_query($_GET); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="bulk_action" id="bulk_action_input">
                </form>
                
            <?php else: ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i> İşçi tapılmadı.
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Seçilmiş işçilərin ID-lərini forma elementlərinə əlavə etmək üçün
$(document).ready(function() {
    $('.bulk-action-form').on('submit', function(e) {
        e.preventDefault(); // Formanı dayandır
        
        // Seçilmiş işçi ID-lərini al
        var selectedIds = [];
        $('.employee-checkbox:checked').each(function() {
            selectedIds.push($(this).val());
        });
        
        if (selectedIds.length === 0) {
            alert('Zəhmət olmasa, ən azı bir işçi seçin!');
            return false;
        }
        
        // Hansı əməliyyatın ediləcəyini müəyyən et
        var action = $(this).find('button[type=submit]').val();
        var actionText = action === 'archive' ? 'arxivləşdirmək' : 'aktivləşdirmək';
        
        // Təsdiq dialoqunu göstər
        if (confirm(selectedIds.length + ' işçini ' + actionText + ' istədiyinizə əminsiniz?')) {
            // Bulk form-a action və ID-ləri əlavə et
            $('#bulk_action_input').val(action);
            
            // Köhnə ID input-ları təmizlə
            $('#bulk-form').find('input[name="selected_ids[]"]').remove();
            
            // Yeni ID-ləri əlavə et
            for (var i = 0; i < selectedIds.length; i++) {
                $('#bulk-form').append(
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'selected_ids[]',
                        value: selectedIds[i]
                    })
                );
            }
            
            // Formanı göndər
            $('#bulk-form').submit();
        }
    });
    
    // Hamısını seç checkbox-u
    $('#selectAll').on('click', function() {
        $('.employee-checkbox').prop('checked', this.checked);
        toggleBulkActionButtons();
    });
    
    // İşçi checkbox-ları
    $('.employee-checkbox').on('click', function() {
        if (!this.checked) {
            $('#selectAll').prop('checked', false);
        } else if ($('.employee-checkbox:checked').length === $('.employee-checkbox').length) {
            $('#selectAll').prop('checked', true);
        }
        toggleBulkActionButtons();
    });
    
    // Bulk action düymələrini aktivləşdir/deaktivləşdir
    function toggleBulkActionButtons() {
        var anyChecked = $('.employee-checkbox:checked').length > 0;
        $('.bulk-action-btn').prop('disabled', !anyChecked);
    }
    
    // İlk yükləmədə bulk action düymələrini yoxla
    toggleBulkActionButtons();
});
</script>