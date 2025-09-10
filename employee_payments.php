<?php

// employee_payments.php

session_start();

require 'config.php';



// İstifadəçi yoxlaması

if (!isset($_SESSION['user_id'])) {

    header('Location: login.php');

    exit();

}



// İşçi ID-ni alırıq

if (!isset($_GET['employee_id'])) {

    header('Location: salary_history.php');

    exit();

}



$employee_id = $_GET['employee_id'];



// İşçinin məlumatlarını alırıq

$stmt = $conn->prepare("SELECT id, name, salary, max_vacation_days, start_date FROM employees WHERE id = :employee_id");

$stmt->execute([':employee_id' => $employee_id]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);



if (!$employee) {

    $_SESSION['error_message'] = "İşçi tapılmadı.";

    header('Location: salary_history.php');

    exit();

}



// İl seçimi

$stmt = $conn->prepare("

    SELECT DISTINCT YEAR(payment_date) as year 

    FROM salary_payments 

    WHERE employee_id = :employee_id 

    ORDER BY year DESC

");

$stmt->execute([':employee_id' => $employee_id]);

$years = $stmt->fetchAll(PDO::FETCH_COLUMN);



// Seçilmiş il

$selected_year = isset($_GET['year']) ? $_GET['year'] : (empty($years) ? date('Y') : $years[0]);



// İşçinin maaş ödənişlərini alırıq

$sql = "SELECT sp.*, DATE_FORMAT(sp.payment_date, '%Y-%m') as payment_month

        FROM salary_payments sp

        WHERE sp.employee_id = :employee_id

        AND YEAR(sp.payment_date) = :year

        ORDER BY sp.payment_date DESC";



$stmt = $conn->prepare($sql);

$stmt->execute([

    ':employee_id' => $employee_id,

    ':year' => $selected_year

]);

$salary_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);



// İl üzrə ümumi məbləğləri hesablayaq

$year_total_gross = 0;

$year_total_deductions = 0;

$year_total_additional = 0;

$year_total_net = 0;



foreach ($salary_payments as $payment) {

    $year_total_gross += $payment['gross_salary'];

    $year_total_deductions += $payment['deductions'];

    $year_total_additional += $payment['additional_payment'];

    $year_total_net += $payment['net_salary'];

}



// İşçinin borclarını alırıq

