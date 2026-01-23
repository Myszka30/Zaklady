<?php
session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

$isAdmin = false;
$stmt = $conn->prepare("SELECT role, username FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) die("Nie znaleziono użytkownika.");
$isAdmin = ($row['role'] === 'admin');
$myUsername = $row['username'];

if (!$isAdmin) { http_response_code(403); die("Brak uprawnień."); }

/* CREATE USER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $username = trim($_POST['username'] ?? '');
  $role = $_POST['role'] ?? 'user';
  if (!in_array($role, ['admin','user','userplus'], true)) $role = 'user';

  $mode = $_POST['create_mode'] ?? 'invite'; // invite | password

  if ($username === '') {
    $_SESSION['blad'] = "Uzupełnij username.";
    header("location: ./admin.php"); exit;
  }

  try {
    $conn->begin_transaction();

    if ($mode === 'invite') {
      $tmpPass = bin2hex(random_bytes(16));
      $hash = password_hash($tmpPass, PASSWORD_DEFAULT);

      $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
      $stmt->bind_param("sss", $username, $hash, $role);
      if (!$stmt->execute()) throw new Exception("Nie udało się dodać konta (może username zajęty).");
      $newUserId = (int)$conn->insert_id;
      $stmt->close();

      $selector = bin2hex(random_bytes(8));
      $token = bin2hex(random_bytes(32));
      $tokenHash = hash('sha256', $token);
      $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

      $stmt = $conn->prepare("INSERT INTO user_invites (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
      $stmt->bind_param("isss", $newUserId, $selector, $tokenHash, $expiresAt);
      $stmt->execute();
      $stmt->close();

      $conn->commit();

      $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
      $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
      $base = $scheme . '://' . $host;

      $_SESSION['ok'] = "Konto utworzone. Skopiuj link (ważny 24h):";
      $_SESSION['invite_link'] = $base . "/set_password.php?sel=" . urlencode($selector) . "&tok=" . urlencode($token);

      header("location: ./admin.php");
      exit;

    } else {
      $pass1 = $_POST['pass1'] ?? '';
      if ($pass1 === '') throw new Exception("Uzupełnij hasło.");

      $hash = password_hash($pass1, PASSWORD_DEFAULT);

      $stmt = $conn->prepare("INSERT INTO users (username, password_hash, role) VALUES (?, ?, ?)");
      $stmt->bind_param("sss", $username, $hash, $role);
      if (!$stmt->execute()) throw new Exception("Nie udało się dodać konta (może username zajęty).");
      $stmt->close();

      $conn->commit();

      $_SESSION['ok'] = "Konto utworzone.";
      header("location: ./admin.php");
      exit;
    }

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./admin.php");
    exit;
  }
}

/* GENERATE INVITE FOR EXISTING USER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite') {
  $targetId = (int)($_POST['invite_user_id'] ?? 0);

  if ($targetId <= 0) { $_SESSION['blad'] = "Złe ID."; header("location: ./admin.php"); exit; }
  if ($targetId === $userId) { $_SESSION['blad'] = "Nie musisz generować linku dla siebie."; header("location: ./admin.php"); exit; }

  try {
    $conn->begin_transaction();

    // unieważnij poprzednie aktywne linki
    $stmt = $conn->prepare("UPDATE user_invites SET used_at=NOW() WHERE user_id=? AND used_at IS NULL");
    $stmt->bind_param("i", $targetId);
    $stmt->execute();
    $stmt->close();

    $selector = bin2hex(random_bytes(8));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = (new DateTime('+24 hours'))->format('Y-m-d H:i:s');

    $stmt = $conn->prepare("INSERT INTO user_invites (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $targetId, $selector, $tokenHash, $expiresAt);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = $scheme . '://' . $host;

    $_SESSION['ok'] = "Wygenerowano link (ważny 24h):";
    $_SESSION['invite_link'] = $base . "/set_password.php?sel=" . urlencode($selector) . "&tok=" . urlencode($token);

    header("location: ./admin.php");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./admin.php");
    exit;
  }
}

/* UPDATE ROLE */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_role') {
  $targetId = (int)($_POST['role_user_id'] ?? 0);
  $newRole = $_POST['new_role'] ?? 'user';

  if ($targetId <= 0) { $_SESSION['blad'] = "Złe ID."; header("location: ./admin.php"); exit; }
  if ($targetId === $userId) { $_SESSION['blad'] = "Nie zmieniaj roli sam sobie."; header("location: ./admin.php"); exit; }

  if (!in_array($newRole, ['admin','user','userplus'], true)) {
    $_SESSION['blad'] = "Nieprawidłowa rola.";
    header("location: ./admin.php"); exit;
  }

  try {
    $stmt = $conn->prepare("UPDATE users SET role=? WHERE id=? LIMIT 1");
    $stmt->bind_param("si", $newRole, $targetId);
    $stmt->execute();
    $stmt->close();

    $_SESSION['ok'] = "Zmieniono rolę.";
    header("location: ./admin.php"); exit;
  } catch (Throwable $e) {
    $_SESSION['blad'] = "Błąd zmiany roli: " . $e->getMessage();
    header("location: ./admin.php"); exit;
  }
}

