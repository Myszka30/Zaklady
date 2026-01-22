<?php
if ($auth == "all") {
    if (!isset($_SESSION['LOGIN'])) {
        header("location: ./login.php");
        die();
    }
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    if ($userId <= 0)
        die("Brak user_id w sesji (zaloguj się ponownie).");
} else if ($auth == "admin") {
    if (!isset($_SESSION['role']) || $_SESSION['role'] != 'admin') {
        header("location: ./login.php");
        die();
    }
}else if ($auth == "userplus") {
    if (!isset($_SESSION['role']) || ($_SESSION['role'] != 'admin' && $_SESSION['role'] != 'userplus')) {
        header("location: ./login.php");
        die();
    }
}
?>