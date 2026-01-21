<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) {
    header("location: ./login.php");
    exit;
}

$postId = (int) ($_GET['id'] ?? 0);
if ($postId <= 0)
    die("Brak id posta.");

$username = $_SESSION['LOGIN'];

// user_id
$userId = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($userId);
$stmt->fetch();
$stmt->close();
if (!$userId)
    die("Nie znaleziono użytkownika.");

// saldo z ledgera
$balance = 0.0;
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) FROM ledger WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($balance);
$stmt->fetch();
$stmt->close();
// --- sprawdź czy user jest autorem posta (potrzebne do zamykania) ---
$postMeta = null;
$stmt = $conn->prepare("SELECT user_id, is_closed, winning_option_id FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$postMeta = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$postMeta)
    die("Nie ma takiego posta.");

$isAuthor = ((int) $postMeta['user_id'] === (int) $userId);

// --- ZAMYKANIE POSTA (autor wybiera zwycięską opcję) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    if (!$isAuthor) {
        $_SESSION['blad'] = "Nie jesteś autorem tego zakładu.";
        header("location: ./post.php?id=" . $postId);
        exit;
    }
    if ((int) $postMeta['is_closed'] === 1) {
        $_SESSION['blad'] = "Ten zakład jest już zakończony.";
        header("location: ./post.php?id=" . $postId);
        exit;
    }

    $winner = (int) ($_POST['winner_option_id'] ?? 0);
    if ($winner <= 0) {
        $_SESSION['blad'] = "Wybierz opcję wygraną.";
        header("location: ./post.php?id=" . $postId);
        exit;
    }

    try {
        $conn->begin_transaction(); // [web:246]

        // Upewnij się, że zwycięska opcja należy do tego posta
        $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? LIMIT 1");
        $stmt->bind_param("ii", $winner, $postId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows;
        $stmt->close();
        if ($ok === 0)
            throw new Exception("Ta opcja nie należy do tego posta.");

        // Zamknij post
        $stmt = $conn->prepare("UPDATE posts SET is_closed=1, winning_option_id=? WHERE id=?");
        $stmt->bind_param("ii", $winner, $postId);
        $stmt->execute();
        $stmt->close();

        // Rozlicz bety
        $stmt = $conn->prepare("UPDATE bets SET status='won'  WHERE post_id=? AND option_id=?");
        $stmt->bind_param("ii", $postId, $winner);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("UPDATE bets SET status='lost' WHERE post_id=? AND option_id<>?");
        $stmt->bind_param("ii", $postId, $winner);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        header("location: ./post.php?id=" . $postId);
        exit;
    } catch (Throwable $e) {
        $conn->rollback(); // [web:148]
        $_SESSION['blad'] = $e->getMessage();
        header("location: ./post.php?id=" . $postId);
        exit;
    }
}

// obstawianie / dopłata
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $optionId = (int) ($_POST['option_id'] ?? 0);
    $newAmount = (float) ($_POST['amount'] ?? 0);

    if ($optionId <= 0 || $newAmount <= 0) {
        $_SESSION['blad'] = "Zła opcja lub kwota.";
        header("location: ./post.php?id=" . $postId);
        exit;
    }

    try {
        $conn->begin_transaction(); // [web:125][web:145]

        // sprawdź, czy option należy do tego posta i jest otwarta
        $stmt = $conn->prepare("SELECT id FROM post_options WHERE id=? AND post_id=? AND is_open=1 LIMIT 1");
        $stmt->bind_param("ii", $optionId, $postId);
        $stmt->execute();
        $ok = $stmt->get_result()->num_rows;
        $stmt->close();
        if ($ok === 0)
            throw new Exception("Opcja nie istnieje albo jest zamknięta.");

        // czy user już ma bet na ten post?
        $betId = null;
        $oldAmount = null;
        $oldOption = null;

        $stmt = $conn->prepare("SELECT id, stake, option_id FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
        $stmt->bind_param("ii", $userId, $postId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $betId = (int) $row['id'];
            $oldAmount = (float) $row['stake'];
            $oldOption = (int) $row['option_id'];
        }
        $stmt->close();

        if ($betId === null) {
            // pierwszy zakład: tworzymy bets, a w ledger zapisujemy -amount
            //if ($balance < $newAmount) throw new Exception("Brak środków.");

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
            // już głosował: musi zostać ta sama opcja, i kwota tylko w górę
            if ($oldOption !== $optionId)
                throw new Exception("Nie możesz zmienić opcji (tylko dopłata do tej samej).");
            if ($newAmount < $oldAmount)
                throw new Exception("Nie możesz zmniejszyć kwoty.");
            if ($newAmount == $oldAmount)
                throw new Exception("Kwota bez zmian.");

            $delta = $newAmount - $oldAmount;
            // przelicz saldo (możesz też policzyć fresh SUMą, ale tu wystarczy bieżące)
            //if ($balance < $delta) throw new Exception("Brak środków na dopłatę.");

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

        $conn->commit(); // [web:145]
        header("location: ./post.php?id=" . $postId);
        exit;

    } catch (Throwable $e) {
        $conn->rollback(); // [web:148]
        $_SESSION['blad'] = $e->getMessage();
        header("location: ./post.php?id=" . $postId);
        exit;
    }
}

// pobierz post
$stmt = $conn->prepare("SELECT title, body, created_at FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post)
    die("Nie ma takiego posta.");

// opcje + suma kasy na opcję
$stmt = $conn->prepare("
  SELECT po.id, po.label, COALESCE(SUM(b.stake),0) AS total_amount
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

// mój bet (żeby podpowiedzieć opcję/kwotę)
$myBet = null;
$stmt = $conn->prepare("SELECT option_id, stake FROM bets WHERE user_id=? AND post_id=? LIMIT 1");
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
        <?php if (isset($_SESSION['blad'])) {
            echo "<div class='alert alert-danger'>" . $_SESSION['blad'] . "</div>";
            unset($_SESSION['blad']);
        } ?>

        <div class="card mb-3">
            <div class="card-body">
                <h5 class="card-title"><?= htmlspecialchars($post['title']) ?></h5>
                <p class="card-text"><?= nl2br(htmlspecialchars($post['body'])) ?></p>
                <p class="card-text text-body-secondary">Saldo: <?= number_format((float) $balance, 2) ?> zł</p>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <h6>Opcje</h6>
                <form method="post">
                    <?php foreach ($options as $opt): ?>
                        <?php
                        $checked = ($myBet && (int) $myBet['option_id'] === (int) $opt['id']) ? "checked" : "";
                        ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio" name="option_id" value="<?= (int) $opt['id'] ?>"
                                <?= $checked ?> required>
                            <label class="form-check-label">
                                <?= htmlspecialchars($opt['label']) ?>
                                <span class="text-body-secondary">(suma:
                                    <?= number_format((float) $opt['total_amount'], 2) ?> zł)</span>
                            </label>
                        </div>
                    <?php endforeach; ?>

                    <div class="mt-3">
                        <label class="form-label">Kwota (jeśli dopłacasz, wpisz nową łączną kwotę)</label>
                        <input class="form-control" name="amount" type="number" step="0.01" min="0.01"
                            value="<?= $myBet ? htmlspecialchars((string) $myBet['stake']) : '' ?>" required>
                    </div>

                    <button class="btn btn-success w-100 mt-3" type="submit">
                        <?= $myBet ? "Dopłać / Zwiększ" : "Postaw" ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
</body>

</html>