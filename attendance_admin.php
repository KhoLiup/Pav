<?php
/**
 * Davamiyyət Yoxlama Sistemi - Admin İdarəetmə Paneli
 * Bu səhifə davamiyyət parametrlərini dəyişmək üçün istifadə olunur
 */

session_start();
// Admin giriş yoxlaması
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Confiqurasiya yükləmək üçün icazə
define('ALLOW_ACCESS', true);

// Ana konfiqurasiya faylını daxil edin
require_once __DIR__ . '/config.php';

// Parametrlər faylı yolu
$config_file = __DIR__ . '/attendance_config.php';

// Parametrləri yükləmək
if (file_exists($config_file)) {
    $attendance_config = include($config_file);
    
    // Köhnə tək nömrəli konfiqurasiyadan yeniyə keçid (uyğunluq üçün)
    if (isset($attendance_config['notification_phone']) && !isset($attendance_config['notification_phones'])) {
        $attendance_config['notification_phones'] = [$attendance_config['notification_phone']];
        unset($attendance_config['notification_phone']);
    }
} else {
    // Default parametrlər - fayl olmadıqda istifadə olunur
    $attendance_config = [
        'notifications_enabled' => true,
        'notification_phones' => ['994776000034'],
        'working_hours' => [
            'start' => 9,
            'end' => 18
        ],
        'notification_start_hour' => 10,
        'working_days' => [1, 2, 3, 4, 5],
        'non_working_days' => [],
        'notification_interval' => 20,
        'notification_template' => "⚠️ *Davamiyyət Xəbərdarlığı*\n\n📅 Tarix: *{date}*\n\n❗ *Diqqət:* Bugünkü davamiyyət hələ qeyd edilməyib!\n\nZəhmət olmasa, işçilərin davamiyyətini sistem üzərində qeyd edin."
    ];
}

// Mesaj çıxarışları üçün dəyişənlər
$success_message = '';
$error_message = '';

// Cron işini qurmaq üçün
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_cron'])) {
    $script_path = realpath(__DIR__ . '/check_attendance.php');
    $log_path = realpath(__DIR__ . '/logs');
    
    $interval = $attendance_config['notification_interval'];
    $start_hour = $attendance_config['working_hours']['start'];
    $end_hour = $attendance_config['working_hours']['end'];
    $days = implode(',', $attendance_config['working_days']);
    
    $cron_cmd = "*/$interval $start_hour-$end_hour * * $days php $script_path >> $log_path/cron_attendance.log 2>&1";
    
    // Shell script yaratmaq
    $shell_script = "#!/bin/bash\n\n";
    $shell_script .= "# Cron Job üçün avtomatik quraşdırma skripti\n";
    $shell_script .= "# Davamiyyət Yoxlama Sistemi\n\n";
    $shell_script .= "# Cari istifadəçinin crontab-ını oxuyuruq\n";
    $shell_script .= "CURRENT_CRONTAB=\$(crontab -l 2>/dev/null)\n\n";
    $shell_script .= "# Əgər oxuma uğursuzdursa, boş bir başlanğıc yaradırıq\n";
    $shell_script .= "if [ \$? -ne 0 ]; then\n";
    $shell_script .= "    CURRENT_CRONTAB=\"# Davamiyyət Yoxlama Sistemi crontab\"\n";
    $shell_script .= "fi\n\n";
    $shell_script .= "# Köhnə davamiyyət cron job-unu təmizləyək\n";
    $shell_script .= "CURRENT_CRONTAB=\$(echo \"\$CURRENT_CRONTAB\" | grep -v \"$script_path\")\n\n";
    $shell_script .= "# Yeni cron job-u əlavə edirik\n";
    $shell_script .= "NEW_CRONTAB=\"\$CURRENT_CRONTAB\n$cron_cmd\"\n\n";
    $shell_script .= "# Yeni crontab-ı yazırıq\n";
    $shell_script .= "echo \"\$NEW_CRONTAB\" | crontab -\n\n";
    $shell_script .= "# Uğurlu installyasiya mesajı\n";
    $shell_script .= "echo \"Cron job uğurla quruldu.\"\n";
    $shell_script .= "echo \"Konfiqurasiya: */$interval $start_hour-$end_hour * * $days\"\n";
    $shell_script .= "echo \"Hər $interval dəqiqədən bir iş günlərində saat $start_hour-$end_hour arasında işləyəcək.\"\n\n";
    $shell_script .= "# crontab statusunu yoxlayırıq\n";
    $shell_script .= "echo \"Cron servisi statusu:\"\n";
    $shell_script .= "systemctl status cron\n";
    
    // Shell skriptini fayla yazırıq
    $shell_file = __DIR__ . '/setup_cron.sh';
    if (file_put_contents($shell_file, $shell_script)) {
        // Faylı yerinə yetirilə bilən et
        chmod($shell_file, 0755);
        $success_message = "Cron Job quraşdırma skripti yaradıldı! Serverdə setup_cron.sh faylını çalışdırın.";
    } else {
        $error_message = "Cron Job skriptinin yaradılması zamanı xəta!";
    }
}

