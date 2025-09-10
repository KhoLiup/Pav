<?php

// includes/functions.php

/**
 * msg.pavilion.az API vasitəsilə WhatsApp mesajı göndərən funksiya
 *
 * @param string $number Göndəriləcək telefon nömrəsi (məsələn, "994776000034")
 * @param string $message Göndəriləcək mesaj mətni
 * @param string $instance_id Köhnə API üçün (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param string $access_token Köhnə API üçün (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @return array Cavabın statusu və məlumatı
 */
function sendWhatsAppMessage($number, $message, $instance_id = null, $access_token = null) {
    // msg.pavilion.az API endpointi - API.md-ə uyğun
    $url = 'https://msg.pavilion.az/api/send-message';

    // Telefon nömrəsini təmizləyirik - API.md-ə görə @s.whatsapp.net avtomatik əlavə olunur
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

    // Debug məlumatları log-a yazaq
    error_log("WhatsApp API Debug - Number: $number, Status: $http_status, Response: $response");

    if ($err) {
        return ['success' => false, 'error' => "cURL Error #: " . $err];
    }

    // Cavabı JSON formatında decode et
    $response_data = json_decode($response, true);
    
    // Debug: JSON decode xətasını yoxlayaq
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("WhatsApp API JSON Error: " . json_last_error_msg() . " - Raw response: " . $response);
        return [
            'success' => false,
            'error' => 'API cavabı JSON formatında deyil: ' . json_last_error_msg(),
            'raw_response' => $response,
            'http_status' => $http_status
        ];
    }
    
    // API cavabını yoxla - Daha çevik yanaşma
    if ($http_status == 200) {
        // Əvvəlcə API.md formatını yoxlayaq: { status: "sent" }
        if (isset($response_data['status']) && $response_data['status'] === 'sent') {
            return [
                'success' => true,
                'message' => 'Mesaj uğurla göndərildi',
                'data' => $response_data,
                'http_status' => $http_status
            ];
        }
        // Əgər köhnə format varsa: { success: true }
        elseif (isset($response_data['success']) && $response_data['success'] === true) {
            return [
                'success' => true,
                'message' => 'Mesaj uğurla göndərildi',
                'data' => $response_data,
                'http_status' => $http_status
            ];
        }
        // Hər hansı pozitiv cavab
        elseif (isset($response_data['message']) && !isset($response_data['error'])) {
            return [
                'success' => true,
                'message' => $response_data['message'],
                'data' => $response_data,
                'http_status' => $http_status
            ];
        }
        // Mətn cavabı (JSON deyil)
        elseif (empty($response_data) && !empty($response) && strpos(strtolower($response), 'success') !== false) {
            return [
                'success' => true,
                'message' => 'Mesaj uğurla göndərildi',
                'raw_response' => $response,
                'http_status' => $http_status
            ];
        }
        else {
            return [
                'success' => false,
                'error' => 'Gözlənilməz cavab formatı',
                'data' => $response_data,
                'raw_response' => $response,
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
            'raw_response' => $response,
            'http_status' => $http_status
        ];
    }
}

/**
 * msg.pavilion.az API vasitəsilə WhatsApp şəkil göndərən funksiya
 * 
 * DİQQƏT: Bu funksiya hazırda dəstəklənmir çünki API.md-də şəkil göndərmə endpointi qeyd edilməyib.
 * Yalnız mətn mesajları göndərmək üçün sendWhatsAppMessage() funksiyasından istifadə edin.
 *
 * @param string $number Göndəriləcək telefon nömrəsi
 * @param string $image_path Şəkil faylının yolu
 * @param string $caption Şəkil açıqlaması (isteğe bağlı)
 * @return array Cavabın statusu və məlumatı
 */
function sendWhatsAppImage($number, $image_path, $caption = '') {
    // API.md-də şəkil göndərmə endpointi qeyd edilməyib
    return [
        'success' => false,
        'error' => 'Şəkil göndərmə funksiyası hazırda dəstəklənmir. API.md-də bu endpoint qeyd edilməyib.',
        'data' => null,
        'http_status' => 501 // Not Implemented
    ];
    
    /* Köhnə kod - API.md-də endpoint qeyd edilməyib
    // msg.pavilion.az API endpointi
    $url = 'https://msg.pavilion.az/api/send-image';

    // Şəkil faylının mövcudluğunu yoxla
    if (!file_exists($image_path)) {
        return [
            'success' => false,
            'error' => 'Şəkil faylı tapılmadı: ' . $image_path
        ];
    }

    // Form data hazırla
    $postFields = [
        'number' => $number,
        'caption' => $caption,
        'image' => new CURLFile($image_path)
    ];

    // cURL sorğusunu qur
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Şəkil üçün daha uzun timeout

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err
        ];
    } else {
        // Cavabı JSON formatında decode et
        $response_data = json_decode($response, true);
        
        // API cavabını yoxla
        if ($http_status >= 200 && $http_status < 300) {
            if (isset($response_data['success']) && $response_data['success'] === true) {
                return [
                    'success' => true,
                    'message' => $response_data['message'] ?? 'Şəkil uğurla göndərildi',
                    'data' => $response_data,
                    'http_status' => $http_status
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response_data['error'] ?? 'Bilinməyən xəta',
                    'data' => $response_data,
                    'http_status' => $http_status
                ];
            }
        } else {
            return [
                'success' => false,
                'error' => "HTTP Error: " . $http_status . " - " . ($response_data['error'] ?? 'Server xətası'),
                'data' => $response_data,
                'http_status' => $http_status
            ];
        }
    }
    */
}

