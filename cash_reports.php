<?php
// cash_reports.php

// Xətaların göstərilməsi (inkişaf mühiti üçün)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Burada config.php-yə daxil olub, $owner_phone_number, $appkey, $authkey və s. dəyişənləri götürürük
require 'config.php'; // Verilənlər bazası və Telebat parametrləri üçün ($conn, $owner_phone_number, $appkey, $authkey və s.)

// Helper funksiyaları əlavə edirik
// Əvvəlcə includes/helpers.php yükləyirik (əgər varsa)
if (file_exists('includes/helpers.php')) {
    require_once 'includes/helpers.php';
}
// Sonra root helpers.php yükləyirik
require_once 'helpers.php';

// İstifadəçi yoxlaması (əgər varsa)
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// İnput sanitize etmək üçün funksiya (əgər helpers.php-də təyin edilməyibsə)
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        return htmlspecialchars(stripslashes(trim($data)));
    }
}

// Kassirlərin siyahısını əldə etmək funksiyası
function getCashiers($conn) {
    $stmt = $conn->prepare("
        SELECT e.id, e.name, e.cash_register_id 
        FROM employees e
        WHERE e.category = 'kassir' 
          AND e.is_active = 1 
        ORDER BY e.name ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Kassaların siyahısını əldə etmək funksiyası
function getCashRegisters($conn) {
    $stmt = $conn->prepare("
        SELECT id, name 
        FROM cash_registers 
        WHERE is_active = 1 
        ORDER BY name ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Seçilmiş kassir və tarix üçün əməliyyatları əldə etmək funksiyası
function getOperations($conn, $employee_id, $date, $cash_register_id = null) {
    // Əgər işçi ID varsa, işçiyə görə hesabatları gətir
    if ($employee_id) {
        $stmt = $conn->prepare("
            SELECT cr.*, e.name, cr2.name AS cash_register_name
            FROM cash_reports cr 
            JOIN employees e ON cr.employee_id = e.id 
            LEFT JOIN cash_registers cr2 ON cr.cash_register_id = cr2.id
            WHERE cr.employee_id = :employee_id 
              AND cr.date = :date
            ORDER BY cr.id DESC
        ");
        $stmt->execute([':employee_id' => $employee_id, ':date' => $date]);
    }
    // Əgər kassa ID varsa, kassaya görə hesabatları gətir (arxivdə olan işçilər daxil olmaqla)
    elseif ($cash_register_id) {
        $stmt = $conn->prepare("
            SELECT cr.*, e.name, cr2.name AS cash_register_name
            FROM cash_reports cr 
            JOIN employees e ON cr.employee_id = e.id 
            LEFT JOIN cash_registers cr2 ON cr.cash_register_id = cr2.id
            WHERE cr.cash_register_id = :cash_register_id 
              AND cr.date = :date
            ORDER BY cr.id DESC
        ");
        $stmt->execute([':cash_register_id' => $cash_register_id, ':date' => $date]);
    }
    // Heç biri seçilməyibsə, boş massiv qaytar
    else {
        return [];
    }
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Borc əlavə etmək funksiyası
function addDebt($conn, $employee_id, $amount, $date, $reason) {
    try {
        // Mövcud borcları yoxlaırıq - daha dəqiq axtarış
        $checkStmt = $conn->prepare("
            SELECT id, amount FROM debts 
            WHERE employee_id = :employee_id 
            AND date = :date 
            AND reason = :reason 
            AND is_paid = 0
        ");
        $checkStmt->execute([
            ':employee_id' => $employee_id,
            ':date' => $date,
            ':reason' => $reason
        ]);
        
        $existingDebt = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        // Ayın formatını hazırlayırıq
        $month = date('Y-m', strtotime($date));
        
        // Əgər artıq borc mövcuddursa, onu yeniləyirik
        if ($existingDebt) {
            $updateStmt = $conn->prepare("
                UPDATE debts 
                SET amount = :amount, 
                    month = :month
                WHERE id = :id
            ");
            $updateStmt->execute([
                ':amount' => $amount,
                ':month' => $month,
                ':id' => $existingDebt['id']
            ]);
            return $existingDebt['id'];
        }
        // Yeni borc əlavə edirik
        else {
            $stmt = $conn->prepare("
                INSERT INTO debts (employee_id, amount, date, reason, is_paid, month) 
                VALUES (:employee_id, :amount, :date, :reason, 0, :month)
            ");
            $stmt->execute([
                ':employee_id' => $employee_id,
                ':amount'      => $amount,
                ':date'        => $date,
                ':reason'      => $reason,
                ':month'       => $month
            ]);
            return $conn->lastInsertId();
        }
    } catch (PDOException $e) {
        // Xəta loq edə bilərsiniz
        error_log("Borc əlavə edilərkən xəta: " . $e->getMessage());
        return false;
    }
}

/**
 * Hesabat məlumatlarının yoxlanması
 * @param array $data Hesabat məlumatları
 * @return array İki element: [bool success, string error_message]
 */
function validateReportData($data) {
    $errors = [];
    
    // Kassir seçilməlidir
    if (empty($data['employee_id'])) {
        $errors[] = 'Kassiri seçməlisiniz.';
    }
    
    // Tarix daxil edilməlidir
    if (empty($data['date'])) {
        $errors[] = 'Tarix daxil edilməlidir.';
    }
    
    // ƏDV seçilib lakin dəyərlər daxil edilməyibsə
    if (!empty($data['has_vat']) && 
        (empty($data['vat_included']) && empty($data['vat_exempt']))) {
        $errors[] = 'Vergi Kassası Hesabatı seçilib, lakin ƏDV-li və/və ya ƏDV-siz məbləğ daxil etməmisiniz!';
    }
    
    // POS məbləği daxil edilməlidir
    if (!isset($data['pos_amount']) || $data['pos_amount'] === '') {
        $errors[] = 'POS Məbləği daxil edilməlidir.';
    }
    
    if (!empty($errors)) {
        return [false, implode('<br>', $errors)];
    }
    
    return [true, ''];
}

/**
 * Hesabat məlumatlarını hazırlamaq
 * @param array $data POST məlumatları
 * @return array Hazırlanmış hesabat məlumatları
 */
function prepareReportData($data) {
    // Bank qəbzlərini təmizləyib JSON formatında saxlayırıq
    $bank_receipts_clean = array_filter(array_map('floatval', $data['bank_receipts'] ?? []));
    $bank_receipts_json  = json_encode(array_values($bank_receipts_clean));

    // Əlavə kassadan verilən pulları təmizləyib JSON formatında saxlayırıq
    $additional_cash_clean = [];
    $additional_cash = $data['additional_cash'] ?? [];
    $additional_cash_descriptions = $data['additional_cash_descriptions'] ?? [];
    
    foreach ($additional_cash as $index => $amount) {
        $description = isset($additional_cash_descriptions[$index])
            ? trim($additional_cash_descriptions[$index]) 
            : '';
        if (floatval($amount) > 0 && !empty($description)) {
            $additional_cash_clean[] = [
                'amount'      => floatval($amount),
                'description' => $description
            ];
        }
    }
    $additional_cash_json = json_encode(array_values($additional_cash_clean));

    // Ümumi məbləği hesablamaq
    $total_bank_receipts = array_sum($bank_receipts_clean);
    $total_additional_cash = array_sum(array_column($additional_cash_clean, 'amount'));
    $total_amount = $total_bank_receipts + floatval($data['cash_given'] ?? 0) + $total_additional_cash;

    // Fərqi hesablamaq
    $difference = $total_amount - floatval($data['pos_amount'] ?? 0);
    
    // VAT məlumatlarını əldə et
    $has_vat = !empty($data['has_vat']);
    $vat_included = $has_vat ? floatval($data['vat_included'] ?? 0) : 0;
    $vat_exempt = $has_vat ? floatval($data['vat_exempt'] ?? 0) : 0;
    
    // Məlumatları hazırla
    return [
        'employee_id' => intval($data['employee_id'] ?? 0),
        'date' => $data['date'] ?? '',
        'bank_receipts' => $bank_receipts_json,
        'cash_given' => floatval($data['cash_given'] ?? 0),
        'additional_cash' => $additional_cash_json,
        'pos_amount' => floatval($data['pos_amount'] ?? 0),
        'total_amount' => $total_amount,
        'difference' => $difference,
        'is_debt' => !empty($data['is_debt']) ? 1 : 0,
        'vat_included' => $vat_included,
        'vat_exempt' => $vat_exempt,
        'cash_register_id' => intval($data['cash_register_id'] ?? 0),
        'total_bank_receipts' => $total_bank_receipts,
        'total_additional_cash' => $total_additional_cash
    ];
}

/**
 * WhatsApp mesajını hazırlamaq və göndərmək
 * @param array $report Hesabat məlumatları
 * @param string $cashier_name Kassirin adı
 * @param string $cash_register_name Kassanın adı
 * @param bool $is_edit Düzəliş rejimidirmi
 * @param array $whatsapp_config WhatsApp konfiqurasyonu
 * @return array status: [bool success, string error_message] 
 */
function prepareAndSendWhatsAppMessage($report, $cashier_name, $cash_register_name = null, $is_edit = false, $whatsapp_config = []) {
    global $conn;
    
    // Bank qəbzlərini JSON-dan deşifrə edirik
    $bank_receipts = json_decode($report['bank_receipts'], true) ?: [];
    $total_bank_receipts = array_sum($bank_receipts);
    
    // Əlavə kassadan verilən pulları JSON-dan deşifrə edirik
    $additional_cash = json_decode($report['additional_cash'], true) ?: [];
    $total_additional_cash = array_sum(array_column($additional_cash, 'amount'));
    
    // Mesaj mətni
    $message = "📊 *Kassa Hesabatı #" . $report['id'] . "*\n\n";
    $message .= "👤 *Kassir:* " . $cashier_name . "\n";
    
    if (!empty($cash_register_name)) {
        $message .= "🏦 *Kassa:* " . $cash_register_name . "\n";
    }
    
    $message .= "📅 *Tarix:* " . format_date($report['date']) . "\n\n";
    
    // Bank qəbzləri barədə məlumat
    if (count($bank_receipts) > 0) {
        $message .= "🏦 *Bank Qəbzləri:*\n";
        foreach ($bank_receipts as $index => $receipt) {
            $message .= "- Qəbz #" . ($index + 1) . ": " . format_amount($receipt) . "\n";
        }
        $message .= "*Toplam Bank Qəbzləri:* " . format_amount($total_bank_receipts) . "\n\n";
    }
    
    $message .= "💰 *Nağd Pul:* " . format_amount($report['cash_given']) . "\n";
    
    // Əlavə kassadan verilən pullar barədə məlumat
    if (count($additional_cash) > 0) {
        $message .= "\n🔍 *Əlavə Pul Məlumatları:*\n";
        foreach ($additional_cash as $ac) {
            $message .= "- " . ($ac['description'] ?? 'Təsvir yoxdur') . ": " . format_amount($ac['amount']) . "\n";
        }
        $message .= "*Ümumi Əlavə Məbləğ:* " . format_amount($total_additional_cash) . "\n\n";
    }
    
    $message .= "💳 *POS Məbləği:* " . format_amount($report['pos_amount']) . "\n";
    $message .= "💵 *Yekun Məbləğ:* " . format_amount($report['total_amount']) . "\n";
    
    // Fərq
    $difference_text = format_amount($report['difference']);
    if ($report['difference'] < 0) {
        $message .= "⚠️ *Fərq:* " . $difference_text . " (ÇATIŞMIR)\n";
    } elseif ($report['difference'] > 0) {
        $message .= "✅ *Fərq:* " . $difference_text . " (ARTIQ)\n";
    } else {
        $message .= "✅ *Fərq:* " . $difference_text . " (BƏRABƏR)\n";
    }
    
    // ƏDV məlumatları
    if (isset($report['vat_included']) || isset($report['vat_exempt'])) {
        $message .= "\n🧾 *ƏDV Məlumatları:*\n";
        $message .= "- ƏDV-li: " . format_amount($report['vat_included'] ?? 0) . "\n";
        $message .= "- ƏDV-dən azad: " . format_amount($report['vat_exempt'] ?? 0) . "\n";
        $totalVat = ($report['vat_included'] ?? 0) + ($report['vat_exempt'] ?? 0);
        $message .= "*Ümumi:* " . format_amount($totalVat) . "\n";
        
        // Ayın əvvəlindən bu günə qədər olan ƏDV məlumatları - seçilmiş kassir üçün
        $month_start = date('Y-m-01', strtotime($report['date']));
        $today = $report['date'];
        
        $stmt = $conn->prepare("
            SELECT 
                SUM(vat_included) as total_vat_included,
                SUM(vat_exempt) as total_vat_exempt
            FROM cash_reports
            WHERE date BETWEEN :month_start AND :today
            AND employee_id = :employee_id
        ");
        $stmt->execute([
            ':month_start' => $month_start,
            ':today' => $today,
            ':employee_id' => $report['employee_id']
        ]);
        $monthly_vat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($monthly_vat) {
            $message .= "\n📆 *Kassirin ayın əvvəlindən bu günə qədər:*\n";
            $message .= "- ƏDV-li: " . format_amount($monthly_vat['total_vat_included'] ?? 0) . "\n";
            $message .= "- ƏDV-dən azad: " . format_amount($monthly_vat['total_vat_exempt'] ?? 0) . "\n";
            $monthly_total = ($monthly_vat['total_vat_included'] ?? 0) + ($monthly_vat['total_vat_exempt'] ?? 0);
            $message .= "*Ümumi:* " . format_amount($monthly_total) . "\n";
        }
        
        // Bütün kassir və kassaların cari aydakı toplam ƏDV məlumatları
        $stmt = $conn->prepare("
            SELECT 
                SUM(vat_included) as total_vat_included,
                SUM(vat_exempt) as total_vat_exempt
            FROM cash_reports
            WHERE date BETWEEN :month_start AND :today
        ");
        $stmt->execute([
            ':month_start' => $month_start,
            ':today' => $today
        ]);
        $all_monthly_vat = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($all_monthly_vat) {
            $message .= "\n📊 *Bütün kassirlərin ayın əvvəlindən bu günə qədər:*\n";
            $message .= "- ƏDV-li: " . format_amount($all_monthly_vat['total_vat_included'] ?? 0) . "\n";
            $message .= "- ƏDV-dən azad: " . format_amount($all_monthly_vat['total_vat_exempt'] ?? 0) . "\n";
            $all_monthly_total = ($all_monthly_vat['total_vat_included'] ?? 0) + ($all_monthly_vat['total_vat_exempt'] ?? 0);
            $message .= "*Ümumi:* " . format_amount($all_monthly_total) . "\n";
        }
    }
    
    // Borc
    if ($report['is_debt']) {
        $message .= "\n⚠️ *Diqqət:* Bu hesabat borca yazılmışdır.\n";
    }
    
    // Düzəliş rejimi məlumatı
    if ($is_edit) {
        $message .= "\n📝 *Bu hesabat düzəliş edilmişdir.*\n";
    }
    
    // Mesajın sonuna tarix və saat
    $message .= "\n⏱️ " . date('d.m.Y H:i:s');

    // Rəhbərə göndərilən mesaj
    $result = sendWhatsAppMessage(
        $whatsapp_config['owner_phone_number'], 
        $message, 
        $whatsapp_config['appkey'], 
        $whatsapp_config['authkey'],
        false
    );
    
    // Kassirə göndərilən mesaj (əgər telefon nömrəsi varsa)
    $cashier_phone = '';
    $stmt = $conn->prepare("SELECT phone FROM employees WHERE id = ?");
    $stmt->bind_param("i", $report['employee_id']);
    $stmt->execute();
    $result_employee = $stmt->get_result();
    if ($result_employee->num_rows > 0) {
        $employee_data = $result_employee->fetch_assoc();
        $cashier_phone = $employee_data['phone'];
        
        if (!empty($cashier_phone)) {
            sendWhatsAppMessage(
                $cashier_phone, 
                $message, 
                $whatsapp_config['appkey'], 
                $whatsapp_config['authkey'],
                false
            );
        }
    }
    
    return $result;
}

// Uğur və xəta mesajlarını idarə etmək
$success_message = '';
$error_message   = '';

// Düzəliş rejimi üçün dəyişənlər
$edit_mode = false;
$edit_id = 0;
$edit_data = null;

// Bu dəyişənlər form doldurulandan sonra səhv çıxanda məlumatları saxlayacaq
$old_employee_id          = '';
$old_date                 = '';
$old_bank_receipts        = [];
$old_cash_given           = '';
$old_additional_cash      = [];
$old_additional_cash_desc = [];
$old_pos_amount           = '';
$old_is_debt              = 0;
$old_has_vat              = 1; // default checked
$old_vat_included         = '';
$old_vat_exempt           = '';
$old_vat_total            = '0.00';
$old_cash_register_id     = '';

// Düzəliş rejimini yoxlayırıq
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    
    // Hesabat məlumatlarını əldə edirik
    $stmt = $conn->prepare("
        SELECT cr.*, e.name as employee_name
        FROM cash_reports cr
        JOIN employees e ON cr.employee_id = e.id
        WHERE cr.id = :id
    ");
    $stmt->execute([':id' => $edit_id]);
    $edit_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($edit_data) {
        $edit_mode = true;
        
        // Form dəyərlərini doldururuq
        $old_employee_id = $edit_data['employee_id'];
        $old_date = $edit_data['date'];
        $old_bank_receipts = json_decode($edit_data['bank_receipts'], true) ?: [];
        $old_cash_given = $edit_data['cash_given'];
        $old_additional_cash = [];
        $old_additional_cash_desc = [];
        
        $additional_cash_data = json_decode($edit_data['additional_cash'], true) ?: [];
        foreach ($additional_cash_data as $item) {
            $old_additional_cash[] = $item['amount'];
            $old_additional_cash_desc[] = $item['description'];
        }
        
        $old_pos_amount = $edit_data['pos_amount'];
        $old_is_debt = $edit_data['is_debt'];
        $old_has_vat = ($edit_data['vat_included'] > 0 || $edit_data['vat_exempt'] > 0) ? 1 : 0;
        $old_vat_included = $edit_data['vat_included'];
        $old_vat_exempt = $edit_data['vat_exempt'];
        $old_vat_total = $edit_data['vat_total'] ?? ($old_vat_included + $old_vat_exempt);
        $old_cash_register_id = $edit_data['cash_register_id'];
    }
}

// Form göndərilibsə
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Form məlumatlarını alırıq
        $employee_id  = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
        $date         = isset($_POST['date']) ? $_POST['date'] : '';
        $bank_receipts= isset($_POST['bank_receipts']) ? $_POST['bank_receipts'] : [];
        $cash_given   = isset($_POST['cash_given']) ? (float)$_POST['cash_given'] : 0.00;
        $additional_cash = isset($_POST['additional_cash']) ? $_POST['additional_cash'] : [];
        $additional_cash_descriptions = isset($_POST['additional_cash_descriptions']) 
            ? $_POST['additional_cash_descriptions'] 
            : [];
        $pos_amount = isset($_POST['pos_amount']) ? (float)$_POST['pos_amount'] : 0.00;
        $is_debt    = isset($_POST['is_debt']) ? 1 : 0;
        $has_vat    = isset($_POST['has_vat']) ? 1 : 0;
        $vat_included = ($has_vat && isset($_POST['vat_included'])) ? (float)$_POST['vat_included'] : 0.00;
        $vat_exempt   = ($has_vat && isset($_POST['vat_exempt']))   ? (float)$_POST['vat_exempt']   : 0.00;
        $vat_total    = $vat_included + $vat_exempt;
        
        // Düzəliş rejimini yoxlayırıq
        $edit_id = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
        $edit_mode = ($edit_id > 0);

        // Form dəyərlərini sonra səhv çıxanda göstərmək üçün yadda saxlayırıq
        $old_employee_id          = $employee_id;
        $old_date                 = $date;
        $old_bank_receipts        = $bank_receipts; 
        $old_cash_given           = $cash_given;
        $old_additional_cash      = $additional_cash;
        $old_additional_cash_desc = $additional_cash_descriptions;
        $old_pos_amount           = $pos_amount;
        $old_is_debt              = $is_debt;
        $old_has_vat              = $has_vat;
        $old_vat_included         = $vat_included;
        $old_vat_exempt           = $vat_exempt;
        $old_vat_total            = $vat_total;
        $old_cash_register_id     = isset($_POST['cash_register_id']) ? (int)$_POST['cash_register_id'] : 0;

        // Məlumatların yoxlanması
        if ($employee_id <= 0) {
            throw new Exception('Kassiri seçməlisiniz.');
        }
        if (empty($date)) {
            throw new Exception('Tarixi seçməlisiniz.');
        }
        if ($has_vat && ($vat_included <= 0 && $vat_exempt <= 0)) {
            throw new Exception(
                'Vergi Kassası Hesabatı seçilib, lakin ƏDV-li və/və ya ƏDV-siz məbləğ daxil etməmisiniz!'
            );
        }

        // Bank qəbzlərini təmizləyib JSON formatında saxlayırıq
        $bank_receipts_clean = array_filter(array_map('floatval', $bank_receipts));
        $bank_receipts_json  = json_encode(array_values($bank_receipts_clean));

        // Əlavə kassadan verilən pulları təmizləyib JSON formatında saxlayırıq
        $additional_cash_clean = [];
        foreach ($additional_cash as $index => $amount) {
            $description = isset($additional_cash_descriptions[$index])
                ? trim($additional_cash_descriptions[$index]) 
                : '';
            if ($amount > 0 && !empty($description)) {
                $additional_cash_clean[] = [
                    'amount'      => floatval($amount),
                    'description' => $description
                ];
            }
        }
        $additional_cash_json = json_encode(array_values($additional_cash_clean));

        // Ümumi məbləği hesablamaq
        $total_bank_receipts = array_sum($bank_receipts_clean);
        $total_additional_cash = array_sum(array_column($additional_cash_clean, 'amount'));
        $total_amount = $total_bank_receipts + $cash_given + $total_additional_cash;

        // Fərqi hesablamaq
        $difference = $total_amount - $pos_amount;

        // Verilənlər bazasına yazmaq
        if ($edit_mode) {
            // Mövcud hesabatı yeniləyirik
            $stmt = $conn->prepare("
                UPDATE cash_reports 
                SET employee_id = :employee_id, 
                    date = :date, 
                    bank_receipts = :bank_receipts, 
                    cash_given = :cash_given, 
                    additional_cash = :additional_cash, 
                    pos_amount = :pos_amount, 
                    total_amount = :total_amount, 
                    difference = :difference, 
                    is_debt = :is_debt, 
                    vat_included = :vat_included, 
                    vat_exempt = :vat_exempt,
                    cash_register_id = :cash_register_id
                WHERE id = :id
            ");
            $params = [
                ':id'                 => $edit_id,
                ':employee_id'        => $employee_id,
                ':date'               => $date,
                ':bank_receipts'      => $bank_receipts_json,
                ':cash_given'         => $cash_given,
                ':additional_cash'    => $additional_cash_json,
                ':pos_amount'         => $pos_amount,
                ':total_amount'       => $total_amount,
                ':difference'         => $difference,
                ':is_debt'            => $is_debt,
                ':vat_included'       => $vat_included,
                ':vat_exempt'         => $vat_exempt,
                ':cash_register_id'   => isset($_POST['cash_register_id']) ? (int)$_POST['cash_register_id'] : 0
            ];
            $stmt->execute($params);
            
            $success_message = "Kassa hesabatı uğurla yeniləndi!";
        } else {
            // Yeni hesabat əlavə edirik
            $stmt = $conn->prepare("
                INSERT INTO cash_reports 
                (employee_id, date, bank_receipts, cash_given, additional_cash, 
                 pos_amount, total_amount, difference, is_debt, vat_included, vat_exempt, cash_register_id) 
                VALUES 
                (:employee_id, :date, :bank_receipts, :cash_given, :additional_cash, 
                 :pos_amount, :total_amount, :difference, :is_debt, :vat_included, :vat_exempt, :cash_register_id)
            ");
            $params = [
                ':employee_id'        => $employee_id,
                ':date'               => $date,
                ':bank_receipts'      => $bank_receipts_json,
                ':cash_given'         => $cash_given,
                ':additional_cash'    => $additional_cash_json,
                ':pos_amount'         => $pos_amount,
                ':total_amount'       => $total_amount,
                ':difference'         => $difference,
                ':is_debt'            => $is_debt,
                ':vat_included'       => $vat_included,
                ':vat_exempt'         => $vat_exempt,
                ':cash_register_id'   => isset($_POST['cash_register_id']) ? (int)$_POST['cash_register_id'] : 0
            ];
            $stmt->execute($params);
            
            $success_message = "Kassa hesabatı uğurla əlavə edildi!";
        }

        // Əgər fərq borc kimi yazılmalıdırsa
        if ($is_debt && $difference != 0) {
            $reason = $difference > 0 ? 'Kassa artıqı' : 'Kassa kəsiri';
            $amount = abs($difference);
            
            // Düzəliş rejimində əvvəlki borcu yoxlayırıq və silirik
            if ($edit_mode) {
                // Bu tarix və kassir üçün əvvəlki kassa borclarını silirik
                $stmt = $conn->prepare("
                    DELETE FROM debts 
                    WHERE employee_id = :employee_id 
                      AND date = :date 
                      AND (reason = 'Kassa artıqı' OR reason = 'Kassa kəsiri')
                      AND is_paid = 0
                ");
                $stmt->execute([
                    ':employee_id' => $employee_id,
                    ':date' => $date
                ]);
            }
            
            // Yeni borcu əlavə edirik
            $debt_result = addDebt($conn, $employee_id, $amount, $date, $reason);
            
            if ($debt_result === false) {
                $error_message .= "Borc əlavə edilərkən xəta baş verdi.<br>";
            } else {
                $success_message .= " Fərq kassirin adına borc olaraq qeyd edildi.";
            }
        } else {
            // Əgər borc kimi yazılmayacaqsa, mövcud kassa borclarını silirik
            $stmt = $conn->prepare("
                DELETE FROM debts 
                WHERE employee_id = :employee_id 
                  AND date = :date 
                  AND (reason = 'Kassa artıqı' OR reason = 'Kassa kəsiri')
                  AND is_paid = 0
            ");
            $stmt->execute([
                ':employee_id' => $employee_id,
                ':date' => $date
            ]);
        }

        // Kassirin adını əldə edirik
        $stmt = $conn->prepare("SELECT name FROM employees WHERE id = :employee_id");
        $stmt->execute([':employee_id' => $employee_id]);
        $cashier = $stmt->fetch();
        $cashier_name = $cashier ? $cashier['name'] : 'Bilinməyən Kassir';

        // Əlavə kassadan verilən pulların izahını hazırlayırıq (WhatsApp mesajı üçün)
        $additional_cash_details = '';
        foreach ($additional_cash_clean as $ac) {
            $additional_cash_details .= number_format($ac['amount'], 2) 
                                        . " AZN - " 
                                        . htmlspecialchars($ac['description']) 
                                        . "\n";
        }

        // Kassaya aid WhatsApp mesajı
        $message = "Kassir {$cashier_name},\n\n" .
                   ($edit_mode ? "Kassa hesabatınızda düzəliş edilmişdir.\n\n" : "Sizə yeni bir kassa hesabatı əlavə edilmişdir.\n\n") .
                   "Tarix: " . htmlspecialchars($date) . "\n" .
                   "Nağd Pul: " . number_format($cash_given, 2) . " AZN\n" .
                   "Bank Qəbzlərinin Cəmi: " . number_format($total_bank_receipts, 2) . " AZN\n" .
                   "Əlavə Kassadan Verilən Pullar:\n{$additional_cash_details}\n" .
                   "Yekun Məbləğ: " . number_format($total_amount, 2) . " AZN\n" .
                   "POS Məbləği: " . number_format($pos_amount, 2) . " AZN\n" .
                   "Fərq: " . number_format($difference, 2) . " AZN\n";
        
        // Borc məlumatını əlavə edirik
        if ($is_debt && $difference != 0) {
            $debt_type = $difference > 0 ? 'artıq' : 'kəsir';
            $message .= "⚠️ Bu fərq kassirin adına BORC olaraq qeyd edilmişdir (Kassa {$debt_type}).\n";
        }
        
        $message .= "\nƏlavə məlumat üçün sistemə daxil olun.";

        // Bu nömrəyə göndəririk (sahibkar və ya kimə lazımdırsa)
        // config.php-dən $owner_phone_number
        $recipients = [$whatsapp_config['owner_phone_number']];

        foreach ($recipients as $number) {
            // Telebat API-yə göndəririk
            $result = sendWhatsAppMessage($number, $message);

            if ($result['success']) {
                // Yeni API-da cavab artıq JSON formatında gəlir
                $response_data = $result['data'] ?? [];
                
                // API.md-ə uyğun cavab formatını yoxlayaq: {"status":"sent"}
                if ($result['http_status'] == 200) {
                    if (isset($response_data['status']) && $response_data['status'] === 'sent') {
                        // Mesaj uğurla göndərildi, heç bir xəta mesajı göstərmə
                    } else {
                        // Gözlənilməz cavab formatı
                        $error_text = 'Gözlənilməz cavab formatı';
                        $error_message .= "Mesaj göndərilmədi: $number - " . $error_text . "<br>";
                    }
                } else {
                    // HTTP xətası
                    $error_text = isset($response_data['error'])
                        ? htmlspecialchars($response_data['error'])
                        : 'HTTP xətası: ' . $result['http_status'];
                    $error_message .= "Mesaj göndərilmədi: $number - " . $error_text . "<br>";
                }
            } else {
                $error_message .= "Mesaj göndərilmədi: $number - " 
                                  . htmlspecialchars($result['error']) 
                                  . "<br>";
            }
        }

        // Sessiyada hesabat məlumatlarını saxlayırıq ki, tarixçə səhifəsinə keçid üçün istifadə edə bilək
        $_SESSION['last_cash_report'] = [
            'employee_id' => $employee_id,
            'date' => $date,
            'total_amount' => $total_amount,
            'difference' => $difference,
            'edit_mode' => $edit_mode,
            'edit_id' => $edit_id
        ];

        // Uğur mesajı
        $success_message = "Kassa hesabatı uğurla saxlanıldı.";
        
        // Form dəyərlərini təmizləyirik
        $old_employee_id = '';
        $old_date = '';
        $old_bank_receipts = [];
        $old_cash_given = '';
        $old_additional_cash = [];
        $old_additional_cash_desc = [];
        $old_pos_amount = '';
        $old_is_debt = 0;
        $old_has_vat = 1;
        $old_vat_included = '';
        $old_vat_exempt = '';
        $old_vat_total = '0.00';
        $old_cash_register_id = '';

        // Əgər has_vat = 1-dirsə, VAT mesajını da göndəririk
        if ($has_vat) {
            // Ayın əvvəlindən bu günə qədər olan ƏDV məlumatları - bütün kassir və kassalar üçün
            $month_start = date('Y-m-01', strtotime($date));
            $today = $date;
            
            $stmt = $conn->prepare("
                SELECT 
                    SUM(vat_included) as total_vat_included,
                    SUM(vat_exempt) as total_vat_exempt
                FROM cash_reports
                WHERE date BETWEEN :month_start AND :today
            ");
            $stmt->execute([
                ':month_start' => $month_start,
                ':today' => $today
            ]);
            $all_monthly_vat = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $vat_message = "Kassir {$cashier_name},\n\n" .
                           "Vergi Kassası Hesabatı:\n\n" .
                           "ƏDV-li Məbləğ: " . number_format($vat_included, 2) . " AZN\n" .
                           "ƏDV-dən Azad Məbləğ: " . number_format($vat_exempt, 2) . " AZN\n" .
                           "Toplam Məbləğ: " . number_format($vat_total, 2) . " AZN\n\n";
            
            if ($all_monthly_vat) {
                $vat_message .= "Bütün kassirlərin ayın əvvəlindən bu günə qədər:\n" .
                               "ƏDV-li Məbləğ: " . number_format($all_monthly_vat['total_vat_included'] ?? 0, 2) . " AZN\n" .
                               "ƏDV-dən Azad Məbləğ: " . number_format($all_monthly_vat['total_vat_exempt'] ?? 0, 2) . " AZN\n" .
                               "Toplam Məbləğ: " . number_format(($all_monthly_vat['total_vat_included'] ?? 0) + ($all_monthly_vat['total_vat_exempt'] ?? 0), 2) . " AZN\n\n";
            }
            
            $vat_message .= "Bu məbləğlər kassa hesabatlarına aid deyil.";

            // Vergi kassası məlumatı göndəriləcək nömrə (misal üçün)
            $vat_recipient = '994708866551';
            $vat_result = sendWhatsAppMessage($vat_recipient, $vat_message);

            if ($vat_result['success']) {
                $vat_response_data = $vat_result['data'] ?? [];
                
                // API.md-ə uyğun cavab formatını yoxlayaq: {"status":"sent"}
                if ($vat_result['http_status'] == 200) {
                    if (isset($vat_response_data['status']) && $vat_response_data['status'] === 'sent') {
                        // VAT mesajı uğurla göndərildi, heç bir xəta mesajı göstərmə
                    } else {
                        // Gözlənilməz cavab formatı
                        $error_text = 'Gözlənilməz cavab formatı';
                        $error_message .= "VAT mesajı göndərilmədi: $vat_recipient - " . $error_text . "<br>";
                    }
                } else {
                    // HTTP xətası
                    $error_text = isset($vat_response_data['error'])
                        ? htmlspecialchars($vat_response_data['error'])
                        : 'HTTP xətası: ' . $vat_result['http_status'];
                    $error_message .= "VAT mesajı göndərilmədi: $vat_recipient - " . $error_text . "<br>";
                }
            } else {
                $error_message .= "VAT mesajı göndərilmədi: $vat_recipient - " 
                                  . htmlspecialchars($vat_result['error']) 
                                  . "<br>";
            }
        }

        // Əgər bu yerə qədər hər hansı ciddi xəta olmadısa
        if (!$error_message) {
            $success_message = "Kassa hesabatı uğurla saxlanıldı.";
            $_SESSION['success_message'] = $success_message;
            
            // Hesabat ID-ni sessiyada saxlayırıq
            if ($edit_mode) {
                $_SESSION['last_report_id'] = $edit_id;
            } else {
                $_SESSION['last_report_id'] = $conn->lastInsertId();
            }
            
            header('Location: cash_reports.php?report_saved=1');
            exit();
        }

    } catch (Exception $e) {
        // Xəta baş verərsə
        $error_message = $e->getMessage();
    }
}

// Hesabat formunun işlənməsi - əsas PHP kod hissəsi - bu bloku tamamilə siləcəyik
/*
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['view_report'])) {
    // Bu kod hissəsi tamamilə ləğv ediləcək
    $employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
    $cash_register_id = isset($_GET['cash_register_id']) ? (int)$_GET['cash_register_id'] : 0;
    $date = isset($_GET['date']) ? sanitize_input($_GET['date']) : date('Y-m-d');

    // Hər ikisi seçilibsə, xəta çıxar
    if ($employee_id > 0 && $cash_register_id > 0) {
        $error_message = "Zəhmət olmasa ya kassir, ya da kassa seçin, hər ikisini eyni zamanda seçmək olmaz.";
    } 
    // Heç biri seçilməyibsə, xəta çıxar
    elseif ($employee_id == 0 && $cash_register_id == 0) {
        $error_message = "Zəhmət olmasa ya kassir, ya da kassa seçin.";
    }
    else {
        // Əməliyyatları gətir
        $operations = getOperations($conn, $employee_id, $date, $cash_register_id);
        
        if (empty($operations)) {
            if ($employee_id > 0) {
                $error_message = "Seçilmiş kassir üçün " . date('d.m.Y', strtotime($date)) . " tarixində hesabat tapılmadı.";
            } else {
                $error_message = "Seçilmiş kassa üçün " . date('d.m.Y', strtotime($date)) . " tarixində hesabat tapılmadı.";
            }
        }
    }
}
*/

// Kassirləri və kassaları gətir
$cashiers = getCashiers($conn);
$cash_registers = getCashRegisters($conn);

// Seçilmiş kassir və tarix üçün əməliyyatları əldə edirik
$selected_cashier_id = isset($_GET['selected_cashier_id']) ? (int)$_GET['selected_cashier_id'] : 0;
$selected_date = isset($_GET['selected_date']) ? $_GET['selected_date'] : '';

$reports = [];
if ($selected_cashier_id > 0 && !empty($selected_date)) {
    $reports = getOperations($conn, $selected_cashier_id, $selected_date);
}


// Kassir üçün bu tarixdə artıq hesabat olub-olmadığını AJAX ilə yoxlamaq üçün
if (isset($_GET['check']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
    $date = isset($_GET['date']) ? $_GET['date'] : '';

    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM cash_reports 
        WHERE employee_id = :employee_id 
          AND date = :date
    ");
    $stmt->execute([':employee_id' => $employee_id, ':date' => $date]);
    $count = $stmt->fetchColumn();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['exists' => $count > 0]);
    exit();
}

// ====================================
// EXCEL EXPORT HİSSƏSİ
// ====================================
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Seçilmiş kassir və tarix üzrə $reports massivindəki məlumatları Excel faylı kimi göndəririk
    if ($selected_cashier_id > 0 && !empty($selected_date) && !empty($reports)) {
        try {
            // (Bu hissə eynilə sizdə olduğu kimi saxlanılıb. Excel faylı yaradılır.)
            // 1. Müxtəlif XML Fayllarını Yaradın

            // 1.1 [Content_Types].xml
            $contentTypes = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' . "\n" .
                '    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' . "\n" .
                '    <Default Extension="xml" ContentType="application/xml"/>' . "\n" .
                '    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . "\n" .
                '    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' . "\n" .
                '    <Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>' . "\n" .
                '</Types>';

            // 1.2 _rels/.rels
            $rels = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
                '    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' . "\n" .
                '</Relationships>';

            // 1.3 xl/workbook.xml
            $workbook = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" ' .
                'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' . "\n" .
                '    <sheets>' . "\n" .
                '        <sheet name="Hesabat" sheetId="1" r:id="rId1"/>' . "\n" .
                '    </sheets>' . "\n" .
                '</workbook>';

            // 1.4 xl/_rels/workbook.xml.rels
            $workbookRels = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . "\n" .
                '    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' . "\n" .
                '    <Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>' . "\n" .
                '</Relationships>';

            // 1.5 xl/sharedStrings.xml
            $unique_strings = [];
            $headers = [
                'Tarix',
                'Kassir',
                'Bank Qəbzləri (AZN)',
                'Nağd Pul (AZN)',
                'Əlavə Kassadan Verilən Pullar (AZN)',
                'POS Məbləği (AZN)',
                'Yekun Məbləğ (AZN)',
                'Fərq (AZN)',
                'ƏDV-li Məbləğ (AZN)',
                'ƏDV-dən Azad Məbləğ (AZN)',
                'Borc'
            ];

            foreach ($headers as $header) {
                $unique_strings[] = $header;
            }

            // Məlumatlardan stringləri toplayın
            foreach ($reports as $report) {
                $unique_strings[] = $report['date'];
                $unique_strings[] = $report['name'];
                $additionalCashArr = json_decode($report['additional_cash'], true);
                if ($additionalCashArr && count($additionalCashArr) > 0) {
                    foreach ($additionalCashArr as $ac) {
                        $unique_strings[] = $ac['description'];
                    }
                }
                // Borc sətiri
                $unique_strings[] = $report['is_debt'] ? 'Borc' : '-';
            }

            // Duplicatları aradan qaldırırıq
            $unique_strings = array_values(array_unique($unique_strings));

            // sharedStrings.xml faylını yaradın
            $sharedStringsXML = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
                '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($unique_strings) . '" uniqueCount="' . count($unique_strings) . '">' . "\n";

            foreach ($unique_strings as $string) {
                $sharedStringsXML .= '    <si><t>' . htmlspecialchars($string) . '</t></si>' . "\n";
            }
            $sharedStringsXML .= '</sst>';

            // 1.6 xl/worksheets/sheet1.xml
            $sheetData = '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' . "\n" .
                '    <sheetData>' . "\n" .
                '        <row r="1">' . "\n";

            // Başlıqları əlavə edin
            foreach ($headers as $colIndex => $header) {
                $sharedStringIndex = array_search($header, $unique_strings);
                $cellRef = chr(65 + $colIndex) . '1'; // A1, B1, C1, ...
                $sheetData .= '            <c r="' . $cellRef . '" t="s"><v>' . $sharedStringIndex . '</v></c>' . "\n";
            }
            $sheetData .= '        </row>' . "\n";

            // Məlumat sətirlərini əlavə edin
            $rowNumber = 2;
            foreach ($reports as $report):
                $sheetData .= '        <row r="' . $rowNumber . '">' . "\n";

                // A: Tarix (string)
                $dateIndex = array_search($report['date'], $unique_strings);
                $sheetData .= '            <c r="A' . $rowNumber . '" t="s"><v>' . $dateIndex . '</v></c>' . "\n";

                // B: Kassir (string)
                $cashierIndex = array_search($report['name'], $unique_strings);
                $sheetData .= '            <c r="B' . $rowNumber . '" t="s"><v>' . $cashierIndex . '</v></c>' . "\n";

                // C: Bank Qəbzləri (AZN) (string)
                $bankReceiptsArr = json_decode($report['bank_receipts'], true);
                $bankReceiptsStr = '-';
                if ($bankReceiptsArr && count($bankReceiptsArr) > 0) {
                    $bankReceiptsStr = implode(", ", array_map(function($val) {
                        return number_format($val, 2);
                    }, $bankReceiptsArr));
                }
                // Unikal massivə salıb index tapırıq, əks halda yeni axtarış
                if (!in_array($bankReceiptsStr, $unique_strings)) {
                    $unique_strings[] = $bankReceiptsStr;
                }
                $bankReceiptsIndex = array_search($bankReceiptsStr, $unique_strings);
                $sheetData .= '            <c r="C' . $rowNumber . '" t="s"><v>' . $bankReceiptsIndex . '</v></c>' . "\n";

                // D: Nağd Pul (AZN) (number)
                $cash_given = number_format($report['cash_given'], 2, '.', '');
                $sheetData .= '            <c r="D' . $rowNumber . '" t="n"><v>' . $cash_given . '</v></c>' . "\n";

                // E: Əlavə Kassadan Verilən Pullar (AZN) (string)
                $additionalCashArr = json_decode($report['additional_cash'], true);
                $additionalCashStr = '-';
                if ($additionalCashArr && count($additionalCashArr) > 0) {
                    $arrText = [];
                    foreach ($additionalCashArr as $ac) {
                        $arrText[] = number_format($ac['amount'], 2) . " AZN - " . htmlspecialchars($ac['description']);
                    }
                    $additionalCashStr = implode("; ", $arrText);
                }
                if (!in_array($additionalCashStr, $unique_strings)) {
                    $unique_strings[] = $additionalCashStr;
                }
                $additionalCashIndex = array_search($additionalCashStr, $unique_strings);
                $sheetData .= '            <c r="E' . $rowNumber . '" t="s"><v>' . $additionalCashIndex . '</v></c>' . "\n";

                // F: POS Məbləği (AZN) (number)
                $pos_amount = number_format($report['pos_amount'], 2, '.', '');
                $sheetData .= '            <c r="F' . $rowNumber . '" t="n"><v>' . $pos_amount . '</v></c>' . "\n";

                // G: Yekun Məbləğ (AZN) (number)
                $total_amount = number_format($report['total_amount'], 2, '.', '');
                $sheetData .= '            <c r="G' . $rowNumber . '" t="n"><v>' . $total_amount . '</v></c>' . "\n";

                // H: Fərq (AZN) (number)
                $difference = number_format($report['difference'], 2, '.', '');
                $sheetData .= '            <c r="H' . $rowNumber . '" t="n"><v>' . $difference . '</v></c>' . "\n";

                // I: ƏDV-li Məbləğ (AZN) (number)
                $vat_included = number_format($report['vat_included'], 2, '.', '');
                $sheetData .= '            <c r="I' . $rowNumber . '" t="n"><v>' . $vat_included . '</v></c>' . "\n";

                // J: ƏDV-dən Azad Məbləğ (AZN) (number)
                $vat_exempt = number_format($report['vat_exempt'], 2, '.', '');
                $sheetData .= '            <c r="J' . $rowNumber . '" t="n"><v>' . $vat_exempt . '</v></c>' . "\n";

                // K: Borc (string)
                $debtStr = $report['is_debt'] ? 'Borc' : '-';
                $debtIndex = array_search($debtStr, $unique_strings);
                $sheetData .= '            <c r="K' . $rowNumber . '" t="s"><v>' . $debtIndex . '</v></c>' . "\n";

                $sheetData .= '        </row>' . "\n";
                $rowNumber++;
            endforeach;

            $sheetData .= '    </sheetData>' . "\n" .
                '</worksheet>';

            // 1.7 zip arxivini yaradın və faylları əlavə edin
            $zip = new ZipArchive();
            $temp_file = tempnam(sys_get_temp_dir(), 'xlsx');
            if ($zip->open($temp_file, ZipArchive::CREATE) !== TRUE) {
                throw new Exception("Cannot create ZIP file");
            }

            // ZIP-ə faylları əlavə edirik
            $zip->addFromString('[Content_Types].xml', $contentTypes);
            $zip->addFromString('_rels/.rels', $rels);
            $zip->addFromString('xl/workbook.xml', $workbook);
            $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
            $zip->addFromString('xl/sharedStrings.xml', $sharedStringsXML);
            $zip->addFromString('xl/worksheets/sheet1.xml', $sheetData);
            $zip->close();

            // 1.8 XLSX faylını göndəririk
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="cash_reports_' . date('Ymd_His') . '.xlsx"');
            header('Content-Length: ' . filesize($temp_file));

            readfile($temp_file);
            unlink($temp_file); // müvəqqəti faylı sil
            exit();

        } catch (Exception $e) {
            echo "Excel eksport zamanı xəta baş verdi: " . $e->getMessage();
            exit();
        }
    } else {
        echo "Excel eksport etmək üçün məlumat tapılmadı.";
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <title>Kassa Hesabatları</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <style>
        .table td, .table th {
            vertical-align: middle;
            text-align: center;
        }
        .remove-row {
            cursor: pointer;
            color: red;
            font-weight: bold;
            padding-left: 10px;
        }
        .close-section {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 1.2rem;
            font-weight: bold;
            color: #dc3545;
            cursor: pointer;
        }
        .relative-position {
            position: relative;
        }
        /* Şifrə modalındakı göz ikonu üçün stil */
        .toggle-password {
            position: absolute;
            top: 38px;
            right: 10px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <!-- Navbar (əgər varsa) -->
    <?php include 'includes/header.php'; ?>

    <!-- Ay Seçimi Modal -->
    <div class="modal fade" id="monthDateModal" tabindex="-1" aria-labelledby="monthDateModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header bg-primary text-white">
            <h5 class="modal-title" id="monthDateModalLabel"><i class="fas fa-calendar-alt"></i> Tarix Seçimi</h5>
            <button type="button" class="btn-close bg-white" data-bs-dismiss="modal" aria-label="Bağla"></button>
          </div>
          <div class="modal-body">
            <div class="text-center mb-4">
              <i class="fas fa-exclamation-triangle text-warning fa-3x mb-3"></i>
              <p class="lead">Zəhmət olmasa hesabat üçün tarixi seçin</p>
              <p class="text-muted">Hər dəfə hesabat əlavə edərkən tarix seçilməlidir</p>
            </div>
            <div class="form-group">
              <label for="modalDatePicker" class="form-label fw-bold">Hesabat tarixi:</label>
              <input type="date" class="form-control form-control-lg" id="modalDatePicker" value="<?php echo date('Y-m-d'); ?>">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-primary btn-lg" id="applySelectedDate">Təsdiq et</button>
          </div>
        </div>
      </div>
    </div>

    <div class="container mt-4">
        <h2 class="mb-4">Kassa Hesabatları</h2>

        <!-- Uğur və Xəta Mesajları -->
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo nl2br(htmlspecialchars($success_message)); ?>
                <?php if (isset($_SESSION['last_cash_report'])): ?>
                    <div class="mt-2">
                        <a href="cash_report_history.php?selected_cashier_ids[]=<?php echo $_SESSION['last_cash_report']['employee_id']; ?>&start_date=<?php echo $_SESSION['last_cash_report']['date']; ?>&end_date=<?php echo $_SESSION['last_cash_report']['date']; ?>" class="btn btn-info btn-sm">
                            <i class="fas fa-history"></i> Hesabat Tarixçəsində Göstər
                        </a>
                        <?php if ($_SESSION['last_cash_report']['edit_mode']): ?>
                            <a href="cash_reports.php" class="btn btn-primary btn-sm ml-2">
                                <i class="fas fa-plus-circle"></i> Yeni Hesabat Əlavə Et
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['report_saved']) && isset($_SESSION['last_report_id'])): ?>
                    <div class="mt-2">
                        <a href="view_report.php?id=<?php echo $_SESSION['last_report_id']; ?>" class="btn btn-success btn-sm">
                            <i class="fas fa-eye"></i> Hesabata Keç
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <?php echo nl2br(htmlspecialchars($error_message)); ?>
            </div>
        <?php endif; ?>

        <!-- Hesabat axtarışı üçün forma silinib -->

        <!-- Hesabat Əlavə Etmə Forması -->
        <div class="card mb-4 relative-position" id="cash-report-form">
            <div class="card-header">
                <h4><?php echo $edit_mode ? 'Kassa Hesabatını Düzəliş Et' : 'Kassa Hesabatı Əlavə Et'; ?></h4>
            </div>
            <div class="card-body">
                <form method="POST" action="cash_reports.php<?php echo $edit_mode ? '?edit=' . $edit_id : ''; ?>">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">
                    <?php endif; ?>
                    
                    <!-- Kassir seçimi -->
                    <div class="form-group">
                        <label for="cashier_id">Kassir Seçin:</label>
                        <select name="employee_id" id="cashier_id" class="form-control" required>
                            <option value="">Seçin</option>
                            <?php foreach ($cashiers as $cashier): ?>
                                <option value="<?php echo $cashier['id']; ?>" 
                                    data-cash-register="<?php echo $cashier['cash_register_id']; ?>"
                                    <?php if ($cashier['id'] == $old_employee_id) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($cashier['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Kassa seçimi -->
                    <div class="form-group">
                        <label for="cash_register_id">Kassa Seçin:</label>
                        <select name="cash_register_id" id="cash_register_id" class="form-control">
                            <option value="">Seçin</option>
                            <?php foreach ($cash_registers as $register): ?>
                                <option value="<?php echo $register['id']; ?>"
                                    <?php if ($register['id'] == ($old_cash_register_id ?? '')) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($register['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Tarix seçimi -->
                    <div class="form-group">
                        <label for="date">Tarix:</label>
                        <input type="date" name="date" id="date" class="form-control" 
                               value="<?php echo htmlspecialchars($old_date ?: date('Y-m-d')); ?>" 
                               required>
                    </div>

                    <!-- Bank Qəbzləri -->
                    <div class="form-group">
                        <label>Bank Qəbzləri Məbləğləri:</label>
                        <div id="bank-receipts-container">
                            <?php 
                            if (!empty($old_bank_receipts)) {
                                foreach ($old_bank_receipts as $receiptValue): ?>
                                    <div class="input-group mb-2">
                                        <input type="number" step="0.01" name="bank_receipts[]" 
                                               class="form-control bank-receipt" 
                                               placeholder="Məbləğ daxil edin" 
                                               value="<?php echo htmlspecialchars($receiptValue); ?>" 
                                               required>
                                        <div class="input-group-append">
                                            <span class="input-group-text remove-row" onclick="removeRow(this)">×</span>
                                        </div>
                                    </div>
                                <?php endforeach;
                            } else { ?>
                                <div class="input-group mb-2">
                                    <input type="number" step="0.01" name="bank_receipts[]" 
                                           class="form-control bank-receipt" 
                                           placeholder="Məbləğ daxil edin" required>
                                    <div class="input-group-append">
                                        <span class="input-group-text remove-row" onclick="removeRow(this)">×</span>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                        <button type="button" class="btn btn-secondary mt-2" id="add-bank-receipt">
                            Yeni Qəbz Əlavə Et
                        </button>
                    </div>

                    <!-- Nağd Pul -->
                    <div class="form-group">
                        <label for="cash_given">Nağd Pul (AZN):</label>
                        <input type="number" step="0.01" name="cash_given" id="cash_given" 
                               class="form-control" required
                               value="<?php echo htmlspecialchars($old_cash_given); ?>">
                    </div>

                    <!-- Əlavə Kassadan Verilən Pullar -->
                    <div class="form-group">
                        <label>Əlavə Kassadan Verilən Pullar (AZN):</label>
                        <div id="additional-cash-container">
                            <?php
                            if (!empty($old_additional_cash)) {
                                foreach ($old_additional_cash as $index => $cashValue):
                                    $descValue = isset($old_additional_cash_desc[$index]) ? $old_additional_cash_desc[$index] : ''; ?>
                                    <div class="form-row mb-2">
                                        <div class="col">
                                            <input type="number" step="0.01" name="additional_cash[]" 
                                                   class="form-control additional-cash" 
                                                   placeholder="Məbləğ daxil edin" required
                                                   value="<?php echo htmlspecialchars($cashValue); ?>">
                                        </div>
                                        <div class="col">
                                            <input type="text" name="additional_cash_descriptions[]" 
                                                   class="form-control" 
                                                   placeholder="Açıqlama daxil edin" required
                                                   value="<?php echo htmlspecialchars($descValue); ?>">
                                        </div>
                                        <div class="col-1">
                                            <span class="remove-row" onclick="removeRow(this)">×</span>
                                        </div>
                                    </div>
                                <?php endforeach;
                            } else { ?>
                                <div class="form-row mb-2">
                                    <div class="col">
                                        <input type="number" step="0.01" name="additional_cash[]" 
                                               class="form-control additional-cash" 
                                               placeholder="Məbləğ daxil edin" required>
                                    </div>
                                    <div class="col">
                                        <input type="text" name="additional_cash_descriptions[]" 
                                               class="form-control" 
                                               placeholder="Açıqlama daxil edin" required>
                                    </div>
                                    <div class="col-1">
                                        <span class="remove-row" onclick="removeRow(this)">×</span>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                        <button type="button" class="btn btn-secondary mt-2" id="add-additional-cash">
                            Yeni Ödəniş Əlavə Et
                        </button>
                    </div>

                    <!-- Vergi Kassası Hesabatı Checkbox -->
                    <div class="form-check mb-3">
                        <input type="checkbox" name="has_vat" id="has_vat" 
                               class="form-check-input" value="1"
                               <?php if($old_has_vat) echo 'checked'; ?>>
                        <label for="has_vat" class="form-check-label">
                            Vergi Kassası Hesabatı var
                        </label>
                    </div>

                    <!-- VAT Hesabatı -->
                    <div class="card mb-4" id="vat-section">
                        <div class="card-header">
                            <h5>Vergi Kassası Hesabatı</h5>
                            <span class="close-section" onclick="hideSection('vat-section')">&times;</span>
                        </div>
                        <div class="card-body">
                            <!-- ƏDV-li Məbləğ -->
                            <div class="form-group">
                                <label for="vat_included">ƏDV-li Məbləğ (AZN):</label>
                                <input type="number" step="0.01" name="vat_included" id="vat_included" 
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($old_vat_included); ?>">
                            </div>

                            <!-- ƏDV-dən Azad Məbləğ -->
                            <div class="form-group">
                                <label for="vat_exempt">ƏDV-dən Azad Məbləğ (AZN):</label>
                                <input type="number" step="0.01" name="vat_exempt" id="vat_exempt" 
                                       class="form-control"
                                       value="<?php echo htmlspecialchars($old_vat_exempt); ?>">
                            </div>

                            <!-- Toplam ƏDV Məbləği -->
                            <div class="form-group">
                                <label for="vat_total">Toplam ƏDV Məbləği (AZN):</label>
                                <input type="number" step="0.01" name="vat_total" id="vat_total" 
                                       class="form-control" readonly
                                       value="<?php echo htmlspecialchars($old_vat_total); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Yekun Məbləğ -->
                    <div class="form-group">
                        <label for="total_amount">Yekun Məbləğ (AZN):</label>
                        <input type="number" step="0.01" name="total_amount" id="total_amount" 
                               class="form-control" readonly
                               value="0.00">
                    </div>

                    <!-- POS Sistemi -->
                    <div class="form-group">
                        <label for="pos_amount">POS Sistemində Göstərilən Məbləğ (AZN):</label>
                        <input type="number" step="0.01" name="pos_amount" id="pos_amount" 
                               class="form-control" required
                               value="<?php echo htmlspecialchars($old_pos_amount); ?>">
                    </div>

                    <!-- Fərq -->
                    <div class="form-group">
                        <label for="difference">Fərq (AZN):</label>
                        <input type="number" step="0.01" name="difference" id="difference" 
                               class="form-control" readonly value="0.00">
                    </div>

                    <!-- Borc kimi yazılsın -->
                    <div class="form-group form-check">
                        <input type="checkbox" name="is_debt" id="is_debt" class="form-check-input"
                               <?php if($old_is_debt) echo 'checked'; ?>>
                        <label for="is_debt" class="form-check-label">
                            Fərq kassirin adına borc kimi yazılsın
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary">Hesabatı Saxla</button>
                </form>
            </div>
        </div>

        <!-- Şifrə Modal Pəncərəsi-->
        <div class="modal fade" id="passwordModal" tabindex="-1" aria-labelledby="passwordModalLabel" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="passwordModalLabel">Təkrar Hesabat: Şifrə Tələb Olunur</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Bağla"></button>
              </div>
              <div class="modal-body">
                <div class="mb-3 position-relative">
                  <label for="modalPassword" class="form-label">Şifrə:</label>
                  <input type="password" class="form-control" id="modalPassword" placeholder="Şifrə daxil edin">
                  <i class="fa fa-eye toggle-password"></i>
                </div>
                <div id="passwordError" class="text-danger" style="display:none;">Yanlış şifrə!</div>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ləğv et</button>
                <button type="button" class="btn btn-primary" id="passwordSubmit">Təsdiq et</button>
              </div>
            </div>
          </div>
        </div>

        <!-- Hesabat Tarixçəsi -->
        <div class="card">
            <div class="card-header">
                <h4>Hesabat Tarixçəsi</h4>
            </div>
            <div class="card-body">
                <form method="GET" action="cash_reports.php" class="form-inline mb-3">
                    <label for="selected_date" class="mr-2">Tarix seçin:</label>
                    <input type="date" name="selected_date" id="selected_date" 
                           class="form-control mr-2" 
                           value="<?php echo htmlspecialchars($selected_date); ?>" required>
                    
                    <label for="selected_cashier_id" class="mr-2">Kassir seçin:</label>
                    <select name="selected_cashier_id" id="selected_cashier_id" 
                            class="form-control mr-2" required>
                        <option value="">Seçin</option>
                        <?php foreach ($cashiers as $cashier): ?>
                            <option value="<?php echo $cashier['id']; ?>"
                                <?php if ($selected_cashier_id == $cashier['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($cashier['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="submit" class="btn btn-primary">Axtar</button>
                </form>

                <?php if ($selected_cashier_id > 0 && !empty($selected_date)): ?>
                    <?php if (count($reports) > 0): ?>
                        <!-- Excelə Export Düyməsi -->
                        <a href="cash_reports.php?selected_date=<?php echo urlencode($selected_date); ?>&selected_cashier_id=<?php echo $selected_cashier_id; ?>&export=excel" 
                           class="btn btn-success mb-3">
                           <i class="fas fa-file-excel"></i> Excelə Export Et
                        </a>

                        <table class="table table-bordered table-hover">
                            <thead class="thead-dark">
                                <tr>
                                    <th>Bank Qəbzləri (AZN)</th>
                                    <th>Nağd Pul (AZN)</th>
                                    <th>Əlavə Kassadan Verilən Pullar (AZN)</th>
                                    <th>POS Məbləği (AZN)</th>
                                    <th>Yekun Məbləğ (AZN)</th>
                                    <th>Fərq (AZN)</th>
                                    <th>ƏDV-li Məbləğ (AZN)</th>
                                    <th>ƏDV-dən Azad Məbləğ (AZN)</th>
                                    <th>Borc</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <tr>
                                        <td>
                                            <?php 
                                                $bankReceiptsArr = json_decode($report['bank_receipts'], true);
                                                if ($bankReceiptsArr && count($bankReceiptsArr) > 0) {
                                                    foreach ($bankReceiptsArr as $receipt) {
                                                        echo number_format($receipt, 2) . "<br>";
                                                    }
                                                } else {
                                                    echo "–";
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo number_format($report['cash_given'], 2); ?></td>
                                        <td>
                                            <?php 
                                                $additionalCashArr = json_decode($report['additional_cash'], true);
                                                if ($additionalCashArr && count($additionalCashArr) > 0) {
                                                    foreach ($additionalCashArr as $ac) {
                                                        echo number_format($ac['amount'], 2) 
                                                             . " AZN - " 
                                                             . htmlspecialchars($ac['description']) 
                                                             . "<br>";
                                                    }
                                                } else {
                                                    echo "–";
                                                }
                                            ?>
                                        </td>
                                        <td><?php echo number_format($report['pos_amount'], 2); ?></td>
                                        <td><?php echo number_format($report['total_amount'], 2); ?></td>
                                        <td><?php echo number_format($report['difference'], 2); ?></td>
                                        <td><?php echo number_format($report['vat_included'], 2); ?></td>
                                        <td><?php echo number_format($report['vat_exempt'], 2); ?></td>
                                        <td>
                                            <?php echo $report['is_debt'] ? 'Borc' : '–'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p>Seçilmiş tarix və kassir üçün əməliyyat tapılmadı.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- JavaScript -->
    <script>
        var pendingForm = null; // Hesabat formu göndərilməzdən əvvəl saxlanılacaq

        // Bank qəbzləri və əlavə kassalar ilə bağlı entry-ləri formatlama
        $(document).on('blur', 'input.bank-receipt, input.additional-cash', function() {
            var value = $(this).val().trim();
            if (value !== "") {
                var floatValue = parseFloat(value);
                if (!isNaN(floatValue)) {
                    // Daxil edilən rəqəmi iki onluq dəqiqliklə formatla
                    $(this).val(floatValue.toFixed(2));
                }
            }
        });

        // Kassir seçildikdə müvafiq kassanın avtomatik seçilməsi
        $('#cashier_id').on('change', function() {
            var selectedCashierId = $(this).val();
            
            if (selectedCashierId) {
                // Seçilmiş kassirin data-cash-register atributundan kassa ID-ni əldə edirik
                var cashRegisterId = $(this).find('option:selected').data('cash-register');
                
                if (cashRegisterId) {
                    // Kassa ID-ni seçirik
                    $('#cash_register_id').val(cashRegisterId);
                }
            }
        });

        // Səhifə yüklənəndə də kassirin kassasını yoxlayaq
        $(document).ready(function() {
            // Kassirin kassasını yoxlayaq
            var selectedCashierId = $('#cashier_id').val();
            
            if (selectedCashierId) {
                var cashRegisterId = $('#cashier_id').find('option:selected').data('cash-register');
                
                if (cashRegisterId) {
                    $('#cash_register_id').val(cashRegisterId);
                }
            }
            
            // Şifrə modalındakı göz ikonunu klikləyib input-u görmə/gizlətmə
            $(document).on('click', '.toggle-password', function() {
                var input = $('#modalPassword');
                if (input.attr('type') === 'password') {
                    input.attr('type', 'text');
                    $(this).removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    input.attr('type', 'password');
                    $(this).removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });

            // "Təsdiq et" düyməsi
            $('#passwordSubmit').on('click', function() {
                var enteredPassword = $('#modalPassword').val();
                if (enteredPassword === "4447") {
                    $('#passwordError').hide();
                    var passwordModalEl = document.getElementById('passwordModal');
                    var modalInstance   = bootstrap.Modal.getInstance(passwordModalEl);
                    modalInstance.hide();
                    if (pendingForm) {
                        pendingForm.off('submit').submit();
                    }
                } else {
                    $('#passwordError').show();
                }
            });

            // Modal bağlananda reset edirik
            $('#passwordModal').on('hidden.bs.modal', function () {
                $('#modalPassword').val('');
                $('#passwordError').hide();
                $('.toggle-password').removeClass('fa-eye-slash').addClass('fa-eye');
            });
            
            // Səhifə yükləndikdə tarix modalını göstər
            var monthDateModal = new bootstrap.Modal(document.getElementById('monthDateModal'), {
                backdrop: 'static',
                keyboard: false
            });
            monthDateModal.show();
            
            // Təsdiq düyməsi kliklənəndə
            $('#applySelectedDate').on('click', function() {
                var selectedDate = $('#modalDatePicker').val();
                if(selectedDate) {
                    // Seçilmiş tarixi əsas forma input-una köçür
                    $('#date').val(selectedDate);
                    monthDateModal.hide();
                } else {
                    alert('Zəhmət olmasa tarix seçin!');
                }
            });
            
            // Modaldan birbaşa bu günün tarixini seçmək üçün
            $('#modalDatePicker').on('change', function() {
                // Avtomatik olaraq date seçilməsini yenilə
                $('#date').val($(this).val());
            });
        });

        // Bank qəbzləri üçün Enter düyməsi ilə fokus keçidi
        $(document).on('keydown', 'input.bank-receipt', function(e) {
            if (e.which === 13) {  // Enter düyməsi
                e.preventDefault();
                var inputs = $('#bank-receipts-container').find('input.bank-receipt');
                var currentIndex = inputs.index(this);
                if (currentIndex === inputs.length - 1) {
                    // Sonuncudursa, yeni sətir əlavə et
                    $('#add-bank-receipt').click();
                    $('#bank-receipts-container').find('input.bank-receipt').last().focus();
                } else {
                    inputs.eq(currentIndex + 1).focus();
                }
            }
        });

        // Əlavə kassalar üçün Enter kliki
        $(document).on('keydown', 'input.additional-cash, input[name="additional_cash_descriptions[]"]', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                var container  = $('#additional-cash-container');
                var currentRow = $(this).closest('.form-row');
                var rows       = container.find('.form-row');
                if (currentRow.is(rows.last())) {
                    $('#add-additional-cash').click();
                    container.find('.form-row').last().find('input.additional-cash').focus();
                } else {
                    var nextRow = currentRow.next('.form-row');
                    nextRow.find('input.additional-cash').focus();
                }
            }
        });

        // Vergi Kassası checkbox-a görə bölməni göstər/gizlət
        $('#has_vat').on('change', function() {
            if ($(this).is(':checked')) {
                $('#vat-section').show();
            } else {
                $('#vat-section').hide();
                $('#vat_included').val('');
                $('#vat_exempt').val('');
                $('#vat_total').val('0.00');
            }
        });

        // Yeni Bank Qəbzinin Əlavə Edilməsi
        $('#add-bank-receipt').click(function() {
            $('#bank-receipts-container').append(`
                <div class="input-group mb-2">
                    <input type="number" step="0.01" name="bank_receipts[]" 
                           class="form-control bank-receipt" 
                           placeholder="Məbləğ daxil edin" required>
                    <div class="input-group-append">
                        <span class="input-group-text remove-row" onclick="removeRow(this)">×</span>
                    </div>
                </div>
            `);
            $('#bank-receipts-container .input-group:last-child .bank-receipt').focus();
        });

        // Yeni Əlavə Kassadan Verilən Pulun Əlavə Edilməsi
        $('#add-additional-cash').click(function() {
            $('#additional-cash-container').append(`
                <div class="form-row mb-2">
                    <div class="col">
                        <input type="number" step="0.01" name="additional_cash[]" 
                               class="form-control additional-cash" 
                               placeholder="Məbləğ daxil edin" required>
                    </div>
                    <div class="col">
                        <input type="text" name="additional_cash_descriptions[]" 
                               class="form-control" 
                               placeholder="Açıqlama daxil edin" required>
                    </div>
                    <div class="col-1">
                        <span class="remove-row" onclick="removeRow(this)">×</span>
                    </div>
                </div>
            `);
            $('#additional-cash-container .form-row:last-child .additional-cash').focus();
        });

        // Sətir Silmək
        window.removeRow = function(element) {
            $(element).closest('.input-group, .form-row').remove();
            calculateTotals();
            calculateVATTotal();
        };

        // Ümumi Məbləğ və Fərqin Hesablanması
        function calculateTotals() {
            let bankReceipts = 0;
            $('input[name="bank_receipts[]"]').each(function() {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    bankReceipts += val;
                }
            });

            let cashGiven = parseFloat($('#cash_given').val()) || 0;
            let additionalCash = 0;
            $('input[name="additional_cash[]"]').each(function() {
                let val = parseFloat($(this).val());
                if (!isNaN(val)) {
                    additionalCash += val;
                }
            });

            let totalAmount = bankReceipts + cashGiven + additionalCash;
            $('#total_amount').val(totalAmount.toFixed(2));

            let posAmount   = parseFloat($('#pos_amount').val()) || 0;
            let difference  = totalAmount - posAmount;
            $('#difference').val(difference.toFixed(2));
        }

        // VAT Hesabatının Hesablanması
        function calculateVATTotal() {
            let vatIncluded = parseFloat($('#vat_included').val()) || 0;
            let vatExempt   = parseFloat($('#vat_exempt').val())   || 0;
            let vatTotal    = vatIncluded + vatExempt;
            $('#vat_total').val(vatTotal.toFixed(2));
        }

        // Dəyişiklikləri dinlədikcə hesablamaları yenilə
        $(document).on('input',
            'input[name="bank_receipts[]"], #cash_given, input[name="additional_cash[]"], #pos_amount',
            function() {
                calculateTotals();
            }
        );
        $(document).on('input', '#vat_included, #vat_exempt', function() {
            calculateVATTotal();
        });

        // İlk açılışda hesabla
        calculateTotals();
        calculateVATTotal();

        // Əgər VAT seçilməyibsə, hissəni gizlət
        if (!$('#has_vat').is(':checked')) {
            $('#vat-section').hide();
        }

        // FORMUN GÖNDƏRİLMƏSİNİ İDARƏ ET (AJAX ilə hesabatın olub-olmadığını yoxlayırıq)
        $('form[action="cash_reports.php"]').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            let employee_id = $('#cashier_id').val();
            let date        = $('#date').val();

            if (employee_id && date) {
                $.ajax({
                    url: 'cash_reports.php',
                    type: 'GET',
                    dataType: 'json',
                    data: {
                        check: 1,
                        employee_id: employee_id,
                        date: date
                    },
                    success: function(response) {
                        if (response.exists) {
                            pendingForm = form;
                            var passwordModal = new bootstrap.Modal(document.getElementById('passwordModal'));
                            passwordModal.show();
                        } else {
                            form.off('submit').submit();
                        }
                    },
                    error: function() {
                        form.off('submit').submit();
                    }
                });
            } else {
                form.off('submit').submit();
            }
        });

        // VAT Bölməsini X düyməsi ilə gizlətmək
        window.hideSection = function(sectionId) {
            $('#' + sectionId).hide();
            $('#has_vat').prop('checked', false);
            $('#vat_included').val('');
            $('#vat_exempt').val('');
            $('#vat_total').val('0.00');
        }

        // Kassir və kassa seçimi qarşılıqlı əlaqəli olmalıdır
        document.getElementById('cashier_id').addEventListener('change', function() {
            // Kassir ID-si
            var selectedCashierId = this.value;
            
            if (selectedCashierId) {
                // Seçilmiş kassirə aid kassa ID-ni əldə edirik
                var cashRegisterId = this.options[this.selectedIndex].getAttribute('data-cash-register');
                
                if (cashRegisterId) {
                    // Kassa ID-ni seçirik
                    document.getElementById('cash_register_id').value = cashRegisterId;
                }
            } 
        });
        
        // Səhifə yüklənəndə də seçilmiş kassirin kassasını yükləyək
        window.addEventListener('DOMContentLoaded', function() {
            var cashierId = document.getElementById('cashier_id').value;
            if (cashierId) {
                var select = document.getElementById('cashier_id');
                var option = select.options[select.selectedIndex];
                var cashRegisterId = option.getAttribute('data-cash-register');
                
                if (cashRegisterId) {
                    document.getElementById('cash_register_id').value = cashRegisterId;
                }
            }
        });
    </script>
</body>
</html>
