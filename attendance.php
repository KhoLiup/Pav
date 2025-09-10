<?php
// attendance.php
session_start();
require 'config.php'; // Config faylını daxil edin

// İnkişaf mərhələsində xətaların göstərilməsi (istehsal mühitində deaktiv edin)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// İstifadəçi autentifikasiyasını yoxlayın
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// CSRF token yaradılması və yoxlanılması
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Məlumatları təmizləmək üçün funksiya - helpers.php-də bunu istifadə edirik
// function sanitize($data) {
//     return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
// }

// Həftə günlərini Azərbaycan dilinə tərcümə etmək üçün funksiya
function translateDayName($day_name) {
    $translations = [
        'Monday' => 'Bazar ertəsi',
        'Tuesday' => 'Çərşənbə axşamı',
        'Wednesday' => 'Çərşənbə',
        'Thursday' => 'Cümə axşamı',
        'Friday' => 'Cümə',
        'Saturday' => 'Şənbə',
        'Sunday' => 'Bazar'
    ];
    
    return $translations[$day_name] ?? $day_name;
}

// Müəyyən tarix üçün davamiyyət qeydlərini əldə edən funksiya
function getAttendanceRecords($conn, $date) {
    try {
        $stmt = $conn->prepare("SELECT employee_id, status, reason, appearance_check FROM attendance WHERE date = ?");
        $stmt->execute([$date]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Əgər verilən tarix üçün heç bir qeyd yoxdursa
        if (empty($records)) {
            // Aktiv işçiləri əldə edirik
            $stmt_employees = $conn->query("SELECT id FROM employees WHERE is_active = 1");
            $active_employees = $stmt_employees->fetchAll(PDO::FETCH_COLUMN);
            
            // Hər bir aktiv işçi üçün standart dəyərlər yaradırıq
            foreach ($active_employees as $emp_id) {
                $records[] = [
                    'employee_id' => $emp_id,
                    'status' => 1.0,           // Standart status: işdədir
                    'reason' => null,          // Səbəb yoxdur
                    'appearance_check' => 1    // Üz-baş və geyim forması normal
                ];
            }
        }
        
        return $records;
    } catch (PDOException $e) {
        error_log("Error fetching attendance records: " . $e->getMessage());
        return [];
    }
}

// Davamiyyət qeydlərini işçi ID-sinə görə indeksləyən funksiya
function indexAttendanceRecords($records) {
    $indexed = [];
    foreach ($records as $record) {
        $indexed[$record['employee_id']] = $record;
    }
    return $indexed;
}

// Hesabat üçün davamiyyət məlumatlarını əldə edən funksiya
function getAttendanceReport($conn, $filters = []) {
    try {
        $query = "SELECT a.date, a.status, a.reason, e.name, e.id as employee_id 
                  FROM attendance a 
                  JOIN employees e ON a.employee_id = e.id 
                  WHERE 1=1";
        $params = [];

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $query .= " AND a.date BETWEEN ? AND ?";
            $params[] = $filters['start_date'];
            $params[] = $filters['end_date'];
        }

        if (!empty($filters['employee_id'])) {
            $query .= " AND a.employee_id = ?";
            $params[] = $filters['employee_id'];
        }

        $query .= " ORDER BY a.date DESC, e.name ASC";

        $stmt = $conn->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log("Error generating report: " . $e->getMessage());
        return [];
    }
}

// Hesabat məlumatlarından statistikalar yaradan funksiya
function generateReportStatistics($report_data) {
    $stats = [];
    $detailed_days = [];
    
    foreach ($report_data as $record) {
        $emp_id = $record['employee_id'];
        $date = $record['date'];
        
        if (!isset($stats[$emp_id])) {
            $stats[$emp_id] = [
                'name' => $record['name'],
                'present' => 0,
                'half_day' => 0,
                'absent' => 0,
                'reasons' => [],
                'detailed_days' => [
                    'present' => [],
                    'half_day' => [],
                    'absent' => []
                ]
            ];
        }

        // Statusu normallaşdırmaq
        $status = floatval($record['status']);
        $formatted_date = date('d.m.Y', strtotime($date));
        
        if ($status === 1.0) {
            $stats[$emp_id]['present'] += 1;
            $stats[$emp_id]['detailed_days']['present'][] = [
                'date' => $formatted_date,
                'day_name' => date('l', strtotime($date))
            ];
        } elseif ($status === 0.5) {
            $stats[$emp_id]['half_day'] += 1;
            $stats[$emp_id]['detailed_days']['half_day'][] = [
                'date' => $formatted_date,
                'day_name' => date('l', strtotime($date)),
                'reason' => $record['reason'] ?? ''
            ];
        } elseif ($status === 0.0) {
            $stats[$emp_id]['absent'] += 1;
            $stats[$emp_id]['detailed_days']['absent'][] = [
                'date' => $formatted_date,
                'day_name' => date('l', strtotime($date)),
                'reason' => $record['reason'] ?? ''
            ];
        }

        if (!empty($record['reason'])) {
            if (!isset($stats[$emp_id]['reasons'][$record['reason']])) {
                $stats[$emp_id]['reasons'][$record['reason']] = 0;
            }
            $stats[$emp_id]['reasons'][$record['reason']] += 1;
        }
    }
    return $stats;
}

/**
 * Toplu davamiyyət qeydiyyatı üçün funksiya
 * Bu funksiya bütün işçilər üçün eyni statusu təyin edir
 */
