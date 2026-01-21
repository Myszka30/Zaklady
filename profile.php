<?php
session_start();
require_once('./config.php');
if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$username = $_SESSION['LOGIN'];

// user_id
$userId = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($userId);
$stmt->fetch();
$stmt->close();
if (!$userId) die("Nie znaleziono użytkownika.");

// bilans (może być ujemny)
$balance = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE user_id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();

// wygrane/przegrane liczbowo
$won = $lost = 0;
$stmt = $conn->prepare("
  SELECT
    SUM(CASE WHEN status='won' THEN 1 ELSE 0 END) AS won_cnt,
    SUM(CASE WHEN status='lost' THEN 1 ELSE 0 END) AS lost_cnt
  FROM bets
  WHERE user_id=?
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$won  = (int)($row['won_cnt'] ?? 0);
$lost = (int)($row['lost_cnt'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Profil</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">
  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Profil: <?= htmlspecialchars($username) ?></h5>
      <p class="card-text">Bilans (ledger): <?= number_format((float)$balance, 2) ?> zł</p>
      <p class="card-text">Wygrane: <?= $won ?></p>
      <p class="card-text">Przegrane: <?= $lost ?></p>
    </div>
  </div>
</div>
<ul class="nav nav-underline nav-fill fixed-bottom">
        <li class="nav-item">
            <a class="nav-link" href="./index.php">Zakłady</a>
        </li>
        <li class="nav-item">
            <a class="nav-link active" href="./profile.php">Profil</a>
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
