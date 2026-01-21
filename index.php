<?php
session_start();
if (!isset($_SESSION['LOGIN']))
    header("location: ./login.php");
?>

<!DOCTYPE html>
<html lang="pl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bułka Bet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
        integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI"
        crossorigin="anonymous"></script>
</head>

<body data-bs-theme="dark">


    <ul class="nav nav-underline nav-fill fixed-bottom">
        <li class="nav-item">
            <a class="nav-link active" aria-current="page" href="#">Zakłady</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="./logout.php">Wyloguj się</a>
        </li>
    </ul>


</body>

</html>