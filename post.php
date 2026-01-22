<?php


session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) die("Brak id posta.");

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

// saldo z ledgera
$balance = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();

// meta posta (autor, zamknięty, zwycięzca)
$postMeta = null;
$stmt = $conn->prepare("SELECT user_id, is_closed, winning_option_id FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$postMeta = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$postMeta) die("Nie ma takiego posta.");

$isAuthor = ((int)$postMeta['user_id'] === (int)$userId);

// --- ZAMYKANIE POSTA (autor wybiera zwycięską opcję) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
  if (!$isAuthor) {
    $_SESSION['blad'] = "Nie jesteś autorem tego zakładu.";
    header("location: ./post.php?id=".$postId); exit;
  }
  if ((int)$postMeta['is_closed'] === 1) {
    $_SESSION['blad'] = "Ten zakład jest już zakończony.";
    header("location: ./post.php?id=".$postId); exit;
  }

  $winner = (int)($_POST['winner_option_id'] ?? 0);
  if ($winner <= 0) {
    $_SESSION['blad'] = "Wybierz opcję wygraną.";
    header("location: ./post.php?id=".$postId); exit;
  }

  try {
    $conn->begin_transaction(); // [web:246]

    // opcja musi należeć do posta
    $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? LIMIT 1");
    $stmt->bind_param("ii", $winner, $postId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows;
    $stmt->close();
    if ($ok === 0) throw new Exception("Ta opcja nie należy do tego posta.");

    // zamknij post
    $stmt = $conn->prepare("UPDATE posts SET is_closed=1, winning_option_id=? WHERE id=?");
    $stmt->bind_param("ii", $winner, $postId);
    $stmt->execute();
    $stmt->close();

    // rozlicz bety
    $stmt = $conn->prepare("UPDATE bets SET status='won'  WHERE post_id=? AND option_id=?");
    $stmt->bind_param("ii", $postId, $winner);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE bets SET status='lost' WHERE post_id=? AND option_id<>?");
    $stmt->bind_param("ii", $postId, $winner);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("location: ./post.php?id=".$postId);
    exit;

  } catch (Throwable $e) {
    $conn->rollback(); // [web:148]
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./post.php?id=".$postId);
    exit;
  }
}

// --- OBSTAWIANIE / DOPŁATA ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  if ((int)$postMeta['is_closed'] === 1) {
    $_SESSION['blad'] = "Ten zakład jest zakończony — nie można już obstawiać.";
    header("location: ./post.php?id=".$postId); exit;
  }

  $optionId = (int)($_POST['option_id'] ?? 0);
  $newAmount = (float)($_POST['amount'] ?? 0);

  if ($optionId <= 0 || $newAmount <= 0) {
    $_SESSION['blad'] = "Zła opcja lub kwota.";
    header("location: ./post.php?id=".$postId); exit;
  }

  try {
    $conn->begin_transaction();

    // opcja musi należeć do posta i być otwarta
    $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? AND is_open=1 LIMIT 1");
    $stmt->bind_param("ii", $optionId, $postId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows;
    $stmt->close();
    if ($ok === 0) throw new Exception("Opcja nie istnieje albo jest zamknięta.");

    // czy user już ma bet na ten post?
    $betId = null;
    $oldAmount = null;
    $oldOption = null;

    $stmt = $conn->prepare("SELECT id, stake, option_id FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
    $stmt->bind_param("ii", $userId, $postId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $betId = (int)$row['id'];
      $oldAmount = (float)$row['stake'];
      $oldOption = (int)$row['option_id'];
    }
    $stmt->close();

    if ($betId === null) {
      // pierwszy zakład
      $stmt = $conn->prepare("INSERT INTO bets (user_id, post_id, option_id, stake) VALUES (?, ?, ?, ?)");
      $stmt->bind_param("iiid", $userId, $postId, $optionId, $newAmount);
      $stmt->execute();
      $betId = $conn->insert_id;
      $stmt->close();

      $neg = -$newAmount;
      $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, ?, 'stake', ?)");
      $stmt->bind_param("iid", $userId, $betId, $neg);
      $stmt->execute();
      $stmt->close();

    } else {
      // dopłata: ta sama opcja i tylko w górę
      if ($oldOption !== $optionId) throw new Exception("Nie możesz zmienić opcji (tylko dopłata do tej samej).");
      if ($newAmount < $oldAmount) throw new Exception("Nie możesz zmniejszyć kwoty.");
      if ($newAmount == $oldAmount) throw new Exception("Kwota bez zmian.");

      $delta = $newAmount - $oldAmount;

      $stmt = $conn->prepare("UPDATE bets SET stake=? WHERE id=?");
      $stmt->bind_param("di", $newAmount, $betId);
      $stmt->execute();
      $stmt->close();

      $neg = -$delta;
      $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, ?, 'stake', ?)");
      $stmt->bind_param("iid", $userId, $betId, $neg);
      $stmt->execute();
      $stmt->close();
    }

    $conn->commit();
    header("location: ./post.php?id=".$postId);
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./post.php?id=".$postId);
    exit;
  }
}