/* DELETE USER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $delId = (int)($_POST['delete_user_id'] ?? 0);

  if ($delId <= 0) { $_SESSION['blad'] = "Złe ID."; header("location: ./admin.php"); exit; }
  if ($delId === $userId) { $_SESSION['blad'] = "Nie możesz usunąć samego siebie."; header("location: ./admin.php"); exit; }

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("DELETE FROM ledger WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM bets WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM posts WHERE user_id=?");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param("i", $delId);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("location: ./admin.php");
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = "Błąd usuwania: " . $e->getMessage();
    header("location: ./admin.php");
    exit;
  }
}

/* LIST USERS (z aktywnością) */
$stmt = $conn->prepare("SELECT id, username, role, max_devices, last_active_at FROM users ORDER BY id ASC");
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* LIST INVITES (kto użył i kiedy) */
$stmt = $conn->prepare("
  SELECT ui.id, ui.user_id, ui.selector, ui.expires_at, ui.used_at,
         u.username
  FROM user_invites ui
  JOIN users u ON u.id = ui.user_id
  ORDER BY ui.id DESC
  LIMIT 200
");
$stmt->execute();
$invites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Panel admina</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">

  <?php
  if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); }
  if(isset($_SESSION['ok'])) { echo "<div class='alert alert-success'>".$_SESSION['ok']."</div>"; unset($_SESSION['ok']); }
  if(isset($_SESSION['invite_link'])) {
    $l = htmlspecialchars($_SESSION['invite_link']);
    echo "<div class='alert alert-info'><div class='mb-2'>Link do ustawienia hasła:</div><input class='form-control' value='$l' readonly></div>";
    unset($_SESSION['invite_link']);
  }
  ?>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Panel admina</h5>
      <p class="card-text text-body-secondary">Zalogowany: <?= htmlspecialchars($myUsername) ?> (admin)</p>
      <a class="btn btn-secondary w-100" href="./index.php">Wróć</a>
    </div>
  </div>

  <div class="accordion" id="adminAccordion">
    <div class="accordion-item">
      <h2 class="accordion-header" id="hCreate">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#cCreate" aria-expanded="true" aria-controls="cCreate">
          Dodaj konto
        </button>
      </h2>
      <div id="cCreate" class="accordion-collapse collapse show" aria-labelledby="hCreate" data-bs-parent="#adminAccordion">
        <div class="accordion-body">
          <form method="post" id="createUserForm">
            <input type="hidden" name="action" value="create">

            <div class="mb-2">
              <label class="form-label">Username</label>
              <input class="form-control" name="username" maxlength="50" required>
            </div>

            <div class="mb-2">
              <label class="form-label">Tryb utworzenia</label>
              <select class="form-select" name="create_mode" id="create_mode">
                <option value="invite" selected>Wyślij link do ustawienia hasła</option>
                <option value="password">Ustaw hasło teraz</option>
              </select>
            </div>

            <div class="mb-2" id="passwordWrap" style="display:none;">
              <label class="form-label">Hasło</label>
              <input class="form-control" name="pass1" type="password">
            </div>

            <div class="mb-2">
              <label class="form-label">Rola</label>
              <select class="form-select" name="role">
                <option value="user" selected>user</option>
                <option value="userplus">userplus</option>
                <option value="admin">admin</option>
              </select>
            </div>

            <button class="btn btn-primary w-100" type="submit">Utwórz</button>
          </form>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="hUsers">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cUsers" aria-expanded="false" aria-controls="cUsers">
          Użytkownicy
        </button>
      </h2>
      <div id="cUsers" class="accordion-collapse collapse" aria-labelledby="hUsers" data-bs-parent="#adminAccordion">
        <div class="accordion-body">
          <div class="table-responsive">
            <table class="table table-dark table-striped align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Username</th>
                  <th>Rola</th>
                  <th>max_devices</th>
                  <th>Ostatnia aktywność</th>
                  <th>Akcje</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($users as $u): ?>
                <tr>
                  <td><?= (int)$u['id'] ?></td>
                  <td><?= htmlspecialchars($u['username']) ?></td>
                  <td>
                    <?php if ((int)$u['id'] === $userId): ?>
                      <span class="text-body-secondary"><?= htmlspecialchars($u['role']) ?></span>
                    <?php else: ?>
                      <form method="post" class="d-flex gap-2">
                        <input type="hidden" name="action" value="update_role">
                        <input type="hidden" name="role_user_id" value="<?= (int)$u['id'] ?>">

                        <select class="form-select form-select-sm" name="new_role">
                          <option value="user" <?= ($u['role']==='user'?'selected':'') ?>>user</option>
                          <option value="userplus" <?= ($u['role']==='userplus'?'selected':'') ?>>userplus</option>
                          <option value="admin" <?= ($u['role']==='admin'?'selected':'') ?>>admin</option>
                        </select>

                        <button class="btn btn-success btn-sm" type="submit">Zapisz</button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars((string)$u['max_devices']) ?></td>
                  <td><?= htmlspecialchars((string)($u['last_active_at'] ?? '-')) ?></td>
                  <td>
                    <?php if ((int)$u['id'] === $userId): ?>
                      <span class="text-body-secondary">to Ty</span>
                    <?php else: ?>
                      <div class="d-flex gap-2">
                        <form method="post">
                          <input type="hidden" name="action" value="invite">
                          <input type="hidden" name="invite_user_id" value="<?= (int)$u['id'] ?>">
                          <button class="btn btn-outline-info btn-sm" type="submit">Nowy link</button>
                        </form>

                        <form method="post" onsubmit="return confirm('Usunąć konto?');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="delete_user_id" value="<?= (int)$u['id'] ?>">
                          <button class="btn btn-danger btn-sm" type="submit">Usuń</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="accordion-item">
      <h2 class="accordion-header" id="hInvites">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#cInvites" aria-expanded="false" aria-controls="cInvites">
          Linki do ustawienia hasła (kto użył)
        </button>
      </h2>
      <div id="cInvites" class="accordion-collapse collapse" aria-labelledby="hInvites" data-bs-parent="#adminAccordion">
        <div class="accordion-body">
          <div class="table-responsive">
            <table class="table table-dark table-striped align-middle">
              <thead>
                <tr>
                  <th>ID</th>
                  <th>Użytkownik</th>
                  <th>Wygasa</th>
                  <th>Użyty</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach($invites as $iv): ?>
                <tr>
                  <td><?= (int)$iv['id'] ?></td>
                  <td><?= htmlspecialchars($iv['username']) ?> (ID: <?= (int)$iv['user_id'] ?>)</td>
                  <td><?= htmlspecialchars((string)$iv['expires_at']) ?></td>
                  <td><?= htmlspecialchars((string)($iv['used_at'] ?? '-')) ?></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

  </div>
</div>

<script>
(function () {
  const sel = document.getElementById('create_mode');
  const wrap = document.getElementById('passwordWrap');
  const pass = wrap.querySelector('input[name="pass1"]');

  function sync() {
    if (sel.value === 'password') {
      wrap.style.display = '';
      pass.required = true;
    } else {
      wrap.style.display = 'none';
      pass.required = false;
      pass.value = '';
    }
  }

  sel.addEventListener('change', sync);
  sync();
})();
</script>

<?php $page = "admin.php"; require("./partials/bottom.php")?>
</body>
</html>
