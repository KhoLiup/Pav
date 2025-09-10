<?php

// report.php



// Xətaların göstərilməsi üçün (istehsalat mühitində deaktiv edin)

ini_set('display_errors', 0);

ini_set('display_startup_errors', 0);

error_reporting(0);



// Verilənlər bazası bağlantısı üçün config faylını daxil et

require_once 'config.php';



// Sessiya başladılmaması üçün əvvəlcə config.php daxil edilməlidir

// session_start(); // config.php-də artıq sessiya başladıldığı üçün buna ehtiyac yoxdur



// İstifadəçi yoxlanışı

if (!isset($_SESSION['user_id'])) {

    header("Location: login.php");

    exit();

}



// Statistikaları əldə etmək üçün funksiyalar

function getTotalPayments($conn) {

    $stmt = $conn->query("SELECT COUNT(*) as total FROM payments");

    return $stmt->fetch()['total'];

}



function getTotalAmount($conn) {

    $stmt = $conn->query("SELECT SUM(amount) as total_amount FROM payments");

    $result = $stmt->fetch()['total_amount'];

    return $result ? $result : 0;

}



function getOverduePayments($conn) {

    $stmt = $conn->prepare("SELECT COUNT(*) as overdue FROM payments WHERE status = 'Gecikmiş'");

    $stmt->execute();

    return $stmt->fetch()['overdue'];

}



function getPaidPayments($conn) {

    $stmt = $conn->prepare("SELECT COUNT(*) as paid FROM payments WHERE status = 'Ödənilib'");

    $stmt->execute();

    return $stmt->fetch()['paid'];

}



function getAveragePayment($conn) {

    $stmt = $conn->query("SELECT AVG(amount) as average FROM payments");

    $result = $stmt->fetch()['average'];

    return $result ? number_format($result, 2) : 0;

}



