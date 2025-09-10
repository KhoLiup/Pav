<?php
// config.php
// Sistem xətalarını göstərmək (təhsil mühitində faydalı ola bilər)
// İstehsal mühitinə keçdikdə, bu sətrləri söndürün
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Zaman bölgəsini təyin edirik
date_default_timezone_set('Asia/Baku');

// Verilənlər bazası parametrləri
$servername = "localhost";
$username = "pavmgmt";
$password = "258el852";
$dbname = "pavmgmt";

// Köməkçi funksiyaları daxil edirik
require_once __DIR__ . '/includes/helpers.php';

// PDO istifadə edərək verilənlər bazası bağlantısı
try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname;charset=utf8", $username, $password);
    // PDO xətaları atır
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Suallarda varsayılan olaraq hazırlanmış ifadələri istifadə edirik
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Azərbaycan dilində simvollar üçün UTF-8 istifadə edirik
    $conn->exec("SET NAMES utf8mb4");
} catch(PDOException $e) {
    echo "Bağlantı xətası: " . $e->getMessage();
    die();
}

// Sistem parametrləri - WhatsApp API
$whatsapp_config = [
    'access_token' => '673f1af156993',
    'instance_id' => '6765167C1B748',
    'owner_phone_number' => '994776000034',
    'appkey' => '1ddce386-aecd-43fb-818c-59c6a05acdad',
    'authkey' => '3ybkGbsVVQoz5aVybZEp8FKd4sQ6kLEuULCWss1djR5aFbV5CO'
];

// Səhifələrin səlahiyyət təyinatları (rol-əsaslı giriş)
$page_permissions = [
    'dashboard.php' => ['admin', 'manager', 'user', 'muhasib'],
    'add_employee.php' => ['admin', 'manager'],
    'edit_employee.php' => ['admin', 'manager'],
    'employees.php' => ['admin', 'manager', 'user', 'muhasib'],
    'salary.php' => ['admin', 'manager', 'muhasib'],
    'debts.php' => ['admin', 'manager', 'muhasib'],
    'cash_reports.php' => ['admin', 'manager', 'muhasib', 'user'],
    'attendance.php' => ['admin', 'manager'],
    'reports.php' => ['admin', 'manager', 'muhasib'],
    'manage_firms.php' => ['admin', 'muhasib'],
    'manage_payments.php' => ['admin', 'muhasib'],
    'salary_history.php' => ['admin', 'manager', 'muhasib']
];

// Səssiya parametrləri - sessiya başlamadan ƏVVƏL təyin edilməlidir
$session_lifetime = 14400; // 4 saat (saniyə ilə)

// Sessiya artıq başlamamışsa, parametrləri təyin edirik və başladırıq
if (session_status() == PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', $session_lifetime);
    session_set_cookie_params($session_lifetime);
    session_start();
}

// CSRF token yaratma funksiyası
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Səhifə izni yoxlama funksiyası
function check_page_permission($page) {
    global $page_permissions;
    
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
    
    $user_role = $_SESSION['user_role'] ?? 'guest';
    $page_name = basename($page);
    
    if (isset($page_permissions[$page_name]) && !in_array($user_role, $page_permissions[$page_name])) {
        $_SESSION['error_message'] = 'Bu səhifəyə giriş icazəniz yoxdur.';
        header('Location: dashboard.php');
        exit();
    }
}
?>
