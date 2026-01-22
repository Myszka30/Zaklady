<?php
session_start();
require_once('./config.php');
if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Opcje</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body data-bs-theme="dark">
    <?php $page = "opcje.php"; require_once("./partials/bottom.php")?>
</body>
</html>