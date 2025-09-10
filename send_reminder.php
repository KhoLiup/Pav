<?php
// send_reminder.php

// Xətaların göstərilməsi üçün (geliştirmə mühiti üçün)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

// Verilənlər bazası bağlantısı
$host = 'sql113.infinityfree.com';
$db   = 'if0_37675399_localhost';
$user = 'if0_37675399';
$pass = 'pavilion258852'; // Verilənlər bazası şifrənizi buraya daxil edin
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];
try {
     $conn = new PDO($dsn, $user, $pass, $options);
     echo "Database bağlantısı uğurla quruldu.<br>";
} catch (\PDOException $e) {
     echo "Database bağlantısı xətası: " . htmlspecialchars($e->getMessage());
     exit();
}

// Mesaj göndəriləcək nömrələr
$recipients = [
    '994776000034',
    '994709590034'
];

// Mesaj mətniniz
$message = "İşçilərin davamiyyəti qeyd olunmayıb!";

date_default_timezone_set('UTC'); // UTC vaxt zonasını təyin edin

// Bakı vaxtına uyğunlaşdırın
$baku_time = new DateTime('now', new DateTimeZone('UTC'));
$baku_time->modify('+4 hours'); // Bakı vaxtı UTC-dən 4 saat irəlidir

$current_hour = $baku_time->format('H');
$current_minute = $baku_time->format('i');

// Mesaj göndərilməsini yoxlamaq üçün funksiya
function shouldSendMessage($hour, $minute) {
    // Mesaj göndərmə saatları: 11:00 - 22:00
    if ($hour < 4 || $hour > 22) {
        return false;
    }

    // Hər dəqiqədə bir göndərmək üçün
    return true;
}

// Mesaj göndərmək üçün əsas funksionallıq
if (shouldSendMessage($current_hour, $current_minute)) {
    echo "Mesaj göndərilməyə başlayır.<br>";
    
    foreach ($recipients as $number) {
        $result = sendWhatsAppMessage($number, $message);
        
        if ($result['success']) {
            echo "Mesaj uğurla göndərildi: $number<br>";
        } else {
            echo "Mesaj göndərilmədi: $number - " . htmlspecialchars($result['error']) . "<br>";
        }
    }
} else {
    echo "Mesaj göndərmək üçün uyğun vaxt deyil.<br>";
}
?>
