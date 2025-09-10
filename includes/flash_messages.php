<?php
// includes/flash_messages.php

if (!function_exists('set_flash_message')) {
    function set_flash_message($type, $message) {
        if (!isset($_SESSION['flash_messages'])) {
            $_SESSION['flash_messages'] = [];
        }
        $_SESSION['flash_messages'][$type][] = $message;
    }
}

if (!function_exists('display_flash_messages')) {
    function display_flash_messages() {
        if (isset($_SESSION['flash_messages'])) {
            foreach ($_SESSION['flash_messages'] as $type => $messages) {
                foreach ($messages as $message) {
                    echo '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">';
                    echo htmlspecialchars($message);
                    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
                    echo '<span aria-hidden="true">&times;</span>';
                    echo '</button>';
                    echo '</div>';
                }
            }
            unset($_SESSION['flash_messages']);
        }
    }
}
?>
