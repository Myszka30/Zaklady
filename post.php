<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }

$postId = (int)($_GET['id'] ?? 0);
if ($postId <= 0) die("Brak id posta.");

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) die("Brak user_id w sesji (zaloguj się ponownie).");

$myRole = 'user';
$stmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($r) $myRole = $r['role'];

$isAdmin = ($myRole === 'admin');

$balance = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS bal FROM ledger WHERE user_id=?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();

$postMeta = null;
$stmt = $conn->prepare("
  SELECT p.user_id, p.is_closed, p.winning_option_id, p.allow_descriptive_bets,
         u.username AS author_username
  FROM posts p
  JOIN users u ON u.id = p.user_id
  WHERE p.id=?
  LIMIT 1
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$postMeta = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$postMeta) die("Nie ma takiego posta.");

$isAuthor = ((int)$postMeta['user_id'] === (int)$userId);
$allowDesc = ((int)($postMeta['allow_descriptive_bets'] ?? 1) === 1);
$authorUsername = $postMeta['author_username'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
  if (!$isAuthor && !$isAdmin) {
    $_SESSION['blad'] = "Brak uprawnień do zakończenia.";
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
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? LIMIT 1");
    $stmt->bind_param("ii", $winner, $postId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows;
    $stmt->close();
    if ($ok === 0) throw new Exception("Ta opcja nie należy do tego posta.");

    $stmt = $conn->prepare("UPDATE posts SET is_closed=1, winning_option_id=? WHERE id=?");
    $stmt->bind_param("ii", $winner, $postId);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE bets SET status='won'  WHERE post_id=? AND option_id=?");
    $stmt->bind_param("ii", $postId, $winner);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE bets SET status='lost' WHERE post_id=? AND option_id<>?");
    $stmt->bind_param("ii", $postId, $winner);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
    header("location: ./post.php?id=".$postId); exit;
  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./post.php?id=".$postId); exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action'])) {
  if ((int)$postMeta['is_closed'] === 1) {
    $_SESSION['blad'] = "Ten zakład jest zakończony — nie można już obstawiać.";
    header("location: ./post.php?id=".$postId); exit;
  }

  $optionId = (int)($_POST['option_id'] ?? 0);

  $amountRaw = trim((string)($_POST['amount'] ?? ''));
  $newStake = ($amountRaw === '') ? null : (float)$amountRaw;

  $note = trim($_POST['stake_note'] ?? '');
  if ($note === '') $note = null;

  if ($optionId <= 0) {
    $_SESSION['blad'] = "Zła opcja.";
    header("location: ./post.php?id=".$postId); exit;
  }

  if ($allowDesc) {
    if (($newStake === null || $newStake <= 0) && $note === null) {
      $_SESSION['blad'] = "Podaj kwotę albo stawkę opisową.";
      header("location: ./post.php?id=".$postId); exit;
    }
  } else {
    if ($newStake === null || $newStake <= 0) {
      $_SESSION['blad'] = "Musisz podać kwotę (bety opisowe są wyłączone).";
      header("location: ./post.php?id=".$postId); exit;
    }
  }

  if ($newStake !== null && $newStake < 0) {
    $_SESSION['blad'] = "Kwota nie może być ujemna.";
    header("location: ./post.php?id=".$postId); exit;
  }

  $stakeToSave = ($newStake === null) ? 0.0 : (float)$newStake;

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? AND is_open=1 LIMIT 1");
    $stmt->bind_param("ii", $optionId, $postId);
    $stmt->execute();
    $ok = $stmt->get_result()->num_rows;
    $stmt->close();
    if ($ok === 0) throw new Exception("Opcja nie istnieje albo jest zamknięta.");

    $betId = null;
    $oldStake = null;
    $oldOption = null;
    $oldNote = null;

    $stmt = $conn->prepare("SELECT id, stake, option_id, stake_note FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
    $stmt->bind_param("ii", $userId, $postId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($row = $res->fetch_assoc()) {
      $betId = (int)$row['id'];
      $oldStake = (float)$row['stake'];
      $oldOption = (int)$row['option_id'];
      $oldNote = $row['stake_note'];
    }
    $stmt->close();

    if ($betId === null) {
      $stmt = $conn->prepare("INSERT INTO bets (user_id, post_id, option_id, stake, stake_note) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param("iiids", $userId, $postId, $optionId, $stakeToSave, $note);
      $stmt->execute();
      $betId = $conn->insert_id;
      $stmt->close();

      if ($stakeToSave > 0) {
        $neg = -$stakeToSave;
        $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, ?, 'stake', ?)");
        $stmt->bind_param("iid", $userId, $betId, $neg);
        $stmt->execute();
        $stmt->close();
      }

      $stmt = $conn->prepare("
        INSERT INTO bet_changes (bet_id, user_id, old_option_id, new_option_id, old_stake, new_stake, old_note, new_note)
        VALUES (?, ?, NULL, ?, 0, ?, NULL, ?)
      ");
      $stmt->bind_param("iiids", $betId, $userId, $optionId, $stakeToSave, $note);
      $stmt->execute();
      $stmt->close();

    } else {
      $noteChanged   = ($note !== $oldNote);
      $stakeChanged  = ($stakeToSave != $oldStake);
      $optionChanged = ($optionId != $oldOption);

      if (!$noteChanged && !$stakeChanged && !$optionChanged) throw new Exception("Brak zmian.");

      $stmt = $conn->prepare("UPDATE bets SET stake=?, option_id=?, stake_note=? WHERE id=?");
      $stmt->bind_param("disi", $stakeToSave, $optionId, $note, $betId);
      $stmt->execute();
      $stmt->close();

      if ($stakeToSave != $oldStake) {
        $delta = abs($stakeToSave - $oldStake);

        if ($stakeToSave > $oldStake) {
          $neg = -$delta;
          $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, ?, 'stake', ?)");
          $stmt->bind_param("iid", $userId, $betId, $neg);
          $stmt->execute();
          $stmt->close();
        } else {
          $pos = $delta;
          $stmt = $conn->prepare("INSERT INTO ledger (user_id, bet_id, type, amount) VALUES (?, ?, 'refund', ?)");
          $stmt->bind_param("iid", $userId, $betId, $pos);
          $stmt->execute();
          $stmt->close();
        }
      }

      $stmt = $conn->prepare("
        INSERT INTO bet_changes (bet_id, user_id, old_option_id, new_option_id, old_stake, new_stake, old_note, new_note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
      ");
      $stmt->bind_param(
        "iiiiddss",
        $betId,
        $userId,
        $oldOption,
        $optionId,
        $oldStake,
        $stakeToSave,
        $oldNote,
        $note
      );
      $stmt->execute();
      $stmt->close();
    }

    $conn->commit();
    header("location: ./post.php?id=".$postId); exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = $e->getMessage();
    header("location: ./post.php?id=".$postId); exit;
  }
}

$stmt = $conn->prepare("
  SELECT p.title, p.body, p.created_at, p.is_closed, p.winning_option_id,
         u.username AS author_username
  FROM posts p
  JOIN users u ON u.id = p.user_id
  WHERE p.id=?
  LIMIT 1
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post) die("Nie ma takiego posta.");

if (($authorUsername ?? '') === '') {
  $authorUsername = $post['author_username'] ?? '';
}

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

$stmt = $conn->prepare("SELECT id, option_id, stake, stake_note, status FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
$stmt->bind_param("ii", $userId, $postId);
$stmt->execute();
$myBet = $stmt->get_result()->fetch_assoc();
$stmt->close();

$stmt = $conn->prepare("
  SELECT b.option_id, u.username, b.stake, b.stake_note, b.status, b.placed_at
  FROM bets b
  JOIN users u ON u.id = b.user_id
  WHERE b.post_id = ?
  ORDER BY b.placed_at DESC
");
$stmt->bind_param("i", $postId);
$stmt->execute();
$allBets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$byOption = [];
foreach ($options as $opt) {
  $byOption[(int)$opt['id']] = [
    'label' => $opt['label'],
    'rows' => []
  ];
}
foreach ($allBets as $b) {
  $oid = (int)$b['option_id'];
  if (!isset($byOption[$oid])) $byOption[$oid] = ['label' => 'Inna', 'rows' => []];
  $byOption[$oid]['rows'][] = $b;
}

$myChanges = [];
if ($myBet) {
  $stmt = $conn->prepare("
    SELECT old_option_id, new_option_id, old_stake, new_stake, old_note, new_note, created_at
    FROM bet_changes
    WHERE bet_id=?
    ORDER BY created_at DESC
    LIMIT 50
  ");
  $betId = (int)$myBet['id'];
  $stmt->bind_param("i", $betId);
  $stmt->execute();
  $myChanges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}

/* ADMIN: historia zmian wszystkich betów w tym poście */
$allChanges = [];
if ($isAdmin) {
  $stmt = $conn->prepare("
    SELECT
      bc.created_at,
      bu.username AS who_changed,
      betu.username AS bet_owner,
      bc.old_option_id, bc.new_option_id,
      bc.old_stake, bc.new_stake,
      bc.old_note, bc.new_note
    FROM bet_changes bc
    JOIN bets b ON b.id = bc.bet_id
    JOIN users bu ON bu.id = bc.user_id
    JOIN users betu ON betu.id = b.user_id
    WHERE b.post_id = ?
    ORDER BY bc.created_at DESC
    LIMIT 500
  ");
  $stmt->bind_param("i", $postId);
  $stmt->execute();
  $allChanges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($post['title']) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"></script>
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">
  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card mb-3">
    <div class="card-body">
      <h5 class="card-title"><?= htmlspecialchars($post['title']) ?></h5>
      <div class="text-body-secondary mb-2">Autor: <?= htmlspecialchars($authorUsername) ?></div>
      <p class="card-text"><?= nl2br(htmlspecialchars($post['body'])) ?></p>
      <p class="card-text text-body-secondary">
        Saldo: <?= number_format((float)$balance, 2) ?> |
        Status: <?= ((int)$post['is_closed']===1) ? "Zakończony" : "Otwarty" ?>
      </p>
      <?php if ($isAuthor || $isAdmin): ?>
        <a class="btn btn-outline-info w-100" href="./edit_post.php?id=<?= (int)$postId ?>">Edytuj zakład</a>
      <?php endif; ?>
    </div>
  </div>

  <div class="accordion mb-3" id="mainAccordion">
    <div class="accordion-item">
      <h2 class="accordion-header" id="headingWho">
        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapseWho" aria-expanded="true" aria-controls="collapseWho">
          Kto co obstawił
        </button>
      </h2>
      <div id="collapseWho" class="accordion-collapse collapse show" aria-labelledby="headingWho" data-bs-parent="#mainAccordion">
        <div class="accordion-body">

          <?php if (!$allBets): ?>
            <div class="text-body-secondary">Brak obstawień.</div>
          <?php else: ?>
            <?php foreach ($byOption as $oid => $group): ?>
              <?php if (!$group['rows']) continue; ?>
              <div class="card mb-2">
                <div class="card-body">
                  <div class="d-flex justify-content-between">
                    <div><strong><?= htmlspecialchars($group['label']) ?></strong></div>
                    <div class="text-body-secondary"><?= count($group['rows']) ?> osób</div>
                  </div>

                  <div class="table-responsive mt-2">
                    <table class="table table-dark table-striped align-middle mb-0">
                      <thead>
                        <tr>
                          <th>Użytkownik</th>
                          <th>Kwota</th>
                          <th>Opis</th>
                          <th>Status</th>
                          <th>Kiedy</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($group['rows'] as $b): ?>
                          <tr>
                            <td><?= htmlspecialchars($b['username']) ?></td>
                            <td><?= number_format((float)$b['stake'], 2) ?></td>
                            <td><?= htmlspecialchars($b['stake_note'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($b['status']) ?></td>
                            <td><?= htmlspecialchars($b['placed_at']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>

                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-body">
      <h6>Postaw / zmień</h6>

      <form method="post">
        <?php foreach($options as $opt): ?>
          <?php $checked = ($myBet && (int)$myBet['option_id'] === (int)$opt['id']) ? "checked" : ""; ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="radio" name="option_id" value="<?= (int)$opt['id'] ?>" <?= $checked ?> required>
            <label class="form-check-label">
              <?= htmlspecialchars($opt['label']) ?>
              <span class="text-body-secondary">(suma: <?= number_format((float)$opt['total_stake'], 2) ?>)</span>
            </label>
          </div>
        <?php endforeach; ?>

        <div class="mt-3">
          <label class="form-label">Kwota<?= $allowDesc ? " (opcjonalnie)" : "" ?></label>
          <input class="form-control" name="amount" type="number" step="0.01" min="0"
                 value="<?= $myBet ? htmlspecialchars((string)$myBet['stake']) : '' ?>"
                 <?= ((int)$post['is_closed']===1) ? "disabled" : "" ?>>
          <div class="form-text text-body-secondary">
            <?= $allowDesc ? "Możesz zostawić puste i wpisać sam opis." : "Bety opisowe są wyłączone — musisz podać kwotę." ?>
          </div>
        </div>

        <div class="mt-2">
          <label class="form-label">Stawka opisowa (opcjonalnie)</label>
          <input class="form-control" name="stake_note" maxlength="100"
                 value="<?= $myBet ? htmlspecialchars((string)($myBet['stake_note'] ?? '')) : '' ?>"
                 <?= ((int)$post['is_closed']===1) ? "disabled" : "" ?>>
        </div>

        <button class="btn btn-success w-100 mt-3" type="submit" <?= ((int)$post['is_closed']===1) ? "disabled" : "" ?>>
          <?= $myBet ? "Zapisz zmianę" : "Postaw" ?>
        </button>
      </form>
    </div>
  </div>

  <div class="accordion" id="extraAccordion">
    <div class="accordion-item">
      <h2 class="accordion-header" id="headingHistory">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseHistory" aria-expanded="false" aria-controls="collapseHistory">
          Historia zmian (Twoja)
        </button>
      </h2>
      <div id="collapseHistory" class="accordion-collapse collapse" aria-labelledby="headingHistory" data-bs-parent="#extraAccordion">
        <div class="accordion-body">
          <?php if (!$myBet): ?>
            <div class="text-body-secondary">Brak Twojego betu.</div>
          <?php elseif (!$myChanges): ?>
            <div class="text-body-secondary">Brak historii.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-dark table-striped">
                <thead>
                  <tr><th>Kiedy</th><th>Opcja</th><th>Stawka</th><th>Opis</th></tr>
                </thead>
                <tbody>
                  <?php foreach($myChanges as $c): ?>
                    <tr>
                      <td><?= htmlspecialchars($c['created_at']) ?></td>
                      <td><?= htmlspecialchars((string)($c['old_option_id'] ?? '-')) ?> → <?= htmlspecialchars((string)($c['new_option_id'] ?? '-')) ?></td>
                      <td><?= number_format((float)$c['old_stake'],2) ?> → <?= number_format((float)$c['new_stake'],2) ?></td>
                      <td><?= htmlspecialchars(($c['old_note'] ?? '-')) ?> → <?= htmlspecialchars(($c['new_note'] ?? '-')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="accordion-item">
      <h2 class="accordion-header" id="headingAdminHistory">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdminHistory" aria-expanded="false" aria-controls="collapseAdminHistory">
          Historia betów (admin)
        </button>
      </h2>
      <div id="collapseAdminHistory" class="accordion-collapse collapse" aria-labelledby="headingAdminHistory" data-bs-parent="#extraAccordion">
        <div class="accordion-body">
          <?php if (!$allChanges): ?>
            <div class="text-body-secondary">Brak historii zmian.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-dark table-striped align-middle">
                <thead>
                  <tr>
                    <th>Kiedy</th>
                    <th>Bet użytkownika</th>
                    <th>Zmienione przez</th>
                    <th>Opcja</th>
                    <th>Stawka</th>
                    <th>Opis</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($allChanges as $c): ?>
                    <tr>
                      <td><?= htmlspecialchars($c['created_at']) ?></td>
                      <td><?= htmlspecialchars($c['bet_owner']) ?></td>
                      <td><?= htmlspecialchars($c['who_changed']) ?></td>
                      <td><?= htmlspecialchars((string)($c['old_option_id'] ?? '-')) ?> → <?= htmlspecialchars((string)($c['new_option_id'] ?? '-')) ?></td>
                      <td><?= number_format((float)$c['old_stake'],2) ?> → <?= number_format((float)$c['new_stake'],2) ?></td>
                      <td><?= htmlspecialchars(($c['old_note'] ?? '-')) ?> → <?= htmlspecialchars(($c['new_note'] ?? '-')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($isAuthor || $isAdmin): ?>
    <div class="accordion-item">
      <h2 class="accordion-header" id="headingAdmin">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAdmin" aria-expanded="false" aria-controls="collapseAdmin">
          Zarządzanie (autor/admin)
        </button>
      </h2>
      <div id="collapseAdmin" class="accordion-collapse collapse" aria-labelledby="headingAdmin" data-bs-parent="#extraAccordion">
        <div class="accordion-body">
          <?php if ((int)$post['is_closed'] === 1): ?>
            <div class="alert alert-secondary mb-2">
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
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <a class="btn btn-secondary w-100 mt-3" href="./index.php">Wróć</a>
</div>
</body>
</html>