function bulkAttendanceUpdate($conn, $date, $status, $reason = null) {
    try {
        // Aktiv işçiləri əldə edirik
        $stmt_employees = $conn->query("SELECT id FROM employees WHERE is_active = 1");
        $employees = $stmt_employees->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($employees)) {
            return ['success' => false, 'message' => 'Aktiv işçi tapılmadı.'];
        }
        
        $conn->beginTransaction();
        $updated = 0;
        $appearance_fines = 0; // Cərimə sayğacı
        
        foreach ($employees as $employee_id) {
            // Mövcud qeydi yoxlayırıq
            $stmt_check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND date = ?");
            $stmt_check->execute([$employee_id, $date]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            // İşçi işdə deyilsə appearance_check = 1 olaraq təyin etmək
            $appearance_check = ($status === 0.0) ? 1 : 1; // İşdə deyilsə və ya işdədirsə, default olaraq 1 (yəni üz-baş və geyim forması düzgün)
            
            if ($existing) {
                // Mövcud qeydi yeniləyirik
                $stmt_update = $conn->prepare("UPDATE attendance SET status = ?, reason = ?, appearance_check = ? WHERE id = ?");
                $stmt_update->execute([$status, $reason, $appearance_check, $existing['id']]);
            } else {
                // Yeni qeyd əlavə edirik
                $stmt_insert = $conn->prepare("INSERT INTO attendance (employee_id, date, status, reason, appearance_check) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->execute([$employee_id, $date, $status, $reason, $appearance_check]);
            }
            
            $updated++;
            
            // Üz-baş və geyim cəriməsi sadəcə normal davamiyyət qeydində tətbiq olunur, toplu yeniləmə zamanı yox
            // Çünki toplu yeniləmədə appearance_check həmişə 1-dir (yəni forması normaldır)
        }
        
        $conn->commit();
        
        return [
            'success' => true, 
            'message' => "$updated işçi üçün davamiyyət qeydi uğurla yeniləndi.", 
            'updated' => $updated
        ];
    } catch (PDOException $e) {
        $conn->rollBack();
        error_log("Bulk attendance update error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Xəta baş verdi: ' . $e->getMessage()];
    }
}

/**
 * İşçilərə deyil, sahibə WhatsApp bildirişi göndərmək üçün funksiya
 */
function sendAttendanceNotifications($conn, $date, $whatsapp_config) {
    try {
        // O gün üçün işdə olmayan işçiləri əldə edirik
        $stmt = $conn->prepare("
            SELECT a.employee_id, a.status, a.reason, e.name, e.phone_number
            FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE a.date = ? AND a.status < 1
            ORDER BY e.name ASC
        ");
        $stmt->execute([$date]);
        $absent_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($absent_employees)) {
            return ['success' => true, 'message' => 'İşdə olmayan işçi yoxdur, bildiriş göndərilmədi.'];
        }
        
        // İşdə olmayan işçilər haqqında məlumat hazırlayın
        $notification_message = "📊 *Davamiyyət bildirişi (Toplu)*\n\n";
        $notification_message .= "📅 Tarix: *" . date('d.m.Y', strtotime($date)) . "*\n\n";
        $notification_message .= "🔴 *İşdə olmayan işçilər:*\n\n";
        
        foreach ($absent_employees as $employee) {
            $status_text = ($employee['status'] == 0) ? 'İşdə Deyil' : 'Yarım Gün';
            $notification_message .= "👤 *{$employee['name']}*\n";
            $notification_message .= "📝 Status: *{$status_text}*\n";
            if (!empty($employee['reason'])) {
                $notification_message .= "🔍 Səbəb: {$employee['reason']}\n";
            }
            $notification_message .= "\n";
        }
        
        // Cərimə yazılmış işçiləri yığırıq
        $fine_query = $conn->prepare("
            SELECT e.name, d.amount, d.reason 
            FROM debts d
            JOIN employees e ON d.employee_id = e.id
            WHERE d.date = ? AND d.reason LIKE '%Uz-bas ve geyim%'
            ORDER BY e.name ASC
        ");
        $fine_query->execute([$date]);
        $fine_employees = $fine_query->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($fine_employees)) {
            $notification_message .= "\n⚠️ *Cərimə yazılmış işçilər:*\n\n";
            foreach ($fine_employees as $emp) {
                $notification_message .= "👤 *{$emp['name']}* - *{$emp['amount']} AZN*\n";
                $notification_message .= "   Səbəb: {$emp['reason']}\n\n";
            }
        }
        
        // WhatsApp bildirişi göndəririk (sadəcə sahibə)
        $result = sendWhatsAppMessage(
            $whatsapp_config['owner_phone_number'],  // Sahibin nömrəsi
            $notification_message                    // Bildiriş mesajı
        );
        
        if ($result['success']) {
            return [
                'success' => true,
                'message' => "İşdə olmayan və cərimə yazılmış işçilər haqqında sahibə bildiriş göndərildi.",
                'sent' => 1
            ];
        } else {
            error_log("WhatsApp notification failed: " . json_encode($result));
            return [
                'success' => false, 
                'message' => 'Bildiriş göndərilmədi: ' . ($result['error'] ?? 'Bilinməyən xəta')
            ];
        }
    } catch (PDOException $e) {
        error_log("Send attendance notifications error: " . $e->getMessage());
        return ['success' => false, 'message' => 'Xəta baş verdi: ' . $e->getMessage()];
    }
}

// Görünüşü müəyyənləşdirmək
$view = filter_input(INPUT_GET, 'view', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'attendance';

// Hesabatlar üçün CSV ixracını idarə etmək
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export' && $view === 'reports') {
    // Girişləri yoxlamaq və təmizləmək
    $start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $employee_id = filter_input(INPUT_GET, 'employee_id', FILTER_SANITIZE_NUMBER_INT);

    // Tarix formatını yoxlamaq
    $date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($date_pattern, $start_date) || !preg_match($date_pattern, $end_date)) {
        $_SESSION['error_message'] = 'Yanlış tarix formatı üçün ixrac.';
        header("Location: attendance.php?view=reports");
        exit();
    }

    // Hesabat məlumatlarını əldə etmək
    $filters = [];
    if ($start_date && $end_date) {
        $filters['start_date'] = $start_date;
        $filters['end_date'] = $end_date;
    }
    if ($employee_id) {
        $filters['employee_id'] = $employee_id;
    }

    $report_data = getAttendanceReport($conn, $filters);

    // CSV faylı yaratmaq
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=attendance_report.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Employee', 'Status', 'Reason']);

    foreach ($report_data as $record) {
        $status_text = match (floatval($record['status'])) {
            1.0 => 'İşdədir',
            0.5 => 'Yarım Gün',
            0.0 => 'İşdə Deyil',
            default => 'Naməlum',
        };

        fputcsv($output, [
            $record['date'],
            $record['name'],
            $status_text,
            $record['reason'] ?? ''
        ]);
    }

    fclose($output);
    exit();
}

// Davamiyyət qeydiyyatını idarə etmək
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    // CSRF token yoxlanışı
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Təhlükəsizlik xətası! Zəhmət olmasa yenidən cəhd edin.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    // Tarix parametrini almaq və yoxlamaq
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    if (!$date) {
        $_SESSION['error_message'] = 'Tarix parametri tələb olunur.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    // Davamiyyət məlumatlarını almaq
    $attendance_data = isset($_POST['attendance']) ? $_POST['attendance'] : [];
    
    if (empty($attendance_data)) {
        $_SESSION['error_message'] = 'Davamiyyət məlumatları tapılmadı.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    try {
        $conn->beginTransaction();
        $updated = 0;
        $appearance_fines = 0;
        
        foreach ($attendance_data as $employee_id => $data) {
            // Statusu almaq və yoxlamaq
            $status = isset($data['status']) ? filter_var($data['status'], FILTER_VALIDATE_FLOAT) : null;
            if ($status === false || $status === null) {
                continue; // Yanlış status, növbəti işçiyə keçirik
            }
            
            // Səbəbi almaq
            $reason = isset($data['reason']) ? filter_var($data['reason'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : null;
            
            // Üz-baş və geyim forması yoxlamasını almaq
            $appearance_check = isset($data['appearance_check']) ? 1 : 0;
            
            // Mövcud qeydi yoxlayırıq
            $stmt_check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = ? AND date = ?");
            $stmt_check->execute([$employee_id, $date]);
            $existing = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Mövcud qeydi yeniləyirik
                $stmt_update = $conn->prepare("UPDATE attendance SET status = ?, reason = ?, appearance_check = ? WHERE id = ?");
                $stmt_update->execute([$status, $reason, $appearance_check, $existing['id']]);
            } else {
                // Yeni qeyd əlavə edirik
                $stmt_insert = $conn->prepare("INSERT INTO attendance (employee_id, date, status, reason, appearance_check) VALUES (?, ?, ?, ?, ?)");
                $stmt_insert->execute([$employee_id, $date, $status, $reason, $appearance_check]);
            }
            
            $updated++;
            
            // Əgər işçi işdədirsə və üz-baş və geyim forması yerində deyilsə, cərimə tətbiq edirik
            if ($status > 0 && $appearance_check == 0) {
                // İşçiyə eyni gündə, eyni məbləğində (10 AZN) cərimənin yazılıb-yazılmadığını yoxlayırıq
                $check_date = date('Y-m-d', strtotime($date)); 
                $stmt_check_fine = $conn->prepare("
                    SELECT d.id 
                    FROM debts d
                    WHERE d.employee_id = ? 
                    AND d.date = ? 
                    AND d.amount = 10.00
                ");
                $stmt_check_fine->execute([$employee_id, $check_date]);
                $existing_fine = $stmt_check_fine->fetch(PDO::FETCH_ASSOC);
                
                // Əgər bu gün bu işçiyə cərimə yazılmayıbsa, yeni cərimə əlavə edirik
                if (!$existing_fine) {
                    // Cərimə əlavə edirik
                    $fine_amount = 10.00; // 10 AZN cərimə
                    $fine_reason = "Uz-bas ve geyim formasi teleblere uygun deyil (" . date('d.m.Y', strtotime($date)) . ")";
                    
                    // Kodlaşdırma problemi olmaması üçün ASCII simvollardan istifadə edirik
                    $stmt_fine = $conn->prepare("
                        INSERT INTO debts (employee_id, amount, date, reason, is_paid, month) 
                        VALUES (?, ?, ?, ?, 0, ?)
                    ");
                    $month = date('Y-m', strtotime($date));
                    $stmt_fine->execute([$employee_id, $fine_amount, $check_date, $fine_reason, $month]);
                    
                    // Bu bildirişləri burada təkil göndərmirik
                    // Hər cərimə üçün ayrı bildiriş göndərmək əvəzinə, 
                    // sonda hamısı bir mesajda toplanacaq
                    
                    $appearance_fines++;
                }
            }
        }
        
        $conn->commit();
        
        $message = "$updated işçi üçün davamiyyət qeydi uğurla yeniləndi.";
        if ($appearance_fines > 0) {
            $message .= " $appearance_fines işçiyə üz-baş və geyim forması tələblərinə uyğun olmadığı üçün 10 AZN cərimə tətbiq edildi.";
        }
        $_SESSION['success_message'] = $message;
        
        // İşdə olmayan işçilərin siyahısını hazırlayaq
        $absent_employees_list = "";
        $fine_employees_list = "";
        
        // İşdə olmayan və cərimə yazılmış işçiləri yığırıq
        $absent_query = $conn->prepare("
            SELECT e.name, a.status, a.reason 
            FROM attendance a
            JOIN employees e ON a.employee_id = e.id
            WHERE a.date = ? AND a.status < 1
            ORDER BY e.name ASC
        ");
        $absent_query->execute([$date]);
        $absent_employees = $absent_query->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($absent_employees)) {
            $absent_employees_list = "📋 *İşdə olmayan işçilər:*\n\n";
            foreach ($absent_employees as $emp) {
                $status_text = ($emp['status'] == 0) ? 'İşdə Deyil' : 'Yarım Gün';
                $absent_employees_list .= "👤 *{$emp['name']}* - *{$status_text}*\n";
                if (!empty($emp['reason'])) {
                    $absent_employees_list .= "   Səbəb: {$emp['reason']}\n";
                }
            }
        }
        
        // Cərimə yazılmış işçiləri yığırıq
        if ($appearance_fines > 0) {
            $fine_query = $conn->prepare("
                SELECT e.name, d.amount, d.reason 
                FROM debts d
                JOIN employees e ON d.employee_id = e.id
                WHERE d.date = ? AND d.reason LIKE '%Uz-bas ve geyim%'
                ORDER BY e.name ASC
            ");
            $fine_query->execute([$date]);
            $fine_employees = $fine_query->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($fine_employees)) {
                $fine_employees_list = "\n\n⚠️ *Cərimə yazılmış işçilər:*\n\n";
                foreach ($fine_employees as $emp) {
                    $fine_employees_list .= "👤 *{$emp['name']}* - *{$emp['amount']} AZN*\n";
                    $fine_employees_list .= "   Səbəb: {$emp['reason']}\n";
                }
            }
        }
        
        // Davamiyyət qeydi barədə sahibə bildiriş göndərmək
        $notification_message = "📊 *Davamiyyət qeydi yeniləndi*\n\n";
        $notification_message .= "📅 Tarix: *" . date('d.m.Y', strtotime($date)) . "*\n";
        $notification_message .= "✅ Yenilənən qeydlər: *" . $updated . " işçi*\n";
        
        if (!empty($absent_employees_list)) {
            $notification_message .= "\n" . $absent_employees_list;
        }
        
        if (!empty($fine_employees_list)) {
            $notification_message .= $fine_employees_list;
        }
        
        $notification_message .= "\n\n📝 Qeyd edən: *" . ($_SESSION['user_name'] ?? 'Admin') . "*";
        
        // Mesajı sahibə göndər
        sendWhatsAppMessage(
            $whatsapp_config['owner_phone_number'],  // Sahibin nömrəsi
            $notification_message                   // Hazırlanmış mesaj
        );
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = 'Xəta baş verdi: ' . $e->getMessage();
    }
    
    header("Location: attendance.php?view=attendance&date=" . urlencode($date));
    exit();
}

// Toplu davamiyyət yeniləməsini idarə etmək
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_update'])) {
    // CSRF tokenin doğruluğunu yoxlamaq
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = 'Yanlış CSRF token.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    $date = filter_input(INPUT_POST, 'bulk_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $status = filter_input(INPUT_POST, 'bulk_status', FILTER_VALIDATE_FLOAT);
    $reason = filter_input(INPUT_POST, 'bulk_reason', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    
    // Tarixi yoxlamaq
    $date_obj = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date_obj || $date_obj->format('Y-m-d') !== $date) {
        $_SESSION['error_message'] = 'Yanlış tarix formatı.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    // Statusu yoxlamaq
    if (!in_array($status, [0.0, 0.5, 1.0], true)) {
        $_SESSION['error_message'] = 'Yanlış status.';
        header("Location: attendance.php?view=attendance");
        exit();
    }
    
    // Toplu yeniləmə
    $result = bulkAttendanceUpdate($conn, $date, $status, $reason);
    
    if ($result['success']) {
        $_SESSION['success_message'] = $result['message'];
        
        // Bildiriş göndərmək istəyirsə
        if (isset($_POST['send_notifications']) && $_POST['send_notifications'] == 1 && $status < 1) {
            $notification_result = sendAttendanceNotifications($conn, $date, $whatsapp_config);
            if ($notification_result['success']) {
                $_SESSION['success_message'] .= ' ' . $notification_result['message'];
            } else {
                $_SESSION['error_message'] = $notification_result['message'];
            }
        }
    } else {
        $_SESSION['error_message'] = $result['message'];
    }
    
    header("Location: attendance.php?view=attendance&date=" . urlencode($date));
    exit();
}

// Aktiv işçiləri əldə etmək
$employees = getActiveEmployees($conn);

// Görünüş üçün dəyişənləri ilkinləşdirmək
$attendance_records_indexed = [];
$report_stats = [];

// AJAX sorğusu deyilsə, səhifəni render etmək
if (!isset($_POST['ajax'])) {
    if ($view === 'attendance') {
        $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-d');
        $attendance_records = getAttendanceRecords($conn, $date);
        $attendance_records_indexed = indexAttendanceRecords($attendance_records);
    } elseif ($view === 'reports') {
        // Hesabatlar üçün ilkin məlumatları burada əldə etməyə ehtiyac yoxdur, çünki AJAX ilə dinamik olaraq yüklənəcək
    }
}

// AJAX sorğularını emal etmək
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AJAX hesabat sorğusu
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'fetch_reports') {
        // Filtrləri almaq
        $start_date = isset($_POST['start_date']) ? filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
        $end_date = isset($_POST['end_date']) ? filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
        $employee_id = isset($_POST['employee_id']) ? filter_input(INPUT_POST, 'employee_id', FILTER_SANITIZE_NUMBER_INT) : '';
        
        // Tarix formatını yoxlamaq
        $date_pattern = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($date_pattern, $start_date) || !preg_match($date_pattern, $end_date)) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Yanlış tarix formatı'
            ]);
            exit();
        }
        
        // Hesabat məlumatlarını əldə etmək
        $filters = [];
        if (!empty($start_date) && !empty($end_date)) {
            $filters['start_date'] = $start_date;
            $filters['end_date'] = $end_date;
        }
        if (!empty($employee_id)) {
            $filters['employee_id'] = $employee_id;
        }
        
        $report_data = getAttendanceReport($conn, $filters);
        $report_stats = generateReportStatistics($report_data);
        
        $total_present = 0;
        $total_half_day = 0;
        $total_absent = 0;
        
        // Ümumi statistikaları hesablamaq
        foreach ($report_stats as $stat) {
            $total_present += $stat['present'];
            $total_half_day += $stat['half_day'];
            $total_absent += $stat['absent'];
        }
        
        // JSON formatında cavab qaytarmaq
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => [
                'report_stats' => $report_stats,
                'total_present' => $total_present,
                'total_half_day' => $total_half_day,
                'total_absent' => $total_absent
            ]
        ]);
        exit();
    }
    
    // AJAX davamiyyət sorğusu
    if (isset($_POST['ajax']) && $_POST['ajax'] === 'fetch_attendance') {
        $date = isset($_POST['date']) ? filter_input(INPUT_POST, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
        
        // Tarixi yoxlamaq
        $date_obj = DateTime::createFromFormat('Y-m-d', $date);
        if (!$date_obj || $date_obj->format('Y-m-d') !== $date) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Yanlış tarix formatı'
            ]);
            exit();
        }
        
        try {
            // Verilən tarix üçün davamiyyət məlumatlarını əldə etmək
            $stmt = $conn->prepare("SELECT a.employee_id, a.status, a.reason, a.appearance_check 
                                   FROM attendance a 
                                   WHERE a.date = ?");
            $stmt->execute([$date]);
            $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Bütün aktiv işçiləri əldə etmək
            $stmt_employees = $conn->prepare("SELECT id, name FROM employees WHERE is_active = 1 ORDER BY name");
            $stmt_employees->execute();
            $all_employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
            
            // İşçi məlumatlarını ID-yə görə indeksləmək
            $indexed_attendance = [];
            foreach ($attendance_records as $record) {
                $indexed_attendance[$record['employee_id']] = $record;
            }
            
            // Nəticə massivini hazırlamaq
            $attendance_data = [];
            
            // Bütün aktiv işçilər üçün məlumatlar hazırlamaq
            foreach ($all_employees as $employee) {
                $emp_id = $employee['id'];
                
                // Əgər işçi üçün davamiyyət qeydi varsa
                if (isset($indexed_attendance[$emp_id])) {
                    $record = $indexed_attendance[$emp_id];
                    $attendance_data[] = [
                        'id' => $emp_id,
                        'name' => $employee['name'],
                        'status' => isset($record['status']) ? floatval($record['status']) : 1.0,
                        'reason' => isset($record['reason']) ? (string)$record['reason'] : '',
                        'appearance_check' => isset($record['appearance_check']) ? intval($record['appearance_check']) : 1
                    ];
                } else {
                    // Əgər işçi üçün qeyd yoxdursa, standart dəyərlər təyin et
                    $attendance_data[] = [
                        'id' => $emp_id,
                        'name' => $employee['name'],
                        'status' => 1.0, // Standart status: işdədir
                        'reason' => '',  // Səbəb yoxdur
                        'appearance_check' => 1 // Üz-baş və geyim forması normal
                    ];
                }
            }
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'data' => $attendance_data,
                'has_records' => count($attendance_records) > 0,
                'date' => $date
            ]);
            exit();
            
        } catch (PDOException $e) {
            error_log("AJAX attendance error: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Verilənlər bazası xətası: ' . $e->getMessage()
            ]);
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Davamiyyət və Hesabatlar | İşçi İdarəetmə Sistemi</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- jQuery for AJAX və DOM Manipulyasiyası -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Chart.js for Statistikalar -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Özəl CSS -->
    <style>
        :root {
            --primary-color: #4e73df;
            --secondary-color: #6c757d;
            --success-color: #1cc88a;
            --warning-color: #f6c23e;
            --danger-color: #e74a3b;
            --light-color: #f8f9fc;
            --dark-color: #5a5c69;
        }
        
        body {
            background-color: var(--light-color);
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }
        
        .card {
            border: none;
            width: 1200px;
            border-radius: 0.35rem;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        .card-header {
            padding: 1rem 1.35rem;
            margin-bottom: 0;
            background-color: #f8f9fc;
            border-bottom: 1px solid #e3e6f0;
        }
        
        .card-body {
            width: 1000px;
            position: relative;
        }
        
        /* Davamiyyət kartı üçün xüsusi stillər */
        .attendance-card .card-body {
            padding: 1.25rem 0.75rem; /* Yan tərəflərdə daha az padding */
            width: 900px;
        }
        
        .attendance-card .form-control,
        .attendance-card .form-select {
            font-size: 0.9rem; /* Form elementləri üçün daha kiçik font */
        }
        
        .attendance-card .badge {
            font-size: 0.75rem; /* Badge-lər üçün daha kiçik font */
            padding: 0.35em 0.65em;
        }
        
        .attendance-card .form-check-input {
            margin-top: 0.25rem;
        }
        
        .reason-select {
            display: none;
            max-width: 100%; /* Tam genişlik */
            font-size: 0.85rem; /* Daha kiçik font */
        }
        
        /* Səbəb seçimi göstərildikdə */
        .reason-select.show {
            display: block;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .table th, .table td {
            vertical-align: middle;
            text-align: center;
            padding: 0.5rem; /* Daha az padding */
        }
        
        /* Davamiyyət cədvəli üçün əlavə stillər */
        .attendance-table {
            width: 100%;
            table-layout: fixed; /* Sütun genişliklərini sabit saxlamaq üçün */
            margin-bottom: 0; /* Alt boşluğu sıfırlamaq */
        }
        
        .attendance-table-container {
            overflow-x: auto; /* Horizontal scroll əlavə edirik */
            margin-bottom: 10px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
            border-radius: 0.35rem; /* Kənarları yumrulamaq */
            width: 900px;
        }
        
        /* Cədvəl sətirləri üçün hover effekti */
        .attendance-table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        /* Status sütunları üçün xüsusi stillər */
        .attendance-table .status-column {
            width: 15%;
            text-align: center;
        }
        
        /* Səbəb sütunu üçün xüsusi stillər */
        .attendance-table .reason-column {
            width: 20%;
        }
        
        /* Üz-baş və geyim sütunu üçün xüsusi stillər */
        .attendance-table .appearance-column {
            width: 15%;
            text-align: center;
        }
        
        .attendance-table th {
            position: sticky;
            top: 0;
            background-color: #343a40;
            color: white;
            z-index: 10;
        }
        
        .attendance-table .employee-name {
            font-weight: bold;
            text-align: left;
            width: 20%;
        }
        
        /* Mobil cihazlar üçün responsive dizayn */
        @media (max-width: 992px) {
            .attendance-table {
                min-width: 800px; /* Kiçik ekranlarda minimum genişlik */
            }
        }
        
        /* Böyük ekranlar üçün */
        @media (min-width: 993px) {
            .attendance-table {
                min-width: auto; /* Böyük ekranlarda avtomatik genişlik */
            }
            
            .card-body {
                padding: 1.5rem; /* Böyük ekranlarda daha çox padding */
            }
        }
        
        .alert-custom {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 300px;
            box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15);
        }
        
        @media (max-width: 767.98px) {
            .form-group.row {
                flex-direction: column;
                align-items: flex-start;
            }
            .form-group.row .col-md-3,
            .form-group.row .col-md-9 {
                width: 100%;
                margin-bottom: 10px;
            }
            .form-group.row .text-end {
                text-align: left;
            }
        }
        
        .bulk-attendance-card {
            margin-bottom: 20px;
            border-left: 5px solid var(--success-color);
        }
        
        .bulk-attendance-card .card-header {
            background-color: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .btn-success {
            background-color: var(--success-color);
            border-color: var(--success-color);
        }
        
        .btn-warning {
            background-color: var(--warning-color);
            border-color: var(--warning-color);
        }
        
        .btn-danger {
            background-color: var(--danger-color);
            border-color: var(--danger-color);
        }
        
        .bg-primary {
            background-color: var(--primary-color) !important;
        }
        
        .bg-success {
            background-color: var(--success-color) !important;
        }
        
        .bg-warning {
            background-color: var(--warning-color) !important;
        }
        
        .bg-danger {
            background-color: var(--danger-color) !important;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #bac8f3;
            box-shadow: 0 0 0 0.25rem rgba(78, 115, 223, 0.25);
        }
        
        .page-header {
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e3e6f0;
            padding-bottom: 1rem;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        /* Ətraflı günlər məlumatı üçün stillər */
        .detailed-days-row {
            background-color: #f8f9fa;
        }
        
        .detailed-days-row .card {
            background-color: #f8f9fa;
        }
        
        .detailed-days-row .list-group-item {
            padding: 0.5rem 1rem;
            font-size: 0.9rem;
        }
        
        .detailed-days-row h6 {
            font-size: 1rem;
            margin-bottom: 0.75rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
    </style>
    <script>
        // Səbəb seçimini toggl etmək üçün funksiya
        function toggleReasonSelect(employeeId) {
            const status = $('input[name="attendance[' + employeeId + '][status]"]:checked').val();
            const statusValue = parseFloat(status);
            const reasonSelect = $('#reason-' + employeeId);
            
            if (statusValue === 0.0 || statusValue === 0.5) {
                reasonSelect.addClass('show').show();
            } else {
                reasonSelect.removeClass('show').hide();
                reasonSelect.val('');
            }
            
            // İşdə deyilsə üz-baş və geyim checkbox-ını deaktiv etmək, 
            // işdədirsə və ya yarım gündədirsə aktiv etmək
            const appearanceCheck = $('#appearance-' + employeeId);
            if (statusValue === 0.0) {
                appearanceCheck.prop('disabled', true);
                appearanceCheck.prop('checked', true); // İşdə deyilsə avtomatik olaraq checked etmək
            } else {
                appearanceCheck.prop('disabled', false);
            }
        }

        $(document).ready(function(){
            // Bildirişləri avtomatik bağlamaq
            $('.alert').each(function(){
                const alert = $(this);
                setTimeout(function(){
                    alert.alert('close');
                }, 5000);
            });
            
            <?php if ($view === 'attendance'): ?>
                // İşçilər üçün səbəb seçimini toggl etmək
                <?php foreach($employees as $employee): ?>
                    toggleReasonSelect(<?php echo $employee['id']; ?>);
                    $('input[name="attendance[<?php echo $employee['id']; ?>][status]"]').change(function(){
                        toggleReasonSelect(<?php echo $employee['id']; ?>);
                    });
                <?php endforeach; ?>

                // Tarix dəyişdikdə AJAX sorğusu göndərmək
                $('#date').change(function(){
                    const selectedDate = $(this).val();
                    
                    // Yükləndiyini göstərmək
                    $('#attendance-table-body').html('<tr><td colspan="6" class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Məlumatlar yüklənir...</p></td></tr>');
                    
                    $.ajax({
                        url: 'attendance.php',
                        type: 'POST',
                        data: {
                            ajax: 'fetch_attendance',
                            action: 'fetch_attendance',
                            date: selectedDate
                        },
                        dataType: 'json',
                        success: function(response){
                            console.log('AJAX cavabı:', response);
                            
                            if(response.success){
                                const attendanceData = response.data || [];
                                console.log('Davamiyyət məlumatları:', attendanceData);
                                
                                if (attendanceData.length === 0) {
                                    console.log('Seçilən tarix üçün heç bir işçi tapılmadı.');
                                    $('#attendance-table-body').html('<tr><td colspan="6" class="text-center py-4"><div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i> Heç bir aktiv işçi tapılmadı.</div></td></tr>');
                                    return;
                                }
                                
                                // Bütün işçilər üçün davamiyyət cədvəlini təmizləyək və yenidən doldurmaq
                                $('#attendance-table-body').empty();
                                
                                try {
                                    // Hər bir işçi üçün məlumatları göstərmək
                                    attendanceData.forEach(function(emp) {
                                        if (!emp || !emp.id) {
                                            console.error('Yararsız işçi məlumatı:', emp);
                                            return;
                                        }
                                        
                                        const empId = emp.id;
                                        const empName = emp.name;
                                        const empStatus = parseFloat(emp.status);
                                        const empReason = emp.reason || '';
                                        const empAppearance = parseInt(emp.appearance_check) === 1;
                                        
                                        // Statusun müəyyən edilməsi
                                        let statusClass = '';
                                        if (empStatus === 1.0) {
                                            statusClass = 'table-success';
                                        } else if (empStatus === 0.5) {
                                            statusClass = 'table-warning';
                                        } else if (empStatus === 0.0) {
                                            statusClass = 'table-danger';
                                        }
                                        
                                        // İşçi üçün HTML sətirini yaratmaq
                                        const rowHtml = `
                                            <tr class="${statusClass}">
                                                <td class="employee-name">${empName}</td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[${empId}][status]" id="present-${empId}" value="1.0" ${empStatus === 1.0 ? 'checked' : ''}>
                                                        <label class="form-check-label" for="present-${empId}">
                                                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>İşdədir</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[${empId}][status]" id="half-day-${empId}" value="0.5" ${empStatus === 0.5 ? 'checked' : ''}>
                                                        <label class="form-check-label" for="half-day-${empId}">
                                                            <span class="badge bg-warning text-dark"><i class="fas fa-adjust me-1"></i>Yarım Gün</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[${empId}][status]" id="absent-${empId}" value="0.0" ${empStatus === 0.0 ? 'checked' : ''}>
                                                        <label class="form-check-label" for="absent-${empId}">
                                                            <span class="badge bg-danger"><i class="fas fa-times me-1"></i>İşdə Deyil</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="reason-column">
                                                    <select name="attendance[${empId}][reason]" id="reason-${empId}" class="form-select reason-select ${(empStatus === 0.0 || empStatus === 0.5) ? 'show' : ''}">
                                                        <option value="">Səbəb seçin</option>
                                                        <option value="Xəstəlik" ${empReason === 'Xəstəlik' ? 'selected' : ''}>Xəstəlik</option>
                                                        <option value="İcazəli məzuniyyət" ${empReason === 'İcazəli məzuniyyət' ? 'selected' : ''}>İcazəli məzuniyyət</option>
                                                        <option value="Gecikmə" ${empReason === 'Gecikmə' ? 'selected' : ''}>Gecikmə</option>
                                                        <option value="Digər" ${empReason === 'Digər' ? 'selected' : ''}>Digər</option>
                                                    </select>
                                                </td>
                                                <td class="appearance-column">
                                                    <div class="form-check form-switch d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="checkbox" role="switch" 
                                                        name="attendance[${empId}][appearance_check]" 
                                                        id="appearance-${empId}" 
                                                        value="1" 
                                                        ${empAppearance ? 'checked' : ''} 
                                                        ${empStatus === 0.0 ? 'disabled' : ''}>
                                                        <label class="form-check-label" for="appearance-${empId}">
                                                            ${empAppearance ? 
                                                            '<span class="text-success"><i class="fas fa-check-circle"></i></span>' : 
                                                            '<span class="text-danger"><i class="fas fa-times-circle"></i></span>'}
                                                        </label>
                                                    </div>
                                                </td>
                                            </tr>
                                        `;
                                        
                                        // Sətiri əlavə etmək
                                        $('#attendance-table-body').append(rowHtml);
                                        
                                        // Səbəb seçimini düzgün göstərmək
                                        if (empStatus === 0.0 || empStatus === 0.5) {
                                            $(`#reason-${empId}`).show();
                                        } else {
                                            $(`#reason-${empId}`).hide();
                                        }
                                        
                                        // Radio buttonların dəyişmə hadisəsini qeyd etmək
                                        $(`input[name="attendance[${empId}][status]"]`).change(function(){
                                            toggleReasonSelect(empId);
                                            
                                            // Sətir rəngini yeniləmək
                                            const row = $(this).closest('tr');
                                            row.removeClass('table-success table-warning table-danger');
                                            
                                            const newStatus = parseFloat($(this).val());
                                            if (newStatus === 1.0) {
                                                row.addClass('table-success');
                                            } else if (newStatus === 0.5) {
                                                row.addClass('table-warning');
                                            } else if (newStatus === 0.0) {
                                                row.addClass('table-danger');
                                            }
                                        });
                                    });
                                    
                                    // Əgər verilən tarixdə heç bir qeyd yoxdursa, istifadəçiyə bildiriş göstərmək
                                    if (!response.has_records) {
                                        const formattedDate = new Date(response.date).toLocaleDateString('az-AZ');
                                        const infoHtml = `
                                            <div class="alert alert-warning mb-3">
                                                <i class="fas fa-info-circle me-2"></i>
                                                <strong>${formattedDate}</strong> tarixi üçün qeyd edilmiş davamiyyət məlumatları tapılmadı. 
                                                Bütün işçilər üçün standart məlumatlar göstərilir. Dəyişiklikləri saxlamaq üçün "Qeyd Et" düyməsini istifadə edin.
                                            </div>
                                        `;
                                        $('.attendance-table-container').before(infoHtml);
                                    }
                                    
                                    console.log('Davamiyyət məlumatları müvəffəqiyyətlə yeniləndi.');
                                } catch (err) {
                                    console.error('Davamiyyət məlumatlarını emal edərkən xəta baş verdi:', err);
                                    alert('Məlumatları emal edərkən xəta baş verdi: ' + err.message);
                                    $('#attendance-table-body').html('<tr><td colspan="6" class="text-center py-4"><div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i> Məlumatlar emal edilərkən xəta baş verdi: ' + err.message + '</div></td></tr>');
                                }
                                
                            } else {
                                console.error('Server xətası:', response.message || 'Naməlum xəta');
                                alert(response.message || 'Məlumatlar çəkilərkən xəta baş verdi.');
                                // Xəta halında əvvəlki məlumatları göstərmək
                                $('#attendance-table-body').html('<tr><td colspan="6" class="text-center py-4"><div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i> Məlumatlar yüklənərkən xəta baş verdi: ' + 
                                    (response.message || 'Naməlum səbəb') + '</div></td></tr>');
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown){
                            console.error('AJAX Error:', textStatus, errorThrown);
                            console.error('AJAX Response:', jqXHR.responseText);
                            alert('Məlumatlar çəkilərkən xəta baş verdi: ' + textStatus);
                            
                            // Xəta halında yüklənmə göstəricisini təmizləmək
                            $('#attendance-table-body').html('<tr><td colspan="6" class="text-center py-4"><div class="alert alert-danger mb-0"><i class="fas fa-exclamation-circle me-2"></i> Məlumatlar çəkilərkən xəta baş verdi.</div></td></tr>');
                        }
                    });
                });
            <?php endif; ?>

            <?php if ($view === 'reports'): ?>
                // Hesabat formunu AJAX ilə göndərmək
                $('#reports-form').submit(function(e){
                    e.preventDefault();
                    fetchReports();
                });

                // Əgər formda tarixlər əvvəlcədən doldurulubsa, hesabatı yükləmək
                <?php if (isset($_GET['start_date']) && isset($_GET['end_date'])): ?>
                    fetchReports();
                <?php endif; ?>
            <?php endif; ?>
        });

        <?php if ($view === 'reports'): ?>
        // Hesabatları AJAX ilə çəkmək üçün funksiya
        function fetchReports(page = 1) {
            // Form məlumatlarını almaq
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();
            const employeeId = $('#report_employee').val();
            
            // Tarix yoxlamaları
            if (!startDate || !endDate) {
                showAlert('warning', 'Başlanğıc və son tarix seçilməlidir!');
                return;
            }
            
            // Tarixlərin formatını yoxlayaq
            if (!isValidDate(startDate) || !isValidDate(endDate)) {
                showAlert('warning', 'Tarix formatı düzgün deyil! Tarix YYYY-MM-DD formatında olmalıdır.');
                return;
            }
            
            // Başlanğıc tarixinin son tarixdən böyük olmamasını yoxlayaq
            if (new Date(startDate) > new Date(endDate)) {
                showAlert('warning', 'Başlanğıc tarixi son tarixdən böyük ola bilməz!');
                return;
            }
            
            console.log('Hesabat sorğusu göndərilir:', {
                start_date: startDate,
                end_date: endDate,
                employee_id: employeeId
            });

            // Yükləndiyini göstərmək
            $('#reports-content').html('<div class="text-center my-5"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Hesabatlar yüklənir...</p></div>');

            // AJAX sorğusu göndərmək
            $.ajax({
                url: 'attendance.php',
                type: 'POST',
                data: {
                    ajax: 'fetch_reports',
                    action: 'fetch_reports',
                    start_date: startDate,
                    end_date: endDate,
                    employee_id: employeeId,
                    page: page
                },
                dataType: 'json',
                success: function(response){
                    console.log('Hesabat sorğusu cavabı alındı:', response);
                    if(response.success){
                        const data = response.data;
                        
                        // Qrafiklər konteynerini əvvəlcədən yaratmaq
                        let reportsHtml = `
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-chart-pie me-2"></i>Ümumi Statistikalar</h5>
                            <div class="row mb-4">
                                <div class="col-md-4">
                                    <div class="card text-white bg-success mb-3 h-100">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-check-circle me-1"></i> İşdə Günlər</span>
                                            <span class="badge bg-light text-dark">${data.total_present || 0}</span>
                                        </div>
                                        <div class="card-body">
                                            <h5 class="card-title" id="total_present">${data.total_present || 0}</h5>
                                            <p class="card-text">İşçilərin işdə olduğu günlərin ümumi sayı</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-white bg-warning mb-3 h-100">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-adjust me-1"></i> Yarım Günlər</span>
                                            <span class="badge bg-light text-dark">${data.total_half_day || 0}</span>
                                        </div>
                                        <div class="card-body">
                                            <h5 class="card-title" id="total_half_day">${data.total_half_day || 0}</h5>
                                            <p class="card-text">İşçilərin yarım gün işdə olduğu günlərin ümumi sayı</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="card text-white bg-danger mb-3 h-100">
                                        <div class="card-header d-flex justify-content-between align-items-center">
                                            <span><i class="fas fa-times-circle me-1"></i> İşdə Deyil Günlər</span>
                                            <span class="badge bg-light text-dark">${data.total_absent || 0}</span>
                                        </div>
                                        <div class="card-body">
                                            <h5 class="card-title" id="total_absent">${data.total_absent || 0}</h5>
                                            <p class="card-text">İşçilərin işdə olmadığı günlərin ümumi sayı</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Hesabat cədvəlini əlavə etmək
                        reportsHtml += `
                            <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-table me-2"></i>Detallı Hesabat</h5>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover align-middle">
                                    <thead class="table-primary">
                                        <tr>
                                            <th>İşçi</th>
                                            <th>İşdə Günlər</th>
                                            <th>Yarım Günlər</th>
                                            <th>İşdə Deyil Günlər</th>
                                            <th>Səbəblər</th>
                                        </tr>
                                    </thead>
                                    <tbody id="reports-table-body">
                                        <!-- Məlumatlar JS ilə doldurulacaq -->
                                    </tbody>
                                </table>
                            </div>
                        `;
                        
                        // Qrafiklər konteynerini əlavə etmək
                        reportsHtml += `
                            <h5 class="mb-3 mt-4 border-bottom pb-2"><i class="fas fa-chart-bar me-2"></i>Qrafik Statistikalar</h5>
                            <div id="chartsContainer">
                                <div class="row">
                                    <div class="col-md-6 mb-4">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0 text-primary"><i class="fas fa-check-circle me-1"></i> İşdə Günlər</h6>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="presentChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0 text-warning"><i class="fas fa-adjust me-1"></i> Yarım Günlər</h6>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="halfDayChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 mb-4">
                                        <div class="card shadow-sm">
                                            <div class="card-header bg-light">
                                                <h6 class="mb-0 text-danger"><i class="fas fa-times-circle me-1"></i> İşdə Deyil Günlər</h6>
                                            </div>
                                            <div class="card-body">
                                                <canvas id="absentChart"></canvas>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                        
                        // Hesabat məzmununu yeniləyək
                        if (Object.keys(data.report_stats).length > 0) {
                            $('#reports-content').html(reportsHtml);
                            
                            // İndi məlumatları dolduraq
                            updateReportsTable(data.report_stats);
                            
                            // Chart.js kitabxanasının yükləndiyini yoxla
                            if (typeof Chart === 'undefined') {
                                console.error('Chart.js yüklənməyib');
                                setTimeout(function() {
                                    if (typeof Chart === 'undefined') {
                                        $('#chartsContainer').html('<div class="alert alert-danger">Chart.js kitabxanası yüklənmədi. Səhifəni yeniləməyi sınayın.</div>');
                                    } else {
                                        updateChartsWithDelay(data.report_stats, 100);
                                    }
                                }, 1000);
                            } else {
                                // Sonra qrafikləri yaradaq
                                updateChartsWithDelay(data.report_stats, 800);
                            }
                        } else {
                            $('#reports-content').html('<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i> Seçilmiş tarix aralığında heç bir davamiyyət qeydi tapılmadı.</div>');
                        }
                    } else {
                        showAlert('danger', response.message || 'Hesabatlar çəkilərkən xəta baş verdi.');
                        $('#reports-content').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> Hesabatlar yüklənərkən xəta baş verdi: ' + (response.message || 'Bilinməyən xəta') + '</div>');
                    }
                },
                error: function(jqXHR, textStatus, errorThrown){
                    console.error('AJAX Error:', textStatus, errorThrown);
                    console.error('Response:', jqXHR.responseText);
                    showAlert('danger', 'Hesabatlar çəkilərkən xəta baş verdi: ' + textStatus);
                    $('#reports-content').html('<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i> Hesabatlar yüklənərkən xəta baş verdi. Zəhmət olmasa səhifəni yeniləyin və ya sistem administratoru ilə əlaqə saxlayın.</div>');
                }
            });
        }

        // Bildiriş göstərmək üçün funksiya
        function showAlert(type, message) {
            const alertHtml = `
                <div class="alert alert-${type} alert-dismissible fade show alert-custom" role="alert">
                    <i class="fas fa-${type === 'danger' ? 'exclamation-circle' : type === 'warning' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Bağla"></button>
                </div>
            `;
            
            // Əvvəlki bildirişləri silmək
            $('.alert-custom').remove();
            
            // Yeni bildiriş əlavə etmək
            $('body').append(alertHtml);
            
            // 5 saniyə sonra bildirişi bağlamaq
            setTimeout(function() {
                $('.alert-custom').alert('close');
            }, 5000);
        }

        // Tarix formatını yoxlamaq üçün funksiya
        function isValidDate(dateString) {
            const regEx = /^\d{4}-\d{2}-\d{2}$/;
            if(!dateString.match(regEx)) return false;  // Düzgün format deyil
            const d = new Date(dateString);
            const dNum = d.getTime();
            if(!dNum && dNum !== 0) return false; // NaN dəyəri
            return d.toISOString().slice(0,10) === dateString;
        }

        // Hesabat cədvəlini yeniləmək üçün funksiya
        function updateReportsTable(reportStats) {
            const tbody = $('#reports-table-body');
            tbody.empty();

            if (reportStats && Object.keys(reportStats).length > 0) {
                $.each(reportStats, function(emp_id, stats){
                    if (!stats || typeof stats !== 'object') return;
                    
                    const reasons = [];
                    if (stats.reasons && typeof stats.reasons === 'object') {
                        $.each(stats.reasons, function(reason, count){
                            reasons.push(`<span class="badge bg-info text-dark">${reason}: ${count}</span>`);
                        });
                    }
                    const reasonText = reasons.join(' ');
                    
                    // İşdə olmadığı günlərin siyahısı
                    let absentDaysHtml = '';
                    if (stats.detailed_days && stats.detailed_days.absent && stats.detailed_days.absent.length > 0) {
                        absentDaysHtml = `
                            <button class="btn btn-link btn-sm text-danger" type="button" data-bs-toggle="collapse" data-bs-target="#absent-days-${emp_id}" aria-expanded="false">
                                <i class="fas fa-info-circle"></i> Göstər
                            </button>
                            <div class="collapse mt-2" id="absent-days-${emp_id}">
                                <div class="card card-body bg-light py-1 small">
                        `;
                        stats.detailed_days.absent.forEach(function(day, index) {
                            absentDaysHtml += `
                                <div class="d-flex justify-content-between align-items-center border-bottom ${index > 0 ? 'mt-1' : ''} pb-1">
                                    <span>
                                        <i class="fas fa-calendar-day text-danger me-1"></i> 
                                        ${day.date} 
                                        (${translateDayName(day.day_name)})
                                    </span>
                                    ${day.reason ? `<span class="badge bg-secondary">${day.reason}</span>` : ''}
                                </div>
                            `;
                        });
                        absentDaysHtml += `
                                </div>
                            </div>
                        `;
                    }

                    const row = `<tr>
                        <td class="fw-bold">${stats.name || 'Naməlum'}</td>
                        <td><span class="badge bg-success">${parseInt(stats.present || 0)}</span></td>
                        <td><span class="badge bg-warning text-dark">${parseInt(stats.half_day || 0)}</span></td>
                        <td>
                            <span class="badge bg-danger">${parseInt(stats.absent || 0)}</span>
                            ${parseInt(stats.absent || 0) > 0 ? absentDaysHtml : ''}
                        </td>
                        <td>${reasonText}</td>
                    </tr>`;
                    tbody.append(row);
                });
            } else {
                tbody.append(`<tr><td colspan="5" class="text-center py-4">
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i> Seçilmiş tarix aralığında heç bir davamiyyət qeydi tapılmadı.
                    </div>
                </td></tr>`);
            }
        }
        
        // Ümumi statistikaları yeniləmək üçün funksiya
        function updateStatistics(present, half_day, absent) {
            $('#total_present').text(present || 0);
            $('#total_half_day').text(half_day || 0);
            $('#total_absent').text(absent || 0);
        }

        // Qrafikləri gecikmə ilə yükləmək üçün funksiya
        function updateChartsWithDelay(reportStats, delay) {
            setTimeout(function() {
                try {
                    updateCharts(reportStats);
                } catch (e) {
                    console.error('Qrafikləri yenilərkən xəta:', e);
                    $('#chartsContainer').html(`<div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> Qrafikləri yaradarkən xəta baş verdi: ${e.message}
                    </div>`);
                }
            }, delay || 800);
        }

        // Qrafikləri yeniləmək üçün funksiya
        function updateCharts(reportStats) {
            try {
                // Qrafiklər üçün məlumat hazırlamaq
                const labels = [];
                const presentData = [];
                const halfDayData = [];
                const absentData = [];
                const colors = {
                    present: {
                        background: 'rgba(40, 167, 69, 0.6)',
                        border: 'rgba(40, 167, 69, 1)'
                    },
                    halfDay: {
                        background: 'rgba(255, 193, 7, 0.6)',
                        border: 'rgba(255, 193, 7, 1)'
                    },
                    absent: {
                        background: 'rgba(220, 53, 69, 0.6)',
                        border: 'rgba(220, 53, 69, 1)'
                    }
                };

                if (!reportStats || Object.keys(reportStats).length === 0) {
                    // Məlumat yoxdursa, qrafikləri göstərmə
                    console.log('Qrafiklər üçün məlumat yoxdur');
                    $('#chartsContainer').hide();
                    return;
                }

                // Məlumatları hazırla
                $.each(reportStats, function(emp_id, stats){
                    if (!stats || typeof stats !== 'object') return;
                    
                    labels.push(stats.name || 'Naməlum');
                    presentData.push(parseFloat(stats.present || 0));
                    halfDayData.push(parseFloat(stats.half_day || 0));
                    absentData.push(parseFloat(stats.absent || 0));
                });

                // Canvas elementlərinin varlığını yoxla
                if (!document.getElementById('presentChart') || 
                    !document.getElementById('halfDayChart') || 
                    !document.getElementById('absentChart')) {
                    console.error('Canvas elementləri tapılmadı!');
                    $('#chartsContainer').html(`<div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i> Qrafik elementləri tapılmadı.
                    </div>`);
                    return;
                }

                // Qrafikləri göstərmək
                $('#chartsContainer').show();

                // Mövcud qrafikləri təmizləmək
                destroyChart('presentChart');
                destroyChart('halfDayChart');
                destroyChart('absentChart');

                // İşdə Günlər Qrafiki
                createChart('presentChart', 'İşdə Günlər', labels, presentData, colors.present);
                
                // Yarım Günlər Qrafiki
                createChart('halfDayChart', 'Yarım Günlər', labels, halfDayData, colors.halfDay);
                
                // İşdə Deyil Günlər Qrafiki
                createChart('absentChart', 'İşdə Deyil Günlər', labels, absentData, colors.absent);

            } catch (e) {
                console.error('Qrafik yenilənməsi zamanı ümumi xəta:', e);
                $('#chartsContainer').html(`<div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i> Qrafik yenilənməsi zamanı xəta baş verdi: ${e.message}
                </div>`);
            }
        }
        
        // Qrafik yaratmaq üçün funksiya
        function createChart(canvasId, title, labels, data, colors) {
            try {
                const ctx = document.getElementById(canvasId);
                if (!ctx) {
                    console.error(`${canvasId} elementi tapılmadı`);
                    return;
                }
                
                const ctx2D = ctx.getContext('2d');
                if (!ctx2D) {
                    console.error(`${canvasId} canvas context alına bilmədi`);
                    return;
                }
                
                if (typeof Chart !== 'function') {
                    console.error('Chart.js düzgün yüklənməyib');
                    return;
                }
                
                window[canvasId] = new Chart(ctx2D, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: title,
                            data: data,
                            backgroundColor: colors.background,
                            borderColor: colors.border,
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        plugins: {
                            legend: { display: false },
                            title: { display: true, text: title }
                        },
                        scales: {
                            y: { beginAtZero: true }
                        }
                    }
                });
                
                console.log(`${title} qrafiki uğurla yaradıldı`);
            } catch (e) {
                console.error(`${title} qrafiki yaradılarkən xəta baş verdi:`, e);
                $(`#${canvasId}`).parent().html(`<div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i> Qrafik yaradılarkən xəta: ${e.message}
                </div>`);
            }
        }
        
        // Qrafiki təmizləmək üçün funksiya
        function destroyChart(chartId) {
            if (window[chartId]) {
                try {
                    if (typeof window[chartId].destroy === 'function') {
                        window[chartId].destroy();
                    } else {
                        console.warn(`${chartId}.destroy is not a function, setting it to null`);
                        window[chartId] = null;
                    }
                } catch (e) {
                    console.error(`${chartId} destroy xətası:`, e);
                    window[chartId] = null;
                }
            }
        }

        // TranslateDayName JS funksiyası
        function translateDayName(dayName) {
            const translations = {
                'Monday': 'Bazar ertəsi',
                'Tuesday': 'Çərşənbə axşamı',
                'Wednesday': 'Çərşənbə',
                'Thursday': 'Cümə axşamı',
                'Friday': 'Cümə',
                'Saturday': 'Şənbə',
                'Sunday': 'Bazar'
            };
            
            return translations[dayName] || dayName;
        }
        <?php endif; ?>
    </script>
</head>
<body>
    <!-- Navbar -->
    <?php include 'includes/header.php'; ?>

    <div class="container mt-4">
        <div class="page-header d-flex justify-content-between align-items-center">
            <h2><i class="fas fa-clipboard-check me-2"></i>Davamiyyət və Hesabatlar</h2>
            <div class="btn-group">
                <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-home me-1"></i> Ana Səhifə</a>
                <a href="employees.php" class="btn btn-outline-primary"><i class="fas fa-users me-1"></i> İşçilər</a>
            </div>
        </div>

        <!-- Uğur Mesajı -->
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show alert-custom" role="alert">
                <i class="fas fa-check-circle me-2"></i><?php echo sanitize($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Bağla"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Xəta Mesajı -->
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show alert-custom" role="alert">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo sanitize($_SESSION['error_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Bağla"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Görünüş Seçimi -->
        <ul class="nav nav-tabs mb-4">
            <li class="nav-item">
                <a class="nav-link <?php echo ($view === 'attendance') ? 'active' : ''; ?>" href="attendance.php?view=attendance">
                    <i class="fas fa-calendar-check me-1"></i> Davamiyyət
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo ($view === 'reports') ? 'active' : ''; ?>" href="attendance.php?view=reports">
                    <i class="fas fa-chart-bar me-1"></i> Hesabatlar
                </a>
            </li>
        </ul>

        <?php if ($view === 'attendance'): ?>
            <?php
                // Tarixi düzgün təyin etmək
                $date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: date('Y-m-d');
            ?>
            <!-- Davamiyyət Qeyd Etmə Formu -->
            <div class="card mb-4 shadow-sm attendance-card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-calendar-check me-2"></i> Davamiyyət Qeyd Et</h5>
                    <div class="text-end">
                        <span class="badge bg-light text-dark"><?php echo date('d.m.Y', strtotime($date)); ?></span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" action="attendance.php?view=attendance">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($_SESSION['csrf_token']); ?>">
                        <div class="row mb-3 align-items-center">
                            <div class="col-md-4">
                                <label for="date" class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> Tarix:</label>
                                <input type="date" id="date" name="date" class="form-control form-control-lg" value="<?php echo sanitize($date); ?>" required>
                            </div>
                            <div class="col-md-8 text-end">
                                <button type="submit" name="save_attendance" class="btn btn-success btn-lg">
                                    <i class="fas fa-save me-1"></i> Qeyd Et
                                </button>
                            </div>
                        </div>
                        
                        <!-- Davamiyyət Cədvəli -->
                        <div class="attendance-table-container">
                            <table class="table table-bordered table-hover align-middle attendance-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th class="employee-name">İşçi</th>
                                        <th class="status-column">İşdədir</th>
                                        <th class="status-column">Yarım Gün</th>
                                        <th class="status-column">İşdə Deyil</th>
                                        <th class="reason-column">Səbəb</th>
                                        <th class="appearance-column">Üz-baş və geyim</th>
                                    </tr>
                                </thead>
                                <tbody id="attendance-table-body">
                                    <?php if (count($employees) > 0): ?>
                                        <?php foreach ($employees as $employee): 
                                            $emp_id = $employee['id'];
                                            $emp_name = $employee['name'] ?? '';
                                            // Mövcud davamiyyət qeydi varsa, onu əldə etmək
                                            if(isset($attendance_records_indexed[$emp_id])){
                                                $current_status = floatval($attendance_records_indexed[$emp_id]['status']);
                                                $current_reason = sanitize($attendance_records_indexed[$emp_id]['reason'] ?? '');
                                                $appearance_check = isset($attendance_records_indexed[$emp_id]['appearance_check']) ? 
                                                    $attendance_records_indexed[$emp_id]['appearance_check'] : 1;
                                            } else {
                                                $current_status = 1.0; // Default olaraq işdə
                                                $current_reason = '';
                                                $appearance_check = 1; // Default olaraq üz-baş və geyim forması yerindədir
                                            }
                                            
                                            // Status rəngini təyin etmək
                                            $status_class = '';
                                            if($current_status === 1.0) {
                                                $status_class = 'table-success';
                                            } elseif($current_status === 0.5) {
                                                $status_class = 'table-warning';
                                            } elseif($current_status === 0.0) {
                                                $status_class = 'table-danger';
                                            }
                                        ?>
                                            <tr class="<?php echo $status_class; ?>">
                                                <td class="employee-name"><?php echo sanitize($emp_name); ?></td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[<?php echo $emp_id; ?>][status]" id="present-<?php echo $emp_id; ?>" value="1.0" <?php if($current_status === 1.0) echo 'checked'; ?>>
                                                        <label class="form-check-label" for="present-<?php echo $emp_id; ?>">
                                                            <span class="badge bg-success"><i class="fas fa-check me-1"></i>İşdədir</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[<?php echo $emp_id; ?>][status]" id="half-day-<?php echo $emp_id; ?>" value="0.5" <?php if($current_status === 0.5) echo 'checked'; ?>>
                                                        <label class="form-check-label" for="half-day-<?php echo $emp_id; ?>">
                                                            <span class="badge bg-warning text-dark"><i class="fas fa-adjust me-1"></i>Yarım Gün</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="status-column">
                                                    <div class="form-check d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="radio" name="attendance[<?php echo $emp_id; ?>][status]" id="absent-<?php echo $emp_id; ?>" value="0.0" <?php if($current_status === 0.0) echo 'checked'; ?>>
                                                        <label class="form-check-label" for="absent-<?php echo $emp_id; ?>">
                                                            <span class="badge bg-danger"><i class="fas fa-times me-1"></i>İşdə Deyil</span>
                                                        </label>
                                                    </div>
                                                </td>
                                                <td class="reason-column">
                                                    <select name="attendance[<?php echo $emp_id; ?>][reason]" id="reason-<?php echo $emp_id; ?>" class="form-select reason-select">
                                                        <option value="">Səbəb seçin</option>
                                                        <option value="Xəstəlik" <?php if($current_reason === 'Xəstəlik') echo 'selected'; ?>>Xəstəlik</option>
                                                        <option value="İcazəli məzuniyyət" <?php if($current_reason === 'İcazəli məzuniyyət') echo 'selected'; ?>>İcazəli məzuniyyət</option>
                                                        <option value="Gecikmə" <?php if($current_reason === 'Gecikmə') echo 'selected'; ?>>Gecikmə</option>
                                                        <option value="Digər" <?php if($current_reason === 'Digər') echo 'selected'; ?>>Digər</option>
                                                    </select>
                                                </td>
                                                <td class="appearance-column">
                                                    <div class="form-check form-switch d-flex justify-content-center">
                                                        <input class="form-check-input me-2" type="checkbox" role="switch" 
                                                        name="attendance[<?php echo $emp_id; ?>][appearance_check]" 
                                                        id="appearance-<?php echo $emp_id; ?>" 
                                                        value="1" 
                                                        <?php if($appearance_check == 1) echo 'checked'; ?> 
                                                        <?php if($current_status === 0.0) echo 'disabled'; ?>>
                                                        <label class="form-check-label" for="appearance-<?php echo $emp_id; ?>">
                                                            <?php if($appearance_check == 1): ?>
                                                                <span class="text-success"><i class="fas fa-check-circle"></i></span>
                                                            <?php else: ?>
                                                                <span class="text-danger"><i class="fas fa-times-circle"></i></span>
                                                            <?php endif; ?>
                                                        </label>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <div class="alert alert-info mb-0">
                                                    <i class="fas fa-info-circle me-2"></i> Aktiv işçi yoxdur.
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Toplu Davamiyyət Qeyd Etmə Formu -->
            <div class="card mb-4 shadow-sm bulk-attendance-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-users me-2"></i> Toplu Davamiyyət Qeyd Et</h5>
                    <span class="badge bg-success">Bütün işçilər üçün</span>
                </div>
                <div class="card-body">
                    <form method="POST" action="attendance.php?view=attendance">
                        <input type="hidden" name="csrf_token" value="<?php echo sanitize($_SESSION['csrf_token']); ?>">
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="bulk_date" class="form-label fw-bold"><i class="fas fa-calendar-alt me-1"></i> Tarix:</label>
                                <input type="date" id="bulk_date" name="bulk_date" class="form-control" value="<?php echo sanitize($date); ?>" required>
                                <div class="form-text">Bütün işçilər üçün davamiyyət qeyd ediləcək tarix</div>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="bulk_status" class="form-label fw-bold"><i class="fas fa-user-clock me-1"></i> Status:</label>
                                <select id="bulk_status" name="bulk_status" class="form-select" required>
                                    <option value="1.0">İşdədir</option>
                                    <option value="0.5">Yarım Gün</option>
                                    <option value="0.0">İşdə Deyil</option>
                                </select>
                                <div class="form-text">Bütün işçilər üçün tətbiq ediləcək davamiyyət statusu</div>
                            </div>
                            <div class="col-md-4 mb-3 mb-md-0">
                                <label for="bulk_reason" class="form-label fw-bold"><i class="fas fa-comment-alt me-1"></i> Səbəb:</label>
                                <select id="bulk_reason" name="bulk_reason" class="form-select">
                                    <option value="">Seçin</option>
                                    <option value="Xəstəlik">Xəstəlik</option>
                                    <option value="İcazə">İcazə</option>
                                    <option value="Məzuniyyət">Məzuniyyət</option>
                                    <option value="Bayram">Bayram</option>
                                    <option value="İstirahət">İstirahət</option>
                                    <option value="Digər">Digər</option>
                                </select>
                                <div class="form-text">İşdə olmama səbəbi (işdə deyil və ya yarım gün seçildikdə)</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="send_notifications" name="send_notifications" value="1">
                                    <label class="form-check-label" for="send_notifications">
                                        <i class="fab fa-whatsapp text-success me-1"></i> İşdə olmayan işçilər haqqında sahibə bildiriş göndər
                                    </label>
                                    <div class="form-text">İşdə olmayan işçilərin siyahısı və səbəbləri sahibə göndərilir</div>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-12 text-end">
                                <button type="submit" name="bulk_update" class="btn btn-success">
                                    <i class="fas fa-users-cog me-1"></i> Bütün İşçilər Üçün Tətbiq Et
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            
            <script>
                // Toplu davamiyyət formu üçün JavaScript
                $(document).ready(function() {
                    // Status dəyişdikdə səbəb sahəsini göstər/gizlət
                    $('#bulk_status').on('change', function() {
                        var status = parseFloat($(this).val());
                        if (status < 1.0) {
                            $('#bulk_reason').parent().show();
                        } else {
                            $('#bulk_reason').parent().hide();
                            $('#bulk_reason').val('');
                        }
                    });
                    
                    // Səhifə yüklənəndə statusu yoxla
                    var initialStatus = parseFloat($('#bulk_status').val());
                    if (initialStatus < 1.0) {
                        $('#bulk_reason').parent().show();
                    } else {
                        $('#bulk_reason').parent().hide();
                    }
                });
            </script>
        <?php elseif ($view === 'reports'): ?>
            <?php
                // Tarixləri düzgün təyin etmək
                $start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
                $report_employee_id = filter_input(INPUT_GET, 'employee_id', FILTER_SANITIZE_NUMBER_INT);

                // Hesabat məlumatlarını əldə etmək
                $filters = [];
                if (!empty($start_date) && !empty($end_date)) {
                    $filters['start_date'] = $start_date;
                    $filters['end_date'] = $end_date;
                }
                if (!empty($report_employee_id)) {
                    $filters['employee_id'] = $report_employee_id;
                }
                $report_data = getAttendanceReport($conn, $filters);
                $report_stats = generateReportStatistics($report_data);
            ?>
            <!-- Hesabatlar Bölməsi -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-chart-line me-2"></i> Davamiyyət Hesabatları</h5>
                    <div>
                        <?php if (!empty($start_date) && !empty($end_date)): ?>
                            <span class="badge bg-light text-dark me-2">
                                <i class="fas fa-calendar-alt me-1"></i> 
                                <?php echo date('d.m.Y', strtotime($start_date)); ?> - 
                                <?php echo date('d.m.Y', strtotime($end_date)); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form method="GET" action="attendance.php" class="row g-3 mb-4 p-3 bg-light rounded" id="reports-form">
                        <input type="hidden" name="view" value="reports">
                        <div class="col-md-3">
                            <label for="start_date" class="form-label fw-bold"><i class="fas fa-calendar-minus me-1"></i> Başlanğıc Tarix:</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo sanitize($start_date); ?>" required>
                        </div>
                        <div class="col-md-3">
                            <label for="end_date" class="form-label fw-bold"><i class="fas fa-calendar-plus me-1"></i> Son Tarix:</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo sanitize($end_date); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label for="report_employee" class="form-label fw-bold"><i class="fas fa-user me-1"></i> İşçi:</label>
                            <select id="report_employee" name="employee_id" class="form-select">
                                <option value="">Bütün işçilər</option>
                                <?php
                                    foreach ($employees as $emp) {
                                        $selected = ($report_employee_id == $emp['id']) ? 'selected' : '';
                                        echo "<option value=\"{$emp['id']}\" {$selected}>{$emp['name']}</option>";
                                    }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search me-1"></i> Hesabatı Göstər
                            </button>
                        </div>
                    </form>

                    <?php if (!empty($start_date) && !empty($end_date)): ?>
                        <div class="d-flex justify-content-end mb-3">
                            <a href="attendance.php?view=reports&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&employee_id=<?php echo urlencode($report_employee_id); ?>&action=export" class="btn btn-success" onclick="return confirm('CSV-ə ixrac etmək istədiyinizə əminsiniz?');">
                                <i class="fas fa-file-csv me-1"></i> CSV-ə İxrac Et
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Hesabat Məlumatları Konteyner -->
                    <div id="reports-content">
                        <?php if (!empty($start_date) && !empty($end_date)): ?>
                            <!-- Ümumi Statistikalar -->
                            <?php if (count($report_stats) > 0): ?>
                                <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-chart-pie me-2"></i>Ümumi Statistikalar</h5>
                                <div class="row mb-4">
                                    <div class="col-md-4">
                                        <div class="card text-white bg-success mb-3 h-100">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-check-circle me-1"></i> İşdə Günlər</span>
                                                <span class="badge bg-light text-dark"><?php echo array_sum(array_column($report_stats, 'present')); ?></span>
                                            </div>
                                            <div class="card-body">
                                                <h5 class="card-title" id="total_present"><?php echo array_sum(array_column($report_stats, 'present')); ?></h5>
                                                <p class="card-text">İşçilərin işdə olduğu günlərin ümumi sayı</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card text-white bg-warning mb-3 h-100">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-adjust me-1"></i> Yarım Günlər</span>
                                                <span class="badge bg-light text-dark"><?php echo array_sum(array_column($report_stats, 'half_day')); ?></span>
                                            </div>
                                            <div class="card-body">
                                                <h5 class="card-title" id="total_half_day"><?php echo array_sum(array_column($report_stats, 'half_day')); ?></h5>
                                                <p class="card-text">İşçilərin yarım gün işdə olduğu günlərin ümumi sayı</p>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="card text-white bg-danger mb-3 h-100">
                                            <div class="card-header d-flex justify-content-between align-items-center">
                                                <span><i class="fas fa-times-circle me-1"></i> İşdə Deyil Günlər</span>
                                                <span class="badge bg-light text-dark"><?php echo array_sum(array_column($report_stats, 'absent')); ?></span>
                                            </div>
                                            <div class="card-body">
                                                <h5 class="card-title" id="total_absent"><?php echo array_sum(array_column($report_stats, 'absent')); ?></h5>
                                                <p class="card-text">İşçilərin işdə olmadığı günlərin ümumi sayı</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Hesabat Cədvəli -->
                                <h5 class="mb-3 border-bottom pb-2"><i class="fas fa-table me-2"></i>Detallı Hesabat</h5>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover align-middle">
                                        <thead class="table-primary">
                                            <tr>
                                                <th>İşçi</th>
                                                <th>İşdə Günlər</th>
                                                <th>Yarım Günlər</th>
                                                <th>İşdə Deyil Günlər</th>
                                                <th>Səbəblər</th>
                                            </tr>
                                        </thead>
                                        <tbody id="reports-table-body">
                                            <?php foreach ($report_stats as $emp_id => $stats): ?>
                                                <tr>
                                                    <td class="fw-bold"><?php echo sanitize($stats['name']); ?></td>
                                                    <td>
                                                        <span class="badge bg-success"><?php echo intval($stats['present']); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning text-dark"><?php echo intval($stats['half_day']); ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-danger"><?php echo intval($stats['absent']); ?></span>
                                                        <?php if (intval($stats['absent']) > 0): ?>
                                                            <button class="btn btn-link btn-sm text-danger" type="button" data-bs-toggle="collapse" data-bs-target="#absent-days-<?php echo $emp_id; ?>" aria-expanded="false">
                                                                <i class="fas fa-info-circle"></i> Göstər
                                                            </button>
                                                            <div class="collapse mt-2" id="absent-days-<?php echo $emp_id; ?>">
                                                                <div class="card card-body bg-light py-1 small">
                                                                    <?php foreach ($stats['detailed_days']['absent'] as $index => $day): ?>
                                                                        <div class="d-flex justify-content-between align-items-center border-bottom <?php echo ($index > 0) ? 'mt-1' : ''; ?> pb-1">
                                                                            <span>
                                                                                <i class="fas fa-calendar-day text-danger me-1"></i> 
                                                                                <?php echo $day['date']; ?> 
                                                                                (<?php echo translateDayName($day['day_name']); ?>)
                                                                            </span>
                                                                            <?php if (!empty($day['reason'])): ?>
                                                                                <span class="badge bg-secondary"><?php echo sanitize($day['reason']); ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                            $reasons = [];
                                                            foreach ($stats['reasons'] as $reason => $count) {
                                                                $reasons[] = '<span class="badge bg-info text-dark">' . sanitize($reason) . ': ' . intval($count) . '</span>';
                                                            }
                                                            echo implode(' ', $reasons);
                                                        ?>
                                                    </td>
                                                </tr>
                                                <!-- Ətraflı günlər məlumatı -->
                                                <tr class="detailed-days-row">
                                                    <td colspan="5" class="p-0">
                                                        <div class="collapse" id="detailed-days-<?php echo $emp_id; ?>">
                                                            <div class="card card-body border-0">
                                                                <div class="row">
                                                                    <!-- İşdə olduğu günlər -->
                                                                    <div class="col-md-4">
                                                                        <h6 class="text-success"><i class="fas fa-check-circle me-1"></i> İşdə olduğu günlər</h6>
                                                                        <?php if (!empty($stats['detailed_days']['present'])): ?>
                                                                            <ul class="list-group">
                                                                                <?php foreach ($stats['detailed_days']['present'] as $day): ?>
                                                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                                        <?php echo $day['date']; ?>
                                                                                        <span class="badge bg-secondary rounded-pill"><?php echo translateDayName($day['day_name']); ?></span>
                                                                                    </li>
                                                                                <?php endforeach; ?>
                                                                            </ul>
                                                                        <?php else: ?>
                                                                            <p class="text-muted">Məlumat yoxdur</p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    
                                                                    <!-- Yarım gün işdə olduğu günlər -->
                                                                    <div class="col-md-4">
                                                                        <h6 class="text-warning"><i class="fas fa-adjust me-1"></i> Yarım gün işdə olduğu günlər</h6>
                                                                        <?php if (!empty($stats['detailed_days']['half_day'])): ?>
                                                                            <ul class="list-group">
                                                                                <?php foreach ($stats['detailed_days']['half_day'] as $day): ?>
                                                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                                        <?php echo $day['date']; ?>
                                                                                        <div>
                                                                                            <span class="badge bg-secondary rounded-pill"><?php echo translateDayName($day['day_name']); ?></span>
                                                                                            <?php if (!empty($day['reason'])): ?>
                                                                                                <span class="badge bg-info text-dark ms-1"><?php echo sanitize($day['reason']); ?></span>
                                                                                            <?php endif; ?>
                                                                                        </div>
                                                                                    </li>
                                                                                <?php endforeach; ?>
                                                                            </ul>
                                                                        <?php else: ?>
                                                                            <p class="text-muted">Məlumat yoxdur</p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    
                                                                    <!-- İşdə olmadığı günlər -->
                                                                    <div class="col-md-4">
                                                                        <h6 class="text-danger"><i class="fas fa-times-circle me-1"></i> İşdə olmadığı günlər</h6>
                                                                        <?php if (!empty($stats['detailed_days']['absent'])): ?>
                                                                            <ul class="list-group">
                                                                                <?php foreach ($stats['detailed_days']['absent'] as $day): ?>
                                                                                    <li class="list-group-item d-flex justify-content-between align-items-center">
                                                                                        <?php echo $day['date']; ?>
                                                                                        <div>
                                                                                            <span class="badge bg-secondary rounded-pill"><?php echo translateDayName($day['day_name']); ?></span>
                                                                                            <?php if (!empty($day['reason'])): ?>
                                                                                                <span class="badge bg-info text-dark ms-1"><?php echo sanitize($day['reason']); ?></span>
                                                                                            <?php endif; ?>
                                                                                        </div>
                                                                                    </li>
                                                                                <?php endforeach; ?>
                                                                            </ul>
                                                                        <?php else: ?>
                                                                            <p class="text-muted">Məlumat yoxdur</p>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <!-- Ətraflı məlumat göstərmək üçün düymə -->
                                                <tr class="table-light">
                                                    <td colspan="5" class="text-center py-2">
                                                        <button class="btn btn-sm btn-outline-primary" type="button" 
                                                                data-bs-toggle="collapse" data-bs-target="#detailed-days-<?php echo $emp_id; ?>" 
                                                                aria-expanded="false" aria-controls="detailed-days-<?php echo $emp_id; ?>">
                                                            <i class="fas fa-calendar-alt me-1"></i> Ətraflı günlər məlumatı
                                                        </button>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>

                                <!-- Qrafiklər -->
                                <h5 class="mb-3 mt-4 border-bottom pb-2"><i class="fas fa-chart-bar me-2"></i>Qrafik Statistikalar</h5>
                                <div id="chartsContainer">
                                    <div class="row">
                                        <div class="col-md-6 mb-4">
                                            <div class="card shadow-sm">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 text-primary"><i class="fas fa-check-circle me-1"></i> İşdə Günlər</h6>
                                                </div>
                                                <div class="card-body">
                                                    <canvas id="presentChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card shadow-sm">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 text-warning"><i class="fas fa-adjust me-1"></i> Yarım Günlər</h6>
                                                </div>
                                                <div class="card-body">
                                                    <canvas id="halfDayChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md-6 mb-4">
                                            <div class="card shadow-sm">
                                                <div class="card-header bg-light">
                                                    <h6 class="mb-0 text-danger"><i class="fas fa-times-circle me-1"></i> İşdə Deyil Günlər</h6>
                                                </div>
                                                <div class="card-body">
                                                    <canvas id="absentChart"></canvas>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle me-2"></i> Seçilmiş tarix aralığında heç bir davamiyyət qeydi tapılmadı.
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="alert alert-primary">
                                <i class="fas fa-info-circle me-2"></i> Hesabat görmək üçün tarix aralığı seçin.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Auto-dismiss Alerts -->
    <script>
        $(document).ready(function(){
            $('.alert').each(function(){
                var alert = $(this);
                setTimeout(function(){
                    alert.alert('close');
                }, 5000);
            });
        });
    </script>

    <!-- Bootstrap JS və Popper.js -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
</body>
</html>
