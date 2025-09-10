<?php

// employee_debts.php

session_start();

require 'config.php';



if(!isset($_SESSION['user_id'])) {

    header('Location: login.php');

    exit();

}



// İşçi ID-sini alırıq

if(!isset($_GET['employee_id'])) {

    $_SESSION['error_message'] = "İşçi seçilməyib.";

    header('Location: salary_history.php');

    exit();

}



$employee_id = $_GET['employee_id'];



// İşçinin məlumatlarını alırıq

$stmt = $conn->prepare("SELECT id, name, salary, max_vacation_days, start_date FROM employees WHERE id = :employee_id");

$stmt->execute([':employee_id' => $employee_id]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);



if(!$employee) {

    $_SESSION['error_message'] = "İşçi tapılmadı.";

    header('Location: salary_history.php');

    exit();

}



// Yeni borc əlavə etmək

if (isset($_POST['add_debt']) && $_SESSION['user_role'] === 'admin') {

    $amount = floatval($_POST['amount']);

    $date = $_POST['date'];

    $reason = $_POST['reason'];

    $month = date('Y-m', strtotime($date));



    if ($amount > 0) {

        $stmt = $conn->prepare("

            INSERT INTO debts (employee_id, amount, date, reason, is_paid, month)

            VALUES (:employee_id, :amount, :date, :reason, 0, :month)

        ");

        $stmt->execute([

            ':employee_id' => $employee_id,

            ':amount' => $amount,

            ':date' => $date,

            ':reason' => $reason,

            ':month' => $month

        ]);



        $_SESSION['success_message'] = "Borc uğurla əlavə edildi.";

        header("Location: employee_debts.php?employee_id=$employee_id");

        exit();

    } else {

        $_SESSION['error_message'] = "Məbləğ 0-dan böyük olmalıdır.";

    }

}



// Borcun ödənilmə statusunu yeniləmək

if (isset($_GET['pay_debt_id']) && $_SESSION['user_role'] === 'admin') {

    $debt_id = $_GET['pay_debt_id'];

    $payment_date = date('Y-m-d');



    $stmt = $conn->prepare("UPDATE debts SET is_paid = 1, payment_date = :payment_date WHERE id = :debt_id");

    $stmt->execute([

        ':payment_date' => $payment_date,

        ':debt_id' => $debt_id

    ]);



    $_SESSION['success_message'] = "Borc ödənildi olaraq işarələndi.";

    header("Location: employee_debts.php?employee_id=$employee_id");

    exit();

}



// Filtrləri tətbiq edirik

$query = "SELECT * FROM debts WHERE employee_id = :employee_id";

$params = [':employee_id' => $employee_id];



if(isset($_GET['from_date']) && !empty($_GET['from_date'])) {

    $query .= " AND date >= :from_date";

    $params[':from_date'] = $_GET['from_date'];

}



if(isset($_GET['to_date']) && !empty($_GET['to_date'])) {

    $query .= " AND date <= :to_date";

    $params[':to_date'] = $_GET['to_date'];

}



if(isset($_GET['status']) && $_GET['status'] !== '') {

    $query .= " AND is_paid = :status";

    $params[':status'] = $_GET['status'];

}



$query .= " ORDER BY date DESC";



$stmt = $conn->prepare($query);

$stmt->execute($params);

$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Ümumi borcları hesablayırıq

$total_paid = 0;

$total_unpaid = 0;



foreach($debts as $debt):

    // Ödənilmiş və ödənilməmiş borcların cəmini hesablayırıq

    if($debt['is_paid']) {

        $total_paid += $debt['amount'];

    } else {

        $total_unpaid += $debt['amount'];

    }

endforeach;



// Aylara görə borcları qruplaşdırırıq

$monthly_debts = [];

$stmt = $conn->prepare("

    SELECT 

        DATE_FORMAT(date, '%Y-%m') as month,

        SUM(CASE WHEN is_paid = 0 THEN amount ELSE 0 END) as unpaid,

        SUM(CASE WHEN is_paid = 1 THEN amount ELSE 0 END) as paid

    FROM debts

    WHERE employee_id = :employee_id

    GROUP BY month

    ORDER BY month DESC

");

$stmt->execute([':employee_id' => $employee_id]);

$monthly_debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title><?php echo htmlspecialchars($employee['name']); ?> - Borc Tarixçəsi</title>


    <style>

        .card {

            margin-bottom: 20px;

            box-shadow: 0 4px 8px rgba(0,0,0,0.1);

        }

        .card-header {

            background-color: #343a40;

            color: white;

        }

        .summary-card {

            text-align: center;

            padding: 15px;

            border-radius: 5px;

            margin-bottom: 15px;

            color: white;

        }

        .summary-unpaid {

            background-color: #dc3545;

        }

        .summary-paid {

            background-color: #28a745;

        }

        .badge-paid {

            background-color: #28a745;

        }

        .badge-unpaid {

            background-color: #dc3545;

        }

        .filter-section {

            margin-bottom: 20px;

            padding: 15px;

            background-color: #f8f9fa;

            border-radius: 5px;

        }

        .monthly-summary {

            margin-bottom: 30px;

        }

        .monthly-summary .list-group-item {

            display: flex;

            justify-content: space-between;

            align-items: center;

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

            <h2><?php echo htmlspecialchars($employee['name']); ?> - Borc Tarixçəsi</h2>

            <div class="no-print">

                <button class="btn btn-info" onclick="window.print()">

                    <i class="fas fa-print"></i> Çap Et

                </button>

                <a href="employee_payments.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-secondary">

                    <i class="fas fa-arrow-left"></i> Geri

                </a>

                <a href="salary.php" class="btn btn-primary">

                    <i class="fas fa-calculator"></i> Maaş Hesablama

                </a>

            </div>

        </div>



        <?php if (isset($_SESSION['error_message'])): ?>

            <div class="alert alert-danger">

                <?php 

                    echo $_SESSION['error_message']; 

                    unset($_SESSION['error_message']);

                ?>

            </div>

        <?php endif; ?>



        <?php if (isset($_SESSION['success_message'])): ?>

            <div class="alert alert-success">

                <?php 

                    echo $_SESSION['success_message']; 

                    unset($_SESSION['success_message']);

                ?>

            </div>

        <?php endif; ?>



        <div class="row">

            <div class="col-md-4">

                <!-- İşçi məlumatları -->

                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-0">İşçi Məlumatları</h5>

                    </div>

                    <div class="card-body">

                        <p><strong>İşçi ID:</strong> <?php echo $employee_id; ?></p>

                        <p><strong>Ad:</strong> <?php echo htmlspecialchars($employee['name']); ?></p>

                        <p><strong>Cari Maaş:</strong> <?php echo number_format($employee['salary'], 2); ?> AZN</p>

                        <p><strong>İşə Başlama Tarixi:</strong> <?php echo date('d.m.Y', strtotime($employee['start_date'])); ?></p>

                        <p><strong>Maks. İstirahət Günü:</strong> <?php echo $employee['max_vacation_days']; ?> gün</p>

                    </div>

                </div>



                <!-- Borc xülasəsi -->

                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-0">Borc Xülasəsi</h5>

                    </div>

                    <div class="card-body">

                        <div class="summary-card summary-unpaid">

                            <h5>Ödənilməmiş Borc</h5>

                            <h3><?php echo number_format($total_unpaid, 2); ?> AZN</h3>

                        </div>

                        <div class="summary-card summary-paid">

                            <h5>Ödənilmiş Borc</h5>

                            <h3><?php echo number_format($total_paid, 2); ?> AZN</h3>

                        </div>

                    </div>

                </div>



                <!-- Aylıq borc xülasəsi -->

                <div class="card monthly-summary">

                    <div class="card-header">

                        <h5 class="mb-0">Aylıq Borc Xülasəsi</h5>

                    </div>

                    <ul class="list-group list-group-flush">

                        <?php foreach ($monthly_debts as $month): ?>

                            <li class="list-group-item">

                                <span><?php echo formatMonthName($month['month']); ?></span>

                                <div>

                                    <?php if ($month['unpaid'] > 0): ?>

                                        <span class="badge badge-danger">

                                            <?php echo number_format($month['unpaid'], 2); ?> AZN

                                        </span>

                                    <?php endif; ?>

                                    <?php if ($month['paid'] > 0): ?>

                                        <span class="badge badge-success">

                                            <?php echo number_format($month['paid'], 2); ?> AZN

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </li>

                        <?php endforeach; ?>

                    </ul>

                </div>

            </div>



            <div class="col-md-8">

                <!-- Yeni borc əlavə etmə forması -->

                <?php if ($_SESSION['user_role'] === 'admin'): ?>

                <div class="card no-print">

                    <div class="card-header">

                        <h5 class="mb-0">Yeni Borc Əlavə Et</h5>

                    </div>

                    <div class="card-body">

                        <form method="POST" action="">

                            <div class="form-row">

                                <div class="form-group col-md-4">

                                    <label for="amount">Məbləğ (AZN)</label>

                                    <input type="number" step="0.01" class="form-control" id="amount" name="amount" required>

                                </div>

                                <div class="form-group col-md-4">

                                    <label for="date">Tarix</label>

                                    <input type="date" class="form-control" id="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>

                                </div>

                                <div class="form-group col-md-4">

                                    <label for="reason">Səbəb</label>

                                    <select class="form-control" id="reason" name="reason">

                                        <option value="Avans">Avans</option>

                                        <option value="Kredit">Kredit</option>

                                        <option value="Növbəti ay üçün borc">Növbəti ay üçün borc</option>

                                        <option value="Digər">Digər</option>

                                    </select>

                                </div>

                            </div>

                            <button type="submit" name="add_debt" class="btn btn-primary">Borc Əlavə Et</button>

                        </form>

                    </div>

                </div>

                <?php endif; ?>



                <!-- Filtrləmə forması -->

                <div class="card filter-section no-print">

                    <div class="card-header">

                        <h5 class="mb-0">Borcları Filtrlə</h5>

                    </div>

                    <div class="card-body">

                        <form method="GET" action="" class="form-row">

                            <input type="hidden" name="employee_id" value="<?php echo $employee_id; ?>">

                            <div class="form-group col-md-4">

                                <label for="from_date">Başlanğıc Tarixi</label>

                                <input type="date" class="form-control" id="from_date" name="from_date" 

                                       value="<?php echo isset($_GET['from_date']) ? $_GET['from_date'] : ''; ?>">

                            </div>

                            <div class="form-group col-md-4">

                                <label for="to_date">Bitmə Tarixi</label>

                                <input type="date" class="form-control" id="to_date" name="to_date"

                                       value="<?php echo isset($_GET['to_date']) ? $_GET['to_date'] : ''; ?>">

                            </div>

                            <div class="form-group col-md-4">

                                <label for="status">Status</label>

                                <select class="form-control" id="status" name="status">

                                    <option value="">Hamısı</option>

                                    <option value="0" <?php echo (isset($_GET['status']) && $_GET['status'] === '0') ? 'selected' : ''; ?>>Ödənilməyib</option>

                                    <option value="1" <?php echo (isset($_GET['status']) && $_GET['status'] === '1') ? 'selected' : ''; ?>>Ödənilib</option>

                                </select>

                            </div>

                            <div class="form-group col-md-12">

                                <button type="submit" class="btn btn-primary">Filtrlə</button>

                                <a href="employee_debts.php?employee_id=<?php echo $employee_id; ?>" class="btn btn-secondary">Sıfırla</a>

                            </div>

                        </form>

                    </div>

                </div>



                <!-- Borcların siyahısı -->

                <div class="card">

                    <div class="card-header">

                        <h5 class="mb-0">Borc Siyahısı</h5>

                    </div>

                    <div class="card-body">

                        <div class="table-responsive">

                            <table class="table table-bordered table-hover">

                                <thead class="thead-dark">

                                    <tr>

                                        <th>№</th>

                                        <th>Məbləğ (AZN)</th>

                                        <th>Tarix</th>

                                        <th>Səbəb</th>

                                        <th>Status</th>

                                        <th>Ödəniş Tarixi</th>

                                        <th class="no-print">Əməliyyatlar</th>

                                    </tr>

                                </thead>

                                <tbody>

                                    <?php 

                                    $counter = 1;

                                    foreach ($debts as $debt): 

                                    ?>

                                        <tr>

                                            <td><?php echo $counter++; ?></td>

                                            <td><?php echo number_format($debt['amount'], 2); ?></td>

                                            <td><?php echo date('d.m.Y', strtotime($debt['date'])); ?></td>

                                            <td><?php echo isset($debt['reason']) ? htmlspecialchars($debt['reason']) : ''; ?></td>

                                            <td>

                                                <?php if ($debt['is_paid']): ?>

                                                    <span class="badge badge-success">Ödənilib</span>

                                                <?php else: ?>

                                                    <span class="badge badge-danger">Ödənilməyib</span>

                                                <?php endif; ?>

                                            </td>

                                            <td>

                                                <?php

                                                if (!empty($debt['payment_date'])) {

                                                    echo date('d.m.Y', strtotime($debt['payment_date']));

                                                } else {

                                                    echo '-';

                                                }

                                                ?>

                                            </td>

                                            <td class="no-print">

                                                <?php if (!$debt['is_paid'] && $_SESSION['user_role'] === 'admin'): ?>

                                                    <a href="employee_debts.php?employee_id=<?php echo $employee_id; ?>&pay_debt_id=<?php echo $debt['id']; ?>" 

                                                       class="btn btn-sm btn-success" 

                                                       onclick="return confirm('Bu borcu ödənildi olaraq işarələmək istədiyinizə əminsiniz?')">

                                                        <i class="fas fa-check"></i> Ödənildi

                                                    </a>

                                                <?php else: ?>

                                                    -

                                                <?php endif; ?>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                    <?php if (empty($debts)): ?>

                                        <tr>

                                            <td colspan="7" class="text-center">Borc tapılmadı</td>

                                        </tr>

                                    <?php endif; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- JavaScript kitabxanalarını əlavə edirik -->

    <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/js/bootstrap.min.js"></script>

</body>

</html>

