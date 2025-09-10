<?php
/**
 * Davamiyyət Yoxlama Sistemi Parametrləri
 * Bu parametrlər admin panel vasitəsilə idarə edilir
 */

// Heç bir birbaşa giriş yoxdur
if (!defined('ALLOW_ACCESS')) {
    die('Birbaşa giriş qadağandır!');
}

// Davamiyyət yoxlama parametrləri
$attendance_config = array (
  'notifications_enabled' => true,
  'notification_phones' => 
  array (
    0 => '994709590034',
  ),
  'working_hours' => 
  array (
    'start' => 9,
    'end' => 20,
  ),
  'notification_start_hour' => 10,
  'working_days' => 
  array (
    0 => 1,
    1 => 2,
    2 => 3,
    3 => 4,
    4 => 5,
    5 => 6,
    6 => 7,
  ),
  'non_working_days' => 
  array (
  ),
  'notification_interval' => 5,
  'notification_template' => '⚠️ *Davamiyyət Xəbərdarlığı*

📅 Tarix: *{date}*

❗ *Diqqət:* Bugünkü davamiyyət hələ qeyd edilməyib!

Zəhmət olmasa, işçilərin davamiyyətini sistem üzərində qeyd edin.',
);

return $attendance_config;