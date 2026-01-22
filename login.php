<?php


session_start();
require_once('./config.php');

if (isset($_SESSION['LOGIN']))
    header("location: ./");
if (isset($_POST['login']) && $_POST['login'] != '' && isset($_POST['haslo']) && $_POST['haslo'] != '') {
    $login = $_POST['login'];
    $haslo = $_POST['haslo'];

    //echo (password_hash($haslo, PASSWORD_DEFAULT));
    $login = mysqli_real_escape_string($conn, $login);
    $haslo = mysqli_real_escape_string($conn, $haslo);
    $q = "SELECT password_hash, id, role FROM users WHERE username=\"$login\"";
    $wynik = mysqli_query($conn, $q);
    if (mysqli_num_rows($wynik) == 1)
        $row = mysqli_fetch_assoc($wynik);
        if(password_verify($haslo, $row["password_hash"]))
            {
                
                $_SESSION["LOGIN"] = $_POST['login'];
                $_SESSION["user_id"] = $row["id"];
                $_SESSION['role'] = $row['role'];

                header("location: ./");
            }
    else
        $_SESSION['blad'] = "Błędny login lub hasło";

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
    <div class="container">
        <div class="row">
            <div class="col-12 col-md-6 text-center mt-4">
                <h1>Witaj na bułka BET</h1>
            </div>
            <div class="col-12 col-md-6 p-3">
                <form method="POST" action="./login.php">
                    <div class="form-floating mb-3">
                        <input type="text" class="form-control" id="login" required name="login"
                            placeholder="MariuszTrąbka23">
                        <label for="login">Login</label>
                    </div>
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="pass" required name="haslo" placeholder="Hasło">
                        <label for="pass">Hasło</label>
                    </div>
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="info" required>
                        <label class="form-check-label" for="info">Akceptuje <a href="./info.html">informacje
                                ogólne</a></label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">Zaloguj się</button>
                </form>
                <?php
                if(isset($_SESSION['blad']))
                    echo("<p class=\"text-danger h1 text-center\">$_SESSION[blad]</p>");
                ?>
            </div>
            <div>
                <!--<img src="./src/bulka.jpg" class="img-fluid" alt="bułka">-->
            </div>
        </div>
</body>

</html>