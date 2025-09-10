<?php
session_start();
include 'config/db.php';
include 'includes/header.php';

// İstifadəçi giriş edibmi yoxla
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Borc silmə əməliyyatı
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $id = $_GET['delete'];
    
    // Ödəniş tarixçəsinə baxma
    $checkHistorySql = "SELECT COUNT(*) as count FROM debt_payment_history WHERE debt_id = ? AND debt_type = 'recurring'";
    $checkStmt = $conn->prepare($checkHistorySql);
    $checkStmt->bind_param("i", $id);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    $historyCount = $result->fetch_assoc()['count'];
    
    if ($historyCount > 0) {
        $_SESSION['message'] = 'Bu borca aid ödəniş tarixçəsi var. Silmək əvəzinə statusunu dəyişdirin.';
        $_SESSION['message_type'] = 'warning';
        header('Location: recurring_debts.php');
        exit();
    }
    
    // Borcu sil
    $stmt = $conn->prepare("DELETE FROM debt_recurring_debts WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        $_SESSION['message'] = 'Təkrarlanan borc uğurla silindi!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Borc silinmədi: ' . $conn->error;
        $_SESSION['message_type'] = 'danger';
    }
    
    header('Location: recurring_debts.php');
    exit();
}

// Status dəyişmə əməliyyatı
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $id = $_GET['toggle_status'];
    
    // Cari statusu əldə et
    $statusQuery = "SELECT is_active FROM debt_recurring_debts WHERE id = ?";
    $statusStmt = $conn->prepare($statusQuery);
    $statusStmt->bind_param("i", $id);
    $statusStmt->execute();
    $statusResult = $statusStmt->get_result();
    
    if ($statusResult->num_rows > 0) {
        $currentStatus = $statusResult->fetch_assoc()['is_active'];
        $newStatus = $currentStatus ? 0 : 1;
        
        // Statusu dəyiş
        $updateStmt = $conn->prepare("UPDATE debt_recurring_debts SET is_active = ? WHERE id = ?");
        $updateStmt->bind_param("ii", $newStatus, $id);
        
        if ($updateStmt->execute()) {
            $statusMessage = $newStatus ? 'aktivləşdirildi' : 'deaktiv edildi';
            $_SESSION['message'] = "Təkrarlanan borc uğurla $statusMessage!";
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Status dəyişdirilmədi: ' . $conn->error;
            $_SESSION['message_type'] = 'danger';
        }
    } else {
        $_SESSION['message'] = 'Borc tapılmadı!';
        $_SESSION['message_type'] = 'danger';
    }
    
    header('Location: recurring_debts.php');
    exit();
}

// Bütün təkrarlanan borcları əldə et
$sql = "SELECT rd.*, c.name as company_name, c.phone_number, c.email,
        CASE
            WHEN rd.next_payment_date < CURDATE() THEN
                -- Əgər gecikən təkrarlanan borclar varsa, keçən periodların sayını və məbləğini hesabla
                (SELECT CASE
                    WHEN rd.interval_type = 'daily' THEN DATEDIFF(CURDATE(), rd.next_payment_date)
                    WHEN rd.interval_type = 'weekly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 7)
                    WHEN rd.interval_type = 'monthly' THEN 
                        PERIOD_DIFF(
                            EXTRACT(YEAR_MONTH FROM CURDATE()),
                            EXTRACT(YEAR_MONTH FROM rd.next_payment_date)
                        )
                    WHEN rd.interval_type = 'quarterly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 90)
                    WHEN rd.interval_type = 'yearly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 365)
                    ELSE 0
                END + 1) * rd.amount
            ELSE rd.amount
        END as total_amount,
        CASE
            WHEN rd.next_payment_date < CURDATE() THEN
                (SELECT CASE
                    WHEN rd.interval_type = 'daily' THEN DATEDIFF(CURDATE(), rd.next_payment_date)
                    WHEN rd.interval_type = 'weekly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 7)
                    WHEN rd.interval_type = 'monthly' THEN 
                        PERIOD_DIFF(
                            EXTRACT(YEAR_MONTH FROM CURDATE()),
                            EXTRACT(YEAR_MONTH FROM rd.next_payment_date)
                        )
                    WHEN rd.interval_type = 'quarterly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 90)
                    WHEN rd.interval_type = 'yearly' THEN FLOOR(DATEDIFF(CURDATE(), rd.next_payment_date) / 365)
                    ELSE 0
                END + 1)
            ELSE 1
        END as period_count,
        CASE
            WHEN rd.next_payment_date < CURDATE() THEN 'Gecikən'
            WHEN rd.next_payment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY) THEN 'Gözlənilən'
            ELSE 'Normal'
        END as status
        FROM debt_recurring_debts rd
        JOIN debt_companies c ON rd.company_id = c.id
        ORDER BY rd.is_active DESC, rd.next_payment_date ASC";
