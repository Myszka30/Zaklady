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

// rola
$myRole = 'user';
$stmt = $conn->prepare("SELECT role FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $userId);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();
if ($r) $myRole = $r['role'];
$isAdmin = ($myRole === 'admin');

// post + autor
$stmt = $conn->prepare("SELECT id, user_id, title, body, status, is_closed FROM posts WHERE id=? LIMIT 1");
$stmt->bind_param("i", $postId);
$stmt->execute();
$post = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$post) die("Nie ma takiego posta.");

$isAuthor = ((int)$post['user_id'] === (int)$userId);
if (!$isAuthor && !$isAdmin) { http_response_code(403); die("Brak uprawnień."); }

if ((int)$post['is_closed'] === 1) {
  die("Ten zakład jest zakończony – nie można edytować.");
}

// opcje
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

  try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE posts SET title=?, body=?, status=? WHERE id=?");
    $stmt->bind_param("sssi", $title, $body, $status, $postId);
    $stmt->execute();
    $stmt->close();

    // edycja etykiet opcji (nie usuwamy opcji, żeby nie psuć betów)
    foreach ($options as $opt) {
      $oid = (int)$opt['id'];
      $newLabel = trim($_POST['opt'][$oid] ?? '');
      if ($newLabel === '') continue;

      $stmt = $conn->prepare("UPDATE post_options SET label=? WHERE id=? AND post_id=?");
      $stmt->bind_param("sii", $newLabel, $oid, $postId);
      $stmt->execute();
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

      <form method="post">
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

        <h6>Opcje</h6>
        <?php foreach($options as $o): ?>
          <div class="mb-2">
            <input class="form-control" name="opt[<?= (int)$o['id'] ?>]" value="<?= htmlspecialchars($o['label']) ?>">
          </div>
        <?php endforeach; ?>

        <button class="btn btn-primary w-100 mt-2" type="submit">Zapisz</button>
        <a class="btn btn-secondary w-100 mt-2" href="./post.php?id=<?= (int)$postId ?>">Wróć</a>
      </form>
    </div>
  </div>

</div>
</body>
</html>
