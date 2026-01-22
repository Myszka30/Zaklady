<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('./config.php');
if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

// deposit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposit') {
  $amt = (float)($_POST['deposit_amount'] ?? 0);
  if ($amt <= 0) { $_SESSION['blad']="Zła kwota."; header("location: ./profile.php"); exit; }

  $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, NULL, 'deposit', ?)");
  $stmt->bind_param("id", $userId, $amt);
  $stmt->execute();
  $stmt->close();

  header("location: ./profile.php");
  exit;
}

$balance = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE user_id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();

$totalStaked = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(-SUM(amount),0) FROM ledger WHERE user_id=? AND type='stake'");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($totalStaked);
$stmt->fetch();
$stmt->close();

$stmt = $conn->prepare("
  SELECT
    b.placed_at,
    b.stake,
    b.stake_note,
    b.status,
    p.id AS post_id,
    p.title AS post_title,
    po.label AS option_label
  FROM bets b
  JOIN posts p ON p.id = b.post_id
  LEFT JOIN post_options po ON po.id = b.option_id
  WHERE b.user_id=?
  ORDER BY b.placed_at DESC
  LIMIT 200
");
$stmt->bind_param("i", $userId);
$stmt->execute();
$bets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
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

  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title">Profil: <?= htmlspecialchars($_SESSION['LOGIN']) ?></h5>
      <p class="card-text">Saldo: <?= number_format((float)$balance, 2) ?></p>
      <p class="card-text">Łącznie postawione: <?= number_format((float)$totalStaked, 2) ?></p>

      <form method="post" class="mt-3">
        <input type="hidden" name="action" value="deposit">
        <label class="form-label">Deposit</label>
        <input class="form-control" name="deposit_amount" type="number" step="0.01" min="0.01" required>
        <button class="btn btn-primary w-100 mt-2" type="submit">Dodaj</button>
      </form>

      <a class="btn btn-secondary w-100 mt-3" href="./index.php">Wróć</a>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6>Historia zakładów</h6>

      <?php if(!$bets): ?>
        <div class="text-body-secondary">Brak zakładów.</div>
      <?php else: ?>
        <div class="table-responsive">
          <table class="table table-dark table-striped align-middle">
            <thead>
              <tr>
                <th>Kiedy</th>
                <th>Zakład</th>
                <th>Opcja</th>
                <th>Kwota</th>
                <th>Rzecz</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($bets as $b): ?>
              <tr>
                <td><?= htmlspecialchars($b['placed_at']) ?></td>
                <td><a href="./post.php?id=<?= (int)$b['post_id'] ?>"><?= htmlspecialchars($b['post_title']) ?></a></td>
                <td><?= htmlspecialchars($b['option_label'] ?? '-') ?></td>
                <td><?= number_format((float)$b['stake'], 2) ?></td>
                <td><?= htmlspecialchars($b['stake_note'] ?? '-') ?></td>
                <td><?= htmlspecialchars($b['status']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

</div>
</body>
</html>