$result = $conn->query($sql);

// Ümumi məlumatları hesabla
$stats = [
    'active' => 0,
    'inactive' => 0,
    'total' => 0,
    'active_amount' => 0,
    'inactive_amount' => 0,
    'total_amount' => 0
];

$stats_sql = "SELECT 
                COUNT(*) as total, 
                SUM(amount) as total_amount,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = 1 THEN amount ELSE 0 END) as active_amount,
                SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN is_active = 0 THEN amount ELSE 0 END) as inactive_amount
              FROM debt_recurring_debts";

$stats_result = $conn->query($stats_sql);
if ($stats_result->num_rows > 0) {
    $stats = $stats_result->fetch_assoc();
}
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-12">
            <div class="card shadow">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h4 class="mb-0"><i class="fas fa-sync-alt me-2"></i>Təkrarlanan Borclar</h4>
                    <a href="add_recurring_debt.php" class="btn btn-light"><i class="fas fa-plus me-1"></i>Yeni Təkrarlanan</a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover datatable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Şirkət</th>
                                    <th>Məbləğ</th>
                                    <th>Toplam borc</th>
                                    <th>İnterval</th>
                                    <th>Növbəti ödəniş</th>
                                    <th>Status</th>
                                    <th>Aktivdir</th>
                                    <th>Əməliyyatlar</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                if ($result->num_rows > 0) {
                                    $intervalMap = [
                                        'daily' => 'Gündəlik',
                                        'weekly' => 'Həftəlik',
                                        'monthly' => 'Aylıq',
                                        'quarterly' => 'Rüblük',
                                        'yearly' => 'İllik'
                                    ];
                                    
                                    while($row = $result->fetch_assoc()) {
                                        $statusClass = 'bg-success';
                                        // Set a default status if not defined
                                        if (!isset($row['status'])) {
                                            $row['status'] = 'Normal';
                                        }
                                        
                                        if ($row['status'] == 'Gecikən') {
                                            $statusClass = 'bg-danger';
                                        } else if ($row['status'] == 'Gözlənilən') {
                                            $statusClass = 'bg-warning';
                                        }
                                        
                                        echo "<tr" . ($row['is_active'] ? '' : ' class="table-secondary"') . ">";
                                        echo "<td>" . $row['id'] . "</td>";
                                        echo "<td>" . htmlspecialchars($row['company_name']) . "</td>";
                                        echo "<td>" . number_format($row['amount'], 2) . " AZN</td>";
                                        
                                        // Toplam məbləğ (gecikən periodlar + cari period)
                                        if ($row['period_count'] > 1) {
                                            echo "<td>" . number_format($row['total_amount'], 2) . " AZN <small class='text-muted'>(" . $row['period_count'] . " period)</small></td>";
                                        } else {
                                            echo "<td>" . number_format($row['amount'], 2) . " AZN</td>";
                                        }
                                        
                                        echo "<td>" . ($intervalMap[$row['interval_type']] ?? $row['interval_type']) . "</td>";
                                        echo "<td>" . date('d.m.Y', strtotime($row['next_payment_date'])) . "</td>";
                                        echo "<td><span class='badge $statusClass'>" . $row['status'] . "</span></td>";
                                        echo "<td>" . ($row['is_active'] ? '<span class="text-success"><i class="fas fa-check"></i> Bəli</span>' : '<span class="text-danger"><i class="fas fa-times"></i> Xeyr</span>') . "</td>";
                                        echo "<td>";
                                        echo "<div class='btn-group'>";
                                        if ($row['is_active']) {
                                            $mark_paid_url = 'mark_paid.php?type=recurring&id=' . $row['id'];
                                            // Əgər periodlar varsa, URL-ə period_count parametrini əlavə et
                                            if ($row['period_count'] > 1) {
                                                $mark_paid_url .= '&periods=' . $row['period_count'];
                                            }
                                            echo "<a href='{$mark_paid_url}' class='btn btn-sm btn-success' title='Ödənildi'><i class='fas fa-money-bill-wave'></i></a>";
                                        }
                                        echo "<a href='edit_recurring_debt.php?id=" . $row['id'] . "' class='btn btn-sm btn-primary' title='Düzəliş et'><i class='fas fa-edit'></i></a>";
                                        echo "<a href='view_recurring_history.php?id=" . $row['id'] . "' class='btn btn-sm btn-info' title='Tarixçə'><i class='fas fa-history'></i></a>";
                                        echo "<a href='recurring_debts.php?toggle_status=" . $row['id'] . "' class='btn btn-sm btn-warning' title='Statusu dəyiş'><i class='fas fa-toggle-" . ($row['is_active'] ? 'on' : 'off') . "'></i></a>";
                                        echo "<a href='#' class='btn btn-sm btn-danger' data-bs-toggle='modal' data-bs-target='#deleteModal" . $row['id'] . "' title='Sil'><i class='fas fa-trash'></i></a>";
                                        echo "</div>";
                                        
                                        // Silmə Modal
                                        echo "<div class='modal fade' id='deleteModal" . $row['id'] . "' tabindex='-1' aria-hidden='true'>";
                                        echo "<div class='modal-dialog'>";
                                        echo "<div class='modal-content'>";
                                        echo "<div class='modal-header bg-danger text-white'>";
                                        echo "<h5 class='modal-title'>Təkrarlanan borcu sil</h5>";
                                        echo "<button type='button' class='btn-close' data-bs-dismiss='modal' aria-label='Close'></button>";
                                        echo "</div>";
                                        echo "<div class='modal-body'>";
                                        echo "Bu təkrarlanan borcu silmək istədiyinizə əminsiniz?<br><br>";
                                        echo "<strong>Şirkət:</strong> " . htmlspecialchars($row['company_name']) . "<br>";
                                        echo "<strong>Məbləğ:</strong> " . number_format($row['amount'], 2) . " AZN<br>";
                                        echo "<strong>İnterval:</strong> " . ($intervalMap[$row['interval_type']] ?? $row['interval_type']) . "<br>";
                                        echo "<strong>Növbəti ödəniş:</strong> " . date('d.m.Y', strtotime($row['next_payment_date']));
                                        echo "</div>";
                                        echo "<div class='modal-footer'>";
                                        echo "<button type='button' class='btn btn-secondary' data-bs-dismiss='modal'>Ləğv et</button>";
                                        echo "<a href='recurring_debts.php?delete=" . $row['id'] . "' class='btn btn-danger'>Sil</a>";
                                        echo "</div>";
                                        echo "</div>";
                                        echo "</div>";
                                        echo "</div>";
                                        
                                        echo "</td>";
                                        echo "</tr>";
                                    }
                                } else {
                                    echo "<tr><td colspan='9' class='text-center'>Heç bir təkrarlanan borc tapılmadı.</td></tr>";
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?> 