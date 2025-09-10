<?php
// Xətaların göstərilməsi (test mühitində tövsiyə olunur, realda deaktiv edə bilərsiniz)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Köməkçi funksiyaları və konfiqurasiyaları daxil edirik
require_once __DIR__ . '/includes/helpers.php';
require_once 'config.php';

/**
 * msg.pavilion.az API vasitəsilə WhatsApp mesajı göndərən funksiya
 *
 * @param string $number  Göndəriləcək nömrə (məsələn, 994501234567)
 * @param string $message Göndəriləcək mesaj mətn
 *
 * @return array Nəticə (success, error, data, http_status)
 */
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
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json'
        ),
    ));

    $response = curl_exec($curl);
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        // cURL xəta vəziyyəti
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err
        ];
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

// Xəta və uğur mesajlarını saxlamaq üçün dəyişənlər
$errors = [];
$success_message = '';

// POST metodu ilə form göndərilibsə
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Form sahələrini oxu, boşluqlardan təmizlə
    $number  = trim($_POST['number'] ?? '');
    $message = trim($_POST['message'] ?? '');

    // Telefon nömrəsi yoxlaması
    if (empty($number)) {
        $errors[] = "Telefon nömrəsi boş ola bilməz.";
    } else {
        // 10-15 rəqəm aralığında olub-olmadığını yoxlayırıq
        if (!preg_match('/^\d{10,15}$/', $number)) {
            $errors[] = "Telefon nömrəsi düzgün formatda deyil. Məsələn: 994501234567";
        }
    }

    // Mesaj mətninin yoxlanması
    if (empty($message)) {
        $errors[] = "Mesaj mətnini boş buraxa bilməzsiniz.";
    }

    // Əgər xəta yoxdursa, msg.pavilion.aze API-ya sorğu göndər
    if (empty($errors)) {
        $result = sendWhatsAppMessage($number, $message);

        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $errors[] = "Mesaj göndərilmədi: " . $result['error'];
        }
    }
}

// AJAX sorğularını emal edək
if (isset($_POST['action']) && $_POST['action'] == 'send_message') {
    $response = ['success' => false, 'message' => 'Parametrlər əksikdir'];
    
    if (isset($_POST['number']) && isset($_POST['message'])) {
        $number = $_POST['number'];
        $message = $_POST['message'];
        
        $result = sendWhatsAppMessage($number, $message);
        
        if ($result['success']) {
            $response = ['success' => true, 'message' => $result['message']];
        } else {
            $response = ['success' => false, 'message' => $result['error']];
        }
    }
    
    // JSON formatında cavab qaytarmaq
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// İstifadəçi sessiyası yoxlaması
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// İcazə yoxlaması
if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'manager'])) {
    $_SESSION['error_message'] = 'Bu səhifəyə giriş üçün icazəniz yoxdur.';
    header("Location: dashboard.php");
    exit();
}

?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Mesaj Göndərmə</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <style>
        body {
            background-color: #f8f9fa;
        }
        .message-container {
            max-width: 600px;
        }
        .preview-container {
            background-color: #e9ecef;
            border-radius: 10px;
            padding: 15px;
            margin-top: 20px;
        }
        #messagePreview {
            white-space: pre-line; /* Mətn içindəki yeni sətir simvollarını saxla */
        }
        .success-icon {
            color: green;
            font-size: 24px;
        }
        .error-icon {
            color: red;
            font-size: 24px;
        }
    </style>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>
    
    <div class="container mt-4">
        <div class="row justify-content-center">
            <div class="col-md-8 message-container">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fab fa-whatsapp me-2"></i> WhatsApp Mesaj Göndərmə</h5>
                    </div>
                    <div class="card-body">
                        <form id="messageForm">
                            <input type="hidden" name="action" value="send_message">
                            
                            <div class="mb-3">
                                <label for="number" class="form-label">Telefon Nömrəsi:</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" class="form-control" id="number" name="number" 
                                           placeholder="994501234567" required
                                           pattern="^994[0-9]{9}$">
                                </div>
                                <div class="form-text">Nömrəni 994 ilə başlayaraq, 12 rəqəmlə daxil edin.</div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="message" class="form-label">Mesaj:</label>
                                <textarea class="form-control" id="message" name="message" rows="5" 
                                          placeholder="Mesajınızı buraya yazın..." required></textarea>
                            </div>
                            
                            <div class="preview-container mb-3 d-none" id="previewSection">
                                <h6>Mesaj Önizləməsi:</h6>
                                <p id="messagePreview"></p>
                            </div>
                            
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-secondary mb-2" id="previewBtn">
                                    <i class="fas fa-eye me-2"></i> Önizlə
                                </button>
                                <button type="submit" class="btn btn-success" id="sendBtn">
                                    <i class="fab fa-whatsapp me-2"></i> Göndər
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Nəticə -->
                <div class="alert d-none" id="resultAlert" role="alert"></div>
                
                <!-- Göndərilən mesajların siyahısı (Əlavə funksionallıq kimi implementasiya edilə bilər) -->
            </div>
        </div>
    </div>
    
    <!-- jQuery və Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Önizləmə
            $('#previewBtn').click(function() {
                var message = $('#message').val();
                if (message) {
                    $('#messagePreview').text(message);
                    $('#previewSection').removeClass('d-none');
                } else {
                    alert('Zəhmət olmasa, əvvəlcə mesaj daxil edin.');
                }
            });
            
            // Mesaj yazıldığında önizləməni yenilə
            $('#message').on('input', function() {
                var message = $(this).val();
                if (message && !$('#previewSection').hasClass('d-none')) {
                    $('#messagePreview').text(message);
                }
            });
            
            // Mesaj göndərmə
            $('#messageForm').submit(function(e) {
                e.preventDefault();
                
                var formData = $(this).serialize();
                $('#sendBtn').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span> Göndərilir...');
                
                $.ajax({
                    url: 'send_message.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $('#resultAlert')
                                .removeClass('d-none alert-danger')
                                .addClass('alert-success')
                                .html('<i class="fas fa-check-circle success-icon me-2"></i> ' + response.message);
                            
                            // Formu təmizlə
                            $('#messageForm')[0].reset();
                            $('#previewSection').addClass('d-none');
                        } else {
                            $('#resultAlert')
                                .removeClass('d-none alert-success')
                                .addClass('alert-danger')
                                .html('<i class="fas fa-exclamation-circle error-icon me-2"></i> ' + response.message);
                        }
                        $('#sendBtn').prop('disabled', false).html('<i class="fab fa-whatsapp me-2"></i> Göndər');
                    },
                    error: function() {
                        $('#resultAlert')
                            .removeClass('d-none alert-success')
                            .addClass('alert-danger')
                            .html('<i class="fas fa-exclamation-circle error-icon me-2"></i> Server xətası! Yenidən cəhd edin.');
                        $('#sendBtn').prop('disabled', false).html('<i class="fab fa-whatsapp me-2"></i> Göndər');
                    }
                });
            });
        });
    </script>
</body>
</html>
