<?php
// navbar.php

// Sessiyanın başladılması (əgər başqa fayllarda başlamayıbsa):
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['user_role'] ?? 'guest';
?>

<nav class="navbar navbar-expand-lg sticky-top">
    <div class="container-fluid px-4">

<!-- Marka (Loqo + Ad) -->
<a class="navbar-brand d-flex align-items-center" href="/dashboard.php">
    <div class="brand-logo me-2">
        <img src="../assets/img/logo.png" alt="Logo" class="logo-img" />
    </div>
    <div class="brand-text">
        <span class="brand-title">PAVILION.AZ</span>
        <span class="brand-subtitle d-none d-md-inline">İdarəetmə Sistemi</span>
    </div>
</a>

        <!-- Mobil görünüş üçün Toggler -->
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Menyular -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">

                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link <?php if($current_page == 'dashboard.php') echo 'active'; ?>"
                       href="/dashboard.php">
                        <i class="fas fa-home me-1"></i>
                        <span>Ana Səhifə</span>
                    </a>
                </li>
                
                <!-- Muhasibatliq -->
                <li class="nav-item">
                    <a class="nav-link <?php if(strpos($current_page, 'acc') === 0) echo 'active'; ?>"
                       href="/acc/index.php">
                        <i class="fas fa-landmark me-1"></i>
                        <span>Mühasibatlıq</span>
                    </a>
                </li>

                <!-- İşçilər Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php if(in_array($current_page, ['add_employee.php', 'edit_employee.php', 'employees.php'])) echo 'active'; ?>"
                       href="#" id="employeesDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-users me-1"></i>
                        <span>İşçilər</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'add_employee.php') echo 'active'; ?>"
                               href="/add_employee.php">
                                <i class="fas fa-user-plus me-2"></i>İşçi Əlavə Et
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'employees.php') echo 'active'; ?>"
                               href="/employees.php">
                                <i class="fas fa-list me-2"></i>İşçi Siyahısı
                            </a>
                        </li>
                        <?php if($user_role == 'admin' || $user_role == 'manager'): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'attendance.php') echo 'active'; ?>"
                               href="/attendance.php">
                                <i class="fas fa-user-clock me-2"></i>Davamiyyət
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Maliyyə Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php if(in_array($current_page, ['debts.php', 'cash_reports.php', 'salary.php'])) echo 'active'; ?>"
                       href="#" id="financeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-money-bill-wave me-1"></i>
                        <span>Maliyyə</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'debts.php') echo 'active'; ?>"
                               href="/debts.php">
                                <i class="fas fa-hand-holding-usd me-2"></i>Borclar
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item"
                               href="/modules/debt/index.php">
                                <i class="fas fa-money-check-alt me-2"></i>Borc İdarəetmə Modulu
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'cash_reports.php') echo 'active'; ?>"
                               href="/cash_reports.php">
                                <i class="fas fa-cash-register me-2"></i>Kassa Hesabatları
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'cash_report_hsitory.php') echo 'active'; ?>"
                               href="/cash_report_history.php">
                                <i class="fas fa-cash-register me-2"></i>Kassa Hesabatları Tarixçəsi
                            </a>
                        </li>                        
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'salary.php') echo 'active'; ?>"
                               href="/salary.php">
                                <i class="fas fa-money-check-alt me-2"></i>Maaşlar
                            </a>
                        </li>
                        <?php if($user_role == 'admin' || $user_role == 'manager'): ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'manage_firms.php') echo 'active'; ?>"
                               href="/manage_firms.php">
                                <i class="fas fa-building me-2"></i>Firmalar
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Firma Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php if(in_array($current_page, ['manage_payments.php', 'reports_firms.php'])) echo 'active'; ?>"
                       href="#" id="firmDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-briefcase me-1"></i>
                        <span>Firma</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'manage_payments.php') echo 'active'; ?>"
                               href="/manage_payments.php">
                                <i class="fas fa-hand-holding-usd me-2"></i>Ödənişlər
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'reports_firms.php') echo 'active'; ?>"
                               href="/reports_firms.php">
                                <i class="fas fa-chart-bar me-2"></i>Hesabatlar
                            </a>
                        </li>
                    </ul>
                </li>

                <!-- Yoxlama Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php if(strpos($current_page, 'checklist/') === 0 || $current_page == 'checklist') echo 'active'; ?>"
                       href="#" id="checklistDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-clipboard-check me-1"></i>
                        <span>Yoxlama</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'checklist/index.php') echo 'active'; ?>"
                               href="/checklist/index.php">
                                <i class="fas fa-home me-2"></i>Əsas Səhifə
                            </a>
                        </li>
                        <?php if($user_role == 'admin' || $user_role == 'manager'): ?>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'checklist/create.php') echo 'active'; ?>"
                               href="/checklist/create.php">
                                <i class="fas fa-plus-circle me-2"></i>Yeni Yoxlama
                            </a>
                        </li>
                        <?php endif; ?>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'checklist/reports.php') echo 'active'; ?>"
                               href="/checklist/reports.php">
                                <i class="fas fa-chart-bar me-2"></i>Hesabatlar
                            </a>
                        </li>
                        <?php if($user_role == 'admin' || $user_role == 'manager'): ?>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'checklist/monthly_reports.php') echo 'active'; ?>"
                               href="/checklist/monthly_reports.php">
                                <i class="fas fa-calendar-alt me-2"></i>Aylıq Hesabatlar
                            </a>
                        </li>
                        <?php endif; ?>
                        <?php if($user_role == 'admin'): ?>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'checklist/manage_questions.php') echo 'active'; ?>"
                               href="/checklist/manage_questions.php">
                                <i class="fas fa-question-circle me-2"></i>Sualları İdarə Et
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </li>

                <!-- Hesabatlar Dropdown -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php if(in_array($current_page, ['reports.php', 'reports_firms.php'])) echo 'active'; ?>"
                       href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-chart-bar me-1"></i>
                        <span>Hesabatlar</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'reports.php') echo 'active'; ?>"
                               href="/reports.php">
                                <i class="fas fa-users me-2"></i>İşçilər
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item <?php if($current_page == 'reports_firms.php') echo 'active'; ?>"
                               href="/reports_firms.php">
                                <i class="fas fa-building me-2"></i>Firmalar
                            </a>
                        </li>
                    </ul>
                </li>
                
                <!-- İstifadəçi Dropdown -->
                <li class="nav-item dropdown ms-2">
                    <a class="nav-link user-dropdown d-flex align-items-center p-2" href="#" id="userDropdown" 
                       role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="user-avatar me-2">
                            <i class="fas fa-user"></i>
                        </div>
                        <div class="user-info d-none d-md-block text-start">
                            <div class="user-name"><?php echo $_SESSION['user_name'] ?? 'İstifadəçi'; ?></div>
                            <div class="user-role"><?php echo translateRole($_SESSION['user_role'] ?? 'guest'); ?></div>
                        </div>
                        <i class="fas fa-chevron-down ms-md-2 ms-0"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end animate__animated animate__fadeIn animate__faster">
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="fas fa-user-circle me-2"></i>Profil
                            </a>
                        </li>
                        <li>
                            <a class="dropdown-item" href="#">
                                <i class="fas fa-cog me-2"></i>Ayarlar
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item logout-btn" href="/logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Çıxış
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<style>
/* Navbar style */
.navbar {
    background: linear-gradient(45deg, #1f1c2c, #928dab);
    padding: 0.8rem 1rem;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

.navbar-brand {
    color: #fff;
    padding: 0;
}

.brand-logo {
    display: flex;
    align-items: center;
    justify-content: center;
}

.logo-img {
    height: 38px;
    transition: transform 0.3s;
}

.navbar-brand:hover .logo-img {
    transform: scale(1.05);
}

.brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}

.brand-title {
    font-weight: 700;
    font-size: 1.2rem;
    color: #fff;
    letter-spacing: 0.5px;
}

.brand-subtitle {
    font-size: 0.7rem;
    color: rgba(255, 255, 255, 0.7);
    font-weight: 300;
}

.navbar-toggler {
    color: #fff;
    border: none;
    padding: 6px 8px;
    border-radius: 5px;
    background: rgba(255, 255, 255, 0.1);
    transition: all 0.3s;
}

.navbar-toggler:hover, .navbar-toggler:focus {
    background: rgba(255, 255, 255, 0.2);
    outline: none;
    box-shadow: none;
}

.nav-link {
    color: rgba(255, 255, 255, 0.9);
    font-weight: 500;
    padding: 0.7rem 1rem;
    border-radius: 6px;
    display: flex;
    align-items: center;
    transition: all 0.3s;
    margin: 0 2px;
}

.nav-link i {
    margin-right: 8px;
    opacity: 0.8;
    transition: all 0.3s;
}

.nav-link:hover {
    color: #fff;
    background: rgba(255, 255, 255, 0.1);
    transform: translateY(-2px);
}

.nav-link:hover i {
    opacity: 1;
}

.nav-link.active {
    background: rgba(255, 255, 255, 0.2);
    color: #fff;
    font-weight: 600;
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.dropdown-menu {
    border: none;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
    padding: 10px;
    min-width: 220px;
    margin-top: 10px;
}

.dropdown-item {
    padding: 10px 15px;
    border-radius: 6px;
    transition: all 0.2s;
    color: #1f1c2c;
    font-weight: 500;
    margin-bottom: 2px;
}

.dropdown-item:hover, .dropdown-item:focus {
    background: rgba(31, 28, 44, 0.07);
    color: #1f1c2c;
    transform: translateX(5px);
}

.dropdown-item.active {
    background: linear-gradient(45deg, #1f1c2c, #928dab);
    color: #fff;
}

.dropdown-divider {
    margin: 8px 0;
    opacity: 0.3;
}

/* İstifadəçi dropdown dizaynı */
.user-dropdown {
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 50px;
    padding: 0.5rem 1rem;
    display: flex;
    align-items: center;
}

.user-info {
    display: flex;
    align-items: center;
    padding: 5px 5px 10px;
}

.user-avatar {
    width: 40px;
    height: 40px;
    background: linear-gradient(45deg, #1f1c2c, #928dab);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    margin-right: 10px;
}

.user-details {
    display: flex;
    flex-direction: column;
}

.user-name {
    font-weight: 600;
    color: #1f1c2c;
    font-size: 0.9rem;
}

.user-role {
    font-size: 0.7rem;
    color: #6c757d;
}

.dropdown-header {
    padding: 0;
    color: inherit;
}

.logout-btn {
    color: #dc3545 !important;
    font-weight: 600;
}

.logout-btn:hover {
    background-color: #ffecef !important;
}

/* Responsive düzəlişlər */
@media (max-width: 991.98px) {
    .navbar-nav {
        padding-top: 1rem;
    }
    .nav-link {
        padding: 0.8rem 1rem;
        margin-bottom: 5px;
    }
    .dropdown-menu {
        box-shadow: none;
        padding-left: 2rem;
        border-left: 1px solid rgba(255, 255, 255, 0.2);
        border-radius: 0;
        background: transparent;
        margin-top: 0;
    }
    .dropdown-item {
        color: rgba(255, 255, 255, 0.8);
        padding: 0.7rem 1rem;
    }
    .dropdown-item:hover, .dropdown-item:focus, .dropdown-item.active {
        background: rgba(255, 255, 255, 0.15);
        color: #fff;
    }
    .dropdown-divider {
        border-color: rgba(255, 255, 255, 0.2);
    }
    .user-dropdown {
        border: none;
        border-radius: 6px;
    }
    .user-info {
        color: #fff;
    }
    .user-name, .user-role {
        color: #fff;
    }
}
</style>

<?php
// İstifadəçi adını qaytaran funksiya
function getUserName($conn, $user_id) {
    if (!$user_id) return 'İstifadəçi';
    
    try {
        $stmt = $conn->prepare("SELECT CONCAT(firstname, ' ', lastname) as fullname FROM users WHERE id = :id");
        $stmt->execute(['id' => $user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['fullname'] ?? 'İstifadəçi';
    } catch (PDOException $e) {
        return 'İstifadəçi';
    }
}

// Rolu tərcümə edən funksiya
function translateRole($role) {
    $roles = [
        'admin' => 'Administrator',
        'manager' => 'Menecer',
        'accountant' => 'Mühasib',
        'user' => 'İstifadəçi',
        'guest' => 'Qonaq'
    ];
    
    return $roles[$role] ?? 'İstifadəçi';
}
?>
