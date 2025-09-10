<?php
/**
 * Kassa Hesabat Tarixçəsi
 * 
 * Bu fayl kassa hesabatlarının tarixçəsini göstərir, axtarış və filtrlər təqdim edir,
 * və hesabatların eksport edilməsi üçün funksiyalar təmin edir.
 * 
 * Əsas funksiyalar:
 * - Kassir və kassa əsasında hesabatların axtarışı
 * - Tarix aralığı və əlavə filtrlər
 * - Hesabat statistikası və icmalı
 * - CSV və Excel formatlarında eksport
 * - Hesabat detallarının görüntülənməsi
 * - Hesabatların silinməsi
 * 
 * @version 2.0
 * @author Your Name
 */

// Xətaların göstərilməsi (İnkişaf mühiti üçün açıq saxlamaq olar)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Sessiya
session_start();

// Verilənlər bazasına bağlantı
require 'config.php';

// İstifadəçi yoxlaması
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Helpers kitabxanasını əlavə edək
if (file_exists('includes/helpers.php')) {
    require_once 'includes/helpers.php';
}
require_once 'helpers.php';

/**
 * Kassirlərin siyahısını gətirən funksiya
 * Performans üçün keşləmə əlavə edilib
 */
function getCashiers($conn) {
    static $cashiers = null;
    
    // Əgər artıq keşlənibsə, keşdən qaytar
    if ($cashiers !== null) {
        return $cashiers;
    }
    
    // Sorğunu optimallaşdıraq və indekslərdən istifadə edək
    $stmt = $conn->prepare("
        SELECT 
            e.id, 
            e.name,
            COUNT(cr.id) as report_count
        FROM employees e
        LEFT JOIN cash_reports cr ON e.id = cr.employee_id
        WHERE e.category = 'kassir' 
          AND (e.is_active = 1 OR e.id IN (SELECT DISTINCT employee_id FROM cash_reports))
        GROUP BY e.id, e.name
        ORDER BY e.name ASC
    ");
    $stmt->execute();
    $cashiers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $cashiers;
}

/**
 * Kassaların siyahısını gətirən funksiya
 * Performans üçün keşləmə əlavə edilib
 */
function getCashRegisters($conn) {
    static $registers = null;
    
    // Əgər artıq keşlənibsə, keşdən qaytar
    if ($registers !== null) {
        return $registers;
    }
    
    // Sorğunu optimallaşdıraq və indekslərdən istifadə edək
    $stmt = $conn->prepare("
        SELECT 
            cr.id, 
            cr.name,
            COUNT(crp.id) as report_count
        FROM cash_registers cr
        LEFT JOIN cash_reports crp ON cr.id = crp.cash_register_id
        WHERE cr.is_active = 1 OR cr.id IN (SELECT DISTINCT cash_register_id FROM cash_reports WHERE cash_register_id IS NOT NULL)
        GROUP BY cr.id, cr.name
        ORDER BY cr.name ASC
    ");
    $stmt->execute();
    $registers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $registers;
}

/**
 * Seçilmiş kassir(lər) və tarix aralığı üçün əməliyyatları gətirən funksiya
 * Əlavə filtrlər və performans optimallaşdırmaları əlavə edilib
 */
function getOperations($conn, $employee_ids, $start_date, $end_date, $offset, $per_page, $cash_register_ids = [], $filters = []) {
    // offset və per_page tam rəqəmlərə çevrilir:
    $offset = max(0, (int)$offset);
    $per_page = max(1, (int)$per_page);

    // Əsas sorğu hissəsi
    $base_sql = "
        SELECT 
            cr.*, 
            e.name,
            cr2.name AS cash_register_name,
            COALESCE(SUM(JSON_LENGTH(cr.bank_receipts) - 1), 0) AS receipt_count
        FROM cash_reports cr
        JOIN employees e ON cr.employee_id = e.id
        LEFT JOIN cash_registers cr2 ON cr.cash_register_id = cr2.id
    ";
    
    $where_conditions = ["cr.date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    
    // Kassa ID əsasında axtarış (bir neçə kassa seçimi dəstəklənir)
    if (!empty($cash_register_ids)) {
        if (is_array($cash_register_ids)) {
            $placeholders = implode(',', array_fill(0, count($cash_register_ids), '?'));
            $where_conditions[] = "cr.cash_register_id IN ($placeholders)";
            $params = array_merge($params, $cash_register_ids);
        } else {
            $where_conditions[] = "cr.cash_register_id = ?";
            $params[] = $cash_register_ids;
        }
    }
    
    // İşçilər əsasında axtarış
    if (!empty($employee_ids)) {
        // Kassirlərin sayına uyğun '?' yerlikləri
        $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
        $where_conditions[] = "cr.employee_id IN ($placeholders)";
        $params = array_merge($params, $employee_ids);
    }
    
    // Əlavə filtrlər
    if (!empty($filters)) {
        // Borc filtrləri
        if (isset($filters['is_debt']) && $filters['is_debt'] !== '') {
            $where_conditions[] = "cr.is_debt = ?";
            $params[] = (int)$filters['is_debt'];
        }
        
        // Fərq filtrləri
        if (isset($filters['has_difference']) && $filters['has_difference'] !== '') {
            if ($filters['has_difference'] == 1) {
                $where_conditions[] = "cr.difference != 0";
            } else {
                $where_conditions[] = "cr.difference = 0";
            }
        }
        
        // ƏDV filtrləri
        if (isset($filters['has_vat']) && $filters['has_vat'] !== '') {
            if ($filters['has_vat'] == 1) {
                $where_conditions[] = "(cr.vat_included > 0 OR cr.vat_exempt > 0)";
            } else {
                $where_conditions[] = "(cr.vat_included = 0 AND cr.vat_exempt = 0)";
            }
        }
        
        // Məbləğ aralığı filtrləri
        if (isset($filters['min_amount']) && $filters['min_amount'] !== '') {
            $where_conditions[] = "cr.total_amount >= ?";
            $params[] = (float)$filters['min_amount'];
        }
        
        if (isset($filters['max_amount']) && $filters['max_amount'] !== '') {
            $where_conditions[] = "cr.total_amount <= ?";
            $params[] = (float)$filters['max_amount'];
        }
    }
    
    // WHERE şərtlərini birləşdirək
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Qruplaşdırma və sıralama
    $group_by = " GROUP BY cr.id ";
    
    // Sıralama
    $order_by = " ORDER BY cr.date DESC, cr.id DESC ";
    if (!empty($filters['sort_by']) && !empty($filters['sort_order'])) {
        $sort_field = $filters['sort_by'];
        $sort_order = strtoupper($filters['sort_order']) === 'ASC' ? 'ASC' : 'DESC';
        
        // Təhlükəsizlik üçün icazə verilən sütunları yoxlayaq
        $allowed_sort_fields = ['date', 'employee_id', 'cash_register_id', 'total_amount', 'difference', 'pos_amount', 'cash_given'];
        if (in_array($sort_field, $allowed_sort_fields)) {
            $order_by = " ORDER BY cr.$sort_field $sort_order, cr.id DESC ";
        }
    }
    
    // Limit
    $limit = " LIMIT $offset, $per_page";
    
    // Tam sorğu
    $sql = $base_sql . $where_clause . $group_by . $order_by . $limit;
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Əlavə məlumatları hesablayaq
        foreach ($results as &$result) {
            // Bank qəbzləri sayı
            $bank_receipts = json_decode($result['bank_receipts'], true);
            $result['bank_receipt_count'] = is_array($bank_receipts) ? count($bank_receipts) : 0;
            
            // Əlavə kassa məlumatları
            $additional_cash = json_decode($result['additional_cash'], true);
            $result['additional_cash_count'] = is_array($additional_cash) ? count($additional_cash) : 0;
            
            // Fərq faizi
            if ($result['total_amount'] > 0) {
                $result['difference_percentage'] = round(($result['difference'] / $result['total_amount']) * 100, 2);
            } else {
                $result['difference_percentage'] = 0;
            }
        }
        
        return $results;
    } catch (PDOException $e) {
        // Xəta baş verərsə boş array qaytar
        error_log("SQL Error in getOperations: " . $e->getMessage());
    return [];
    }
}

/**
 * Seçilmiş parametrlər üzrə ümumi hesabat sayı
 * Əlavə filtrlər əlavə edilib
 */
function getReportCount($conn, $employee_ids, $start_date, $end_date, $cash_register_ids = [], $filters = []) {
    $base_sql = "SELECT COUNT(*) FROM cash_reports cr";
    
    $where_conditions = ["cr.date BETWEEN ? AND ?"];
    $params = [$start_date, $end_date];
    
    // Kassa ID əsasında axtarış (bir neçə kassa seçimi dəstəklənir)
    if (!empty($cash_register_ids)) {
        if (is_array($cash_register_ids)) {
            $placeholders = implode(',', array_fill(0, count($cash_register_ids), '?'));
            $where_conditions[] = "cr.cash_register_id IN ($placeholders)";
            $params = array_merge($params, $cash_register_ids);
        } else {
            $where_conditions[] = "cr.cash_register_id = ?";
            $params[] = $cash_register_ids;
        }
    }
    
    // İşçilər əsasında axtarış
    if (!empty($employee_ids)) {
        $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
        $where_conditions[] = "cr.employee_id IN ($placeholders)";
        $params = array_merge($params, $employee_ids);
    }
    
    // Əlavə filtrlər
    if (!empty($filters)) {
        // Borc filtrləri
        if (isset($filters['is_debt']) && $filters['is_debt'] !== '') {
            $where_conditions[] = "cr.is_debt = ?";
            $params[] = (int)$filters['is_debt'];
        }
        
        // Fərq filtrləri
        if (isset($filters['has_difference']) && $filters['has_difference'] !== '') {
            if ($filters['has_difference'] == 1) {
                $where_conditions[] = "cr.difference != 0";
            } else {
                $where_conditions[] = "cr.difference = 0";
            }
        }
        
        // ƏDV filtrləri
        if (isset($filters['has_vat']) && $filters['has_vat'] !== '') {
            if ($filters['has_vat'] == 1) {
                $where_conditions[] = "(cr.vat_included > 0 OR cr.vat_exempt > 0)";
            } else {
                $where_conditions[] = "(cr.vat_included = 0 AND cr.vat_exempt = 0)";
            }
        }
        
        // Məbləğ aralığı filtrləri
        if (isset($filters['min_amount']) && $filters['min_amount'] !== '') {
            $where_conditions[] = "cr.total_amount >= ?";
            $params[] = (float)$filters['min_amount'];
        }
        
        if (isset($filters['max_amount']) && $filters['max_amount'] !== '') {
            $where_conditions[] = "cr.total_amount <= ?";
            $params[] = (float)$filters['max_amount'];
        }
    }
    
    // WHERE şərtlərini birləşdirək
    $where_clause = "";
    if (!empty($where_conditions)) {
        $where_clause = " WHERE " . implode(" AND ", $where_conditions);
    }
    
    // Tam sorğu
    $sql = $base_sql . $where_clause;
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("SQL Error in getReportCount: " . $e->getMessage());
    return 0;
    }
}

/**
 * Gətirilmiş hesabatların cəmi və statistikası
 * Əlavə statistikalar və performans optimallaşdırmaları əlavə edilib
 * @param array $reports Hesabatlar massivi
 * @param PDO $conn Verilənlər bazası bağlantısı
 * @param array $params Statistika parametrləri (start_date, end_date, employee_ids, cash_register_ids)
 * @return array Statistika məlumatları
 */
function getReportSummary($reports, $conn = null, $params = []) {
    $summary = [
        'total_bank_receipts' => 0,
        'total_cash_given' => 0,
        'total_additional_cash' => 0,
        'total_pos_amount' => 0,
        'total_amount' => 0,
        'total_difference' => 0,
        'total_vat_included' => 0,
        'total_vat_exempt' => 0,
        'total_debts' => 0,
        'total_records' => count($reports),
        
        // Əlavə statistikalar
        'avg_amount' => 0,
        'max_amount' => 0,
        'min_amount' => PHP_FLOAT_MAX,
        'total_positive_difference' => 0,
        'total_negative_difference' => 0,
        'count_positive_difference' => 0,
        'count_negative_difference' => 0,
        'count_zero_difference' => 0,
        'count_with_vat' => 0,
        'count_without_vat' => 0,
        'total_bank_receipt_count' => 0,
        'total_additional_cash_count' => 0,
        'cashiers' => [],
        'cash_registers' => [],
        'daily_stats' => [],
        
        // Seçilmiş dövr üzrə statistika
        'period_stats' => [
            'total_amount' => 0,
            'total_records' => 0,
            'avg_daily_amount' => 0,
            'avg_daily_records' => 0,
            'start_date' => $params['start_date'] ?? '',
            'end_date' => $params['end_date'] ?? '',
            'days_in_period' => 0
        ]
    ];

    if (empty($reports)) {
        // Əgər hesabatlar boşdursa, amma verilənlər bazası bağlantısı və parametrlər varsa,
        // seçilmiş dövr üzrə statistikanı hesablayaq
        if ($conn && !empty($params)) {
            $summary = getPeriodStatistics($conn, $params, $summary);
        }
        return $summary;
    }

    foreach ($reports as $report) {
        // Bank qəbzləri
        $bank_receipts = json_decode($report['bank_receipts'], true);
        if (is_array($bank_receipts)) {
            $receipt_sum = array_sum($bank_receipts);
            $summary['total_bank_receipts'] += $receipt_sum;
            $summary['total_bank_receipt_count'] += count($bank_receipts);
        }

        // Nağd Pul
        $summary['total_cash_given'] += floatval($report['cash_given']);

        // Əlavə Kassadan Verilən Pullar
        $additional_cash = json_decode($report['additional_cash'], true);
        $additional_cash_sum = 0;
        if (is_array($additional_cash)) {
            foreach ($additional_cash as $ac) {
                if (!empty($ac['amount'])) {
                    $additional_cash_sum += floatval($ac['amount']);
                }
            }
            $summary['total_additional_cash'] += $additional_cash_sum;
            $summary['total_additional_cash_count'] += count($additional_cash);
        }

        // POS Məbləği
        $summary['total_pos_amount'] += floatval($report['pos_amount']);

        // Yekun Məbləğ
        $amount = floatval($report['total_amount']);
        $summary['total_amount'] += $amount;
        
        // Min və Max məbləğlər
        if ($amount > $summary['max_amount']) {
            $summary['max_amount'] = $amount;
        }
        if ($amount < $summary['min_amount']) {
            $summary['min_amount'] = $amount;
        }

        // Fərq
        $difference = floatval($report['difference']);
        $summary['total_difference'] += $difference;
        
        // Müsbət və mənfi fərqlər
        if ($difference > 0) {
            $summary['total_positive_difference'] += $difference;
            $summary['count_positive_difference']++;
        } elseif ($difference < 0) {
            $summary['total_negative_difference'] += abs($difference);
            $summary['count_negative_difference']++;
        } else {
            $summary['count_zero_difference']++;
        }
        
        // ƏDV məbləğləri
        $vat_included = floatval($report['vat_included'] ?? 0);
        $vat_exempt = floatval($report['vat_exempt'] ?? 0);
        $summary['total_vat_included'] += $vat_included;
        $summary['total_vat_exempt'] += $vat_exempt;
        
        // ƏDV statistikası
        if ($vat_included > 0 || $vat_exempt > 0) {
            $summary['count_with_vat']++;
        } else {
            $summary['count_without_vat']++;
        }

        // Borc (is_debt = 1)
        if (!empty($report['is_debt'])) {
            $summary['total_debts']++;
        }
        
        // Kassir statistikası
        $cashier_id = $report['employee_id'];
        $cashier_name = $report['name'];
        if (!isset($summary['cashiers'][$cashier_id])) {
            $summary['cashiers'][$cashier_id] = [
                'id' => $cashier_id,
                'name' => $cashier_name,
                'count' => 0,
                'total_amount' => 0,
                'total_difference' => 0
            ];
        }
        $summary['cashiers'][$cashier_id]['count']++;
        $summary['cashiers'][$cashier_id]['total_amount'] += $amount;
        $summary['cashiers'][$cashier_id]['total_difference'] += $difference;
        
        // Kassa statistikası
        if (!empty($report['cash_register_id'])) {
            $register_id = $report['cash_register_id'];
            $register_name = $report['cash_register_name'];
            if (!isset($summary['cash_registers'][$register_id])) {
                $summary['cash_registers'][$register_id] = [
                    'id' => $register_id,
                    'name' => $register_name,
                    'count' => 0,
                    'total_amount' => 0,
                    'total_difference' => 0
                ];
            }
            $summary['cash_registers'][$register_id]['count']++;
            $summary['cash_registers'][$register_id]['total_amount'] += $amount;
            $summary['cash_registers'][$register_id]['total_difference'] += $difference;
        }
        
        // Gün üzrə statistika
        $date = date('Y-m-d', strtotime($report['date']));
        if (!isset($summary['daily_stats'][$date])) {
            $summary['daily_stats'][$date] = [
                'date' => $date,
                'count' => 0,
                'total_amount' => 0,
                'total_difference' => 0,
                'total_cash' => 0,
                'total_pos' => 0
            ];
        }
        $summary['daily_stats'][$date]['count']++;
        $summary['daily_stats'][$date]['total_amount'] += $amount;
        $summary['daily_stats'][$date]['total_difference'] += $difference;
        $summary['daily_stats'][$date]['total_cash'] += floatval($report['cash_given']);
        $summary['daily_stats'][$date]['total_pos'] += floatval($report['pos_amount']);
    }
    
    // Orta məbləğ
    if ($summary['total_records'] > 0) {
        $summary['avg_amount'] = $summary['total_amount'] / $summary['total_records'];
    }
    
    // Əgər heç bir hesabat yoxdursa, min məbləği 0-a bərabər edirik
    if ($summary['min_amount'] == PHP_FLOAT_MAX) {
        $summary['min_amount'] = 0;
    }
    
    // Kassir və kassa statistikalarını sıralayaq
    usort($summary['cashiers'], function($a, $b) {
        return $b['total_amount'] <=> $a['total_amount'];
    });
    
    usort($summary['cash_registers'], function($a, $b) {
        return $b['total_amount'] <=> $a['total_amount'];
    });
    
    // Gün üzrə statistikaları sıralayaq
    $daily_stats = $summary['daily_stats'];
    ksort($daily_stats); // Tarixə görə sıralama
    $summary['daily_stats'] = array_values($daily_stats);
    
    // Seçilmiş dövr üzrə statistikanı hesablayaq
    if ($conn && !empty($params)) {
        $summary = getPeriodStatistics($conn, $params, $summary);
    }
    
    return $summary;
}

/**
 * Seçilmiş dövr üzrə statistikanı hesablayan funksiya
 * @param PDO $conn Verilənlər bazası bağlantısı
 * @param array $params Statistika parametrləri (start_date, end_date, employee_ids, cash_register_ids)
 * @param array $summary Mövcud statistika massivi
 * @return array Yenilənmiş statistika massivi
 */
function getPeriodStatistics($conn, $params, $summary) {
    $start_date = $params['start_date'] ?? '';
    $end_date = $params['end_date'] ?? '';
    $employee_ids = $params['employee_ids'] ?? [];
    $cash_register_ids = $params['cash_register_ids'] ?? [];
    
    if (empty($start_date) || empty($end_date)) {
        return $summary;
    }
    
    // Dövrdəki günlərin sayını hesablayaq
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = $start->diff($end);
    $days_in_period = $interval->days + 1; // Son günü də daxil etmək üçün +1
    
    $summary['period_stats']['start_date'] = $start_date;
    $summary['period_stats']['end_date'] = $end_date;
    $summary['period_stats']['days_in_period'] = $days_in_period;
    
    // Dövr üzrə ümumi məbləğ və hesabat sayını hesablayaq
    $base_sql = "
        SELECT 
            COUNT(*) as total_records,
            SUM(total_amount) as total_amount,
            SUM(cash_given) as total_cash,
            SUM(pos_amount) as total_pos,
            SUM(difference) as total_difference,
            SUM(vat_included) as total_vat_included,
            SUM(vat_exempt) as total_vat_exempt,
            SUM(is_debt) as total_debts
        FROM cash_reports
        WHERE date BETWEEN ? AND ?
    ";
    
    $params_sql = [$start_date, $end_date];
    
    // Əlavə şərtlər
    $where_conditions = [];
    
    // Kassir filtrləri
    if (!empty($employee_ids)) {
        $placeholders = implode(',', array_fill(0, count($employee_ids), '?'));
        $where_conditions[] = "employee_id IN ($placeholders)";
        $params_sql = array_merge($params_sql, $employee_ids);
    }
    
    // Kassa filtrləri
    if (!empty($cash_register_ids)) {
        if (is_array($cash_register_ids)) {
            $placeholders = implode(',', array_fill(0, count($cash_register_ids), '?'));
            $where_conditions[] = "cash_register_id IN ($placeholders)";
            $params_sql = array_merge($params_sql, $cash_register_ids);
        } else {
            $where_conditions[] = "cash_register_id = ?";
            $params_sql[] = $cash_register_ids;
        }
    }
    
    // Əlavə şərtləri əlavə edək
    if (!empty($where_conditions)) {
        $base_sql .= " AND " . implode(" AND ", $where_conditions);
    }
    
    try {
        $stmt = $conn->prepare($base_sql);
        $stmt->execute($params_sql);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $summary['period_stats']['total_records'] = (int)$result['total_records'];
            $summary['period_stats']['total_amount'] = (float)$result['total_amount'];
            $summary['period_stats']['total_cash'] = (float)$result['total_cash'];
            $summary['period_stats']['total_pos'] = (float)$result['total_pos'];
            $summary['period_stats']['total_difference'] = (float)$result['total_difference'];
            $summary['period_stats']['total_vat_included'] = (float)$result['total_vat_included'];
            $summary['period_stats']['total_vat_exempt'] = (float)$result['total_vat_exempt'];
            $summary['period_stats']['total_debts'] = (int)$result['total_debts'];
            
            // Orta günlük məbləğ və hesabat sayı
            if ($days_in_period > 0) {
                $summary['period_stats']['avg_daily_amount'] = $summary['period_stats']['total_amount'] / $days_in_period;
                $summary['period_stats']['avg_daily_records'] = $summary['period_stats']['total_records'] / $days_in_period;
            }
            
            // Aylıq statistika
            $monthly_sql = "
                SELECT 
                    DATE_FORMAT(date, '%Y-%m') as month,
                    COUNT(*) as count,
                    SUM(total_amount) as total_amount
                FROM cash_reports
                WHERE date BETWEEN ? AND ?
            ";
            
            if (!empty($where_conditions)) {
                $monthly_sql .= " AND " . implode(" AND ", $where_conditions);
            }
            
            $monthly_sql .= " GROUP BY DATE_FORMAT(date, '%Y-%m') ORDER BY month";
            
            $stmt = $conn->prepare($monthly_sql);
            $stmt->execute($params_sql);
            $summary['period_stats']['monthly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Həftəlik statistika
            $weekly_sql = "
                SELECT 
                    YEARWEEK(date, 1) as week,
                    MIN(date) as start_date,
                    MAX(date) as end_date,
                    COUNT(*) as count,
                    SUM(total_amount) as total_amount
                FROM cash_reports
                WHERE date BETWEEN ? AND ?
            ";
            
            if (!empty($where_conditions)) {
                $weekly_sql .= " AND " . implode(" AND ", $where_conditions);
            }
            
            $weekly_sql .= " GROUP BY YEARWEEK(date, 1) ORDER BY week";
            
            $stmt = $conn->prepare($weekly_sql);
            $stmt->execute($params_sql);
            $summary['period_stats']['weekly'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log("SQL Error in getPeriodStatistics: " . $e->getMessage());
    }

    return $summary;
}

/**
 * CSV Export funksiyası
 * Təkmilləşdirilmiş versiya - daha çox məlumat və xəta idarəetməsi
 */
function exportCSV($reports, $summary = []) {
    if (empty($reports)) {
        $_SESSION['error_message'] = 'Eksport üçün hesabat tapılmadı.';
        header('Location: cash_report_history.php');
        exit();
    }

    try {
        $filename = 'cash_reports_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

    $output = fopen('php://output', 'w');
        
        // UTF-8 BOM əlavə et (Excel-də Azərbaycan dilində simvolların düzgün göstərilməsi üçün)
        fputs($output, "\xEF\xBB\xBF");

    // CSV başlıqları
    fputcsv($output, [
        'Tarix', 
        'Kassir',
        'Kassa',
        'Bank Qəbzləri (AZN)', 
            'Bank Qəbzləri Sayı',
        'Nağd Pul (AZN)', 
        'Əlavə Kassadan Verilən Pullar (AZN)', 
            'Əlavə Kassa Əməliyyat Sayı',
        'POS Məbləği (AZN)', 
        'Yekun Məbləğ (AZN)', 
        'Fərq (AZN)',
            'Fərq (%)',
        'ƏDV-li Məbləğ (AZN)',
        'ƏDV-dən Azad Məbləğ (AZN)',
            'Borc',
            'Qeydlər'
    ]);

    foreach ($reports as $report) {
            // Bank qəbzləri toplamı və sayı
        $bank_receipts = json_decode($report['bank_receipts'], true);
        $total_bank_receipts = is_array($bank_receipts) ? array_sum($bank_receipts) : 0;
            $bank_receipt_count = is_array($bank_receipts) ? count($bank_receipts) : 0;

            // Əlavə kassadan verilən pulların toplamı və sayı
        $additional_cash = json_decode($report['additional_cash'], true);
        $total_additional_cash = 0;
            $additional_cash_count = 0;
        if (is_array($additional_cash)) {
            foreach ($additional_cash as $ac) {
                if (!empty($ac['amount'])) {
                    $total_additional_cash += floatval($ac['amount']);
                }
            }
                $additional_cash_count = count($additional_cash);
            }
            
            // Fərq faizi
            $difference_percentage = 0;
            if ($report['total_amount'] > 0) {
                $difference_percentage = round(($report['difference'] / $report['total_amount']) * 100, 2) / 100; // Excel format üçün
        }

        // Sətir məlumatları
        $row = [
            date('d.m.Y', strtotime($report['date'])),
            htmlspecialchars($report['name']),
            htmlspecialchars($report['cash_register_name'] ?? '-'),
                number_format($total_bank_receipts, 2, '.', ''),
                $bank_receipt_count,
                number_format($report['cash_given'], 2, '.', ''),
                number_format($total_additional_cash, 2, '.', ''),
                $additional_cash_count,
                number_format($report['pos_amount'], 2, '.', ''),
                number_format($report['total_amount'], 2, '.', ''),
                number_format($report['difference'], 2, '.', ''),
                number_format($difference_percentage, 2, '.', '') . '%',
                number_format($report['vat_included'] ?? 0, 2, '.', ''),
                number_format($report['vat_exempt'] ?? 0, 2, '.', ''),
                $report['is_debt'] ? 'Bəli' : 'Xeyr',
                htmlspecialchars($report['notes'] ?? '')
        ];
        fputcsv($output, $row);
    }
        
        // Əgər icmal məlumatları varsa, onları da əlavə edək
        if (!empty($summary)) {
            // Boş sətir
            fputcsv($output, []);
            
            // İcmal başlığı
            fputcsv($output, ['HESABAT İCMALI']);
            fputcsv($output, []);
            
            // Ümumi məlumatlar
            fputcsv($output, ['Ümumi Hesabat Sayı', $summary['total_records']]);
            fputcsv($output, ['Ümumi Məbləğ (AZN)', number_format($summary['total_amount'], 2, '.', '')]);
            fputcsv($output, ['Orta Məbləğ (AZN)', number_format($summary['avg_amount'], 2, '.', '')]);
            fputcsv($output, ['Ən Yüksək Məbləğ (AZN)', number_format($summary['max_amount'], 2, '.', '')]);
            fputcsv($output, ['Ən Aşağı Məbləğ (AZN)', number_format($summary['min_amount'], 2, '.', '')]);
            fputcsv($output, ['Ümumi Bank Qəbzləri (AZN)', number_format($summary['total_bank_receipts'], 2, '.', '')]);
            fputcsv($output, ['Ümumi Nağd Pul (AZN)', number_format($summary['total_cash_given'], 2, '.', '')]);
            fputcsv($output, ['Ümumi Əlavə Kassa (AZN)', number_format($summary['total_additional_cash'], 2, '.', '')]);
            fputcsv($output, ['Ümumi POS Məbləği (AZN)', number_format($summary['total_pos_amount'], 2, '.', '')]);
            fputcsv($output, ['Ümumi Fərq (AZN)', number_format($summary['total_difference'], 2, '.', '')]);
            fputcsv($output, ['Ümumi ƏDV-li Məbləğ (AZN)', number_format($summary['total_vat_included'], 2, '.', '')]);
            fputcsv($output, ['Ümumi ƏDV-dən Azad Məbləğ (AZN)', number_format($summary['total_vat_exempt'], 2, '.', '')]);
            fputcsv($output, ['Borc Sayı', $summary['total_debts']]);
    }

    fclose($output);
    exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'CSV eksport xətası: ' . $e->getMessage();
        error_log('CSV Export Error: ' . $e->getMessage());
        header('Location: cash_report_history.php');
    exit();
    }
}

/**
 * Excel Export funksiyası
 * Təkmilləşdirilmiş versiya - daha çox məlumat və xəta idarəetməsi
 */
function exportExcel($reports, $summary = []) {
    if (empty($reports)) {
        $_SESSION['error_message'] = 'Eksport üçün hesabat tapılmadı.';
        header('Location: cash_report_history.php');
        exit();
    }

    try {
        $filename = 'cash_reports_' . date('Ymd_His') . '.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        // Excel XML yaratmaq
        $xml = '<?xml version="1.0" encoding="UTF-8"?><?mso-application progid="Excel.Sheet"?>';
        $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        
        // Üslublar
        $xml .= '<Styles>
            <Style ss:ID="Default" ss:Name="Normal">
                <Alignment ss:Vertical="Bottom"/>
                <Borders/>
                <Font ss:FontName="Calibri" ss:Size="11"/>
                <Interior/>
                <NumberFormat/>
                <Protection/>
            </Style>
            <Style ss:ID="Header">
                <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>
                <Interior ss:Color="#D9E1F2" ss:Pattern="Solid"/>
                <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
                <Borders>
                    <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/>
                    <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/>
                </Borders>
            </Style>
            <Style ss:ID="SummaryHeader">
                <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1"/>
                <Interior ss:Color="#4472C4" ss:Pattern="Solid"/>
                <Alignment ss:Horizontal="Center" ss:Vertical="Center"/>
                <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#FFFFFF"/>
            </Style>
            <Style ss:ID="SummaryRow">
                <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1"/>
                <Interior ss:Color="#E2EFDA" ss:Pattern="Solid"/>
            </Style>
            <Style ss:ID="Number">
                <NumberFormat ss:Format="0.00"/>
            </Style>
            <Style ss:ID="Date">
                <NumberFormat ss:Format="dd/mm/yyyy"/>
            </Style>
            <Style ss:ID="Percentage">
                <NumberFormat ss:Format="0.00%"/>
            </Style>
            <Style ss:ID="NegativeNumber">
                <NumberFormat ss:Format="0.00"/>
                <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#FF0000"/>
            </Style>
            <Style ss:ID="PositiveNumber">
                <NumberFormat ss:Format="0.00"/>
                <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#00B050"/>
            </Style>
        </Styles>';
        
        // Hesabatlar vərəqi
        $xml .= '<Worksheet ss:Name="Hesabatlar"><Table>';
        
        // Sütun genişlikləri
        $xml .= '
            <Column ss:Width="80"/>
            <Column ss:Width="120"/>
            <Column ss:Width="120"/>
            <Column ss:Width="90"/>
            <Column ss:Width="60"/>
            <Column ss:Width="90"/>
            <Column ss:Width="90"/>
            <Column ss:Width="60"/>
            <Column ss:Width="90"/>
            <Column ss:Width="90"/>
            <Column ss:Width="90"/>
            <Column ss:Width="60"/>
            <Column ss:Width="90"/>
            <Column ss:Width="90"/>
            <Column ss:Width="60"/>
            <Column ss:Width="150"/>
        ';
        
        // Başlıqlar
        $xml .= '<Row ss:Height="30">
            <Cell ss:StyleID="Header"><Data ss:Type="String">Tarix</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Kassir</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Kassa</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Bank Qəbzləri (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Bank Qəbzləri Sayı</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Nağd Pul (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Əlavə Kassadan Verilən Pullar (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Əlavə Kassa Əməliyyat Sayı</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">POS Məbləği (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Yekun Məbləğ (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Fərq (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Fərq (%)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">ƏDV-li Məbləğ (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">ƏDV-dən Azad Məbləğ (AZN)</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Borc</Data></Cell>
            <Cell ss:StyleID="Header"><Data ss:Type="String">Qeydlər</Data></Cell>
        </Row>';
        
        // Məlumatlar
        foreach ($reports as $report) {
            $bank_receipts = json_decode($report['bank_receipts'], true);
            $total_bank_receipts = is_array($bank_receipts) ? array_sum($bank_receipts) : 0;
            $bank_receipt_count = is_array($bank_receipts) ? count($bank_receipts) : 0;
            
            $additional_cash = json_decode($report['additional_cash'], true);
            $total_additional_cash = 0;
            $additional_cash_count = 0;
            if (is_array($additional_cash)) {
                foreach ($additional_cash as $ac) {
                    if (!empty($ac['amount'])) {
                        $total_additional_cash += floatval($ac['amount']);
                    }
                }
                $additional_cash_count = count($additional_cash);
            }
            
            // Fərq faizi
            $difference_percentage = 0;
            if ($report['total_amount'] > 0) {
                $difference_percentage = round(($report['difference'] / $report['total_amount']) * 100, 2) / 100; // Excel format üçün
            }
            
            // Fərq üçün üslub seçimi
            $difference_style = "Number";
            if ($report['difference'] < 0) {
                $difference_style = "NegativeNumber";
            } elseif ($report['difference'] > 0) {
                $difference_style = "PositiveNumber";
            }
            
            $xml .= '<Row>
                <Cell ss:StyleID="Date"><Data ss:Type="String">' . date('d.m.Y', strtotime($report['date'])) . '</Data></Cell>
                <Cell><Data ss:Type="String">' . htmlspecialchars($report['name']) . '</Data></Cell>
                <Cell><Data ss:Type="String">' . htmlspecialchars($report['cash_register_name'] ?? '-') . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($total_bank_receipts, 2, '.', '') . '</Data></Cell>
                <Cell><Data ss:Type="Number">' . $bank_receipt_count . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($report['cash_given'], 2, '.', '') . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($total_additional_cash, 2, '.', '') . '</Data></Cell>
                <Cell><Data ss:Type="Number">' . $additional_cash_count . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($report['pos_amount'], 2, '.', '') . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($report['total_amount'], 2, '.', '') . '</Data></Cell>
                <Cell ss:StyleID="' . $difference_style . '"><Data ss:Type="Number">' . number_format($report['difference'], 2, '.', '') . '</Data></Cell>
                <Cell ss:StyleID="Percentage"><Data ss:Type="Number">' . $difference_percentage . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($report['vat_included'] ?? 0, 2, '.', '') . '</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($report['vat_exempt'] ?? 0, 2, '.', '') . '</Data></Cell>
                <Cell><Data ss:Type="String">' . ($report['is_debt'] ? 'Bəli' : 'Xeyr') . '</Data></Cell>
                <Cell><Data ss:Type="String">' . htmlspecialchars($report['notes'] ?? '') . '</Data></Cell>
            </Row>';
        }
        
        $xml .= '</Table></Worksheet>';
        
        // Əgər icmal məlumatları varsa, əlavə vərəq yaradaq
        if (!empty($summary)) {
            $xml .= '<Worksheet ss:Name="İcmal"><Table>';
            
            // Sütun genişlikləri
            $xml .= '
                <Column ss:Width="200"/>
                <Column ss:Width="120"/>
            ';
            
            // Başlıq
            $xml .= '<Row ss:Height="30">
                <Cell ss:StyleID="SummaryHeader" ss:MergeAcross="1"><Data ss:Type="String">HESABAT İCMALI</Data></Cell>
            </Row>';
            
            // Boş sətir
            $xml .= '<Row></Row>';
            
            // Ümumi məlumatlar
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Hesabat Sayı</Data></Cell>
                <Cell><Data ss:Type="Number">' . $summary['total_records'] . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_amount'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Orta Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['avg_amount'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ən Yüksək Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['max_amount'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ən Aşağı Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['min_amount'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Bank Qəbzləri (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_bank_receipts'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Nağd Pul (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_cash_given'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Əlavə Kassa (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_additional_cash'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi POS Məbləği (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_pos_amount'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi Fərq (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_difference'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi ƏDV-li Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_vat_included'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Ümumi ƏDV-dən Azad Məbləğ (AZN)</Data></Cell>
                <Cell ss:StyleID="Number"><Data ss:Type="Number">' . number_format($summary['total_vat_exempt'], 2, '.', '') . '</Data></Cell>
            </Row>';
            
            $xml .= '<Row>
                <Cell ss:StyleID="SummaryRow"><Data ss:Type="String">Borc Sayı</Data></Cell>
                <Cell><Data ss:Type="Number">' . $summary['total_debts'] . '</Data></Cell>
            </Row>';
            
            $xml .= '</Table></Worksheet>';
        }
        
        $xml .= '</Workbook>';
        
        echo $xml;
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Excel eksport xətası: ' . $e->getMessage();
        error_log('Excel Export Error: ' . $e->getMessage());
        header('Location: cash_report_history.php');
        exit();
    }
}

/**
 * Səhifələmə funksiyası
 * Təkmilləşdirilmiş versiya - daha yaxşı görünüş və əlavə parametrlər
 */
function paginate($total_items, $current_page, $per_page, $base_url, $options = []) {
    $total_pages = ceil($total_items / $per_page);
    
    if ($total_pages <= 1) {
        return '';
    }
    
    // Parametrləri ayarlayaq
    $defaults = [
        'show_first_last' => true,
        'show_prev_next' => true,
        'show_numbers' => true,
        'show_total' => true,
        'size' => '', // '', 'sm', 'lg'
        'alignment' => 'center', // 'start', 'center', 'end'
        'max_visible_pages' => 5,
        'first_text' => '&laquo;',
        'last_text' => '&raquo;',
        'prev_text' => 'Əvvəlki',
        'next_text' => 'Sonrakı',
        'aria_label' => 'Səhifələmə'
    ];
    
    $options = array_merge($defaults, $options);
    
    // Səhifələmə konteynerini yaradaq
    $size_class = $options['size'] ? " pagination-{$options['size']}" : '';
    $html = '<nav aria-label="' . $options['aria_label'] . '">';
    $html .= '<ul class="pagination justify-content-' . $options['alignment'] . $size_class . '">';
    
    // İlk səhifə
    if ($options['show_first_last'] && $current_page > 1) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=1" title="İlk səhifə">' . $options['first_text'] . '</a></li>';
    }
    
    // Əvvəlki səhifə
    if ($options['show_prev_next']) {
    if ($current_page > 1) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . ($current_page - 1) . '">' . $options['prev_text'] . '</a></li>';
    } else {
            $html .= '<li class="page-item disabled"><span class="page-link">' . $options['prev_text'] . '</span></li>';
        }
    }
    
    // Səhifə nömrələri
    if ($options['show_numbers']) {
        $half_visible = floor($options['max_visible_pages'] / 2);
        $start_page = max(1, $current_page - $half_visible);
        $end_page = min($total_pages, $start_page + $options['max_visible_pages'] - 1);
        
        // Əgər son səhifələrdəyiksə, başlanğıc səhifəni tənzimləyək
        if ($end_page - $start_page + 1 < $options['max_visible_pages']) {
            $start_page = max(1, $end_page - $options['max_visible_pages'] + 1);
        }
        
        // İlk səhifə və ellipsis
    if ($start_page > 1) {
        if ($start_page > 2) {
                $html .= '<li class="page-item d-none d-sm-block"><a class="page-link" href="' . $base_url . 'page=1">1</a></li>';
                $html .= '<li class="page-item disabled d-none d-sm-block"><span class="page-link">...</span></li>';
            } elseif ($start_page == 2) {
                $html .= '<li class="page-item d-none d-sm-block"><a class="page-link" href="' . $base_url . 'page=1">1</a></li>';
        }
    }
    
        // Səhifə nömrələri
    for ($i = $start_page; $i <= $end_page; $i++) {
        if ($i == $current_page) {
                $html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
        // Son səhifə və ellipsis
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) {
                $html .= '<li class="page-item disabled d-none d-sm-block"><span class="page-link">...</span></li>';
                $html .= '<li class="page-item d-none d-sm-block"><a class="page-link" href="' . $base_url . 'page=' . $total_pages . '">' . $total_pages . '</a></li>';
            } elseif ($end_page == $total_pages - 1) {
                $html .= '<li class="page-item d-none d-sm-block"><a class="page-link" href="' . $base_url . 'page=' . $total_pages . '">' . $total_pages . '</a></li>';
        }
        }
    }
    
    // Sonrakı səhifə
    if ($options['show_prev_next']) {
    if ($current_page < $total_pages) {
            $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . ($current_page + 1) . '">' . $options['next_text'] . '</a></li>';
    } else {
            $html .= '<li class="page-item disabled"><span class="page-link">' . $options['next_text'] . '</span></li>';
        }
    }
    
    // Son səhifə
    if ($options['show_first_last'] && $current_page < $total_pages) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $base_url . 'page=' . $total_pages . '" title="Son səhifə">' . $options['last_text'] . '</a></li>';
    }
    
    $html .= '</ul>';
    
    // Ümumi məlumat
    if ($options['show_total']) {
        $start_item = ($current_page - 1) * $per_page + 1;
        $end_item = min($current_page * $per_page, $total_items);
        $html .= '<div class="text-muted text-center mt-2 small">';
        $html .= 'Göstərilir: ' . $start_item . '-' . $end_item . ' / ' . $total_items . ' (Səhifə ' . $current_page . ' / ' . $total_pages . ')';
        $html .= '</div>';
    }
    
    $html .= '</nav>';
    
    return $html;
}

/**
 * Tarixlərin formatlanması funksiyası
 * Təkmilləşdirilmiş versiya - daha çox format seçimləri və xəta yoxlaması
 */
function formatDate($date, $format = 'd.m.Y', $default = '-')
{
    if (empty($date) || $date == '0000-00-00' || $date == '0000-00-00 00:00:00') {
        return $default;
    }
    
    try {
        if (is_string($date)) {
            $datetime = new DateTime($date);
            return $datetime->format($format);
        } elseif ($date instanceof DateTime) {
            return $date->format($format);
        }
    } catch (Exception $e) {
        error_log("Date format error: " . $e->getMessage());
    }
    
    // Əgər helpers.php-dəki format_date funksiyası mövcuddursa, onu istifadə et
    if (function_exists('format_date')) {
    return format_date($date, $format);
    }
    
    // Fallback olaraq sadə formatlaşdırma
    return date($format, strtotime($date));
}

/**
 * Məbləğlərin formatlanması funksiyası
 * Təkmilləşdirilmiş versiya - daha çox format seçimləri və xəta yoxlaması
 */
function formatAmount($amount, $decimal = 2, $currency = 'AZN', $options = [])
{
    // Parametrləri ayarlayaq
    $defaults = [
        'decimal_separator' => '.',
        'thousands_separator' => ',',
        'currency_position' => 'after', // 'before', 'after'
        'space_between' => true,
        'show_zero_decimal' => true,
        'negative_format' => 'minus', // 'minus', 'parentheses', 'color'
        'zero_value' => '0.00'
    ];
    
    $options = array_merge($defaults, $options);
    
    // Məbləği təmizləyək və ədədə çevirək
    $amount = floatval(str_replace(['$', '€', '₽', '₺', '£', '¥', '₼', ','], '', $amount));
    
    // Sıfır dəyəri
    if ($amount == 0) {
        $formatted = $options['zero_value'];
        
        if ($currency) {
            $space = $options['space_between'] ? ' ' : '';
            if ($options['currency_position'] == 'before') {
                $formatted = $currency . $space . $formatted;
            } else {
                $formatted = $formatted . $space . $currency;
            }
        }
        
        return $formatted;
    }
    
    // Mənfi dəyərlər üçün format
    $is_negative = $amount < 0;
    $abs_amount = abs($amount);
    
    // Formatlaşdırma
    if ($options['show_zero_decimal'] || fmod($abs_amount, 1) != 0) {
        $formatted = number_format($abs_amount, $decimal, $options['decimal_separator'], $options['thousands_separator']);
    } else {
        $formatted = number_format($abs_amount, 0, $options['decimal_separator'], $options['thousands_separator']);
    }
    
    // Valyuta əlavə et
    if ($currency) {
        $space = $options['space_between'] ? ' ' : '';
        if ($options['currency_position'] == 'before') {
            $formatted = $currency . $space . $formatted;
        } else {
            $formatted = $formatted . $space . $currency;
        }
    }
    
    // Mənfi dəyər formatı
    if ($is_negative) {
        switch ($options['negative_format']) {
            case 'parentheses':
                $formatted = '(' . $formatted . ')';
                break;
            case 'color':
                $formatted = '<span class="text-danger">' . $formatted . '</span>';
                break;
            case 'minus':
            default:
                $formatted = '-' . $formatted;
                break;
        }
    }
    
    // Əgər helpers.php-dəki format_amount funksiyası mövcuddursa, onu istifadə et
    if (function_exists('format_amount')) {
    return format_amount($amount, $decimal);
    }
    
    return $formatted;
}

// Parametrləri aşkarla
$employee_ids = isset($_GET['employee_ids']) ? $_GET['employee_ids'] : [];
$cash_register_ids = isset($_GET['cash_register_ids']) ? $_GET['cash_register_ids'] : [];
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-7 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = isset($_GET['per_page']) ? max(5, (int)$_GET['per_page']) : 10;
$offset = ($page - 1) * $per_page;

// Əlavə filtrlər
$filters = [
    'is_debt' => isset($_GET['is_debt']) ? $_GET['is_debt'] : '',
    'has_difference' => isset($_GET['has_difference']) ? $_GET['has_difference'] : '',
    'has_vat' => isset($_GET['has_vat']) ? $_GET['has_vat'] : '',
    'min_amount' => isset($_GET['min_amount']) ? $_GET['min_amount'] : '',
    'max_amount' => isset($_GET['max_amount']) ? $_GET['max_amount'] : '',
    'sort_by' => isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date',
    'sort_order' => isset($_GET['sort_order']) ? $_GET['sort_order'] : 'desc'
];

// Sıralama parametrləri
$sort_options = [
    'date' => 'Tarix',
    'employee_id' => 'Kassir',
    'cash_register_id' => 'Kassa',
    'total_amount' => 'Məbləğ',
    'difference' => 'Fərq',
    'pos_amount' => 'POS Məbləği',
    'cash_given' => 'Nağd Pul'
];

// Kassirlərin və kassaların siyahısını əldə edirik
$cashiers = getCashiers($conn);
$cash_registers = getCashRegisters($conn);

// İlkin dəyərlər
$reports = [];
$total_count = 0;
$error_message = '';
$success_message = '';

// Sessiyadan mesajları yoxlayaq
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Hesabatları əldə edək
if (!empty($cash_register_ids) || !empty($employee_ids)) {
    $reports = getOperations($conn, $employee_ids, $start_date, $end_date, $offset, $per_page, $cash_register_ids, $filters);
    $total_count = getReportCount($conn, $employee_ids, $start_date, $end_date, $cash_register_ids, $filters);
} else {
    $error_message = "Zəhmət olmasa hesabatları görmək üçün kassir(lər) və ya kassa(lar) seçin.";
}

// Ümumi səhifə sayı hesablanır
$total_pages = ceil($total_count / $per_page);

// Statistika parametrləri
$stat_params = [
    'start_date' => $start_date,
    'end_date' => $end_date,
    'employee_ids' => $employee_ids,
    'cash_register_ids' => $cash_register_ids
];

// Hesabatların icmalını əldə et
$summary = !empty($reports) ? getReportSummary($reports, $conn, $stat_params) : getReportSummary([], $conn, $stat_params);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Bütün hesabatları əldə et (səhifələmə olmadan)
    $all_reports = getOperations($conn, $employee_ids, $start_date, $end_date, 0, 10000, $cash_register_ids, $filters);
    $all_summary = !empty($all_reports) ? getReportSummary($all_reports, $conn, $stat_params) : [];
    exportCSV($all_reports, $all_summary);
}

// Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Bütün hesabatları əldə et (səhifələmə olmadan)
    $all_reports = getOperations($conn, $employee_ids, $start_date, $end_date, 0, 10000, $cash_register_ids, $filters);
    $all_summary = !empty($all_reports) ? getReportSummary($all_reports, $conn, $stat_params) : [];
    exportExcel($all_reports, $all_summary);
}

// PDF Export
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    // PDF export funksiyası əlavə edilə bilər
    $_SESSION['error_message'] = 'PDF eksport funksiyası hələ əlavə edilməyib.';
    header('Location: cash_report_history.php?' . http_build_query(array_merge($_GET, ['export' => null])));
    exit();
}

// Hesabat silmə
if (isset($_POST['delete_report']) && isset($_POST['report_id'])) {
    $report_id = (int)$_POST['report_id'];
    
    try {
        // Hesabatı yoxlayaq
        $check_stmt = $conn->prepare("SELECT id FROM cash_reports WHERE id = ?");
        $check_stmt->execute([$report_id]);
        
        if ($check_stmt->rowCount() > 0) {
            // Hesabatı siləcəyik
            $delete_stmt = $conn->prepare("DELETE FROM cash_reports WHERE id = ?");
            $result = $delete_stmt->execute([$report_id]);
            
            if ($result) {
                $_SESSION['success_message'] = "Hesabat uğurla silindi.";
            } else {
                $_SESSION['error_message'] = "Hesabat silinərkən xəta baş verdi.";
            }
        } else {
            $_SESSION['error_message'] = "Hesabat tapılmadı.";
        }
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Verilənlər bazası xətası: " . $e->getMessage();
    }
    
    // Əvvəlki səhifəyə qayıdaq
    header('Location: ' . $_SERVER['HTTP_REFERER']);
    exit();
}

?>

<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kassa Hesabat Tarixçəsi</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.11.5/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Select2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
    <!-- Custom CSS -->
    <link rel="stylesheet" href="style.css">
    
    <style>
        .kassir-options select[multiple] {
            height: 150px;
        }
        .summary-card {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            margin-bottom: 20px;
        }
        .report-card {
            border-radius: 0.25rem;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
        .stat-card {
            transition: all 0.3s ease;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 6px 8px rgba(0, 0, 0, 0.15);
        }
        .stat-icon {
            font-size: 2rem;
            opacity: 0.7;
        }
        .bank-receipt-stat {
            background: linear-gradient(45deg, #4158D0, #C850C0);
            color: white;
        }
        .cash-stat {
            background: linear-gradient(45deg, #0093E9, #80D0C7);
            color: white;
        }
        .additional-cash-stat {
            background: linear-gradient(45deg, #00B09B, #96C93D);
            color: white;
        }
        .pos-amount-stat {
            background: linear-gradient(45deg, #FF3CAC, #784BA0);
            color: white;
        }
        .total-amount-stat {
            background: linear-gradient(45deg, #FF9A8B, #FF6A88);
            color: white;
        }
        .debt-stat {
            background: linear-gradient(45deg, #ff7e5f, #feb47b);
            color: white;
        }
        .date-range-form {
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }
        .export-btn {
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            transition: all 0.2s ease;
        }
        .export-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .dataTables_wrapper .dataTables_filter {
            margin-bottom: 15px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        .table th {
            background-color: #f8f9fa;
            position: sticky;
            top: 0;
        }
        .badge {
            font-size: 0.85em;
            padding: 0.4em 0.6em;
        }
        .btn-group-sm > .btn, .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.82rem;
        }
        .detail-icon {
            cursor: pointer;
        }
        .negative-value {
            color: #dc3545;
        }
        .positive-value {
            color: #198754;
        }
        .filter-section {
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid #6c757d;
        }
        .filter-badge {
            margin-right: 5px;
            margin-bottom: 5px;
            font-size: 0.8rem;
            padding: 5px 10px;
            border-radius: 20px;
        }
        .filter-badge .close {
            margin-left: 5px;
            font-size: 0.9rem;
            cursor: pointer;
        }
        .select2-container--bootstrap-5 .select2-selection {
            border-radius: 0.375rem;
            border: 1px solid #ced4da;
            padding: 0.375rem 0.75rem;
            height: auto;
        }
        .advanced-filters {
            display: none;
        }
        .chart-container {
            height: 300px;
            margin-bottom: 20px;
        }
        .tab-content {
            padding: 20px 0;
        }
        .nav-tabs .nav-link {
            border-radius: 0.5rem 0.5rem 0 0;
        }
        .nav-tabs .nav-link.active {
            font-weight: bold;
            border-bottom: 3px solid #0d6efd;
        }
        .per-page-selector {
            width: 80px;
        }
        .sort-selector {
            width: 150px;
        }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>
    
    <div class="container-fluid mt-4 mb-5">
        <div class="d-sm-flex align-items-center justify-content-between mb-4">
            <h1 class="h3 mb-0 text-gray-800">
                <i class="fas fa-history me-2"></i> Kassa Hesabatları Tarixçəsi
            </h1>
            <div>
                <a href="cash_reports.php" class="btn btn-sm btn-primary shadow-sm">
                    <i class="fas fa-plus fa-sm text-white-50 me-1"></i> Yeni Hesabat Əlavə Et
                </a>
            </div>
        </div>
        
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i> <?php echo $error_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i> <?php echo $success_message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        
        <!-- Axtarış formu -->
        <form method="get" action="cash_report_history.php" class="mb-4" id="searchForm">
            <div class="card date-range-form">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0 d-flex justify-content-between align-items-center">
                        <span><i class="fas fa-search me-2"></i> Hesabat Axtarışı</span>
                        <button type="button" class="btn btn-sm btn-outline-light" id="toggleAdvancedFilters">
                            <i class="fas fa-filter me-1"></i> Əlavə Filtrlər
                        </button>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6 kassir-options">
                            <label for="employee_ids" class="form-label fw-bold">
                                <i class="fas fa-user me-1"></i> Kassir Seçimi:
                            </label>
                            <select class="form-select select2" id="employee_ids" name="employee_ids[]" multiple>
                                <?php foreach ($cashiers as $cashier): ?>
                                    <option value="<?php echo $cashier['id']; ?>" 
                                        <?php echo (in_array($cashier['id'], $employee_ids)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cashier['name']); ?>
                                        <?php if (isset($cashier['report_count']) && $cashier['report_count'] > 0): ?>
                                            (<?php echo $cashier['report_count']; ?> hesabat)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> Çoxlu seçim üçün Ctrl və ya Shift düyməsini basılı saxlayın
                            </small>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="cash_register_ids" class="form-label fw-bold">
                                <i class="fas fa-cash-register me-1"></i> Kassa Seçimi:
                            </label>
                            <select class="form-select select2" id="cash_register_ids" name="cash_register_ids[]" multiple>
                                <?php foreach ($cash_registers as $register): ?>
                                    <option value="<?php echo $register['id']; ?>" 
                                        <?php echo (in_array($register['id'], (array)$cash_register_ids)) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($register['name']); ?>
                                        <?php if (isset($register['report_count']) && $register['report_count'] > 0): ?>
                                            (<?php echo $register['report_count']; ?> hesabat)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                <i class="fas fa-info-circle"></i> Çoxlu seçim üçün Ctrl və ya Shift düyməsini basılı saxlayın
                            </small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="start_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i> Başlanğıc Tarix:
                            </label>
                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $start_date; ?>" required>
                        </div>
                        
                        <div class="col-md-6">
                            <label for="end_date" class="form-label fw-bold">
                                <i class="fas fa-calendar-alt me-1"></i> Son Tarix:
                            </label>
                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $end_date; ?>" required>
                        </div>
                    </div>
                    
                    <!-- Tarix aralığı qısa yolları -->
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="btn-group btn-group-sm" id="dateRangePresets">
                                <button type="button" class="btn btn-outline-secondary date-preset" data-days="7">Son 7 gün</button>
                                <button type="button" class="btn btn-outline-secondary date-preset" data-days="30">Son 30 gün</button>
                                <button type="button" class="btn btn-outline-secondary date-preset" data-days="90">Son 3 ay</button>
                                <button type="button" class="btn btn-outline-secondary date-preset" data-days="180">Son 6 ay</button>
                                <button type="button" class="btn btn-outline-secondary date-preset" data-days="365">Son 1 il</button>
                                <button type="button" class="btn btn-outline-secondary" id="currentMonthBtn">Cari ay</button>
                                <button type="button" class="btn btn-outline-secondary" id="previousMonthBtn">Keçən ay</button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Əlavə filtrlər -->
                    <div class="advanced-filters filter-section mt-3" id="advancedFilters">
                        <h6 class="mb-3"><i class="fas fa-sliders-h me-2"></i> Əlavə Filtrlər</h6>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="is_debt" class="form-label">Borc Durumu:</label>
                                <select class="form-select" id="is_debt" name="is_debt">
                                    <option value="" <?php echo $filters['is_debt'] === '' ? 'selected' : ''; ?>>Hamısı</option>
                                    <option value="1" <?php echo $filters['is_debt'] === '1' ? 'selected' : ''; ?>>Borclu</option>
                                    <option value="0" <?php echo $filters['is_debt'] === '0' ? 'selected' : ''; ?>>Borcsuz</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="has_difference" class="form-label">Fərq Durumu:</label>
                                <select class="form-select" id="has_difference" name="has_difference">
                                    <option value="" <?php echo $filters['has_difference'] === '' ? 'selected' : ''; ?>>Hamısı</option>
                                    <option value="1" <?php echo $filters['has_difference'] === '1' ? 'selected' : ''; ?>>Fərqli</option>
                                    <option value="0" <?php echo $filters['has_difference'] === '0' ? 'selected' : ''; ?>>Fərqsiz</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="has_vat" class="form-label">ƏDV Qeyd Olunub:</label>
                                <select class="form-select" id="has_vat" name="has_vat">
                                    <option value="" <?php echo $filters['has_vat'] === '' ? 'selected' : ''; ?>>Hamısı</option>
                                    <option value="1" <?php echo $filters['has_vat'] === '1' ? 'selected' : ''; ?>>ƏDV-li</option>
                                    <option value="0" <?php echo $filters['has_vat'] === '0' ? 'selected' : ''; ?>>ƏDV-siz</option>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="per_page" class="form-label">Səhifədə Göstərilən:</label>
                                <select class="form-select per-page-selector" id="per_page" name="per_page">
                                    <option value="10" <?php echo $per_page == 10 ? 'selected' : ''; ?>>10</option>
                                    <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                                    <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label for="min_amount" class="form-label">Min Məbləğ (AZN):</label>
                                <input type="number" class="form-control" id="min_amount" name="min_amount" value="<?php echo $filters['min_amount']; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="col-md-3">
                                <label for="max_amount" class="form-label">Max Məbləğ (AZN):</label>
                                <input type="number" class="form-control" id="max_amount" name="max_amount" value="<?php echo $filters['max_amount']; ?>" step="0.01" min="0">
                            </div>
                            
                            <div class="col-md-3">
                                <label for="sort_by" class="form-label">Sıralama:</label>
                                <select class="form-select sort-selector" id="sort_by" name="sort_by">
                                    <?php foreach ($sort_options as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo $filters['sort_by'] == $value ? 'selected' : ''; ?>>
                                            <?php echo $label; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="col-md-3">
                                <label for="sort_order" class="form-label">Sıralama İstiqaməti:</label>
                                <select class="form-select" id="sort_order" name="sort_order">
                                    <option value="desc" <?php echo $filters['sort_order'] == 'desc' ? 'selected' : ''; ?>>Azalan</option>
                                    <option value="asc" <?php echo $filters['sort_order'] == 'asc' ? 'selected' : ''; ?>>Artan</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Aktiv filtrlər -->
                        <div class="active-filters mt-3" id="activeFilters">
                            <?php
                            $active_filters = [];
                            
                            if (!empty($filters['is_debt'])) {
                                $debt_text = $filters['is_debt'] == '1' ? 'Borclu' : 'Borcsuz';
                                $active_filters[] = ['name' => 'is_debt', 'label' => "Borc: $debt_text"];
                            }
                            
                            if (!empty($filters['has_difference'])) {
                                $diff_text = $filters['has_difference'] == '1' ? 'Fərqli' : 'Fərqsiz';
                                $active_filters[] = ['name' => 'has_difference', 'label' => "Fərq: $diff_text"];
                            }
                            
                            if (!empty($filters['has_vat'])) {
                                $vat_text = $filters['has_vat'] == '1' ? 'ƏDV-li' : 'ƏDV-siz';
                                $active_filters[] = ['name' => 'has_vat', 'label' => "ƏDV: $vat_text"];
                            }
                            
                            if (!empty($filters['min_amount'])) {
                                $active_filters[] = ['name' => 'min_amount', 'label' => "Min Məbləğ: {$filters['min_amount']} AZN"];
                            }
                            
                            if (!empty($filters['max_amount'])) {
                                $active_filters[] = ['name' => 'max_amount', 'label' => "Max Məbləğ: {$filters['max_amount']} AZN"];
                            }
                            
                            if (!empty($active_filters)):
                            ?>
                                <div class="mb-2">Aktiv filtrlər:</div>
                                <?php foreach ($active_filters as $filter): ?>
                                    <span class="badge bg-info filter-badge">
                                        <?php echo $filter['label']; ?>
                                        <i class="fas fa-times close remove-filter" data-filter="<?php echo $filter['name']; ?>"></i>
                                    </span>
                                <?php endforeach; ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary ms-2 clear-all-filters">
                                    <i class="fas fa-eraser me-1"></i> Bütün filtrləri təmizlə
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search me-1"></i> Axtar
                        </button>
                        
                        <?php if (!empty($reports)): ?>
                            <div class="dropdown ms-2">
                                <button class="btn btn-success dropdown-toggle export-btn" type="button" id="exportDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-download me-1"></i> Eksport Et
                                </button>
                                <ul class="dropdown-menu" aria-labelledby="exportDropdown">
                                    <li>
                                        <a class="dropdown-item" href="cash_report_history.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'csv'])); ?>">
                                            <i class="fas fa-file-csv me-2"></i> CSV formatı
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="cash_report_history.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'excel'])); ?>">
                                            <i class="fas fa-file-excel me-2"></i> Excel formatı
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="cash_report_history.php?<?php echo http_build_query(array_merge($_GET, ['export' => 'pdf'])); ?>">
                                            <i class="fas fa-file-pdf me-2"></i> PDF formatı
                                        </a>
                                    </li>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
        
        <?php if (!empty($reports)): ?>
            <!-- Hesabat Nəticələri -->
            <div class="row mb-4">
                <!-- Ümumi statistika -->
                <div class="col-md-12 mb-4">
                    <div class="card summary-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0">
                                <i class="fas fa-chart-pie me-2"></i> Ümumi Statistika
                                <span class="badge bg-primary rounded-pill ms-2"><?php echo $total_count; ?> hesabat</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- Statistika kartları -->
                            <div class="row">
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card bank-receipt-stat">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-receipt"></i></div>
                                            <h6 class="card-title text-white-50">Bank Qəbzləri</h6>
                                            <h3 class="card-text"><?php echo formatAmount($summary['period_stats']['total_amount'] - $summary['period_stats']['total_cash'], 2, 'AZN'); ?></h3>
                                            <small class="text-white-50">Seçilmiş dövr üzrə</small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card cash-stat">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-money-bill-wave"></i></div>
                                            <h6 class="card-title text-white-50">Nağd Pul</h6>
                                            <h3 class="card-text"><?php echo formatAmount($summary['period_stats']['total_cash'], 2, 'AZN'); ?></h3>
                                            <small class="text-white-50">
                                                <?php 
                                                    $cash_percentage = $summary['period_stats']['total_amount'] > 0 
                                                        ? round(($summary['period_stats']['total_cash'] / $summary['period_stats']['total_amount']) * 100, 1) 
                                                        : 0;
                                                    echo $cash_percentage; 
                                                ?>% ümumi məbləğdən
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card pos-amount-stat">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-credit-card"></i></div>
                                            <h6 class="card-title text-white-50">POS Məbləği</h6>
                                            <h3 class="card-text"><?php echo formatAmount($summary['period_stats']['total_pos'], 2, 'AZN'); ?></h3>
                                            <small class="text-white-50">
                                                <span class="<?php echo ($summary['period_stats']['total_amount'] == $summary['period_stats']['total_pos']) ? 'text-success' : 'text-danger'; ?>">
                                                    <i class="fas <?php echo ($summary['period_stats']['total_amount'] == $summary['period_stats']['total_pos']) ? 'fa-check' : 'fa-exclamation-triangle'; ?>"></i> 
                                                    POS yoxlaması
                                                </span>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card total-amount-stat">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-coins"></i></div>
                                            <h6 class="card-title text-white-50">Ümumi Məbləğ</h6>
                                            <h3 class="card-text"><?php echo formatAmount($summary['period_stats']['total_amount'], 2, 'AZN'); ?></h3>
                                            <small class="text-white-50">
                                                Orta: <?php echo formatAmount($summary['period_stats']['avg_daily_amount'], 2, 'AZN'); ?>/gün
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card debt-stat">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-exclamation-circle"></i></div>
                                            <h6 class="card-title text-white-50">Borc Sayı</h6>
                                            <h3 class="card-text"><?php echo $summary['period_stats']['total_debts']; ?></h3>
                                            <small class="text-white-50">
                                                <?php 
                                                    $debt_percentage = $summary['period_stats']['total_records'] > 0 
                                                        ? round(($summary['period_stats']['total_debts'] / $summary['period_stats']['total_records']) * 100, 1) 
                                                        : 0;
                                                    echo $debt_percentage; 
                                                ?>% hesabatlardan
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4 col-lg-2 mb-3">
                                    <div class="card h-100 stat-card" style="background: linear-gradient(45deg, #6c757d, #495057); color: white;">
                                        <div class="card-body text-center">
                                            <div class="stat-icon mb-2"><i class="fas fa-calendar-alt"></i></div>
                                            <h6 class="card-title text-white-50">Hesabat Sayı</h6>
                                            <h3 class="card-text"><?php echo $summary['period_stats']['total_records']; ?></h3>
                                            <small class="text-white-50">
                                                Orta: <?php echo round($summary['period_stats']['avg_daily_records'], 1); ?>/gün
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Əlavə statistika -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-calculator me-2"></i> Dövr Üzrə Statistika</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped">
                                                    <tbody>
                                                        <tr>
                                                            <th>Dövr:</th>
                                                            <td><?php echo formatDate($summary['period_stats']['start_date']); ?> - <?php echo formatDate($summary['period_stats']['end_date']); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Gün Sayı:</th>
                                                            <td><?php echo $summary['period_stats']['days_in_period']; ?> gün</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Hesabat Sayı:</th>
                                                            <td><?php echo $summary['period_stats']['total_records']; ?> hesabat</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Orta Günlük Hesabat:</th>
                                                            <td><?php echo round($summary['period_stats']['avg_daily_records'], 1); ?> hesabat/gün</td>
                                                        </tr>
                                                        <tr>
                                                            <th>Ümumi Məbləğ:</th>
                                                            <td><?php echo formatAmount($summary['period_stats']['total_amount'], 2, 'AZN'); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Orta Günlük Məbləğ:</th>
                                                            <td><?php echo formatAmount($summary['period_stats']['avg_daily_amount'], 2, 'AZN'); ?>/gün</td>
                                                        </tr>
                                                        <tr>
                                                            <th>POS Məbləği (Yoxlama):</th>
                                                            <td><?php echo formatAmount($summary['period_stats']['total_pos'], 2, 'AZN'); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>Fərq (Ümumi-POS):</th>
                                                            <td class="<?php echo ($summary['period_stats']['total_amount'] - $summary['period_stats']['total_pos']) < 0 ? 'negative-value' : (($summary['period_stats']['total_amount'] - $summary['period_stats']['total_pos']) > 0 ? 'positive-value' : ''); ?>">
                                                                <?php echo formatAmount($summary['period_stats']['total_amount'] - $summary['period_stats']['total_pos'], 2, 'AZN'); ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>Ümumi Fərq:</th>
                                                            <td class="<?php echo $summary['period_stats']['total_difference'] < 0 ? 'negative-value' : ($summary['period_stats']['total_difference'] > 0 ? 'positive-value' : ''); ?>">
                                                                <?php echo formatAmount($summary['period_stats']['total_difference'], 2, 'AZN'); ?>
                                                            </td>
                                                        </tr>
                                                        <tr>
                                                            <th>EDV-li Məbləğ:</th>
                                                            <td><?php echo formatAmount($summary['period_stats']['total_vat_included'], 2, 'AZN'); ?></td>
                                                        </tr>
                                                        <tr>
                                                            <th>EDV-dən Azad Məbləğ:</th>
                                                            <td><?php echo formatAmount($summary['period_stats']['total_vat_exempt'], 2, 'AZN'); ?></td>
                                                        </tr>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i> Ödəniş Növləri</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="paymentMethodsChart"></canvas>
                                            </div>
                                            <div class="row mt-3">
                                                <div class="col-md-4 text-center">
                                                    <div class="d-flex flex-column">
                                                        <span class="text-muted">Nağd</span>
                                                        <span class="h5"><?php echo formatAmount($summary['period_stats']['total_cash'], 2, 'AZN'); ?></span>
                                                        <span class="small text-muted"><?php echo $cash_percentage; ?>%</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <div class="d-flex flex-column">
                                                        <span class="text-muted">Bank</span>
                                                        <span class="h5"><?php echo formatAmount($summary['period_stats']['total_amount'] - $summary['period_stats']['total_cash'], 2, 'AZN'); ?></span>
                                                        <span class="small text-muted"><?php echo 100 - $cash_percentage; ?>%</span>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-center">
                                                    <div class="d-flex flex-column">
                                                        <span class="text-muted">POS (Yoxlama)</span>
                                                        <span class="h5"><?php echo formatAmount($summary['period_stats']['total_pos'], 2, 'AZN'); ?></span>
                                                        <span class="small text-muted">
                                                            <span class="<?php echo ($summary['period_stats']['total_amount'] == $summary['period_stats']['total_pos']) ? 'text-success' : 'text-danger'; ?>">
                                                                <i class="fas <?php echo ($summary['period_stats']['total_amount'] == $summary['period_stats']['total_pos']) ? 'fa-check' : 'fa-exclamation-triangle'; ?>"></i> 
                                                                <?php echo ($summary['period_stats']['total_amount'] == $summary['period_stats']['total_pos']) ? 'Düzgün' : 'Fərq var'; ?>
                                                            </span>
                                                        </span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Aylıq və Həftəlik Statistika -->
                            <?php if (!empty($summary['period_stats']['monthly']) || !empty($summary['period_stats']['weekly'])): ?>
                            <div class="row mt-4">
                                <?php if (!empty($summary['period_stats']['monthly'])): ?>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i> Aylıq Statistika</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="monthlyChart"></canvas>
                                            </div>
                                            <div class="table-responsive mt-3">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Ay</th>
                                                            <th>Hesabat Sayı</th>
                                                            <th>Məbləğ</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($summary['period_stats']['monthly'] as $month): ?>
                                                            <tr>
                                                                <td><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></td>
                                                                <td><?php echo $month['count']; ?></td>
                                                                <td><?php echo formatAmount($month['total_amount'], 2, 'AZN'); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($summary['period_stats']['weekly'])): ?>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0"><i class="fas fa-calendar-week me-2"></i> Həftəlik Statistika</h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="chart-container">
                                                <canvas id="weeklyChart"></canvas>
                                            </div>
                                            <div class="table-responsive mt-3">
                                                <table class="table table-sm table-striped">
                                                    <thead>
                                                        <tr>
                                                            <th>Həftə</th>
                                                            <th>Hesabat Sayı</th>
                                                            <th>Məbləğ</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($summary['period_stats']['weekly'] as $week): ?>
                                                            <tr>
                                                                <td><?php echo formatDate($week['start_date']); ?> - <?php echo formatDate($week['end_date']); ?></td>
                                                                <td><?php echo $week['count']; ?></td>
                                                                <td><?php echo formatAmount($week['total_amount'], 2, 'AZN'); ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Hesabat Cədvəli -->
            <div class="row mb-4">
                <!-- Hesabat Nəticələri -->
                <div class="col-md-12">
                    <div class="card report-card">
                        <div class="card-header bg-light">
                            <h5 class="mb-0 d-flex justify-content-between align-items-center">
                                <span><i class="fas fa-table me-2"></i> Hesabat Nəticələri</span>
                                <span class="badge bg-primary"><?php echo $total_count; ?> hesabat tapıldı</span>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="reportTable">
                                    <thead>
                                        <tr>
                                            <th>Tarix</th>
                                            <th>Kassir</th>
                                            <th>Kassa</th>
                                            <th>Bank Qəbzləri</th>
                                            <th>Nağd Pul</th>
                                            <th>Əlavə Kassadan</th>
                                            <th>POS Məbləği</th>
                                            <th>Cəmi</th>
                                            <th>Fərq</th>
                                            <th>Ədv Qeyd Olunub?</th>
                                            <th>Borc</th>
                                            <th>Əməliyyatlar</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reports as $report): ?>
                                            <tr>
                                                <td><?php echo formatDate($report['date']); ?></td>
                                                <td><?php echo htmlspecialchars($report['name']); ?></td>
                                                <td><?php echo htmlspecialchars($report['cash_register_name'] ?? '-'); ?></td>
                                                <td>
                                                    <?php 
                                                        $bank_receipts = json_decode($report['bank_receipts'], true);
                                                        $total_bank_receipts = is_array($bank_receipts) ? array_sum($bank_receipts) : 0;
                                                        $receipt_count = is_array($bank_receipts) ? count($bank_receipts) : 0;
                                                        echo formatAmount($total_bank_receipts);
                                                        if ($receipt_count > 0) {
                                                            echo ' <span class="badge bg-secondary">' . $receipt_count . ' qəbz</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td><?php echo formatAmount($report['cash_given']); ?></td>
                                                <td>
                                                    <?php 
                                                        $additional_cash = json_decode($report['additional_cash'], true);
                                                        $total_additional = 0;
                                                        $additional_count = 0;
                                                        if (is_array($additional_cash)) {
                                                            foreach ($additional_cash as $ac) {
                                                                if (!empty($ac['amount'])) {
                                                                    $total_additional += floatval($ac['amount']);
                                                                }
                                                            }
                                                            $additional_count = count($additional_cash);
                                                        }
                                                        echo formatAmount($total_additional);
                                                        if ($additional_count > 0) {
                                                            echo ' <span class="badge bg-secondary">' . $additional_count . ' əməl.</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td><?php echo formatAmount($report['pos_amount']); ?></td>
                                                <td><?php echo formatAmount($report['total_amount']); ?></td>
                                                <td class="<?php echo $report['difference'] < 0 ? 'negative-value' : ($report['difference'] > 0 ? 'positive-value' : ''); ?>">
                                                    <?php 
                                                        echo formatAmount($report['difference']); 
                                                        if (isset($report['difference_percentage']) && $report['difference_percentage'] != 0) {
                                                            echo ' <small>(' . number_format($report['difference_percentage'], 2) . '%)</small>';
                                                        }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        $vat_included = floatval($report['vat_included'] ?? 0);
                                                        $vat_exempt = floatval($report['vat_exempt'] ?? 0);
                                                        if ($vat_included > 0 || $vat_exempt > 0):
                                                    ?>
                                                        <span class="badge bg-info" data-bs-toggle="tooltip" data-bs-placement="top" 
                                                              title="ƏDV-li: <?php echo formatAmount($vat_included); ?>, ƏDV-siz: <?php echo formatAmount($vat_exempt); ?>">
                                                            <i class="fas fa-check-circle me-1"></i> VAR
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">YOX</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($report['is_debt']): ?>
                                                        <span class="badge bg-danger">
                                                            <i class="fas fa-exclamation-circle me-1"></i> Borc
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-success">
                                                            <i class="fas fa-check me-1"></i> Düz Qəbul Edilib
                                                        </span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group">
                                                        <a href="cash_reports.php?edit=<?php echo $report['id']; ?>" class="btn btn-sm btn-warning" title="Düzəliş et">
                                                            <i class="fas fa-edit"></i>
                                                        </a>
                                                        <button type="button" class="btn btn-sm btn-info view-details" 
                                                                data-bs-toggle="modal" data-bs-target="#detailsModal"
                                                                data-id="<?php echo $report['id']; ?>" title="Ətraflı bax">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-sm btn-danger delete-report" 
                                                                data-id="<?php echo $report['id']; ?>" title="Sil">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Səhifələmə -->
                            <?php if ($total_pages > 1): ?>
                                <div class="mt-3">
                                    <?php 
                                        $base_url = 'cash_report_history.php?';
                                        $query_params = $_GET;
                                        unset($query_params['page']);
                                        $base_url .= http_build_query($query_params) . '&';
                                        
                                        $pagination_options = [
                                            'show_total' => true,
                                            'size' => 'sm',
                                            'max_visible_pages' => 5
                                        ];
                                        
                                        echo paginate($total_count, $page, $per_page, $base_url, $pagination_options);
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Hesabat Detalları Modal -->
            <div class="modal fade" id="detailsModal" tabindex="-1" aria-labelledby="detailsModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header bg-info text-white">
                            <h5 class="modal-title" id="detailsModalLabel"><i class="fas fa-info-circle me-2"></i> Hesabat Detalları</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div id="reportDetails" class="p-2">
                                <div class="text-center">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Yüklənir...</span>
                                    </div>
                                    <p>Məlumatlar yüklənir...</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Bağla</button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Silmə Təsdiqi Modal -->
            <div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-labelledby="deleteConfirmModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title" id="deleteConfirmModalLabel"><i class="fas fa-exclamation-triangle me-2"></i> Silmə Təsdiqi</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p>Bu hesabatı silmək istədiyinizə əminsiniz?</p>
                            <p class="text-danger"><strong>Diqqət:</strong> Bu əməliyyat geri qaytarıla bilməz!</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ləğv Et</button>
                            <form method="post" action="cash_report_history.php">
                                <input type="hidden" name="report_id" id="deleteReportId" value="">
                                <input type="hidden" name="delete_report" value="1">
                                <button type="submit" class="btn btn-danger">Hesabatı Sil</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>
    <!-- Select2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            // Select2 inicializasiyası
            $('.select2').select2({
                theme: 'bootstrap-5',
                width: '100%'
            });
            
            // DataTable inicializasiyası
            var reportTable = $('#reportTable').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.11.5/i18n/az.json"
                },
                "pageLength": <?php echo $per_page; ?>,
                "ordering": true,
                "paging": <?php echo ($total_pages > 1) ? 'false' : 'true'; ?>,
                "info": true,
                "searching": true,
                "responsive": true,
                "columnDefs": [
                    { "orderable": false, "targets": [11] } // Əməliyyatlar sütunu sıralanmasın
                ],
                "dom": '<"row"<"col-md-6"l><"col-md-6"f>><"table-responsive"t><"row"<"col-md-6"i><"col-md-6"p>>'
            });

            // Tooltips inicializasiyası
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl)
            });
            
            // Əlavə filtrlər düyməsi
            $('#toggleAdvancedFilters').on('click', function() {
                $('#advancedFilters').slideToggle();
                
                // Düymə mətnini dəyişdirmək
                var $button = $(this);
                if ($button.html().indexOf('Əlavə Filtrlər') > -1) {
                    $button.html('<i class="fas fa-times me-1"></i> Filtrləri Gizlət');
                } else {
                    $button.html('<i class="fas fa-filter me-1"></i> Əlavə Filtrlər');
                }
            });
            
            // Əgər aktiv filtrlər varsa, əlavə filtrləri göstər
            <?php if (!empty($active_filters)): ?>
                $('#advancedFilters').show();
                $('#toggleAdvancedFilters').html('<i class="fas fa-times me-1"></i> Filtrləri Gizlət');
            <?php endif; ?>
            
            // Filtr silmə
            $('.remove-filter').on('click', function() {
                var filterName = $(this).data('filter');
                $('#' + filterName).val('');
                $('#searchForm').submit();
            });
            
            // Bütün filtrləri təmizləmə
            $('.clear-all-filters').on('click', function() {
                $('#is_debt, #has_difference, #has_vat').val('');
                $('#min_amount, #max_amount').val('');
                $('#searchForm').submit();
            });
            
            // Modal açılanda hesabat detallarını yükləyək
            $('#detailsModal').on('show.bs.modal', function (event) {
                var button = $(event.relatedTarget);
                var reportId = button.data('id');
                var modal = $(this);
                
                // AJAX ilə hesabat detallarını yükləyək
                $.ajax({
                    url: 'get_report_details.php',
                    type: 'GET',
                    data: { id: reportId },
                    success: function(response) {
                        modal.find('#reportDetails').html(response);
                    },
                    error: function() {
                        modal.find('#reportDetails').html('<div class="alert alert-danger">Xəta baş verdi. Məlumatlar yüklənə bilmədi.</div>');
                    }
                });
            });
            
            // Silmə təsdiqi modalı
            $('.delete-report').on('click', function() {
                var reportId = $(this).data('id');
                $('#deleteReportId').val(reportId);
                $('#deleteConfirmModal').modal('show');
        });

        // Kassir və kassa seçimi qarşılıqlı əlaqəli olmalıdır
            $('#employee_ids').on('change', function() {
                // Artıq kassir və kassa seçimi arasında əlaqə yoxdur
                // Hər ikisini eyni zamanda seçmək mümkündür
            });
            
            $('#cash_register_ids').on('change', function() {
                // Artıq kassir və kassa seçimi arasında əlaqə yoxdur
                // Hər ikisini eyni zamanda seçmək mümkündür
            });
            
            // Səhifə yüklənəndə də yoxlama edirik
            (function() {
                // Artıq kassir və kassa seçimi arasında əlaqə yoxdur
                // Hər ikisini eyni zamanda seçmək mümkündür
            })();
            
            // Tarix aralığı üçün qısa yollar
            $('#dateRangePresets').on('click', '.date-preset', function(e) {
                e.preventDefault();
                var days = $(this).data('days');
                var endDate = new Date();
                var startDate = new Date();
                startDate.setDate(startDate.getDate() - days);
                
                $('#start_date').val(formatDateForInput(startDate));
                $('#end_date').val(formatDateForInput(endDate));
            });
            
            // Cari ay
            $('#currentMonthBtn').on('click', function(e) {
                e.preventDefault();
                var now = new Date();
                var startDate = new Date(now.getFullYear(), now.getMonth(), 1);
                var endDate = new Date(now.getFullYear(), now.getMonth() + 1, 0);
                
                $('#start_date').val(formatDateForInput(startDate));
                $('#end_date').val(formatDateForInput(endDate));
            });
            
            // Keçən ay
            $('#previousMonthBtn').on('click', function(e) {
                e.preventDefault();
                var now = new Date();
                var startDate = new Date(now.getFullYear(), now.getMonth() - 1, 1);
                var endDate = new Date(now.getFullYear(), now.getMonth(), 0);
                
                $('#start_date').val(formatDateForInput(startDate));
                $('#end_date').val(formatDateForInput(endDate));
            });
            
            // Tarix formatı funksiyası
            function formatDateForInput(date) {
                var d = new Date(date),
                    month = '' + (d.getMonth() + 1),
                    day = '' + d.getDate(),
                    year = d.getFullYear();
            
                if (month.length < 2) 
                    month = '0' + month;
                if (day.length < 2) 
                    day = '0' + day;
            
                return [year, month, day].join('-');
            }
            
            // Cədvəl sətirlərinə hover effekti
            $('#reportTable tbody').on('mouseenter', 'tr', function() {
                $(this).addClass('table-active');
            }).on('mouseleave', 'tr', function() {
                $(this).removeClass('table-active');
            });
            
            // Cədvəl sətirlərinə klik hadisəsi
            $('#reportTable tbody').on('click', 'tr', function(e) {
                if (!$(e.target).closest('button, a').length) {
                    var reportId = $(this).find('.view-details').data('id');
                    if (reportId) {
                        $('#detailsModal').find('.view-details[data-id="' + reportId + '"]').trigger('click');
                    }
                }
            });
            
            // Ödəniş növləri qrafiki
            <?php if (!empty($summary['period_stats'])): ?>
            var paymentMethodsCtx = document.getElementById('paymentMethodsChart').getContext('2d');
            var paymentMethodsChart = new Chart(paymentMethodsCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Nağd', 'Bank', 'Yoxlama (POS)'],
                    datasets: [{
                        data: [
                            <?php echo $summary['period_stats']['total_cash']; ?>,
                            <?php echo $summary['period_stats']['total_amount'] - $summary['period_stats']['total_cash']; ?>,
                            <?php echo $summary['period_stats']['total_pos']; ?>
                        ],
                        backgroundColor: [
                            'rgba(0, 147, 233, 0.8)',
                            'rgba(65, 88, 208, 0.8)',
                            'rgba(255, 60, 172, 0.8)'
                        ],
                        borderColor: [
                            'rgba(0, 147, 233, 1)',
                            'rgba(65, 88, 208, 1)',
                            'rgba(255, 60, 172, 1)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    var label = context.label || '';
                                    var value = context.raw || 0;
                                    var index = context.dataIndex;
                                    var total = 0;
                                    
                                    // POS məbləği yoxlama üçündür, ümumi məbləğə cəmləmə edərkən istifadə olunmur
                                    if (index === 0 || index === 1) {
                                        total = context.dataset.data[0] + context.dataset.data[1];
                                    } else {
                                        // POS məbləği üçün xüsusi format
                                        var posTotal = context.dataset.data[2];
                                        var paymentTotal = context.dataset.data[0] + context.dataset.data[1];
                                        
                                        if (posTotal === paymentTotal) {
                                            return 'POS Məbləği: ' + value.toFixed(2) + ' AZN (Düzgün ✓)';
                                        } else {
                                            var diff = Math.abs(posTotal - paymentTotal).toFixed(2);
                                            return 'POS Məbləği: ' + value.toFixed(2) + ' AZN (Fərq: ' + diff + ' AZN ⚠)';
                                        }
                                    }
                                    
                                    var percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                                    return label + ': ' + value.toFixed(2) + ' AZN (' + percentage + '%)';
                                }
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Aylıq statistika qrafiki
            <?php if (!empty($summary['period_stats']['monthly'])): ?>
            var monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
            var monthlyChart = new Chart(monthlyCtx, {
                type: 'bar',
                data: {
                    labels: [
                        <?php 
                            foreach ($summary['period_stats']['monthly'] as $month) {
                                echo "'" . date('F Y', strtotime($month['month'] . '-01')) . "', ";
                            }
                        ?>
                    ],
                    datasets: [{
                        label: 'Aylıq Məbləğ (AZN)',
                        data: [
                            <?php 
                                foreach ($summary['period_stats']['monthly'] as $month) {
                                    echo $month['total_amount'] . ", ";
                                }
                            ?>
                        ],
                        backgroundColor: 'rgba(0, 147, 233, 0.5)',
                        borderColor: 'rgba(0, 147, 233, 1)',
                        borderWidth: 1
                    }, {
                        label: 'Hesabat Sayı',
                        data: [
                            <?php 
                                foreach ($summary['period_stats']['monthly'] as $month) {
                                    echo $month['count'] . ", ";
                                }
                            ?>
                        ],
                        backgroundColor: 'rgba(255, 60, 172, 0.5)',
                        borderColor: 'rgba(255, 60, 172, 1)',
                        borderWidth: 1,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Məbləğ (AZN)'
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false
                            },
                            title: {
                                display: true,
                                text: 'Hesabat Sayı'
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
            
            // Həftəlik statistika qrafiki
            <?php if (!empty($summary['period_stats']['weekly'])): ?>
            var weeklyCtx = document.getElementById('weeklyChart').getContext('2d');
            var weeklyChart = new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: [
                        <?php 
                            foreach ($summary['period_stats']['weekly'] as $week) {
                                echo "'" . formatDate($week['start_date']) . " - " . formatDate($week['end_date']) . "', ";
                            }
                        ?>
                    ],
                    datasets: [{
                        label: 'Həftəlik Məbləğ (AZN)',
                        data: [
                            <?php 
                                foreach ($summary['period_stats']['weekly'] as $week) {
                                    echo $week['total_amount'] . ", ";
                                }
                            ?>
                        ],
                        backgroundColor: 'rgba(0, 176, 155, 0.2)',
                        borderColor: 'rgba(0, 176, 155, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true
                    }, {
                        label: 'Hesabat Sayı',
                        data: [
                            <?php 
                                foreach ($summary['period_stats']['weekly'] as $week) {
                                    echo $week['count'] . ", ";
                                }
                            ?>
                        ],
                        backgroundColor: 'rgba(255, 126, 95, 0.2)',
                        borderColor: 'rgba(255, 126, 95, 1)',
                        borderWidth: 2,
                        tension: 0.3,
                        fill: true,
                        yAxisID: 'y1'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Məbləğ (AZN)'
                            }
                        },
                        y1: {
                            beginAtZero: true,
                            position: 'right',
                            grid: {
                                drawOnChartArea: false
                            },
                            title: {
                                display: true,
                                text: 'Hesabat Sayı'
                            }
                        }
                    }
                }
            });
            <?php endif; ?>
        });
    </script>
</body>
</html> 