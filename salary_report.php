<?php

// salary_report.php

session_start();

require 'config.php';



// Sessiyadan məlumatları əldə edirik

if (!isset($_SESSION['salary_payment_data'])) {

    header('Location: salary.php');

    exit();

}



$employee_data = $_SESSION['salary_payment_data'];

$payment_date = $_SESSION['payment_date'];

$month = $_SESSION['month'];



// İşçilərin məlumatlarını əldə edirik

$employee_ids = array_keys($employee_data);

$placeholders = implode(',', array_fill(0, count($employee_ids), '?'));



$stmt = $conn->prepare("SELECT id, name, max_vacation_days FROM employees WHERE id IN ($placeholders)");

$stmt->execute($employee_ids);

$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$employee_names = [];

$employee_vacation_days = [];

foreach ($employees as $emp) {

    $employee_names[$emp['id']] = $emp['name'];

    $employee_vacation_days[$emp['id']] = $emp['max_vacation_days'];

}



// Ümumi məbləğləri hesablayaq

$total_gross = 0;

$total_deductions = 0;

$total_debt = 0;

$total_additional = 0;

$total_net = 0;



foreach ($employee_data as $emp_id => $data) {

    $total_gross += $data['gross_salary'];

    $total_deductions += $data['deduction'];

    $total_debt += $data['debt'];

    $total_additional += isset($data['additional_payment']) ? $data['additional_payment'] : 0;

    $total_net += $data['net_salary'];

}



// Sessiya məlumatlarını təmizləyirik

// unset($_SESSION['salary_payment_data']);

// unset($_SESSION['payment_date']);

// unset($_SESSION['month']);

?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title>Maaş Ödənişləri Hesabatı - <?php echo htmlspecialchars($month); ?></title>

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>

        /* Çap üçün stil */

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

        body {

            font-family: Arial, sans-serif;

        }

        .report-header {

            text-align: center;

            margin-bottom: 20px;

        }

        .report-meta {

            margin-bottom: 20px;

        }

        .report-meta p {

            margin-bottom: 5px;

        }

        .table-responsive {

            margin-bottom: 20px;

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

        .total-row {

            font-weight: bold;

            background-color: #f8f9fa;

        }

        .employee-details {

            display: none;

            background-color: #f8f9fa;

            padding: 10px;

            margin-top: 5px;

            border-radius: 5px;

        }

    </style>

</head>

<body>

    <div class="container mt-4">

        <div class="no-print mb-3">

            <?php include 'includes/navbar.php'; ?>

            <div class="text-right mb-3">

                <button class="btn btn-info" onclick="window.print()">

                    <i class="fas fa-print"></i> Çap Et

                </button>

                <a href="salary.php" class="btn btn-secondary">

                    <i class="fas fa-arrow-left"></i> Geri Qayıt

                </a>

                <a href="salary_history.php" class="btn btn-primary">

                    <i class="fas fa-history"></i> Maaş Tarixçəsi

                </a>

            </div>

        </div>



        <div class="report-header">

            <h2>Maaş Ödənişləri Hesabatı</h2>

            <h4><?php echo htmlspecialchars($month); ?></h4>

        </div>



        <div class="report-meta">

            <p><strong>Ödəniş Tarixi:</strong> <?php echo date('d.m.Y', strtotime($payment_date)); ?></p>

            <p><strong>Hesabat Tarixi:</strong> <?php echo date('d.m.Y H:i'); ?></p>

        </div>



        <div class="table-responsive">

            <table class="table table-bordered table-hover">

                <thead class="thead-dark">

                    <tr>

                        <th>№</th>

                        <th>İşçi</th>

                        <th>Aylıq Maaş (AZN)</th>

                        <th>İstirahət Günləri</th>

                        <th>Çıxılma (AZN)</th>

                        <th>Borc (AZN)</th>

                        <th>Əlavə Ödəniş (AZN)</th>

                        <th>Səbəb</th>

                        <th>Xalis Maaş (AZN)</th>

                        <th class="no-print">Ətraflı</th>

                    </tr>

                </thead>

                <tbody>

                    <?php 

                    $counter = 1;

                    foreach ($employee_data as $employee_id => $data): 

                        $name = isset($employee_names[$employee_id]) ? $employee_names[$employee_id] : 'Naməlum';

                        $gross_salary = $data['gross_salary'];

                        $deduction = $data['deduction'];

                        $debt = $data['debt'];

                        $additional_payment = isset($data['additional_payment']) ? $data['additional_payment'] : 0;

                        $reason = isset($data['reason']) ? $data['reason'] : '';

                        $net_salary = $data['net_salary'];

                        $absence_count = isset($data['absence_count']) ? $data['absence_count'] : 0;

                    ?>

                        <tr>

                            <td><?php echo $counter++; ?></td>

                            <td><?php echo htmlspecialchars($name); ?></td>

                            <td><?php echo number_format($gross_salary, 2); ?></td>

                            <td><?php echo $absence_count; ?></td>

                            <td><?php echo number_format($deduction, 2); ?></td>

                            <td><?php echo number_format($debt, 2); ?></td>

                            <td><?php echo number_format($additional_payment, 2); ?></td>

                            <td><?php echo htmlspecialchars($reason); ?></td>

                            <td><?php echo number_format($net_salary, 2); ?></td>

                            <td class="no-print">

                                <button class="btn btn-sm btn-info toggle-details" data-employee-id="<?php echo $employee_id; ?>">

                                    <i class="fas fa-info-circle"></i>

                                </button>

                            </td>

                        </tr>

                        <tr class="no-print">

                            <td colspan="10" class="p-0">

                                <div id="employee-details-<?php echo $employee_id; ?>" class="employee-details">

                                    <div class="row">

                                        <div class="col-md-6">

                                            <p><strong>İşçi ID:</strong> <?php echo $employee_id; ?></p>

                                            <p><strong>Maks. İstirahət Günü:</strong> <?php echo isset($employee_vacation_days[$employee_id]) ? $employee_vacation_days[$employee_id] : 'N/A'; ?></p>

                                        </div>

                                        <div class="col-md-6">

                                            <p><strong>Günlük Maaş:</strong> <?php echo isset($data['daily_wage']) ? number_format($data['daily_wage'], 2) : 'N/A'; ?> AZN</p>

                                            <p><strong>Proporsional Maaş:</strong> <?php echo isset($data['proportional_salary']) ? number_format($data['proportional_salary'], 2) : 'N/A'; ?> AZN</p>

                                        </div>

                                    </div>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    <tr class="total-row">

                        <td colspan="2" class="text-right"><strong>CƏMİ:</strong></td>

                        <td><?php echo number_format($total_gross, 2); ?></td>

                        <td>-</td>

                        <td><?php echo number_format($total_deductions, 2); ?></td>

                        <td><?php echo number_format($total_debt, 2); ?></td>

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



    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>

    <script>

        $(document).ready(function() {

            $('.toggle-details').on('click', function() {

                var employeeId = $(this).data('employee-id');

                $('#employee-details-' + employeeId).toggle();

            });

        });

    </script>

</body>

</html>