// pobierz post
$stmt = $conn->prepare("SELECT title, body, created_at, is_closed, winning_option_id FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post) die("Nie ma takiego posta.");

// opcje + suma kasy na opcję
$stmt = $conn->prepare("
  SELECT po.id, po.label, COALESCE(SUM(b.stake),0) AS total_stake
  FROM post_options po
  LEFT JOIN bets b ON b.option_id = po.id
  WHERE po.post_id = ?
  GROUP BY po.id, po.label
  ORDER BY po.id ASC
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// mój bet
$stmt = $conn->prepare("SELECT option_id, stake, status FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
$stmt->bind_param("ii", $userId, $postId);
$stmt->execute();
$myBet = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($post['title']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">
  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title"><?= htmlspecialchars($post['title']) ?></h5>
      <p class="card-text"><?= nl2br(htmlspecialchars($post['body'])) ?></p>
      <p class="card-text text-body-secondary">
        Saldo: <?= number_format((float)$balance, 2) ?> zł |
        Status: <?= ((int)$post['is_closed']===1) ? "Zakończony" : "Otwarty" ?>
      </p>
      <?php if ($myBet): ?>
        <p class="card-text text-body-secondary">Twój bet: <?= number_format((float)$myBet['stake'], 2) ?> zł (<?= htmlspecialchars($myBet['status']) ?>)</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-body">
      <h6>Opcje</h6>

      <form method="post">
        <?php foreach($options as $opt): ?>
          <?php $checked = ($myBet && (int)$myBet['option_id'] === (int)$opt['id']) ? "checked" : ""; ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="option_id" value="<?= (int)$opt['id'] ?>" <?= $checked ?> required>
            <label class="form-check-label">
              <?= htmlspecialchars($opt['label']) ?>
              <span class="text-body-secondary">(suma: <?= number_format((float)$opt['total_stake'], 2) ?> zł)</span>
            </label>
          </div>
        <?php endforeach; ?>

        <div class="mt-3">
          <label class="form-label">Kwota (jeśli dopłacasz, wpisz nową łączną kwotę)</label>
          <input class="form-control" name="amount" type="number" step="0.01" min="0.01"
                 value="<?= $myBet ? htmlspecialchars((string)$myBet['stake']) : '' ?>"
                 required <?= ((int)$post['is_closed']===1) ? "disabled" : "" ?>>
        </div>

        <button class="btn btn-success w-100 mt-3" type="submit" <?= ((int)$post['is_closed']===1) ? "disabled" : "" ?>>
          <?= $myBet ? "Dopłać / Zwiększ" : "Postaw" ?>
        </button>
      </form>

      <?php if ($isAuthor): ?>
        <hr>
        <h6>Zakończ zakład (autor)</h6>

        <?php if ((int)$post['is_closed'] === 1): ?>
          <div class="alert alert-secondary">
            Zakończony. Wygrana opcja ID: <?= (int)$post['winning_option_id'] ?>
          </div>
        <?php else: ?>
          <form method="post">
            <input type="hidden" name="action" value="close">
            <div class="mb-2">
              <label class="form-label">Opcja wygrana</label>
              <select class="form-select" name="winner_option_id" required>
                <option value="">-- wybierz --</option>
                <?php foreach($options as $opt): ?>
                  <option value="<?= (int)$opt['id'] ?>"><?= htmlspecialchars($opt['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-danger w-100" type="submit">Zakończ i rozlicz</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>

      <a class="btn btn-secondary w-100 mt-3" href="./index.php">Wróć</a>
    </div>
  </div>
</div>
</body>
</html>
