<?php if (!isset($page))
    die(); ?>
<ul class="nav nav-underline nav-fill fixed-bottom">
    <li class="nav-item">
        <a class="nav-link <?php if ($page == "index.php")
            echo "active" ?>" href="./index.php">Zakłady</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php if ($page == "opcje.php")
            echo "active" ?>" href="./opcje.php">Opcje</a>
        </li>
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
    </li>
</ul>