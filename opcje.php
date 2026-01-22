<?php
session_start();
require_once('./config.php');

$auth = "all";
require_once('./partials/auth.php');



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

<body data-bs-theme="dark" class="bg-secondary-subtle">
    <div class="container">
        <h1 class="text-center pb-3 pt-4">Witaj <?php echo "$_SESSION[LOGIN]"?></h1>
    <ul class="list-group ">
        <li class="list-group-item"><a href="./profile.php">Profil</a></li>
        <?php if ($_SESSION['role'] == "userplus" || $_SESSION['role'] == "admin")
            echo'<li class="list-group-item"><a href="./nowy_zaklad.php">Nowy zakład</a></li>'; ?>
        <?php if ($_SESSION['role'] == "admin")
            echo'<li class="list-group-item"><a href="./admin.php">Admin</a></li>'; ?>
        <!--<li class="list-group-item"><a href="./">Placeholder</a></li>-->
        <li class="list-group-item"><a href="./logout.php">Wyloguj się</a></li>
    </ul>
    </div>


    <?php $page = "opcje.php";
    require_once("./partials/bottom.php") ?>
</body>

</html>