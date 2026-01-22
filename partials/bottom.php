<?php if (!isset($page))
    die(); ?>
<link rel="stylesheet" href="bottom.css">
<ul class="nav nav-underline nav-fill fixed-bottom bg-secondary-subtle">
    <li class="nav-item">
        <a class="nav-link <?php if ($page == "index.php")
            echo "active" ?>" href="./index.php"><div class="nawigacja">
                <img src="/src/home4.svg" width="30px" height="30px"><br>
                <span>Home</span>
            </div></a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php if ($page == "opcje.php")
            echo "active" ?>" href="./opcje.php"><div class="nawigacja">
                <img src="/src/home4.svg" width="30px" height="30px"><br>
                <span>Opcje</span>
            </div></a>
        </li>
</ul>
<script>
if ("serviceWorker" in navigator) {
  navigator.serviceWorker.register("/sw.js");
}
</script>






    <?php /*<?php if ($_SESSION['role'] == "userplus" || $_SESSION['role'] == "admin")
            echo "<li class=\"nav-item\"><a class=\"nav-link\" href=\"./nowy_zaklad.php";if ($page == "nowy_zaklad.php") echo"class = \"active\"";echo"\">Stwórz zakład</a></li>"; ?>
    <?php if ($_SESSION['role'] == "admin")
        echo '<li class="nav-item"><a class="nav-link active" href="./admin.php';if ($page == "admin.php") echo"class = \"active\"";echo"\">Admin</a></li>"; ?>
    <li class="nav-item">
        <a class="nav-link" href="./logout.php">Wyloguj się</a>
        
        
        
        
        <li class="nav-item">
            <a class="nav-link <?php if ($page == "profile.php")
            echo "active" ?>" href="./profile.php">Profil</a>
        </li>
        
        */ ?>
