<?php
// get_report_details.php
// Kassa hesabatı detallarını göstərən AJAX faylı

// Xətaların göstərilməsi (inkişaf mühiti üçün)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sessiya
session_start();

// Verilənlər bazasına bağlantı
require_once 'config.php';

// Helper funksiyalarını əlavə et
// Əvvəlcə includes/helpers.php yükləyirik (əgər varsa)
if (file_exists('includes/helpers.php')) {
    require_once 'includes/helpers.php';
}
// Sonra root helpers.php yükləyirik
require_once 'helpers.php';

// İstifadəçi yoxlaması
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Hesabat ID-ni alırıq
$report_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($report_id <= 0) {
    echo '<div class="alert alert-danger">Xəta: Hesabat ID-si düzgün deyil.</div>';
    exit();
}

try {
    // Hesabat məlumatlarını əldə edirik
    $stmt = $conn->prepare("
        SELECT 
            cr.*,
            e.name AS employee_name,
            cr2.name AS cash_register_name
        FROM cash_reports cr
        JOIN employees e ON cr.employee_id = e.id
        LEFT JOIN cash_registers cr2 ON cr.cash_register_id = cr2.id
        WHERE cr.id = :id
    ");
    $stmt->bindParam(':id', $report_id, PDO::PARAM_INT);
    $stmt->execute();
    $report = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$report) {
        echo '<div class="alert alert-warning">Hesabat tapılmadı.</div>';
        exit();
    }

    // Bank qəbzləri
    $bank_receipts = json_decode($report['bank_receipts'], true) ?: [];
    $total_bank_receipts = array_sum($bank_receipts);

    // Əlavə kassadan verilən pullar
    $additional_cash = json_decode($report['additional_cash'], true) ?: [];
    $total_additional_cash = 0;
    foreach ($additional_cash as $ac) {
        $total_additional_cash += floatval($ac['amount'] ?? 0);
    }

    // EDV məlumatları
    $has_vat = (floatval($report['vat_included'] ?? 0) > 0 || floatval($report['vat_exempt'] ?? 0) > 0);
    
    // HTML Çıxışı
    ?>
    <div class="container-fluid p-0">
        <div class="row">
            <div class="col-md-12 mb-3">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Əsas Məlumatlar</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-calendar-alt"></i> Tarix:</strong> <?php echo format_date($report['date']); ?></p>
                                <p><strong><i class="fas fa-user"></i> Kassir:</strong> <?php echo htmlspecialchars($report['employee_name']); ?></p>
                                <p><strong><i class="fas fa-cash-register"></i> Kassa:</strong> <?php echo htmlspecialchars($report['cash_register_name'] ?? 'Təyin edilməyib'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-money-bill-wave"></i> Nağd Pul:</strong> <?php echo format_amount($report['cash_given']); ?></p>
                                <p><strong><i class="fas fa-credit-card"></i> POS Məbləği:</strong> <?php echo format_amount($report['pos_amount']); ?></p>
                                <p><strong><i class="fas fa-coins"></i> Yekun Məbləğ:</strong> <?php echo format_amount($report['total_amount']); ?></p>
                                <p>
                                    <strong><i class="fas fa-balance-scale"></i> Fərq:</strong> 
                                    <span class="<?php echo $report['difference'] < 0 ? 'text-danger' : ($report['difference'] > 0 ? 'text-success' : ''); ?>">
                                        <?php echo format_amount($report['difference']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Bank Qəbzləri -->
            <?php if (!empty($bank_receipts)): ?>
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">Bank Qəbzləri</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group">
                            <?php foreach ($bank_receipts as $index => $receipt): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>Qəbz #<?php echo $index + 1; ?></span>
                                    <span class="badge bg-primary rounded-pill"><?php echo format_amount($receipt); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="alert alert-info mt-3">
                            <strong>Ümumi məbləğ:</strong> <?php echo format_amount($total_bank_receipts); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Əlavə Kassadan Verilən Pullar -->
            <?php if (!empty($additional_cash)): ?>
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">Əlavə Kassadan Verilən Pullar</h5>
                    </div>
                    <div class="card-body">
                        <ul class="list-group">
                            <?php foreach ($additional_cash as $ac): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><?php echo htmlspecialchars($ac['description'] ?? 'Təsvir yoxdur'); ?></span>
                                    <span class="badge bg-success rounded-pill"><?php echo format_amount($ac['amount'] ?? 0); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div class="alert alert-success mt-3">
                            <strong>Ümumi məbləğ:</strong> <?php echo format_amount($total_additional_cash); ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- EDV Məlumatları -->
            <?php if ($has_vat): ?>
            <div class="col-md-12 mb-3">
                <div class="card">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">Vergi Kassası Hesabatı</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <strong>EDV-li Məbləğ:</strong> <?php echo format_amount($report['vat_included'] ?? 0); ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-light">
                                    <strong>EDV-dən Azad Məbləğ:</strong> <?php echo format_amount($report['vat_exempt'] ?? 0); ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-warning">
                                    <strong>Ümumi Məbləğ:</strong> <?php echo format_amount(($report['vat_included'] ?? 0) + ($report['vat_exempt'] ?? 0)); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Əlavə məlumatlar -->
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Əlavə Məlumatlar</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-clock"></i> Yaradılma tarixi:</strong> 
                                    <?php echo !empty($report['created_at']) ? format_date($report['created_at'], 'd.m.Y H:i:s') : 'Məlumat yoxdur'; ?>
                                </p>
                            </div>
                            <div class="col-md-6">
                                <p><strong><i class="fas fa-edit"></i> Son yenilənmə tarixi:</strong> 
                                    <?php echo !empty($report['updated_at']) ? format_date($report['updated_at'], 'd.m.Y H:i:s') : 'Məlumat yoxdur'; ?>
                                </p>
                            </div>
                            <div class="col-md-12">
                                <p>
                                    <strong><i class="fas fa-exclamation-circle"></i> Borc statusu:</strong> 
                                    <?php if ($report['is_debt']): ?>
                                        <span class="badge bg-danger">Borc kimi qeyd edilib</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Borc kimi qeyd edilməyib</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php

} catch (PDOException $e) {
    echo '<div class="alert alert-danger">Xəta baş verdi: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?> 