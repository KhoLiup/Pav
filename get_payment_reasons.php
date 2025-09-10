<?php

// get_payment_reasons.php

session_start();

require_once 'config.php';



// JSON formatında cavab vermək üçün header təyin et

header('Content-Type: application/json');



// GET parametrlərini yoxla

if (!isset($_GET['category_id']) || !isset($_GET['firm_id'])) {

    echo json_encode([]);

    exit();

}



// GET parametrlərini doğrula və təmizlə

$category_id = filter_var($_GET['category_id'], FILTER_VALIDATE_INT);

$firm_id = filter_var($_GET['firm_id'], FILTER_VALIDATE_INT);



// Doğrulama uğursuzdursa, boş array qaytar

if ($category_id === false || $firm_id === false) {

    echo json_encode([]);

    exit();

}



try {

    // Müvafiq payment_reasons cədvəlindən məlumatları seç

    $stmt = $conn->prepare("

        SELECT id, reason_name 

        FROM payment_reasons 

        WHERE category_id = :category_id 

          AND firm_id = :firm_id 

        ORDER BY reason_name ASC

    ");

    $stmt->execute([

        ':category_id' => $category_id,

        ':firm_id' => $firm_id

    ]);

    $reasons = $stmt->fetchAll(PDO::FETCH_ASSOC);

    

    // JSON formatında cavab qaytar

    echo json_encode($reasons);

} catch (PDOException $e) {

    // Xətanı log et və boş array qaytar

    error_log("Səbəblər alınarkən xəta: " . $e->getMessage());

    echo json_encode([]);

}

?>

