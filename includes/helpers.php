<?php
/**
 * helpers.php
 * Bütün səhifələrdə istifadə ediləcək ümumi funksiyalar
 */

/**
 * Məlumatları təmizləyən funksiya
 * @param string $data Təmizlənəcək məlumat
 * @return string Təmizlənmiş məlumat
 */
function sanitize($data) {
    return htmlspecialchars(stripslashes(trim($data)), ENT_QUOTES, 'UTF-8');
}

/**
 * Flash mesajlarını göstərən funksiya
 * @return void
 */
function display_flash_messages() {
    if (isset($_SESSION['success_message'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['success_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
        echo htmlspecialchars($_SESSION['error_message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
        echo '</div>';
        unset($_SESSION['error_message']);
    }
}

/**
 * Flash mesajını ayarlayan funksiya
 * @param string $type Mesaj növü (success, danger, warning, info)
 * @param string $message Mesaj məzmunu
 * @return void
 */
function set_flash_message($type, $message) {
    if ($type === 'success') {
        $_SESSION['success_message'] = $message;
    } else {
        $_SESSION['error_message'] = $message;
    }
}

/**
 * Telefon nömrəsini validator funksiyası
 * Düzgün nömrə formatının 994XXXXXXXXX olması gərəkdir
 * @param string $number Telefon nömrəsi
 * @return boolean Nömrə düzgündürsə true
 */
if (!function_exists('validatePhoneNumber')) {
    function validatePhoneNumber($number) {
        // Whitespace və formatları təmizləyək
        $number = preg_replace('/\s+|-|\(|\)/', '', $number);
        
        // Əgər + ilə başlayırsa, onu silin
        if (substr($number, 0, 1) === '+') {
            $number = substr($number, 1);
        }
        
        // Azərbaycan üçün: 994xxxxxxxxx formatında (12 rəqəm)
        return preg_match('/^994[0-9]{9}$/', $number);
    }
}

/**
 * msg.pavilion.az API vasitəsilə WhatsApp mesajı göndərən funksiya
 * @param string $number Telefon nömrəsi (994XXXXXXXXX formatında)
 * @param string $message Göndəriləcək mesaj
 * @param string $appkey Köhnə API üçün appkey (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param string $authkey Köhnə API üçün authkey (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param bool $sandbox Köhnə API üçün sandbox (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param string $instance_id Köhnə API üçün instance_id (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @param string $access_token Köhnə API üçün access token (geriyə uyğunluq üçün, artıq istifadə olunmur)
 * @return array Nəticə məlumatları
 */
function sendWhatsAppMessage($number, $message, $appkey = null, $authkey = null, $sandbox = false, $instance_id = null, $access_token = null) {
    // Nömrə formatını yoxlayaq
    $number = preg_replace('/\s+|-|\(|\)/', '', $number);
    if (substr($number, 0, 1) === '+') {
        $number = substr($number, 1);
    }
    
    // Telefon nömrəsinin validasiyası 
    if (!validatePhoneNumber($number)) {
        return [
            'success' => false,
            'error' => 'Invalid phone number format. Should be 994XXXXXXXXX',
            'data' => null,
            'http_status' => 400
        ];
    }
    
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
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    // Debug məlumatları log-a yazaq
    error_log("WhatsApp API Debug (helpers) - Number: $number, Status: $http_status, Response: $response");
    
    if ($err) {
        // Log yazaq
        error_log(sprintf("WhatsApp API cURL Error: To: %s, Error: %s", $number, $err));
        
        return [
            'success' => false,
            'error' => "cURL Error #: " . $err,
            'data' => null,
            'http_status' => 0
        ];
    }

    // Cavabı JSON formatında decode et
    $response_data = json_decode($response, true);
    
    // Log yazaq
    error_log(sprintf("WhatsApp API Request: To: %s, Response: %s, Status: %d", 
        $number, $response, $http_status));
    
    // Debug: JSON decode xətasını yoxlayaq
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("WhatsApp API JSON Error (helpers): " . json_last_error_msg() . " - Raw response: " . $response);
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
 * Tarix formatını doğrulama
 * @param string $date Tarix
 * @param string $format Format (Y-m-d default)
 * @return bool Tarix düzgündürsə true
 */
if (!function_exists('validateDate')) {
    function validateDate($date, $format = 'Y-m-d') {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }
}

/**
 * FIN kodunu doğrulama
 * @param string $fin_code FIN kodu
 * @return bool FIN kodu düzgündürsə true
 */
if (!function_exists('validateFinCode')) {
    function validateFinCode($fin_code) {
        // FIN kod 7 simvoldan ibarət olmalıdır və hərf və rəqəmlərin kombinasiyasıdır
        return preg_match('/^[A-Z0-9]{7}$/', strtoupper($fin_code));
    }
}

/**
 * Ay adını Azərbaycan dilinə tərcümə edir
 * @param string $month_name Ay adı (ingiliscə)
 * @return string Azərbaycanca ay adı
 */
function translateMonthToAzerbaijani($month_name) {
    $translations = [
        'January' => 'Yanvar',
        'February' => 'Fevral',
        'March' => 'Mart',
        'April' => 'Aprel',
        'May' => 'May',
        'June' => 'İyun',
        'July' => 'İyul',
        'August' => 'Avqust',
        'September' => 'Sentyabr',
        'October' => 'Oktyabr',
        'November' => 'Noyabr',
        'December' => 'Dekabr'
    ];
    
    return str_replace(array_keys($translations), array_values($translations), $month_name);
}

/**
 * Tam ay adını formatla (Azərbaycanca)
 * @param string $month İl və ay formatı YYYY-MM
 * @return string Formatlanmış ay adı (Yanvar 2025)
 */
function formatMonthName($month) {
    $month_name = date('F Y', strtotime($month . '-01'));
    return translateMonthToAzerbaijani($month_name);
}

/**
 * İstifadəçi rolunu yoxla
 * @param array $allowed_roles İcazə verilən rollar
 * @return bool İstifadəçi rolun icazəsi varsa true
 */
function check_user_role($allowed_roles = ['admin']) {
    $user_role = $_SESSION['user_role'] ?? 'guest';
    return in_array($user_role, $allowed_roles);
}

/**
 * Səhifələmə elementini yaratmaq
 * @param int $total Ümumi səhifə sayı
 * @param int $current Cari səhifə
 * @param int $per_page Hər səhifədə olan element sayı
 * @param string $url URL şablonu
 * @return string Səhifələmə HTML-i
 */
if (!function_exists('paginate')) {
    function paginate($total, $current, $per_page, $url) {
        $total_pages = ceil($total / $per_page);
        
        if ($total_pages <= 1) {
            return '';
        }
        
        $html = '<nav aria-label="Səhifələmə"><ul class="pagination justify-content-center">';
        
        // Əvvəlki səhifə
        if ($current > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{PAGE}', $current - 1, $url) . '">&laquo; Əvvəlki</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">&laquo; Əvvəlki</span></li>';
        }
        
        // Orta səhifələr
        $start = max(1, $current - 2);
        $end = min($total_pages, $current + 2);
        
        if ($start > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{PAGE}', 1, $url) . '">1</a></li>';
            if ($start > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }
        
        for ($i = $start; $i <= $end; $i++) {
            if ($i == $current) {
                $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
            } else {
                $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{PAGE}', $i, $url) . '">' . $i . '</a></li>';
            }
        }
        
        if ($end < $total_pages) {
            if ($end < $total_pages - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{PAGE}', $total_pages, $url) . '">' . $total_pages . '</a></li>';
        }
        
        // Sonrakı səhifə
        if ($current < $total_pages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . str_replace('{PAGE}', $current + 1, $url) . '">Sonrakı &raquo;</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Sonrakı &raquo;</span></li>';
        }
        
        $html .= '</ul></nav>';
        
        return $html;
    }
}

/**
 * Verilmiş tarixin gün/ay/il formatında çap edilməsi
 * @param string $date Y-m-d formatında tarix
 * @return string d.m.Y formatında tarix
 */
if (!function_exists('formatDate')) {
    function formatDate($date) {
        if (empty($date)) return '';
        return date('d.m.Y', strtotime($date));
    }
}

/**
 * Rəqəmləri formatla (məs. 1234.56 -> 1,234.56)
 * @param float $number Formatlanacaq rəqəm
 * @param int $decimals Onluq kəsr rəqəmi
 * @return string Formatlanmış rəqəm
 */
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals, '.', ',');
}

/**
 * İşçi məlumatlarını əldə etmək
 * @param PDO $conn Verilənlər bazası əlaqəsi
 * @param int $employee_id İşçi ID
 * @return array İşçi məlumatları
 */
function getEmployeeInfo($conn, $employee_id) {
    $stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");
    $stmt->execute([':id' => $employee_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Aktiv işçilərin siyahısını al
 * @param PDO $conn Verilənlər bazası əlaqəsi
 * @return array Aktiv işçilərin siyahısı
 */
function getActiveEmployees($conn) {
    $stmt = $conn->query("SELECT id, name, salary, category FROM employees WHERE is_active = 1 ORDER BY name ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
} 