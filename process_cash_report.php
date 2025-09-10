<?php

// process_cash_report.php

session_start();

require 'config.php';



// İstifadəçi yoxlaması

if (!isset($_SESSION['user_id'])) {

    header('Location: login.php');

    exit();

}



// Form məlumatlarını alırıq

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $cashier_id = (int)$_POST['cashier_id'];

    $date = $_POST['date'];

    $bank_receipts = $_POST['bank_receipts'];

    $cash_given = (float)$_POST['cash_given'];

    $additional_cash = $_POST['additional_cash'];

    $total_amount = (float)$_POST['total_amount'];

    $pos_amount = (float)$_POST['pos_amount'];

    $difference = (float)$_POST['difference'];

    $is_debt = isset($_POST['is_debt']) ? 1 : 0;



    // Bank qəbzləri və əlavə kassadan verilən pulları JSON formatında saxlayırıq

    $bank_receipts_json = json_encode(array_map('floatval', $bank_receipts));

    $additional_cash_json = json_encode(array_map('floatval', $additional_cash));



    // Kassa hesabatını saxlayırıq

    $stmt = $conn->prepare("INSERT INTO cash_reports (employee_id, date, bank_receipts, cash_given, additional_cash, total_amount, pos_amount, difference, is_debt) VALUES (:employee_id, :date, :bank_receipts, :cash_given, :additional_cash, :total_amount, :pos_amount, :difference, :is_debt)");

    $stmt->execute([

        ':employee_id' => $cashier_id,

        ':date' => $date,

        ':bank_receipts' => $bank_receipts_json,

        ':cash_given' => $cash_given,

        ':additional_cash' => $additional_cash_json,

        ':total_amount' => $total_amount,

        ':pos_amount' => $pos_amount,

        ':difference' => $difference,

        ':is_debt' => $is_debt

    ]);



    // Əgər fərq borc kimi yazılmalıdırsa, borcu qeyd edirik

    if ($is_debt && $difference != 0) {

        $stmt = $conn->prepare("INSERT INTO debts (employee_id, amount, date, reason, is_paid) VALUES (:employee_id, :amount, :date, :reason, 0)");

        $stmt->execute([

            ':employee_id' => $cashier_id,

            ':amount' => abs($difference),

            ':date' => $date,

            ':reason' => 'Kassa fərqi'

        ]);

    }



    $_SESSION['success_message'] = 'Kassa hesabatı uğurla saxlanıldı.';

    header('Location: cash_reports.php?cashier_id=' . $cashier_id);

    exit();

} else {

    header('Location: cash_reports.php');

    exit();

}

?>