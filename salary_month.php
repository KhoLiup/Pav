<?php
// salary_month.php
session_start();
require 'config.php';

// İstifadəçi yoxlaması
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Ayı alırıq
if (!isset($_GET['month'])) {
    header('Location: salary_history.php');
    exit();
}

$month = $_GET['month'];

// Verilən ay üçün maaş ödənişlərini alırıq
$sql = "SELECT sp.*, e.name AS employee_name
        FROM salary_payments sp
        JOIN employees e ON sp.employee_id = e.id
        WHERE DATE_FORMAT(sp.payment_date, '%Y-%m') = :month
        ORDER BY sp.payment_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute([':month' => $month]);
$salary_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ümumi məbləğləri hesablayaq
$total_gross = 0;
$total_deductions = 0;
$total_additional = 0;
$total_net = 0;

foreach ($salary_payments as $payment) {
    $total_gross += $payment['gross_salary'];
    $total_deductions += $payment['deductions'];
    $total_additional += $payment['additional_payment'];
    $total_net += $payment['net_salary'];
}

// Ay adını formatla
$month_name = date('F Y', strtotime($month . '-01'));
$month_name_az = strtr(
    $month_name, 
    [
        'January' => 'Yanvar',
        'February' => 'Fevral',
        'March' => 'Mart',
        'April' => 'Aprel',
        'May' => 'May',
        'June' => 'İyun',
        'July' => 'İyul',
        'August' => 'Avqust',
        'September' => 'Sentyabr',
        'October' => 'Oktyabr',
        'November' => 'Noyabr',
        'December' => 'Dekabr'
    ]
);

?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($month_name_az); ?> Ayı Maaş Ödənişləri</title>
    <!-- Bootstrap CSS əlavə edirik -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .table td, .table th {
            vertical-align: middle;
        }
        .total-row {
            font-weight: bold;
            background-color: #f8f9fa;
        }
        @media print {
            .no-print {
                display: none;
            }
            body {
                font-size: 12pt;
            }
            .container {
                width: 100%;
                max-width: 100%;
            }
            .table-bordered td, .table-bordered th {
                border: 1px solid #000 !important;
            }
            .table thead th {
                background-color: #f2f2f2 !important;
                color: #000 !important;
            }
        }
        .signature-area {
            margin-top: 50px;
            display: flex;
            justify-content: space-between;
        }
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            text-align: center;
            padding-top: 5px;
        }
        .filter-section {
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f8f9fa;
            border-radius: 5px;
        }
    </style>
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><?php echo htmlspecialchars($month_name_az); ?> Ayı Maaş Ödənişləri</h2>
        <div class="no-print">
            <button class="btn btn-info" onclick="window.print()">
                <i class="fas fa-print"></i> Çap Et
            </button>
            <a href="salary_history.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Geri
            </a>
            <a href="salary.php" class="btn btn-primary">
                <i class="fas fa-calculator"></i> Maaş Hesablama
            </a>
        </div>
    </div>

    <div class="filter-section no-print">
        <div class="row">
            <div class="col-md-6">
                <div class="form-group">
                    <label for="employeeFilter">İşçiyə görə filtrlə:</label>
                    <input type="text" id="employeeFilter" class="form-control" placeholder="İşçi adını daxil edin...">
                </div>
            </div>
            <div class="col-md-6">
                <div class="form-group">
                    <label for="dateFilter">Tarixə görə filtrlə:</label>
                    <input type="date" id="dateFilter" class="form-control">
                </div>
            </div>
        </div>
        <div class="text-right">
            <button id="resetFilters" class="btn btn-secondary">Filtrləri Sıfırla</button>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover" id="salaryTable">
            <thead class="thead-dark">
                <tr>
                    <th>№</th>
                    <th>Ödəniş Tarixi</th>
                    <th>İşçi</th>
                    <th>Ümumi Maaş (AZN)</th>
                    <th>Tutulmalar (AZN)</th>
                    <th>Əlavə Ödəniş (AZN)</th>
                    <th>Səbəb</th>
                    <th>Xalis Maaş (AZN)</th>
                    <th class="no-print">Əməliyyatlar</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $counter = 1;
                foreach ($salary_payments as $payment): 
                ?>
                    <tr data-employee="<?php echo strtolower(htmlspecialchars($payment['employee_name'])); ?>" 
                        data-date="<?php echo htmlspecialchars($payment['payment_date']); ?>">
                        <td><?php echo $counter++; ?></td>
                        <td><?php echo date('d.m.Y', strtotime($payment['payment_date'])); ?></td>
                        <td>
                            <a href="employee_payments.php?employee_id=<?php echo $payment['employee_id']; ?>">
                                <?php echo htmlspecialchars($payment['employee_name']); ?>
                            </a>
                        </td>
                        <td><?php echo number_format($payment['gross_salary'], 2); ?></td>
                        <td><?php echo number_format($payment['deductions'], 2); ?></td>
                        <td><?php echo number_format($payment['additional_payment'], 2); ?></td>
                        <td><?php echo htmlspecialchars($payment['reason']); ?></td>
                        <td><?php echo number_format($payment['net_salary'], 2); ?></td>
                        <td class="no-print">
                            <a href="employee_payments.php?employee_id=<?php echo $payment['employee_id']; ?>" 
                               class="btn btn-sm btn-info" title="İşçinin bütün ödənişləri">
                                <i class="fas fa-history"></i>
                            </a>
                            <?php if ($_SESSION['user_role'] === 'admin'): ?>
                            <a href="employee_debts.php?employee_id=<?php echo $payment['employee_id']; ?>" 
                               class="btn btn-sm btn-warning" title="İşçinin borcları">
                                <i class="fas fa-money-bill-wave"></i>
                            </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3" class="text-right"><strong>CƏMİ:</strong></td>
                    <td><?php echo number_format($total_gross, 2); ?></td>
                    <td><?php echo number_format($total_deductions, 2); ?></td>
                    <td><?php echo number_format($total_additional, 2); ?></td>
                    <td>-</td>
                    <td><?php echo number_format($total_net, 2); ?></td>
                    <td class="no-print">-</td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="signature-area">
        <div class="signature-line">
            Hazırlayan
        </div>
        <div class="signature-line">
            Təsdiq edən
        </div>
        <div class="signature-line">
            Qəbul edən
        </div>
    </div>
