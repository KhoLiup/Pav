<?php

// salary_history.php

session_start();

require 'config.php';



// İstifadəçi yoxlaması

if (!isset($_SESSION['user_id'])) {

    header('Location: login.php');

    exit();

}



// Aylara görə maaş ödənişlərini qruplaşdırırıq

$sql = "SELECT 
            DATE_FORMAT(payment_date, '%Y-%m') as payment_month, 
            COUNT(*) as payment_count,
            SUM(gross_salary) as total_gross,
            SUM(deductions) as total_deductions,
            SUM(additional_payment) as total_additional,
            SUM(net_salary) as total_net
        FROM salary_payments
        GROUP BY payment_month
        ORDER BY payment_month DESC";



$stmt = $conn->prepare($sql);

$stmt->execute();

$months = $stmt->fetchAll(PDO::FETCH_ASSOC);



// İl seçimi

$years = [];

foreach ($months as $month) {

    $year = substr($month['payment_month'], 0, 4);

    if (!in_array($year, $years)) {

        $years[] = $year;

    }

}

rsort($years);



// Seçilmiş il

$selected_year = isset($_GET['year']) ? $_GET['year'] : (empty($years) ? date('Y') : $years[0]);



?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title>Maaş Ödəniş Tarixçələri</title>

    <!-- Bootstrap CSS əlavə edirik -->

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        .month-card {
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }
        .month-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .card-header {
            background-color: #343a40;
            color: white;
        }
        .badge-summary {
            font-size: 85%;
            margin-right: 5px;
        }
        .year-filter {
            margin-bottom: 30px;
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
    </style>

</head>

<body>

<?php include 'includes/header.php'; ?>



<div class="container mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">

        <h2>Maaş Ödəniş Tarixçələri</h2>

        <a href="salary.php" class="btn btn-primary">

            <i class="fas fa-calculator"></i> Maaş Hesablama

        </a>

    </div>



    <!-- İl seçimi -->

    <div class="year-filter">

        <form method="GET" class="form-inline">

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



    <!-- Seçilmiş il üçün xülasə -->

    <?php

    $year_total_gross = 0;

    $year_total_deductions = 0;

    $year_total_additional = 0;

    $year_total_net = 0;

    $year_payment_count = 0;
    

    foreach ($months as $month) {

        if (substr($month['payment_month'], 0, 4) == $selected_year) {

            $year_total_gross += $month['total_gross'];

            $year_total_deductions += $month['total_deductions'];

            $year_total_additional += $month['total_additional'];

            $year_total_net += $month['total_net'];

            $year_payment_count += $month['payment_count'];

        }

    }

    ?>
    

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

        <div class="text-center mt-3">

            <span class="badge badge-secondary">Cəmi <?php echo $year_payment_count; ?> ödəniş</span>

        </div>

    </div>



    <div class="row">

        <?php foreach ($months as $month): 

            // Yalnız seçilmiş il üçün ayları göstər

            if (substr($month['payment_month'], 0, 4) != $selected_year) {

                continue;

            }
            

            $month_name = formatMonthName($month['payment_month']);

        ?>

            <div class="col-md-4">

                <div class="card month-card">

                    <div class="card-header d-flex justify-content-between align-items-center">

                        <h5 class="mb-0"><?php echo htmlspecialchars($month_name); ?></h5>

                        <span class="badge badge-light"><?php echo $month['payment_count']; ?> ödəniş</span>

                    </div>

                    <div class="card-body">

                        <div class="mb-3">

                            <span class="badge badge-success badge-summary">Ümumi: <?php echo number_format($month['total_gross'], 2); ?> AZN</span>

                            <span class="badge badge-danger badge-summary">Tutulma: <?php echo number_format($month['total_deductions'], 2); ?> AZN</span>

                            <span class="badge badge-info badge-summary">Əlavə: <?php echo number_format($month['total_additional'], 2); ?> AZN</span>

                            <span class="badge badge-primary badge-summary">Xalis: <?php echo number_format($month['total_net'], 2); ?> AZN</span>

                        </div>

                        <a href="salary_month.php?month=<?php echo $month['payment_month']; ?>" class="btn btn-outline-primary btn-block">

                            <i class="fas fa-eye"></i> Ətraflı Bax

                        </a>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>



<!-- JavaScript kitabxanalarını əlavə edirik -->

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>

</body>

</html>