function getPaymentCategories($conn) {

    $stmt = $conn->query("SELECT pc.category_name, COUNT(*) as count 

                          FROM payments p

                          JOIN payment_categories pc ON p.category_id = pc.id

                          GROUP BY pc.category_name");

    return $stmt->fetchAll();

}



function getMonthlyPayments($conn) {

    $stmt = $conn->query("SELECT DATE_FORMAT(due_date, '%Y-%m') as month, SUM(amount) as total 

                          FROM payments 

                          GROUP BY month 

                          ORDER BY month ASC");

    return $stmt->fetchAll();

}



function getFirmPayments($conn) {

    $stmt = $conn->query("SELECT f.firm_name, COUNT(*) as count 

                          FROM payments p

                          JOIN firms f ON p.firm_id = f.id

                          GROUP BY f.firm_name");

    return $stmt->fetchAll();

}



function getPaymentReasons($conn) {

    $stmt = $conn->query("SELECT pr.reason_name, COUNT(*) as count 

                          FROM payments p

                          JOIN payment_reasons pr ON p.reason_id = pr.id

                          GROUP BY pr.reason_name");

    return $stmt->fetchAll();

}



// Statistikaları əldə et

$total_payments = getTotalPayments($conn);

$total_amount = getTotalAmount($conn);

$overdue_payments = getOverduePayments($conn);

$paid_payments = getPaidPayments($conn);

$average_payment = getAveragePayment($conn);

$payment_categories = getPaymentCategories($conn);

$monthly_payments = getMonthlyPayments($conn);

$firm_payments = getFirmPayments($conn);

$payment_reasons = getPaymentReasons($conn);

?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title>Hesabat Səhifəsi</title>

    <!-- Bootstrap 5 CSS -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- DataTables CSS -->

    <link href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">

    <!-- DataTables Buttons CSS -->

    <link href="https://cdn.datatables.net/buttons/2.3.2/css/buttons.bootstrap5.min.css" rel="stylesheet">

    <!-- Date Range Picker CSS -->

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" />

    <!-- Custom CSS -->

    <style>

        .stat-card {

            padding: 20px;

            color: #fff;

            border-radius: 8px;

            margin-bottom: 20px;

            text-align: center;

        }

        .bg-primary-custom {

            background-color: #0d6efd;

        }

        .bg-success-custom {

            background-color: #198754;

        }

        .bg-warning-custom {

            background-color: #ffc107;

            color: #212529;

        }

        .bg-danger-custom {

            background-color: #dc3545;

        }

        .stat-card h3 {

            margin: 0;

            font-size: 2rem;

        }

        .stat-card p {

            margin: 0;

            font-size: 1rem;

        }

        .filter-section {

            margin-bottom: 30px;

        }

        /* Çartların ölçülərini tənzimləmək */

        .chart-container {

            position: relative;

            width: 100%;

            max-width: 600px;

            height: 400px; /* Düzgün ölçü */

            margin: auto;

            margin-bottom: 30px;

        }

        /* Responsivlik üçün */

        @media (max-width: 768px) {

            .chart-container {

                height: 300px;

            }

        }

        @media (max-width: 576px) {

            .chart-container {

                height: 250px;

            }

        }

    </style>

    <!-- Chart.js -->

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

</head>

<body>



    <!-- Naviqasiya paneli daxil edirik -->

    <?php require_once 'includes/navbar.php'; ?>



<!-- Hesabat Məzmunu -->

<div class="container my-5">

    <h2 class="mb-4 text-center">Hesabat Səhifəsi</h2>

    

    <!-- Ümumi Statistikalar -->

    <div class="row">

        <div class="col-md-3">

            <div class="stat-card bg-primary-custom">

                <h3><?php echo $total_payments; ?></h3>

                <p>Toplam Ödənişlər</p>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-success-custom">

                <h3><?php echo number_format($total_amount, 2); ?> AZN</h3>

                <p>Toplam Məbləğ</p>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-warning-custom">

                <h3><?php echo $overdue_payments; ?></h3>

                <p>Gecikmiş Ödənişlər</p>

            </div>

        </div>

        <div class="col-md-3">

            <div class="stat-card bg-danger-custom">

                <h3><?php echo $paid_payments; ?></h3>

                <p>Ödənilmiş Ödənişlər</p>

            </div>

        </div>

    </div>

    

    <!-- Orta Ödəniş Məbləği -->

    <div class="row">

        <div class="col-md-6">

            <div class="stat-card bg-primary-custom">

                <h3><?php echo $average_payment; ?> AZN</h3>

                <p>Orta Ödəniş Məbləği</p>

            </div>

        </div>

    </div>

    

    <!-- Filtrləmə Bölməsi -->

    <div class="row filter-section">

        <div class="col-md-12">

            <form id="filterForm" class="row g-3">

                <div class="col-md-3">

                    <label for="filter_firm" class="form-label">Firma</label>

                    <select id="filter_firm" class="form-select" name="firm">

                        <option value="">Hamısı</option>

                        <?php

                        // Firmaları çəkin

                        $stmt = $conn->query("SELECT id, firm_name FROM firms ORDER BY firm_name ASC");

                        $firms = $stmt->fetchAll();

                        foreach ($firms as $firm):

                        ?>

                            <option value="<?php echo htmlspecialchars($firm['firm_name']); ?>"><?php echo htmlspecialchars($firm['firm_name']); ?></option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <label for="filter_category" class="form-label">Kateqoriya</label>

                    <select id="filter_category" class="form-select" name="category">

                        <option value="">Hamısı</option>

                        <?php

                        // Kateqoriyaları çəkin

                        $stmt = $conn->query("SELECT id, category_name FROM payment_categories ORDER BY category_name ASC");

                        $categories = $stmt->fetchAll();

                        foreach ($categories as $category):

                        ?>

                            <option value="<?php echo htmlspecialchars($category['category_name']); ?>"><?php echo htmlspecialchars($category['category_name']); ?></option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-md-3">

                    <label for="filter_status" class="form-label">Status</label>

                    <select id="filter_status" class="form-select" name="status">

                        <option value="">Hamısı</option>

                        <option value="Ödənilib">Ödənilib</option>

                        <option value="Gecikmiş">Gecikmiş</option>

                        <option value="Ödənilməyib">Ödənilməyib</option>

                    </select>

                </div>

                <div class="col-md-3">

                    <label for="filter_date" class="form-label">Tarix Aralığı</label>

                    <input type="text" class="form-control" id="filter_date" name="date_range" placeholder="YYYY-MM-DD - YYYY-MM-DD">

                </div>

                <div class="col-md-12 text-end">

                    <button type="button" id="resetFilters" class="btn btn-secondary">Filtrləri Sıfırla</button>

                </div>

            </form>

        </div>

    </div>

    

    <!-- Ödənişlər Çartları -->

    <div class="row">

        <div class="col-md-6">

            <h4 class="mt-4">Kateqoriya üzrə Ödənişlər</h4>

            <div class="chart-container">

                <canvas id="categoryChart"></canvas>

            </div>

        </div>

        <div class="col-md-6">

            <h4 class="mt-4">Aylıq Ödəniş Trendləri</h4>

            <div class="chart-container">

                <canvas id="monthlyChart"></canvas>

            </div>

        </div>

    </div>

    <div class="row">

        <div class="col-md-6">

            <h4 class="mt-4">Firma üzrə Ödənişlər</h4>

            <div class="chart-container">

                <canvas id="firmChart"></canvas>

            </div>

        </div>

        <div class="col-md-6">

            <h4 class="mt-4">Səbəb üzrə Ödənişlər</h4>

            <div class="chart-container">

                <canvas id="reasonChart"></canvas>

            </div>

        </div>

    </div>

    

    <!-- Ödənişlər Cədvəli -->

    <div class="row">

        <div class="col-md-12">

            <h4 class="mt-5">Ödənişlər Cədvəli</h4>

            <table id="paymentsTable" class="table table-striped table-bordered" style="width:100%">

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Firma Adı</th>

                        <th>Kateqoriya</th>

                        <th>Səbəb</th>

                        <th>Məbləğ (AZN)</th>

                        <th>Son Ödəniş Tarixi</th>

                        <th>Status</th>

                    </tr>

                </thead>

                <tbody>

                    <?php

                    // Ödənişləri əldə et

                    $stmt = $conn->query("

                        SELECT p.id, f.firm_name, pc.category_name, pr.reason_name, p.amount, p.due_date, p.status

                        FROM payments p

                        JOIN firms f ON p.firm_id = f.id

                        JOIN payment_categories pc ON p.category_id = pc.id

                        JOIN payment_reasons pr ON p.reason_id = pr.id

                        ORDER BY p.due_date DESC

                    ");

                    $payments = $stmt->fetchAll();



                    foreach ($payments as $payment):

                    ?>

                        <tr>

                            <td><?php echo htmlspecialchars($payment['id']); ?></td>

                            <td><?php echo htmlspecialchars($payment['firm_name']); ?></td>

                            <td><?php echo htmlspecialchars($payment['category_name']); ?></td>

                            <td><?php echo htmlspecialchars($payment['reason_name']); ?></td>

                            <td><?php echo number_format($payment['amount'], 2); ?></td>

                            <td><?php echo htmlspecialchars($payment['due_date']); ?></td>

                            <td>

                                <?php

                                    if ($payment['status'] === 'Ödənilib') {

                                        echo '<span class="badge bg-success">Ödənilib</span>';

                                    } elseif ($payment['status'] === 'Gecikmiş') {

                                        echo '<span class="badge bg-warning text-dark">Gecikmiş</span>';

                                    } else {

                                        echo '<span class="badge bg-danger">Ödənilməyib</span>';

                                    }

                                ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>



<!-- Bootstrap 5 JS Bundle (Includes Popper) -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<!-- jQuery (Required for DataTables) -->

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<!-- DataTables JS -->

<script src="https://cdn.datatables.net/1.13.5/js/jquery.dataTables.min.js"></script>

<script src="https://cdn.datatables.net/1.13.5/js/dataTables.bootstrap5.min.js"></script>

<!-- DataTables Buttons JS -->

<script src="https://cdn.datatables.net/buttons/2.3.2/js/dataTables.buttons.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.3.2/js/buttons.bootstrap5.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>

<script src="https://cdn.datatables.net/buttons/2.3.2/js/buttons.html5.min.js"></script>

<script src="https://cdn.datatables.net/buttons/2.3.2/js/buttons.print.min.js"></script>

<!-- Moment.js (For Date Range Picker) -->

<script src="https://cdn.jsdelivr.net/momentjs/latest/moment.min.js"></script>

<!-- Date Range Picker JS -->

<script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>



<script>

    $(document).ready(function() {

        // DataTables Initialization with Buttons

        var table = $('#paymentsTable').DataTable({

            dom: 'Bfrtip',

            buttons: [

                'copyHtml5',

                'excelHtml5',

                'csvHtml5',

                'pdfHtml5',

                'print'

            ],

            "paging": true,

            "lengthChange": true,

            "searching": true,

            "ordering": true,

            "info": true,

            "autoWidth": false,

            "responsive": true

        });



        // Date Range Picker Initialization

        $('#filter_date').daterangepicker({

            opens: 'left',

            locale: {

                format: 'YYYY-MM-DD'

            }

        });



        // Filtrləmə funksiyası

        $('#filterForm select, #filter_date').on('change', function() {

            applyFilters();

        });



        // Filtrləri tətbiq etmək üçün funksiyanı yaradın

        function applyFilters() {

            var firm = $('#filter_firm').val();

            var category = $('#filter_category').val();

            var status = $('#filter_status').val();

            var dateRange = $('#filter_date').val();



            // DataTables sütun axtarışlarını tətbiq et

            table.column(1).search(firm, true, false);

            table.column(2).search(category, true, false);

            table.column(6).search(status, true, false);



            // Tarix aralığını filtrləmək üçün xüsusi axtarış əlavə et

            if(dateRange) {

                var dates = dateRange.split(' - ');

                var startDate = dates[0];

                var endDate = dates[1];

                $.fn.dataTable.ext.search.push(

                    function(settings, data, dataIndex) {

                        var dueDate = data[5]; // Son Ödəniş Tarixi sütunu

                        if (dueDate >= startDate && dueDate <= endDate) {

                            return true;

                        }

                        return false;

                    }

                );

            } else {

                // Əgər tarix aralığı seçilməyibsə, son əlavə olunan axtarışı sil

                $.fn.dataTable.ext.search.pop();

            }



            table.draw();



            // Çartları yeniləyin

            updateCharts();

        }



        // Filtrləri sıfırlamaq üçün düymə

        $('#resetFilters').on('click', function() {

            $('#filterForm')[0].reset();

            $('#filter_date').val('');

            $.fn.dataTable.ext.search.pop();

            table.columns().search('').draw();

            updateCharts();

        });



        // Çartları yeniləyən funksiyanı yaradın

        function updateCharts() {

            var filteredData = table.rows({ filter: 'applied' }).data().toArray();



            var categoryCounts = {};

            var monthlyTotals = {};

            var firmCounts = {};

            var reasonCounts = {};



            filteredData.forEach(function(payment) {

                // Kateqoriya

                var category = payment[2];

                if(categoryCounts[category]) {

                    categoryCounts[category]++;

                } else {

                    categoryCounts[category] = 1;

                }



                // Aylıq

                var month = payment[5].substring(0,7); // YYYY-MM

                var amount = parseFloat(payment[4]);

                if(monthlyTotals[month]) {

                    monthlyTotals[month] += amount;

                } else {

                    monthlyTotals[month] = amount;

                }



                // Firma

                var firm = payment[1];

                if(firmCounts[firm]) {

                    firmCounts[firm]++;

                } else {

                    firmCounts[firm] = 1;

                }



                // Səbəb

                var reason = payment[3];

                if(reasonCounts[reason]) {

                    reasonCounts[reason]++;

                } else {

                    reasonCounts[reason] = 1;

                }

            });



            // Kateqoriya Çartını Yeniləyin

            categoryChart.data.labels = Object.keys(categoryCounts);

            categoryChart.data.datasets[0].data = Object.values(categoryCounts);

            categoryChart.update();



            // Aylıq Çartını Yeniləyin

            monthlyChart.data.labels = Object.keys(monthlyTotals);

            monthlyChart.data.datasets[0].data = Object.values(monthlyTotals);

            monthlyChart.update();



            // Firma Çartını Yeniləyin

            firmChart.data.labels = Object.keys(firmCounts);

            firmChart.data.datasets[0].data = Object.values(firmCounts);

            firmChart.update();



            // Səbəb Çartını Yeniləyin

            reasonChart.data.labels = Object.keys(reasonCounts);

            reasonChart.data.datasets[0].data = Object.values(reasonCounts);

            reasonChart.update();

        }



        // Çartlar üçün ilkin məlumatları hazırlamaq

        var initialChartData = {

            categoryLabels: [

                <?php foreach ($payment_categories as $category): ?>

                    "<?php echo htmlspecialchars($category['category_name']); ?>",

                <?php endforeach; ?>

            ],

            categoryData: [

                <?php foreach ($payment_categories as $category): ?>

                    <?php echo htmlspecialchars($category['count']); ?>,

                <?php endforeach; ?>

            ],

            monthlyLabels: [

                <?php foreach ($monthly_payments as $month): ?>

                    "<?php echo htmlspecialchars($month['month']); ?>",

                <?php endforeach; ?>

            ],

            monthlyData: [

                <?php foreach ($monthly_payments as $month): ?>

                    <?php echo htmlspecialchars($month['total']); ?>,

                <?php endforeach; ?>

            ],

            firmLabels: [

                <?php foreach ($firm_payments as $firm): ?>

                    "<?php echo htmlspecialchars($firm['firm_name']); ?>",

                <?php endforeach; ?>

            ],

            firmData: [

                <?php foreach ($firm_payments as $firm): ?>

                    <?php echo htmlspecialchars($firm['count']); ?>,

                <?php endforeach; ?>

            ],

            reasonLabels: [

                <?php foreach ($payment_reasons as $reason): ?>

                    "<?php echo htmlspecialchars($reason['reason_name']); ?>",

                <?php endforeach; ?>

            ],

            reasonData: [

                <?php foreach ($payment_reasons as $reason): ?>

                    <?php echo htmlspecialchars($reason['count']); ?>,

                <?php endforeach; ?>

            ]

        };



        // Kateqoriya üzrə Ödənişlər Çartı

        var categoryCtx = document.getElementById('categoryChart').getContext('2d');

        var categoryChart = new Chart(categoryCtx, {

            type: 'pie',

            data: {

                labels: initialChartData.categoryLabels,

                datasets: [{

                    label: 'Kateqoriya üzrə Ödənişlər',

                    data: initialChartData.categoryData,

                    backgroundColor: [

                        '#007bff',

                        '#28a745',

                        '#ffc107',

                        '#dc3545',

                        '#17a2b8',

                        '#6f42c1',

                        '#fd7e14',

                        '#6610f2',

                        '#e83e8c',

                        '#20c997'

                    ],

                    borderWidth: 1

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false, // Bu parametr doğru ölçünü təmin edir

                plugins: {

                    legend: {

                        position: 'bottom',

                    },

                    tooltip: {

                        enabled: true

                    }

                }

            }

        });



        // Aylıq Ödəniş Trendləri Çartı

        var monthlyCtx = document.getElementById('monthlyChart').getContext('2d');

        var monthlyChart = new Chart(monthlyCtx, {

            type: 'line',

            data: {

                labels: initialChartData.monthlyLabels,

                datasets: [{

                    label: 'Aylıq Ödənişlər (AZN)',

                    data: initialChartData.monthlyData,

                    backgroundColor: 'rgba(40, 167, 69, 0.2)',

                    borderColor: 'rgba(40, 167, 69, 1)',

                    borderWidth: 2,

                    fill: true,

                    tension: 0.4

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                scales: {

                    x: {

                        title: {

                            display: true,

                            text: 'Ay'

                        }

                    },

                    y: {

                        title: {

                            display: true,

                            text: 'Məbləğ (AZN)'

                        },

                        beginAtZero: true

                    }

                },

                plugins: {

                    legend: {

                        display: true,

                        position: 'top',

                    },

                    tooltip: {

                        enabled: true

                    }

                }

            }

        });



        // Firma üzrə Ödənişlər Çartı

        var firmCtx = document.getElementById('firmChart').getContext('2d');

        var firmChart = new Chart(firmCtx, {

            type: 'bar',

            data: {

                labels: initialChartData.firmLabels,

                datasets: [{

                    label: 'Firma üzrə Ödənişlər',

                    data: initialChartData.firmData,

                    backgroundColor: '#17a2b8',

                    borderColor: '#17a2b8',

                    borderWidth: 1

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                scales: {

                    x: {

                        title: {

                            display: true,

                            text: 'Firma'

                        }

                    },

                    y: {

                        title: {

                            display: true,

                            text: 'Ödəniş Sayı'

                        },

                        beginAtZero: true

                    }

                },

                plugins: {

                    legend: {

                        display: false

                    },

                    tooltip: {

                        enabled: true

                    }

                }

            }

        });



        // Səbəb üzrə Ödənişlər Çartı

        var reasonCtx = document.getElementById('reasonChart').getContext('2d');

        var reasonChart = new Chart(reasonCtx, {

            type: 'doughnut',

            data: {

                labels: initialChartData.reasonLabels,

                datasets: [{

                    label: 'Səbəb üzrə Ödənişlər',

                    data: initialChartData.reasonData,

                    backgroundColor: [

                        '#6f42c1',

                        '#fd7e14',

                        '#20c997',

                        '#ffc107',

                        '#dc3545',

                        '#007bff',

                        '#28a745',

                        '#17a2b8',

                        '#6610f2',

                        '#e83e8c'

                    ],

                    borderWidth: 1

                }]

            },

            options: {

                responsive: true,

                maintainAspectRatio: false,

                plugins: {

                    legend: {

                        position: 'bottom',

                    },

                    tooltip: {

                        enabled: true

                    }

                }

            }

        });

    });

</script>



</body>

</html>

