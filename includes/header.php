<?php
/**
 * header.php
 * Bütün səhifələrdə istifadə ediləcək ümumi HTML başlığı
 */
?>
<!DOCTYPE html>
<html lang="az">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title ?? 'Stop Shop İdarəetmə Sistemi'; ?></title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap 5 JS və Popper.js -->
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google Fonts - Poppins -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.5/css/dataTables.bootstrap5.min.css">


    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <?php if (isset($use_chart_js) && $use_chart_js): ?>
        <!-- Chart.js -->
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <!-- Chart.js Plugin: Gradient -->
        <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-gradient"></script>
    <?php endif; ?>

    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <!-- Özəl CSS -->
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="assets/css/style.css">

    <?php if (isset($additional_styles) && !empty($additional_styles)): ?>
        <!-- Əlavə CSS -->
        <?php foreach ($additional_styles as $style): ?>
            <link rel="stylesheet" href="<?php echo $style; ?>">
        <?php endforeach; ?>
    <?php endif; ?>

    <link rel="icon" href="assets/img/favicon.ico" type="image/x-icon">

    <?php if (isset($page_specific_css) && !empty($page_specific_css)): ?>
        <style>
            <?php echo $page_specific_css; ?>
        </style>
    <?php endif; ?>
</head>
<body>
<?php include 'navbar.php'; ?>

<div class="container mt-4 animate__animated animate__fadeIn animate__faster">
    <?php display_flash_messages(); ?>
</body>
</html>
