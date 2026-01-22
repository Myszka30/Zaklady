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

$stmt = $conn->prepare("SELECT id, user_id, title, body, status, is_closed, chart_mode, allow_descriptive_bets, hide_from_home FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post) die("Nie ma takiego posta.");

$isAuthor = ((int)$post['user_id'] === (int)$userId);
if (!$isAuthor && !$isAdmin) { http_response_code(403); die("Brak uprawnień."); }

if ((int)$post['is_closed'] === 1 && !$isAdmin) {
  die("Ten zakład jest zakończony – nie można edytować.");
}

$stmt = $conn->prepare("SELECT id, label FROM post_options WHERE post_id=? ORDER BY id ASC");
$stmt->bind_param("i", $postId);
$stmt->execute();
$options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title = trim($_POST['title'] ?? '');
  $body  = trim($_POST['body'] ?? '');
  $status = $_POST['status'] ?? 'published';

  if ($title === '' || $body === '') {
    $_SESSION['blad'] = "Uzupełnij tytuł i opis.";
    header("location: ./edit_post.php?id=".$postId); exit;
  }

  if (!in_array($status, ['published','draft','archived'], true)) {
    $status = 'published';
  }

  $chartMode = $_POST['chart_mode'] ?? 'people';
  if (!in_array($chartMode, ['people','stake'], true)) $chartMode = 'people';

  $allowDesc = isset($_POST['allow_descriptive_bets']) ? 1 : 0;

  // Tylko admin może to zmieniać
  $hideFromHome = (int)($post['hide_from_home'] ?? 0);
  if ($isAdmin) {
    $hideFromHome = isset($_POST['hide_from_home']) ? 1 : 0;
  }

  $newOpts = $_POST['new_option'] ?? [];
  if (!is_array($newOpts)) $newOpts = [];
  $newOpts = array_map('trim', $newOpts);
  $newOpts = array_values(array_filter($newOpts, fn($v) => $v !== ''));

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE posts SET title=?, body=?, status=?, chart_mode=?, allow_descriptive_bets=?, hide_from_home=? WHERE id=?");
    $stmt->bind_param("ssssiii", $title, $body, $status, $chartMode, $allowDesc, $hideFromHome, $postId);
    $stmt->execute();
    $stmt->close();

    foreach ($options as $opt) {
      $oid = (int)$opt['id'];
      $newLabel = trim($_POST['opt'][$oid] ?? '');
      if ($newLabel === '') continue;

      $stmt = $conn->prepare("UPDATE post_options SET label=? WHERE id=? AND post_id=?");
      $stmt->bind_param("sii", $newLabel, $oid, $postId);
      $stmt->execute();
      $stmt->close();
    }

    if ($newOpts) {
      $stmt = $conn->prepare("INSERT INTO post_options (post_id, label) VALUES (?, ?)");
      foreach ($newOpts as $lbl) {
        $stmt->bind_param("is", $postId, $lbl);
        $stmt->execute();
      }
      $stmt->close();
    }

    $conn->commit();
    header("location: ./post.php?id=".$postId);
    exit;

  } catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['blad'] = "Błąd edycji: ".$e->getMessage();
    header("location: ./edit_post.php?id=".$postId); exit;
  }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Edytuj zakład</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">
<div class="container mt-3 mb-5">

  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Edytuj zakład</h5>

      <form method="post" id="editForm">
        <div class="mb-2">
          <label class="form-label">Tytuł</label>
          <input class="form-control" name="title" maxlength="200" value="<?= htmlspecialchars($post['title']) ?>" required>
        </div>

        <div class="mb-2">
          <label class="form-label">Opis</label>
          <textarea class="form-control" name="body" rows="5" required><?= htmlspecialchars($post['body']) ?></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="published" <?= ($post['status']==='published'?'selected':'') ?>>published</option>
            <option value="draft" <?= ($post['status']==='draft'?'selected':'') ?>>draft</option>
            <option value="archived" <?= ($post['status']==='archived'?'selected':'') ?>>archived</option>
          </select>
        </div>

        <div class="mb-3">
          <label class="form-label">Wykres na stronie głównej</label>
          <select class="form-select" name="chart_mode">
            <option value="people" <?= (($post['chart_mode'] ?? 'people') === 'people' ? 'selected' : '') ?>>Liczba osób</option>
            <option value="stake" <?= (($post['chart_mode'] ?? 'people') === 'stake' ? 'selected' : '') ?>>Suma stake</option>
          </select>
        </div>

        <div class="form-check form-switch mb-3">
          <input class="form-check-input" type="checkbox" role="switch" id="allowDesc" name="allow_descriptive_bets" value="1"
                 <?= ((int)($post['allow_descriptive_bets'] ?? 1) === 1 ? 'checked' : '') ?>>
          <label class="form-check-label" for="allowDesc">Włącz bety opisowe (można obstawić sam opis)</label>
        </div>

        <?php if ($isAdmin): ?>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" id="hideFromHome" name="hide_from_home" value="1"
                   <?= ((int)($post['hide_from_home'] ?? 0) === 1 ? 'checked' : '') ?>>
            <label class="form-check-label" for="hideFromHome">Ukryj post na stronie głównej</label>
          </div>
        <?php endif; ?>

        <h6>Istniejące opcje</h6>
        <?php foreach($options as $o): ?>
          <div class="mb-2">
            <input class="form-control" name="opt[<?= (int)$o['id'] ?>]" value="<?= htmlspecialchars($o['label']) ?>">
          </div>
        <?php endforeach; ?>

        <hr>

        <div class="d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Nowe opcje</h6>
          <button type="button" class="btn btn-outline-primary btn-sm" id="addOptionBtn">Dodaj nową opcję</button>
        </div>

        <div id="newOptionsWrap" class="mt-2">
          <div class="input-group mb-2 new-opt-row">
            <input class="form-control" name="new_option[]" placeholder="Nowa opcja">
            <button class="btn btn-outline-danger remove-opt" type="button">Usuń</button>
          </div>
        </div>

        <button class="btn btn-primary w-100 mt-3" type="submit">Zapisz</button>
        <a class="btn btn-secondary w-100 mt-2" href="./post.php?id=<?= (int)$postId ?>">Wróć</a>
      </form>
    </div>
  </div>

</div>

<script>
(function () {
  const wrap = document.getElementById('newOptionsWrap');
  const addBtn = document.getElementById('addOptionBtn');

  function addRow() {
    const row = document.createElement('div');
    row.className = 'input-group mb-2 new-opt-row';

    const input = document.createElement('input');
    input.className = 'form-control';
    input.name = 'new_option[]';
    input.placeholder = 'Nowa opcja';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-danger remove-opt';
    btn.textContent = 'Usuń';

    row.appendChild(input);
    row.appendChild(btn);
    wrap.appendChild(row);
    input.focus();
  }

  addBtn.addEventListener('click', addRow);

  wrap.addEventListener('click', function (e) {
    if (!e.target.classList.contains('remove-opt')) return;
    const row = e.target.closest('.new-opt-row');
    if (row) row.remove();
  });
})();
</script>

</body>
</html>