// Parametrləri yeniləmək
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    // Parametrləri POST-dan almaq
    $notifications_enabled = isset($_POST['notifications_enabled']) ? true : false;
    
    // Telefon nömrələrini emal etmək
    $notification_phones = [];
    if (!empty($_POST['notification_phones'])) {
        $phones_array = explode("\n", trim($_POST['notification_phones']));
        foreach ($phones_array as $phone) {
            $phone = trim($phone);
            if (!empty($phone) && preg_match('/^994[0-9]{9}$/', $phone)) {
                $notification_phones[] = $phone;
            }
        }
    }
    
    $working_hours_start = isset($_POST['working_hours_start']) ? (int)$_POST['working_hours_start'] : 9;
    $working_hours_end = isset($_POST['working_hours_end']) ? (int)$_POST['working_hours_end'] : 18;
    $notification_start_hour = isset($_POST['notification_start_hour']) ? (int)$_POST['notification_start_hour'] : 10;
    
    // İş günlərini almaq
    $working_days = [];
    for ($i = 1; $i <= 7; $i++) {
        if (isset($_POST['working_day_' . $i])) {
            $working_days[] = $i;
        }
    }
    
    // İş olmayan günləri almaq
    $non_working_days = [];
    if (!empty($_POST['non_working_days'])) {
        $days_array = explode("\n", trim($_POST['non_working_days']));
        foreach ($days_array as $day) {
            $day = trim($day);
            if (!empty($day) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
                $non_working_days[] = $day;
            }
        }
    }
    
    // Bildiriş intervalı
    $notification_interval = isset($_POST['notification_interval']) ? (int)$_POST['notification_interval'] : 20;
    if ($notification_interval < 5) $notification_interval = 5;
    if ($notification_interval > 60) $notification_interval = 60;
    
    // Bildiriş şablonu
    $notification_template = isset($_POST['notification_template']) ? $_POST['notification_template'] : '';
    
    // Yeni parametrləri dəyişənə mənimsətmək
    $attendance_config['notifications_enabled'] = $notifications_enabled;
    $attendance_config['notification_phones'] = $notification_phones;
    $attendance_config['working_hours']['start'] = $working_hours_start;
    $attendance_config['working_hours']['end'] = $working_hours_end;
    $attendance_config['notification_start_hour'] = $notification_start_hour;
    $attendance_config['working_days'] = $working_days;
    $attendance_config['non_working_days'] = $non_working_days;
    $attendance_config['notification_interval'] = $notification_interval;
    $attendance_config['notification_template'] = $notification_template;
    
    // Parametrləri fayla yazırıq
    $config_content = "<?php\n/**\n * Davamiyyət Yoxlama Sistemi Parametrləri\n * Bu parametrlər admin panel vasitəsilə idarə edilir\n */\n\n";
    $config_content .= "// Heç bir birbaşa giriş yoxdur\nif (!defined('ALLOW_ACCESS')) {\n    die('Birbaşa giriş qadağandır!');\n}\n\n";
    $config_content .= "// Davamiyyət yoxlama parametrləri\n\$attendance_config = " . var_export($attendance_config, true) . ";\n\n";
    $config_content .= "return \$attendance_config;";
    
    // Faylı yazırıq
    try {
        if (file_put_contents($config_file, $config_content)) {
            $success_message = "Parametrlər uğurla yeniləndi!";
        } else {
            $error_message = "Parametrləri faylına yazma xətası!";
        }
    } catch (Exception $e) {
        $error_message = "Parametrləri faylına yazma xətası: " . $e->getMessage();
    }
}

