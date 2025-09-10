<?php
include "../config.php";

// Form göndərildikdə işləyəcək kod
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // Şifrəni hash edirik
    $user_role = $_POST['user_role'];

    try {
        // SQL sorğusu hazırlayırıq
        $sql = "INSERT INTO users (username, password, user_role) VALUES (:username, :password, :user_role)";
        $stmt = $conn->prepare($sql);
        // Parametrləri bağlayırıq
        $stmt->bindParam(':username', $username);
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':user_role', $user_role);
        // Sorğunu icra edirik
        $stmt->execute();
        echo "Yeni istifadəçi uğurla əlavə edildi!";
    } catch(PDOException $e) {
        echo "Xəta: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>İstifadəçi Əlavə Et</title>
</head>
<body>
    <h2>Yeni İstifadəçi Əlavə Et</h2>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
        <label for="username">İstifadəçi adı:</label>
        <input type="text" id="username" name="username" required>
        <br><br>
        <label for="password">Şifrə:</label>
        <input type="password" id="password" name="password" required>
        <br><br>
        <label for="user_role">Rol:</label>
        <select id="user_role" name="user_role">
            <option value="user">İstifadəçi</option>
            <option value="admin">Admin</option>
            <option value="muhasib">Muhasib</option>            
        </select>
        <br><br>
        <input type="submit" value="Əlavə Et">
    </form>
</body>
</html>