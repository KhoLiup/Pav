<?php

// update_payment_status.php







// Error reporting-i istəhsalat mühitində deaktiv etmək üçün

ini_set('display_errors', 0);

ini_set('display_startup_errors', 0);

error_reporting(0);



// Database və API parametrlərini daxil et

require_once 'config.php';



// Mesaj göndərmə funksiyası

function sendWhatsAppMessage($number, $message) {

    // msg.pavilion.az API endpointi - API.md-ə uyğun

    $url = 'https://msg.pavilion.az/api/send-message';



    // Telefon nömrəsini təmizləyirik

    $number = preg_replace('/[^0-9]/', '', $number);



    // Mesaj məlumatları

    $data = array(

        'number' => $number,

        'message' => $message

    );



    // cURL sorğusunu qur

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_setopt($ch, CURLOPT_POST, true);

    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

    curl_setopt($ch, CURLOPT_HTTPHEADER, [

        "Content-Type: application/json"

    ]);

    curl_setopt($ch, CURLOPT_TIMEOUT, 30);



    $response = curl_exec($ch);

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    $err = curl_error($ch);

    curl_close($ch);



    if ($err) {

        return ['success' => false, 'error' => "cURL Error #: " . $err];

    }



    // Cavabı JSON formatında decode et

    $response_data = json_decode($response, true);
    

    // API cavabını yoxla - API.md-ə görə formatlar

    if ($http_status == 200) {

        // Uğurlu halda: { status: "sent" }

        if (isset($response_data['status']) && $response_data['status'] === 'sent') {

            return [

                'success' => true,

                'message' => 'Mesaj uğurla göndərildi',

                'data' => $response_data,

                'http_status' => $http_status

            ];

        } else {

            return [

                'success' => false,

                'error' => 'Gözlənilməz cavab formatı',

                'data' => $response_data,

                'http_status' => $http_status

            ];

        }

    } elseif ($http_status == 503) {

        // Bağlantı qurulmayıbsa: { error: "Bot hazır deyil. QR kodunu skan edin." }

        return [

            'success' => false,

            'error' => $response_data['error'] ?? 'Bot hazır deyil. QR kodunu skan edin.',

            'data' => $response_data,

            'http_status' => $http_status

        ];

    } elseif ($http_status == 400) {

        // Parametrlər eksikdirsə: { error: "'number' və 'message' tələb olunur" }

        return [

            'success' => false,

            'error' => $response_data['error'] ?? 'Parametrlər eksikdir',

            'data' => $response_data,

            'http_status' => $http_status

        ];

    } elseif ($http_status == 500) {

        // Xəta halında: { error: "Xəta mesajı" }

        return [

            'success' => false,

            'error' => $response_data['error'] ?? 'Server xətası',

            'data' => $response_data,

            'http_status' => $http_status

        ];

    } else {

        return [

            'success' => false,

            'error' => "HTTP Error: " . $http_status . " - " . ($response_data['error'] ?? 'Bilinməyən xəta'),

            'data' => $response_data,

            'http_status' => $http_status

        ];

    }

}



// Log mesajlarını yazmaq üçün funksiya

function log_message($message) {

    $log_file = 'update_payment_status.log';

    $current_time = date('Y-m-d H:i:s');

    file_put_contents($log_file, "[$current_time] $message\n", FILE_APPEND);

}



// Timezone təyin edin (Azərbaycan üçün)

date_default_timezone_set('Asia/Baku');