// Həftə günlərinin adları
$weekdays = [
    1 => 'Bazar ertəsi',
    2 => 'Çərşənbə axşamı',
    3 => 'Çərşənbə',
    4 => 'Cümə axşamı',
    5 => 'Cümə',
    6 => 'Şənbə',
    7 => 'Bazar'
];

// HTML çıxış
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Davamiyyət Yoxlama Parametrləri</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .form-section {
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            background-color: #f8f9fa;
        }
        .form-section h4 {
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #dee2e6;
        }
        .working-days-section {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .working-days-section .form-check {
            width: 150px;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <?php include 'includes/header.php'; ?>
    
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-cogs me-2"></i> Davamiyyət Yoxlama Parametrləri</h2>
                    <div>
                        <form method="post" action="" class="d-inline">
                            <button type="submit" name="setup_cron" class="btn btn-success">
                                <i class="fas fa-clock me-1"></i> Cron Script Yarat
                            </button>
                        </form>
                        <a href="index.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-home me-1"></i> Ana Səhifə
                        </a>
                    </div>
                </div>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <form method="post" action="">
                    <div class="form-section">
                        <h4><i class="fas fa-toggle-on me-2"></i> Əsas Parametrlər</h4>
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="notifications_enabled" name="notifications_enabled" <?php echo $attendance_config['notifications_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="notifications_enabled">Bildiriş Sistemi Aktivdir</label>
                                </div>
                                <div class="form-text">Aktivləşdirilməsə, heç bir bildiriş göndərilməyəcək</div>
                            </div>
                            <div class="col-md-6">
                                <label for="notification_phones" class="form-label">Bildiriş Telefon Nömrələri</label>
                                <textarea class="form-control" id="notification_phones" name="notification_phones" rows="3" placeholder="Hər sətirdə bir nömrə, 994XXXXXXXXX formatında"><?php echo implode("\n", $attendance_config['notification_phones']); ?></textarea>
                                <div class="form-text">994 ilə başlayan 12 rəqəmli nömrələr, hər sətirə bir nömrə yazın</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-clock me-2"></i> Zaman Parametrləri</h4>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="working_hours_start" class="form-label">İş Başlama Saatı</label>
                                <select class="form-select" id="working_hours_start" name="working_hours_start">
                                    <?php for ($i = 6; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $attendance_config['working_hours']['start'] == $i ? 'selected' : ''; ?>><?php echo $i; ?>:00</option>
                                    <?php endfor; ?>
                                </select>
                                <div class="form-text">İş günü başlama saatı</div>
                            </div>
                            <div class="col-md-4">
                                <label for="working_hours_end" class="form-label">İş Bitmə Saatı</label>
                                <select class="form-select" id="working_hours_end" name="working_hours_end">
                                    <?php for ($i = 14; $i <= 22; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $attendance_config['working_hours']['end'] == $i ? 'selected' : ''; ?>><?php echo $i; ?>:00</option>
                                    <?php endfor; ?>
                                </select>
                                <div class="form-text">İş günü bitmə saatı</div>
                            </div>
                            <div class="col-md-4">
                                <label for="notification_start_hour" class="form-label">Bildiriş Başlama Saatı</label>
                                <select class="form-select" id="notification_start_hour" name="notification_start_hour">
                                    <?php for ($i = 8; $i <= 18; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $attendance_config['notification_start_hour'] == $i ? 'selected' : ''; ?>><?php echo $i; ?>:00</option>
                                    <?php endfor; ?>
                                </select>
                                <div class="form-text">Bu saatdan sonra bildiriş göndəriləcək</div>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label for="notification_interval" class="form-label">Bildiriş intervalı (dəqiqə)</label>
                                <input type="number" class="form-control" id="notification_interval" name="notification_interval" value="<?php echo htmlspecialchars($attendance_config['notification_interval']); ?>" min="5" max="60">
                                <div class="form-text">Bildirişlər bu qədər dəqiqədən bir göndəriləcək (5-60)</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-calendar-day me-2"></i> İş Günləri Parametrləri</h4>
                        <div class="mb-3">
                            <label class="form-label">İş Günləri:</label>
                            <div class="working-days-section">
                                <?php foreach ($weekdays as $day_num => $day_name): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="working_day_<?php echo $day_num; ?>" name="working_day_<?php echo $day_num; ?>" <?php echo in_array($day_num, $attendance_config['working_days']) ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="working_day_<?php echo $day_num; ?>"><?php echo $day_name; ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Yoxlamalar bu günlərdə aparılacaq</div>
                        </div>
                        <div class="mb-3">
                            <label for="non_working_days" class="form-label">Xüsusi Qeyri-İş Günləri:</label>
                            <textarea class="form-control" id="non_working_days" name="non_working_days" rows="4" placeholder="Hər sətirdə bir tarix, YYYY-MM-DD formatında"><?php echo implode("\n", $attendance_config['non_working_days']); ?></textarea>
                            <div class="form-text">Bayram, xüsusi günlər və s. Hər sətirdə bir tarix yazın, YYYY-MM-DD formatında (məs. 2023-12-31)</div>
                        </div>
                    </div>
                    
                    <div class="form-section">
                        <h4><i class="fas fa-envelope me-2"></i> Bildiriş Parametrləri</h4>
                        <div class="mb-3">
                            <label for="notification_template" class="form-label">Bildiriş Şablonu:</label>
                            <textarea class="form-control" id="notification_template" name="notification_template" rows="6"><?php echo htmlspecialchars($attendance_config['notification_template']); ?></textarea>
                            <div class="form-text">Bildiriş mesajı şablonu. {date} - cari tarixi göstərir</div>
                        </div>
                    </div>
                    
                    <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                        <button type="submit" name="save_settings" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i> Parametrləri Yadda Saxla
                        </button>
                    </div>
                </form>
                
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="card-title mb-0"><i class="fas fa-info-circle me-2"></i> Cron Job Quraşdırması</h5>
                    </div>
                    <div class="card-body">
                        <p>Bildirişlərin avtomatik göndərilməsi üçün serverdə cron job quraşdırmaq lazımdır. Bunun üçün:</p>
                        <ol>
                            <li>Əvvəlcə "Cron Script Yarat" düyməsini klikləyin.</li>
                            <li>Sonra serverdə yaranan <code>setup_cron.sh</code> faylını icra edin:</li>
                        </ol>
                        <pre class="bg-light p-3 rounded">chmod +x setup_cron.sh
./setup_cron.sh</pre>
                        <p class="mt-3 mb-0">Cron scripti aşağıdakı formatda olacaq:</p>
                        <pre class="bg-light p-3 rounded"><?php 
                            $interval = $attendance_config['notification_interval'];
                            $start_hour = $attendance_config['working_hours']['start'];
                            $end_hour = $attendance_config['working_hours']['end'];
                            $days = implode(',', $attendance_config['working_days']);
                            echo "*/$interval $start_hour-$end_hour * * $days php " . realpath(__DIR__ . '/check_attendance.php') . " >> " . realpath(__DIR__ . '/logs') . "/cron_attendance.log 2>&1";
                        ?></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 