/**
 * WhatsApp bağlantı statusunu yoxlayan funksiya
 *
 * @return array Status məlumatları
 */
function checkWhatsAppStatus() {
    // msg.pavilion.az API endpointi - API.md-ə uyğun
    $url = 'https://msg.pavilion.az/api/status';

    // cURL sorğusunu qur
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err,
            'status' => 'close'
        ];
    }

    // Cavabı JSON formatında decode et
    $response_data = json_decode($response, true);
    
    if ($http_status == 200 && $response_data) {
        // API.md-ə görə cavab formatı: { status: "open" | "close" | "connecting" }
        return [
            'success' => true,
            'status' => $response_data['status'] ?? 'close',
            'data' => $response_data,
            'http_status' => $http_status
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP Error: " . $http_status,
            'status' => 'close',
            'http_status' => $http_status
        ];
    }
}

/**
 * WhatsApp-dan çıxış edən funksiya
 * 
 * DİQQƏT: Bu funksiya hazırda dəstəklənmir çünki API.md-də çıxış endpointi qeyd edilməyib.
 * 
 * @return array Çıxış nəticəsi
 */
function logoutWhatsApp() {
    // API.md-də çıxış endpointi qeyd edilməyib
    return [
        'success' => false,
        'error' => 'Çıxış funksiyası hazırda dəstəklənmir. API.md-də bu endpoint qeyd edilməyib.',
        'data' => null,
        'http_status' => 501 // Not Implemented
    ];
    
    /* Köhnə kod - API.md-də endpoint qeyd edilməyib
    // msg.pavilion.az API endpointi
    $url = 'https://msg.pavilion.az/api/logout';

    // cURL sorğusunu qur
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err
        ];
    } else {
        // Cavabı JSON formatında decode et
        $response_data = json_decode($response, true);
        
        if ($http_status >= 200 && $http_status < 300) {
            if (isset($response_data['success']) && $response_data['success'] === true) {
                return [
                    'success' => true,
                    'message' => $response_data['message'] ?? 'Çıxış edildi',
                    'data' => $response_data,
                    'http_status' => $http_status
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response_data['error'] ?? 'Bilinməyən xəta',
                    'data' => $response_data,
                    'http_status' => $http_status
                ];
            }
        } else {
            return [
                'success' => false,
                'error' => "HTTP Error: " . $http_status . " - " . ($response_data['error'] ?? 'Server xətası'),
                'data' => $response_data,
                'http_status' => $http_status
            ];
        }
    }
    */
}

/**
 * WhatsApp QR kodunu əldə edən funksiya
 *
 * @return array QR kod məlumatları
 */
function getWhatsAppQR() {
    // msg.pavilion.az API endpointi - API.md-ə uyğun
    $url = 'https://msg.pavilion.az/api/qr';

    // cURL sorğusunu qur
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err,
            'qr' => null
        ];
    }

    if ($http_status == 200) {
        // Cavabı JSON formatında decode et
        $response_data = json_decode($response, true);
        
        if ($response_data && isset($response_data['qr'])) {
            // API.md-ə görə cavab formatı: { qr: "QR_KODU_MƏTNİ" }
            return [
                'success' => true,
                'qr' => $response_data['qr'],
                'data' => $response_data,
                'http_status' => $http_status
            ];
        } else {
            return [
                'success' => false,
                'error' => 'QR kod cavab formatı düzgün deyil',
                'qr' => null,
                'http_status' => $http_status
            ];
        }
    } elseif ($http_status == 204) {
        // API.md-ə görə: QR kodu mövcud deyilsə 204 status kodu
        return [
            'success' => true,
            'qr' => null,
            'message' => 'QR kod mövcud deyil',
            'http_status' => $http_status
        ];
    } else {
        return [
            'success' => false,
            'error' => "HTTP Error: " . $http_status,
            'qr' => null,
            'http_status' => $http_status
        ];
    }
}

?>

