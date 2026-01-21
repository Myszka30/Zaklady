<?php
session_start();
require_once(__DIR__ . '/../config.php');

if (!isset($_SESSION['LOGIN'])) {
    http_response_code(401);
    exit("Brak sesji.");
}

$username = $_SESSION['LOGIN'];

// user_id
$userId = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($userId);
$stmt->fetch();
$stmt->close();

if (!$userId) {
    http_response_code(403);
    exit("Nie znaleziono użytkownika.");
}

$stmt = $conn->prepare("
    SELECT p.id, p.title, p.body, p.created_at
    FROM posts p
    WHERE p.status='published'
    ORDER BY p.created_at DESC
    LIMIT 50
");
$stmt->execute();
$posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (!$posts) {
    echo '<div class="col-12"><div class="alert alert-secondary">Brak zakładów.</div></div>';
    exit;
}

$postIds = array_map(fn($r) => (int)$r['id'], $posts);
$placeholders = implode(',', array_fill(0, count($postIds), '?'));
$types = str_repeat('i', count($postIds));

/* Opcje + suma stawek na opcję */
$sqlOptions = "
    SELECT
      po.id AS option_id,
      po.post_id,
      po.label,
      po.is_open,
      COALESCE(SUM(b.stake), 0) AS total_stake
    FROM post_options po
    LEFT JOIN bets b ON b.option_id = po.id
    WHERE po.post_id IN ($placeholders)
    GROUP BY po.id, po.post_id, po.label, po.is_open
    ORDER BY po.post_id ASC, po.id ASC
";
$stmt = $conn->prepare($sqlOptions);
$stmt->bind_param($types, ...$postIds);
$stmt->execute();
$optRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

/* Mój zakład na dany post (stake + option_id) */
$sqlMy = "
    SELECT post_id, option_id, stake
    FROM bets
    WHERE user_id = ? AND post_id IN ($placeholders)
";
$stmt = $conn->prepare($sqlMy);
$typesMy = 'i' . $types;
$stmt->bind_param($typesMy, $userId, ...$postIds);
$stmt->execute();
$myRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$myByPost = [];
foreach ($myRows as $r) {
    $myByPost[(int)$r['post_id']] = [
        'option_id' => (int)$r['option_id'],
        'stake' => (float)$r['stake'],
    ];
}

$optionsByPost = [];
foreach ($optRows as $r) {
    $pid = (int)$r['post_id'];
    $optionsByPost[$pid][] = $r;
}

foreach ($posts as $p) {
    $pid = (int)$p['id'];
    $title = htmlspecialchars($p['title'] ?? '');
    $body  = htmlspecialchars($p['body'] ?? '');
    $createdAt = htmlspecialchars($p['created_at'] ?? '');

    $my = $myByPost[$pid] ?? null;

    echo '<div class="col-12 col-md-6 mb-3">';
    echo '  <div class="card">';
    echo '    <div class="card-body">';
    echo "      <h5 class=\"card-title\">$title</h5>";
    echo "      <h6 class=\"card-subtitle mb-2 text-body-secondary\">$createdAt</h6>";
    echo "      <p class=\"card-text\">$body</p>";

    if ($my && $my['option_id']) {
        echo '<div class="mb-2 text-success">Twój zakład: ' . number_format($my['stake'], 2) . ' zł</div>';
    } else {
        echo '<div class="mb-2 text-body-secondary">Nie obstawiłeś jeszcze.</div>';
    }

    $opts = $optionsByPost[$pid] ?? [];
    echo '<div class="list-group mb-3">';
    if (!$opts) {
        echo '<div class="text-body-secondary">Brak opcji.</div>';
    } else {
        foreach ($opts as $o) {
            $oid = (int)$o['option_id'];
            $label = htmlspecialchars($o['label']);
            $sum = number_format((float)$o['total_stake'], 2);

            $isMine = ($my && (int)$my['option_id'] === $oid);
            $cls = $isMine ? 'list-group-item list-group-item-success' : 'list-group-item';

            echo "<div class=\"$cls d-flex justify-content-between align-items-center\">";
            echo "  <span>$label</span>";
            echo "  <span class=\"badge text-bg-secondary\">$sum zł</span>";
            echo "</div>";
        }
    }
    echo '</div>';

    echo "      <a class=\"btn btn-primary w-100\" href=\"./post.php?id=$pid\">Otwórz</a>";
    echo '    </div>';
    echo '  </div>';
    echo '</div>';
}
