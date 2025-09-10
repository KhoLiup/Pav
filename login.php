<?php
// login.php
session_start();
require_once 'config.php';
require_once 'includes/flash_messages.php';

// Əgər istifadəçi artıq daxil olubsa, yönləndirin
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// CSRF token yaradılması
$csrf_token = generate_csrf_token();

// Form göndərilibsə, məlumatları yoxlayın
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // CSRF token yoxlanışı
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        set_flash_message('danger', 'Doğrulama xətası. Yenidən cəhd edin.');
        header("Location: login.php");
        exit();
    }

    // Giriş məlumatlarını almaq və təmizləmək
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];

    // Məlumatların doğrulanması
    if (empty($username) || empty($password)) {
        set_flash_message('danger', 'Bütün sahələri doldurun.');
    } else {
        try {
            // İstifadəçi məlumatlarını əldə etmək
            $stmt = $conn->prepare("SELECT id, password, user_role FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch();

            // İstifadəçi tapılıbsa və şifrə düzgün deyilsə
            if ($user && password_verify($password, $user['password'])) {
                // Sessiya dəyişənlərini təyin etmək
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_role'] = $user['user_role'];
                // CSRF tokenini yeniləmək
                generate_csrf_token();
                
                // Login fəaliyyətinin qeydini aparırıq
                $ip_address = $_SERVER['REMOTE_ADDR'];
                $user_agent = $_SERVER['HTTP_USER_AGENT'];
                $stmt = $conn->prepare("INSERT INTO user_logs (user_id, activity, ip_address, user_agent) VALUES (:user_id, 'login', :ip, :agent)");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':ip' => $ip_address,
                    ':agent' => $user_agent
                ]);
                
                // İstifadəçini yönləndirmək
                header("Location: dashboard.php");
                exit();
            } else {
                set_flash_message('danger', 'Yanlış istifadəçi adı və ya şifrə.');
            }
        } catch (PDOException $e) {
            error_log("Login zamanı xəta: " . $e->getMessage());
            set_flash_message('danger', 'Xəta baş verdi. Zəhmət olmasa, sonra yenidən cəhd edin.');
        }
    }
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Giriş - Stop Shop İdarəetmə Sistemi</title>
    <!-- Bootstrap CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #1f1c2c;
            --primary-light: #928dab;
            --secondary-color: #667eea;
            --accent-color: #764ba2;
            --text-color: #333;
            --light-color: #f8f9fa;
            --shadow: 0 10px 30px rgba(0,0,0,0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, var(--primary-color), var(--primary-light));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        /* Arka plan animasiyası */
        body:before, body:after {
            content: '';
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
            animation: move 15s infinite linear;
        }
        
        body:before {
            left: -200px;
            top: -200px;
            animation-delay: -5s;
        }
        
        body:after {
            right: -200px;
            bottom: -200px;
            width: 700px;
            height: 700px;
        }
        
        @keyframes move {
            0% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(100px) rotate(180deg); }
            100% { transform: translateY(0) rotate(360deg); }
        }
        
        .login-container {
            width: 100%;
            max-width: 480px;
            z-index: 10;
            padding: 20px;
        }
        
        .login-card {
            background-color: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: var(--shadow);
            padding: 40px;
            backdrop-filter: blur(10px);
            transform: translateY(0);
            transition: all 0.5s ease;
            animation: fadeIn 1s ease forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .login-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.2);
        }
        
        .login-card .form-control {
            border-radius: 12px;
            padding: 14px 15px 14px 50px;
            height: auto;
            background-color: rgba(248, 249, 250, 0.8);
            border: 1px solid #dde0e3;
            margin-bottom: 25px;
            font-size: 15px;
            transition: all 0.3s;
        }
        
        .login-card .form-control:focus {
            box-shadow: 0 0 0 4px rgba(146, 141, 171, 0.2);
            border-color: var(--primary-light);
            background-color: #fff;
        }
        
        .login-card .btn-primary {
            border-radius: 12px;
            padding: 14px;
            background: linear-gradient(to right, var(--secondary-color), var(--accent-color));
            border: none;
            font-weight: 600;
            letter-spacing: 0.5px;
            transition: all 0.3s;
            text-transform: uppercase;
        }
        
        .login-card .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        
        .input-wrapper {
            position: relative;
            margin-bottom: 5px;
        }
        
        .input-wrapper i {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-light);
            font-size: 18px;
            transition: all 0.3s;
        }
        
        .input-wrapper:focus-within i {
            color: var(--primary-color);
            transform: translateY(-50%) scale(1.1);
        }
        
        .login-logo {
            text-align: center;
            margin-bottom: 35px;
            transform: scale(1);
            transition: all 0.5s;
        }
        
        .login-logo:hover {
            transform: scale(1.05);
        }
        
        .login-logo img {
            max-width: 180px;
            height: auto;
            filter: drop-shadow(0 5px 10px rgba(0,0,0,0.1));
        }
        
        .login-title {
            text-align: center;
            margin-bottom: 35px;
            color: var(--primary-color);
            font-weight: 700;
            font-size: 28px;
            letter-spacing: -0.5px;
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 15px;
            margin-bottom: 25px;
            animation: shake 0.5s ease;
            box-shadow: 0 5px 15px rgba(0,0,0,0.05);
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        
        .alert-danger {
            background-color: #fff2f2;
            color: #e74c3c;
            border-left: 4px solid #e74c3c;
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            color: rgba(255, 255, 255, 0.7);
            font-size: 14px;
            font-weight: 300;
        }
        
        .copyright span {
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-logo">
                <img src="assets/img/logo.png" alt="Stop Shop Logo">
            </div>
            <h2 class="login-title">Stop Shop İdarəetmə Sistemi</h2>
            
            <?php display_flash_messages(); ?>
            
            <form method="POST" action="login.php" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" id="username" class="form-control" 
                           placeholder="İstifadəçi adı" required autofocus
                           autocomplete="username">
                </div>
                
                <div class="input-wrapper">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" id="password" class="form-control" 
                           placeholder="Şifrə" required
                           autocomplete="current-password">
                </div>
                
                <div class="d-grid gap-2">
                    <button type="submit" name="login" class="btn btn-primary">
                        <i class="fas fa-sign-in-alt me-2"></i>Daxil Ol
                    </button>
                </div>
            </form>
        </div>
        
        <div class="copyright mt-4 text-center">
            <small>&copy; <?php echo date('Y'); ?> <span>Stop Shop</span>. Bütün hüquqlar qorunur.</small>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
