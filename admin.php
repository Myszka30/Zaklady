<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

// sprawdź rolę w bazie
$isAdmin = false;
$stmt = $conn->prepare("SELECT role, username FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) die("Nie znaleziono użytkownika.");
$isAdmin = ($row['role'] === 'admin');
$myUsername = $row['username'];

if (!$isAdmin) {
  http_response_code(403);
  die("Brak uprawnień.");
}

// dodawanie konta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $username = trim($_POST['username'] ?? '');
  $pass1 = $_POST['pass1'] ?? '';
  $role = $_POST['role'] ?? 'user';

  if ($username === '' || $pass1 === '') {
    $_SESSION['blad'] = "Uzupełnij username i hasło.";
    header("location: ./admin.php"); exit;
  }
  if (!in_array($role, ['admin','user'], true)) $role = 'user';

  $hash = password_hash($pass1, PASSWORD_DEFAULT); // [web:267]

  $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
  $stmt->bind_param("sss", $username, $hash, $role);
  if (!$stmt->execute()) {
    $_SESSION['blad'] = "Nie udało się dodać konta (może username zajęty).";
  }
  $stmt->close();

  header("location: ./admin.php");
  exit;
}

// usuwanie konta
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $delId = (int)($_POST['delete_user_id'] ?? 0);

  if ($delId <= 0) {
    $_SESSION['blad'] = "Złe ID.";
    header("location: ./admin.php"); exit;
  }
  if ($delId === $userId) {
    $_SESSION['blad'] = "Nie możesz usunąć samego siebie.";
    header("location: ./admin.php"); exit;
  }

  // UWAGA: masz FK z posts ON DELETE CASCADE, ale bets/ledger mają ON DELETE RESTRICT/SET NULL,
  // więc usunięcie usera może się nie udać, jeśli ma bety/ledger. To normalne [web:74].
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $delId = (int)($_POST['delete_user_id'] ?? 0);

  if ($delId <= 0) { $_SESSION['blad']="Złe ID."; header("location: ./admin.php"); exit; }
  if ($delId === $userId) { $_SESSION['blad']="Nie możesz usunąć samego siebie."; header("location: ./admin.php"); exit; }

  try {
    $conn->begin_transaction(); // [web:246]

    // 1) ledger (ma FK do bets, ale też do users) – usuń najpierw
    $stmt = $conn->prepare("DELETE FROM ledger WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    // 2) bets
    $stmt = $conn->prepare("DELETE FROM bets WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    // 3) posts (u Ciebie i tak ON DELETE CASCADE z users -> posts, ale zostawiam jawnie)
    $stmt = $conn->prepare("DELETE FROM posts WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    // 4) user
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    $conn->commit(); // [web:248]
  } catch (Throwable $e) {
    $conn->rollback(); // [web:148]
    $_SESSION['blad'] = "Błąd usuwania: " . $e->getMessage();
  }

  header("location: ./admin.php");
  exit;
}

  $stmt->bind_param("i", $delId);
  if (!$stmt->execute()) {
    $_SESSION['blad'] = "Nie udało się usunąć (użytkownik ma powiązane dane).";
  }
  $stmt->close();

  header("location: ./admin.php");
  exit;
}

// lista kont
$stmt = $conn->prepare("SELECT id, username, role, max_devices FROM users ORDER BY id ASC");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admina</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">

  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Panel admina</h5>
      <p class="card-text text-body-secondary">Zalogowany: <?= htmlspecialchars($myUsername) ?> (admin)</p>
      <a class="btn btn-secondary w-100" href="./index.php">Wróć</a>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h6>Dodaj konto</h6>
      <form method="post">
        <input type="hidden" name="action" value="create">

        <div class="mb-2">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" maxlength="50" required>
        </div>

        <div class="mb-2">
          <label class="form-label">Hasło</label>
          <input class="form-control" name="pass1" type="password" required>
        </div>

        <div class="mb-2">
          <label class="form-label">Rola</label>
          <select class="form-select" name="role">
            <option value="user" selected>user</option>
            <option value="admin">admin</option>
          </select>
        </div>

        <button class="btn btn-primary w-100" type="submit">Utwórz</button>
      </form>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6>Konta</h6>

      <div class="table-responsive">
        <table class="table table-dark table-striped align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Username</th>
              <th>Rola</th>
              <th>max_devices</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td><?= (int)$u['id'] ?></td>
              <td><?= htmlspecialchars($u['username']) ?></td>
              <td><?= htmlspecialchars($u['role']) ?></td>
              <td><?= htmlspecialchars((string)$u['max_devices']) ?></td>
              <td>
                <?php if ((int)$u['id'] === $userId): ?>
                  <span class="text-body-secondary">to Ty</span>
                <?php else: ?>
                  <form method="post" onsubmit="return confirm('Usunąć konto?');">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                    <button class="btn btn-danger btn-sm" type="submit">Usuń</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="text-body-secondary">
        Jeśli usuwanie nie działa, to dlatego że FK blokuje usunięcie użytkownika z powiązanymi betami/ledger (to chroni historię) [web:74].
      </div>
    </div>
  </div>

</div>
</body>
</html>