$stmt = $conn->prepare("

    SELECT SUM(amount) as total_debt

    FROM debts

    WHERE employee_id = :employee_id AND is_paid = 0

");

$stmt->execute([':employee_id' => $employee_id]);

$current_debt = $stmt->fetchColumn();

$current_debt = $current_debt ? (float)$current_debt : 0.0;



?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title><?php echo htmlspecialchars($employee['name']); ?> - Maaş Ödənişləri</title>

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

        .employee-info {

            background-color: #f8f9fa;

            padding: 20px;

            border-radius: 5px;

            margin-bottom: 30px;

        }

        .employee-info .row {

            margin-bottom: 10px;

        }

        .summary-section {

            background-color: #f8f9fa;

            padding: 20px;

            border-radius: 5px;

            margin-bottom: 30px;

        }

        .summary-card {

            text-align: center;

            padding: 15px;

            border-radius: 5px;

            margin-bottom: 15px;

            color: white;

        }

        .summary-gross {

            background-color: #28a745;

        }

        .summary-deductions {

            background-color: #dc3545;

        }

        .summary-additional {

            background-color: #17a2b8;

        }

        .summary-net {

            background-color: #007bff;

        }

        .chart-container {

            height: 300px;

            margin-bottom: 30px;

        }

        @media print {

            .no-print {

                display: none;

            }

            .container {

                width: 100%;

                max-width: 100%;

            }

        }

    </style>

</head>

<body>

<?php include 'includes/header.php'; ?>



<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2><?php echo htmlspecialchars($employee['name']); ?> - Maaş Ödənişləri</h2>

        <div class="no-print">

            <button class="btn btn-info" onclick="window.print()">

                <i class="fas fa-print"></i> Çap Et

            </button>

            <a href="salary_history.php" class="btn btn-secondary">

                <i class="fas fa-arrow-left"></i> Geri

            </a>

            <?php if ($_SESSION['user_role'] === 'admin'): ?>

            <a href="employee_debts.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-warning">

                <i class="fas fa-money-bill-wave"></i> Borclar

            </a>

            <?php endif; ?>

        </div>

    </div>



    <!-- İşçi məlumatları -->

    <div class="employee-info">

        <div class="row">

            <div class="col-md-6">

                <p><strong>İşçi ID:</strong> <?php echo $employee_id; ?></p>

                <p><strong>Ad:</strong> <?php echo htmlspecialchars($employee['name']); ?></p>

                <p><strong>Cari Maaş:</strong> <?php echo number_format($employee['salary'], 2); ?> AZN</p>

            </div>

            <div class="col-md-6">

                <p><strong>İşə Başlama Tarixi:</strong> <?php echo date('d.m.Y', strtotime($employee['start_date'])); ?></p>

                <p><strong>Maks. İstirahət Günü:</strong> <?php echo $employee['max_vacation_days']; ?> gün</p>

                <p><strong>Cari Borc:</strong> <span class="<?php echo $current_debt > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo number_format($current_debt, 2); ?> AZN</span></p>

            </div>

        </div>

    </div>



    <!-- İl seçimi -->

    <div class="year-filter mb-4">

        <form method="GET" class="form-inline">

            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">

            <label for="year" class="mr-2">İl seçin:</label>

            <select name="year" id="year" class="form-control mr-2" onchange="this.form.submit()">

                <?php foreach ($years as $year): ?>

                    <option value="<?php echo $year; ?>" <?php echo $year == $selected_year ? 'selected' : ''; ?>>

                        <?php echo $year; ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </form>

    </div>



    <!-- İl üzrə xülasə -->

    <div class="summary-section">

        <h4 class="mb-3"><?php echo $selected_year; ?> İli Üzrə Xülasə</h4>

        <div class="row">

            <div class="col-md-3">

                <div class="summary-card summary-gross">

                    <h5>Ümumi Maaş</h5>

                    <h3><?php echo number_format($year_total_gross, 2); ?> AZN</h3>

                </div>

            </div>

            <div class="col-md-3">

                <div class="summary-card summary-deductions">

                    <h5>Tutulmalar</h5>

                    <h3><?php echo number_format($year_total_deductions, 2); ?> AZN</h3>

                </div>

            </div>

            <div class="col-md-3">

                <div class="summary-card summary-additional">

                    <h5>Əlavə Ödənişlər</h5>

                    <h3><?php echo number_format($year_total_additional, 2); ?> AZN</h3>

                </div>

            </div>

            <div class="col-md-3">

                <div class="summary-card summary-net">

                    <h5>Xalis Maaş</h5>

                    <h3><?php echo number_format($year_total_net, 2); ?> AZN</h3>

                </div>

            </div>

        </div>

    </div>



    <!-- Qrafik -->

    <div class="chart-container no-print">

        <canvas id="salaryChart"></canvas>

    </div>



    <div class="table-responsive">

        <table class="table table-bordered table-hover">

            <thead class="thead-dark">

                <tr>

                    <th>№</th>

                    <th>Ödəniş Tarixi</th>

                    <th>Ay</th>

                    <th>Ümumi Maaş (AZN)</th>

                    <th>Tutulmalar (AZN)</th>

                    <th>Əlavə Ödəniş (AZN)</th>

                    <th>Səbəb</th>

                    <th>Xalis Maaş (AZN)</th>

                </tr>

            </thead>

            <tbody>

                <?php 

                $counter = 1;

                $chart_labels = [];

                $chart_gross = [];

                $chart_net = [];
                

                foreach ($salary_payments as $payment): 

                    // Qrafik üçün məlumatları hazırlayırıq
                    $chart_labels[] = formatMonthName($payment['payment_month']);
                    $chart_gross[] = $payment['gross_salary'];
                    $chart_net[] = $payment['net_salary'];

                ?>

                    <tr>

                        <td><?php echo $counter++; ?></td>

                        <td><?php echo date('d.m.Y', strtotime($payment['payment_date'])); ?></td>

                        <td><?php echo formatMonthName($payment['payment_month']); ?></td>

                        <td><?php echo number_format($payment['gross_salary'], 2); ?></td>

                        <td><?php echo number_format($payment['deductions'], 2); ?></td>

                        <td><?php echo number_format($payment['additional_payment'], 2); ?></td>

                        <td><?php echo htmlspecialchars($payment['reason']); ?></td>

                        <td><?php echo number_format($payment['net_salary'], 2); ?></td>

                    </tr>

                <?php endforeach; ?>

                <tr class="total-row">

                    <td colspan="3" class="text-right"><strong>CƏMİ:</strong></td>

                    <td><?php echo number_format($year_total_gross, 2); ?></td>

                    <td><?php echo number_format($year_total_deductions, 2); ?></td>

                    <td><?php echo number_format($year_total_additional, 2); ?></td>

                    <td>-</td>

                    <td><?php echo number_format($year_total_net, 2); ?></td>

                </tr>

            </tbody>

        </table>

    </div>

</div>



<!-- JavaScript kitabxanalarını əlavə edirik -->

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>

$(document).ready(function() {

    // Qrafik məlumatları

    var chartLabels = <?php echo json_encode(array_reverse($chart_labels)); ?>;

    var chartGross = <?php echo json_encode(array_reverse($chart_gross)); ?>;

    var chartNet = <?php echo json_encode(array_reverse($chart_net)); ?>;
    

    // Qrafiki yaradırıq

    var ctx = document.getElementById('salaryChart').getContext('2d');

    var salaryChart = new Chart(ctx, {

        type: 'line',

        data: {

            labels: chartLabels,

            datasets: [

                {

                    label: 'Ümumi Maaş',

                    data: chartGross,

                    backgroundColor: 'rgba(40, 167, 69, 0.2)',

                    borderColor: 'rgba(40, 167, 69, 1)',

                    borderWidth: 2,

                    tension: 0.1

                },

                {

                    label: 'Xalis Maaş',

                    data: chartNet,

                    backgroundColor: 'rgba(0, 123, 255, 0.2)',

                    borderColor: 'rgba(0, 123, 255, 1)',

                    borderWidth: 2,

                    tension: 0.1

                }

            ]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            scales: {

                y: {

                    beginAtZero: true,

                    ticks: {

                        callback: function(value) {

                            return value.toFixed(2) + ' AZN';

                        }

                    }

                }

            },

            plugins: {

                tooltip: {

                    callbacks: {

                        label: function(context) {

                            return context.dataset.label + ': ' + context.parsed.y.toFixed(2) + ' AZN';

                        }

                    }

                }

            }

        }

    });

});

</script>

</body>

</html>

