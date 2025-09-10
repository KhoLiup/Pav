<?php
/**
 * Davamiyyət Yoxlama Skripti
 * Bu skript əgər bugünün davamiyyəti qeyd edilməyibsə
 * konfiqurasiyada qeyd olunan nömrələrə WhatsApp bildirişi göndərir
 * Cron job ilə müəyyən zaman aralığında işə salınır
 */

// Xəta göstərməni aktivləşdirmək
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Konfiqurasiyanı yükləməyə icazə vermək
define('ALLOW_ACCESS', true);

// Konfiqurasiya və köməkçi funksiyaları daxil etmək
require_once __DIR__ . '/config.php';

// Davamiyyət parametrlərini yükləmək
$attendance_config = include(__DIR__ . '/attendance_config.php');

// Log faylı yaratmaq
$log_file = __DIR__ . '/logs/attendance_check_' . date('Y-m-d') . '.log';
$log_dir = dirname($log_file);

// Logs qovluğu yoxdursa yaratmaq
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Log mesajı yazmaq üçün funksiya
 * @param string $message Log mesajı
 */
function writeLog($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message" . PHP_EOL;
    file_put_contents($log_file, $log_message, FILE_APPEND);
}

// Skript başladığını log et
writeLog("Davamiyyət yoxlama skripti başladı");

// Bildiriş sistemini yoxla
if (!$attendance_config['notifications_enabled']) {
    writeLog("Bildiriş sistemi deaktivdir, yoxlama dayandırıldı");
    exit();
}

// Bugünkü tarix
$today = date('Y-m-d');
$current_day_of_week = (int)date('N'); // 1 (Bazar ertəsi) - 7 (Bazar)
$current_hour = (int)date('H');

// Xüsusi qeyri-iş günlərini yoxla
if (in_array($today, $attendance_config['non_working_days'])) {
    writeLog("Bugün qeyri-iş günüdür (xüsusi gün: $today), yoxlama keçirilmədi");
    exit();
}

// İş günü olub-olmadığını yoxla
if (!in_array($current_day_of_week, $attendance_config['working_days'])) {
    writeLog("Bugün iş günü deyil (həftənin günü: $current_day_of_week), yoxlama keçirilmədi");
    exit();
}

// İş saatını yoxla
if ($current_hour < $attendance_config['working_hours']['start'] || 
    $current_hour > $attendance_config['working_hours']['end']) {
    writeLog("İş saatı deyil (saat: $current_hour), yoxlama keçirilmədi");
    exit();
}

try {
    // Bugünkü tarix üçün davamiyyət qeydlərini yoxlamaq
    $stmt = $conn->prepare("SELECT COUNT(*) FROM attendance WHERE date = ?");
    $stmt->execute([$today]);
    $records_count = $stmt->fetchColumn();

    if ($records_count > 0) {
        // Davamiyyət qeydləri var, bildiriş göndərməyə ehtiyac yoxdur
        writeLog("Bugün üçün davamiyyət qeydləri var ($records_count qeyd), bildiriş göndərilmədi");
        exit();
    }

    // Vaxtı yoxlamaq - müəyyən edilmiş saatdan sonra bildiriş göndərilsin
    if ($current_hour < $attendance_config['notification_start_hour']) {
        writeLog("Hələ bildiriş vaxtı deyil (saat: $current_hour, bildiriş saatı: {$attendance_config['notification_start_hour']}), bildiriş göndərilmədi");
        exit();
    }

    // Bildiriş mesajı hazırlanır
    $formatted_date = date('d.m.Y');
    $notification_message = str_replace('{date}', $formatted_date, $attendance_config['notification_template']);

    // Bildiriş göndəriləcək telefon nömrələri
    $phones = $attendance_config['notification_phones'];
    
    // Hər bir nömrəyə bildiriş göndər
    $success_count = 0;
    $error_count = 0;
    
    foreach ($phones as $phone) {
        // WhatsApp bildirişini göndərmək
        $result = sendWhatsAppMessage(
            $phone,
            $notification_message
        );

        if ($result['success']) {
            $success_count++;
            writeLog("Uğurla bildiriş göndərildi: $phone nömrəsinə");
        } else {
            $error_count++;
            writeLog("Bildiriş göndərilə bilmədi ($phone): " . ($result['error'] ?? 'Naməlum xəta'));
        }
    }
    
    // Ümumi nəticəni logla
    writeLog("Bildiriş göndərmə tamamlandı. Uğurlu: $success_count, Uğursuz: $error_count");

} catch (PDOException $e) {
    writeLog("Verilənlər bazası xətası: " . $e->getMessage());
} catch (Exception $e) {
    writeLog("Ümumi xəta: " . $e->getMessage());
}

// Skript uğurla tamamlandı
writeLog("Davamiyyət yoxlama skripti tamamlandı"); 