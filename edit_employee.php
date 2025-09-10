<?php

// edit_employee.php

session_start();

require 'config.php';



// İstifadəçi yoxlaması

if (!isset($_SESSION['user_id'])) {

    header('Location: login.php');

    exit();

}



// İşçi ID-ni alırıq

if (!isset($_GET['id'])) {

    header('Location: add_employee.php');

    exit();

}



$employee_id = (int)$_GET['id'];



// İşçinin məlumatlarını alırıq

$stmt = $conn->prepare("SELECT * FROM employees WHERE id = :id");

$stmt->execute([':id' => $employee_id]);

$employee = $stmt->fetch(PDO::FETCH_ASSOC);



if (!$employee) {

    echo "İşçi tapılmadı.";

    exit();

}



// Aktiv kassaların siyahısını alırıq

$cash_registers = [];

$stmt = $conn->prepare("SELECT id, name FROM cash_registers WHERE is_active = 1 ORDER BY name ASC");

$stmt->execute();

$cash_registers = $stmt->fetchAll(PDO::FETCH_ASSOC);



// Form submit olunubsa, məlumatları emal edirik

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name = trim($_POST['name']);

    $salary = (float)$_POST['salary'];

    $category = trim($_POST['category']);

    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $cash_register_id = ($category === 'kassir' && isset($_POST['cash_register_id'])) ? (int)$_POST['cash_register_id'] : null;



    // Məlumatların yoxlanması

    if (empty($name)) {

        $error_message = 'Ad daxil edilməlidir.';

    } elseif ($salary <= 0) {

        $error_message = 'Maaş müsbət bir rəqəm olmalıdır.';

    } elseif (empty($category)) {

        $error_message = 'Kateqoriya seçilməlidir.';

    } elseif ($category === 'kassir' && empty($cash_register_id)) {

        $error_message = 'Kassir üçün kassa seçilməlidir.';

    } else {

        // İşçinin məlumatlarını yeniləyirik

        $stmt = $conn->prepare("UPDATE employees SET name = :name, salary = :salary, category = :category, is_active = :is_active, cash_register_id = :cash_register_id WHERE id = :id");

        $stmt->execute([

            ':name' => $name,

            ':salary' => $salary,

            ':category' => $category,

            ':is_active' => $is_active,

            ':cash_register_id' => $cash_register_id,

            ':id' => $employee_id

        ]);



        $_SESSION['success_message'] = 'İşçi məlumatları uğurla yeniləndi.';

        header('Location: add_employee.php');

        exit();

    }

}

?>

<!DOCTYPE html>

<html lang="az">

<head>

    <meta charset="UTF-8">

    <title>İşçi Düzəliş Et</title>

    <!-- Bootstrap CSS əlavə edirik -->

    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.0/css/bootstrap.min.css">

</head>

<body>

<?php include 'includes/header.php'; ?>



<div class="container mt-4">

    <h2 class="mb-4">İşçi Düzəliş Et</h2>



    <?php if (isset($error_message)): ?>

        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>

    <?php endif; ?>



    <form method="POST" action="edit_employee.php?id=<?php echo $employee_id; ?>">

        <div class="form-group">

            <label for="name">Ad:</label>

            <input type="text" name="name" id="name" class="form-control" required value="<?php echo htmlspecialchars($employee['name']); ?>">

        </div>

        <div class="form-group">

            <label for="salary">Maaş (AZN):</label>

            <input type="number" step="0.01" name="salary" id="salary" class="form-control" required value="<?php echo htmlspecialchars($employee['salary']); ?>">

        </div>

        <div class="form-group">

            <label for="category">Kateqoriya:</label>

            <select name="category" id="category" class="form-control" required>

                <option value="">Seçin</option>

                <option value="kassir" <?php if ($employee['category'] === 'kassir') echo 'selected'; ?>>Kassir</option>

                <option value="menecer" <?php if ($employee['category'] === 'menecer') echo 'selected'; ?>>Menecer</option>

                <option value="satici" <?php if ($employee['category'] === 'satici') echo 'selected'; ?>>Satıcı</option>

                <!-- Lazım olduqda əlavə kateqoriyalar əlavə edə bilərsiniz -->

            </select>

        </div>

        <div class="form-group kassir-options" <?php if ($employee['category'] !== 'kassir') echo 'style="display:none;"'; ?>>

            <label for="cash_register_id">Kassa:</label>

            <select name="cash_register_id" id="cash_register_id" class="form-control">

                <option value="">Seçin</option>

                <?php foreach ($cash_registers as $register): ?>

                    <option value="<?php echo $register['id']; ?>" <?php if ($employee['cash_register_id'] == $register['id']) echo 'selected'; ?>>

                        <?php echo htmlspecialchars($register['name']); ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="form-group form-check">

            <input type="checkbox" name="is_active" id="is_active" class="form-check-input" <?php if ($employee['is_active']) echo 'checked'; ?>>

            <label for="is_active" class="form-check-label">Aktivdir</label>

        </div>

        <button type="submit" class="btn btn-primary">Yenilə</button>

        <a href="add_employee.php" class="btn btn-secondary">Geri Qayıt</a>

    </form>

</div>



<!-- JavaScript kitabxanalarını əlavə edirik -->

<script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>

<script>
    // Kateqoriya dəyişdikdə kassa seçim sahəsini göstər/gizlət
    document.getElementById('category').addEventListener('change', function() {
        const kassirOptions = document.querySelector('.kassir-options');
        if (this.value === 'kassir') {
            kassirOptions.style.display = 'block';
            document.getElementById('cash_register_id').setAttribute('required', 'required');
        } else {
            kassirOptions.style.display = 'none';
            document.getElementById('cash_register_id').removeAttribute('required');
        }
    });
</script>

</body>

</html>