try {

    // Cari tarixi əldə et

    $current_date = date('Y-m-d');



    // Ödənilib və ya Gecikmiş statusunda olmayan ödənişləri seç

    $stmt = $conn->prepare("SELECT id, due_date, status FROM payments WHERE status NOT IN ('Ödənilib', 'Gecikmiş')");

    $stmt->execute();

    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // Status yeniləmə üçün hazırla

    $update_stmt = $conn->prepare("UPDATE payments SET status = :status WHERE id = :id");



    // Gecikmiş ödənişləri saxlayacaq array

    $overdue_payments = [];



    foreach ($payments as $payment) {

        $payment_id = $payment['id'];

        $due_date = $payment['due_date'];

        $current_status = $payment['status'];



        // `due_date`-i cari tarixlə müqayisə et

        if ($due_date < $current_date) {

            // `due_date` keçib, statusu Gecikmiş olaraq yenilə

            $new_status = 'Gecikmiş';

            $update_stmt->execute([':status' => $new_status, ':id' => $payment_id]);

            log_message("Payment ID $payment_id marked as Gecikmiş.");

            

            // Gecikmiş ödənişləri array-a əlavə et

            $overdue_payments[] = $payment_id;

        } elseif ($due_date == $current_date && $current_status != 'Ödənilib') {

            // `due_date` bu gündür, statusu Ödənilməyib olaraq saxla

            $new_status = 'Ödənilməyib';

            $update_stmt->execute([':status' => $new_status, ':id' => $payment_id]);

            log_message("Payment ID $payment_id marked as Ödənilməyib today.");

            

            // Bu ödənişin dəqiq olaraq gecikmədiyini, amma bu gün yekunlaşdığını bildiririk

            // Əgər bu vəziyyətdə də mesaj göndərmək istəyirsinizsə, onu da əlavə edə bilərsiniz

        }

        // Əgər `due_date` gələcəkdədirsə, heç bir dəyişiklik etmə

    }



    // Gecikmiş ödənişləri WhatsApp-a göndərmək

    // Bütün 'Gecikmiş' ödənişləri çəkmək üçün

    $stmt = $conn->prepare("

        SELECT p.id, p.firm_id, p.category_id, p.reason_id, p.amount, p.due_date, 

               f.firm_name, pc.category_name, pr.reason_name

        FROM payments p

        JOIN firms f ON p.firm_id = f.id

        JOIN payment_categories pc ON p.category_id = pc.id

        JOIN payment_reasons pr ON p.reason_id = pr.id

        WHERE p.status = 'Gecikmiş'

        ORDER BY p.due_date ASC

    ");

    $stmt->execute();

    $all_overdue_payments = $stmt->fetchAll(PDO::FETCH_ASSOC);



    // Mesajı formatlamaq

    if (!empty($all_overdue_payments)) {

        $message = "*Salam,\n\nGecikmiş Ödənişləriniz barədə məlumat:*\n\n";



        foreach ($all_overdue_payments as $payment) {

            $message .= "*Firma:* " . $payment['firm_name'] . "\n";

            $message .= "*Kateqoriya:* " . $payment['category_name'] . "\n";

            $message .= "*Səbəb:* " . $payment['reason_name'] . "\n";

            $message .= "*Məbləğ:* " . number_format($payment['amount'], 2) . " AZN\n";

            $message .= "*Son Ödəniş Tarixi:* " . $payment['due_date'] . "\n";

            $message .= "*Status:* " . $payment['status'] . "\n";

            $message .= "--------------------------\n";

        }



        $message .= "\nZəhmət olmasa, ən qısa zamanda ödənişinizi tamamlayın.\n\n*Təşəkkürlər.*";

    } else {

        // Heç bir gecikmiş ödəniş yoxdursa, standart xatırlatma mesajı

        $message = "Salam,\n\nHal-hazırda gecikmiş ödənişiniz yoxdur. Hər hansı bir gecikmiş ödəniş yaranarsa, sizə bildiriş göndərəcəyik.\n\n*Təşəkkürlər.*";

    }



    // Göndəriləcək nömrə (994776000034)

    $recipient_number = "994776000034";



    // Mesajı göndərmək

    $result = sendWhatsAppMessage($recipient_number, $message);



    if ($result['success']) {

        // API cavabını yoxlayın

        $response_data = $result['data'];



        if ($result['http_status'] >= 200 && $result['http_status'] < 300) {

            if (isset($response_data['success']) && $response_data['success'] === true) {

                log_message("Mesaj uğurla göndərildi.");

                echo "Mesaj uğurla göndərildi.\n";

            } else {

                $error_message = isset($response_data['error']) ? $response_data['error'] : 'Bilinməyən xəta.';

                log_message("Mesaj göndərilmədi: " . $error_message);

                echo "Mesaj göndərilmədi: " . htmlspecialchars($error_message) . "\n";

            }

        } else {

            $error_message = isset($response_data['error']) ? $response_data['error'] : 'Bilinməyən xəta.';

            log_message("Mesaj göndərilmədi: " . $error_message);

            echo "Mesaj göndərilmədi: " . htmlspecialchars($error_message) . "\n";

        }

    } else {

        log_message("Mesaj göndərilmədi: " . $result['error']);

        echo "Mesaj göndərilmədi: " . htmlspecialchars($result['error']) . "\n";

    }



    // Uğurlu tamamlanma mesajı

    log_message("Payment statuses updated successfully.");

    echo "Payment statuses updated successfully.\n";



} catch (PDOException $e) {

    // Database xətalarını log et

    log_message("Database error: " . $e->getMessage());

    echo "Verilənlər bazası xətası baş verdi.\n";

} catch (Exception $e) {

    // Ümumi xətaları log et

    log_message("General error: " . $e->getMessage());

    echo "Ümumi xəta baş verdi.\n";

}

?>

