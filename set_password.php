<?php
session_start();
require_once('./config.php');

$sel = $_GET['sel'] ?? '';
$tok = $_GET['tok'] ?? '';

$sel = preg_replace('/[^a-f0-9]/i', '', $sel);
$tok = preg_replace('/[^a-f0-9]/i', '', $tok);

if ($sel === '' || $tok === '' || strlen($sel) < 8 || strlen($tok) < 32) {
  http_response_code(400);
  die("Nieprawidłowy link.");
}

$tokenHash = hash('sha256', $tok);

$user = null;

$stmt = $conn->prepare("
  SELECT ui.id AS invite_id, ui.user_id, ui.token_hash, ui.expires_at, ui.used_at,
         u.username
  FROM user_invites ui
  JOIN users u ON u.id = ui.user_id
  WHERE ui.selector = ?
  LIMIT 1
");
$stmt->bind_param("s", $sel);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();

if (!$row) {
  http_response_code(404);
  die("Link nie istnieje.");
}

if (!empty($row['used_at'])) {
  http_response_code(410);
  die("Link został już użyty.");
}

if (strtotime($row['expires_at']) < time()) {
  http_response_code(410);
  die("Link wygasł.");
}

if (!hash_equals($row['token_hash'], $tokenHash)) {
  http_response_code(403);
  die("Nieprawidłowy token.");
}

$targetUsername = $row['username'];
$inviteId = (int)$row['invite_id'];
$targetUserId = (int)$row['user_id'];

/* Ustawianie hasła */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $pass1 = $_POST['pass1'] ?? '';
  $pass2 = $_POST['pass2'] ?? '';

  if ($pass1 === '' || $pass2 === '') {
    $_SESSION['blad'] = "Uzupełnij oba pola hasła.";
    header("Location: ./set_password.php?sel=" . urlencode($sel) . "&tok=" . urlencode($tok));
    exit;
  }
  if ($pass1 !== $pass2) {
    $_SESSION['blad'] = "Hasła nie są takie same.";
    header("Location: ./set_password.php?sel=" . urlencode($sel) . "&tok=" . urlencode($tok));
    exit;
  }
  if (strlen($pass1) < 6) {
    $_SESSION['blad'] = "Hasło musi mieć min. 6 znaków.";
    header("Location: ./set_password.php?sel=" . urlencode($sel) . "&tok=" . urlencode($tok));
    exit;
  }

  try {
    $conn->begin_transaction();

    $hash = password_hash($pass1, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password_hash=? WHERE id=? LIMIT 1");
    $stmt->bind_param("si", $hash, $targetUserId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE user_invites SET used_at=NOW() WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $inviteId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $_SESSION['ok'] = "Hasło ustawione. Możesz się zalogować.";
    header("Location: ./login.php");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = "Błąd: " . $e->getMessage();
    header("Location: ./set_password.php?sel=" . urlencode($sel) . "&tok=" . urlencode($tok));
    exit;
  }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Ustaw hasło</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
<div class="container mt-3" style="max-width: 520px;">

  <?php
  if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); }
  if(isset($_SESSION['ok'])) { echo "<div class='alert alert-success'>".$_SESSION['ok']."</div>"; unset($_SESSION['ok']); }
  ?>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Ustaw hasło</h5>
      <p class="card-text text-body-secondary">
        Ustawiasz hasło dla użytkownika: <b><?= htmlspecialchars($targetUsername) ?></b>
      </p>

      <form method="post">
        <div class="mb-2">
          <label class="form-label">Nowe hasło</label>
          <input class="form-control" type="password" name="pass1" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Powtórz hasło</label>
          <input class="form-control" type="password" name="pass2" required>
        </div>

        <button class="btn btn-primary w-100" type="submit">Ustaw hasło</button>
      </form>

      <div class="mt-3">
        <a class="btn btn-secondary w-100" href="./login.php">Powrót do logowania</a>
      </div>
    </div>
  </div>

</div>
</body>
</html>
