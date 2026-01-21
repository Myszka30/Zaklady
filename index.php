<?php
session_start();
require_once('./config.php');
if (!isset($_SESSION['LOGIN'])) {
    header("location: ./login.php");
    die();
}
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

    <?php
    //echo password_hash("test123", PASSWORD_DEFAULT);
    if (isset($_SESSION['blad'])) {
        echo ("<p class=\"text-danger h1 text-center\">$_SESSION[blad]</p>");
        unset($_SESSION['blad']);
    }
    ?>

    <div class="container">
        <div class="row" id="bety">
            <?php

            echo <<<_EOF
            <div class="col-12 col-md-6">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"></h5>
                        <h6 class="card-subtitle mb-2 text-body-secondary">Twoje konto</h6>
                        <p class="card-text">Tutaj znajdziesz informacje o swoim koncie i aktualnych zakładach.</p>
                        <a href="#" class="card-link">Moje zakłady</a>
                        <a href="#" class="card-link">Ustawienia</a>
                    </div>
                </div>
            </div>
            _EOF;
            ?>
        </div>

    </div>
    <ul class="nav nav-underline nav-fill fixed-bottom">
        <li class="nav-item">
            <a class="nav-link active" href="./index.php">Zakłady</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="./profile.php">Profil</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php if ($_SESSION['LOGIN'] != "admin")
                echo "disabled" ?>" href="./nowy_zaklad.php">Stwórz
                    zakład</a> <!-- #TODO dla zwykłych ludzi też zrób-->
            </li>
            <li class="nav-item">
                <a class="nav-link" href="./logout.php">Wyloguj się</a>
            </li>
        </ul>


    </body>

    </html>

    <script>
        async function refreshDivs() {
            const [aktualne] = await Promise.all([
                fetch('./partials/zaklady.php', { cache: 'no-store' }).then(r => r.text()),
            ]);

            document.getElementById('bety').innerHTML = aktualne;
        }

        refreshDivs();
        setInterval(refreshDivs, 5000);
    </script>