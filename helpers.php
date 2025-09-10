<?php
/**
 * Utility funksiyaları
 * Versiya: 1.0
 * Tarix: 2023
 */

/**
 * msg.pavilion.az API vasitəsilə WhatsApp mesajı göndərən funksiya
 * 
 * @param string $number Telefon nömrəsi
 * @param string $message Mesaj mətni
 * @param string $appkey Köhnə API üçün (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param string $authkey Köhnə API üçün (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param bool $sandbox Köhnə API üçün (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @return array Nəticələr: [bool success, ?string data, ?string error, ?int http_status]
 */
if (!function_exists('sendWhatsAppMessage')) {
    function sendWhatsAppMessage($number, $message, $appkey = null, $authkey = null, $sandbox = false) {
        // Telefon nömrəsini təmizləyirik
        $number = preg_replace('/[^0-9]/', '', $number);
        
        // msg.pavilion.az API endpointi - API.md-ə uyğun
        $url = 'https://msg.pavilion.az/api/send-message';
        
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
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        
        // Debug məlumatları log-a yazaq
        error_log("WhatsApp API Debug (root helpers) - Number: $number, Status: $http_status, Response: $response");
        
        if ($err) {
            return [
                'success' => false,
                'error' => "cURL Error #: " . $err,
                'http_status' => 0
            ];
        }
        
        // Cavabı JSON formatında decode et
        $response_data = json_decode($response, true);
        
        // Debug: JSON decode xətasını yoxlayaq
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("WhatsApp API JSON Error (root helpers): " . json_last_error_msg() . " - Raw response: " . $response);
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
}

/**
 * Safe input məlumatlarını təmizləmək üçün funksiya
 *
 * @param mixed $data Təmizlənəcək məlumat
 * @return mixed Təmizlənmiş məlumat
 */
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = sanitize_input($value);
            }
            return $data;
        }
        
        if (is_string($data)) {
            return htmlspecialchars(stripslashes(trim($data)));
        }
        
        return $data;
    }
}

/**
 * Tarix formatını düzgün formata çevirmək
 *
 * @param string $date Tarix
 * @param string $format Çıxış formatı
 * @return string Formatlanmış tarix
 */
if (!function_exists('format_date')) {
    function format_date($date, $format = 'd.m.Y') {
        if (empty($date)) return '';
        
        try {
            $date_obj = new DateTime($date);
            return $date_obj->format($format);
        } catch (Exception $e) {
            return $date;
        }
    }
}

/**
 * Məbləği formatlamaq
 *
 * @param float $amount Məbləğ
 * @param int $decimals Onluq yerlər
 * @param string $currency Valyuta
 * @return string Formatlanmış məbləğ
 */
if (!function_exists('format_amount')) {
    function format_amount($amount, $decimals = 2, $currency = 'AZN') {
        return number_format((float)$amount, $decimals, '.', ' ') . ' ' . $currency;
    }
} 