</div>

<!-- JavaScript kitabxanalarını əlavə edirik -->
<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>
<script>
$(document).ready(function() {
    // İşçi adına görə filtrlə
    $("#employeeFilter").on("keyup", function() {
        var value = $(this).val().toLowerCase();
        $("#salaryTable tbody tr").filter(function() {
            var shouldShow = true;
            
            // İşçi adına görə filtrlə
            if (value) {
                shouldShow = $(this).data('employee').toLowerCase().indexOf(value) > -1;
            }
            
            // Tarix filtri də varsa, onu da nəzərə al
            var dateFilter = $("#dateFilter").val();
            if (dateFilter && shouldShow) {
                var rowDate = $(this).data('date');
                shouldShow = rowDate === dateFilter;
            }
            
            // Cəmi sətri həmişə göstər
            if ($(this).hasClass('total-row')) {
                shouldShow = true;
            }
            
            $(this).toggle(shouldShow);
        });
        updateTotals();
    });
    
    // Tarixə görə filtrlə
    $("#dateFilter").on("change", function() {
        var value = $(this).val();
        $("#salaryTable tbody tr").filter(function() {
            var shouldShow = true;
            
            // Tarixə görə filtrlə
            if (value) {
                shouldShow = $(this).data('date') === value;
            }
            
            // İşçi filtri də varsa, onu da nəzərə al
            var employeeFilter = $("#employeeFilter").val().toLowerCase();
            if (employeeFilter && shouldShow) {
                shouldShow = $(this).data('employee').toLowerCase().indexOf(employeeFilter) > -1;
            }
            
            // Cəmi sətri həmişə göstər
            if ($(this).hasClass('total-row')) {
                shouldShow = true;
            }
            
            $(this).toggle(shouldShow);
        });
        updateTotals();
    });
    
    // Filtrləri sıfırla
    $("#resetFilters").on("click", function() {
        $("#employeeFilter").val('');
        $("#dateFilter").val('');
        $("#salaryTable tbody tr").show();
        updateTotals();
    });
    
    // Cəmi yenilə
    function updateTotals() {
        var totalGross = 0;
        var totalDeductions = 0;
        var totalAdditional = 0;
        var totalNet = 0;
        
        $("#salaryTable tbody tr:visible").not('.total-row').each(function() {
            var cells = $(this).find('td');
            totalGross += parseFloat(cells.eq(3).text().replace(/,/g, ''));
            totalDeductions += parseFloat(cells.eq(4).text().replace(/,/g, ''));
            totalAdditional += parseFloat(cells.eq(5).text().replace(/,/g, ''));
            totalNet += parseFloat(cells.eq(7).text().replace(/,/g, ''));
        });
        
        var totalRow = $("#salaryTable tbody tr.total-row");
        totalRow.find('td').eq(1).text(formatNumber(totalGross));
        totalRow.find('td').eq(2).text(formatNumber(totalDeductions));
        totalRow.find('td').eq(3).text(formatNumber(totalAdditional));
        totalRow.find('td').eq(5).text(formatNumber(totalNet));
    }
    
    // Rəqəmi formatla
    function formatNumber(num) {
        return num.toFixed(2).replace(/\d(?=(\d{3})+\.)/g, '$&,');
    }
});
</script>
</body>
</html>
