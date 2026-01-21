<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once('./config.php');

if (!isset($_SESSION['LOGIN'])) { header("location: ./login.php"); exit; }
if ($_SESSION['LOGIN'] !== 'admin') { $_SESSION['blad']="Brak uprawnień."; header("location: ./index.php"); exit; }

$username = $_SESSION['LOGIN'];

// user_id po username
$userId = null;
$stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$stmt->bind_result($userId);
$stmt->fetch();
$stmt->close();
if (!$userId) { die("Nie znaleziono użytkownika."); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title  = trim($_POST['title'] ?? '');
  $body   = trim($_POST['body'] ?? '');
  $status = $_POST['status'] ?? 'published';

  $options = $_POST['option'] ?? [];
  $options = array_map('trim', $options);
  $options = array_values(array_filter($options, fn($v)=>$v!==''));

  if ($title==='' || $body==='') {
    $_SESSION['blad']="Uzupełnij tytuł i opis.";
    header("location: ./nowy_zaklad.php"); exit;
  }
  if (count($options) < 2 || count($options) > 8) {
    $_SESSION['blad']="Podaj od 2 do 8 opcji (niepuste).";
    header("location: ./nowy_zaklad.php"); exit;
  }

  try {
    $conn->begin_transaction(); // transakcja [web:125][web:145]

    // insert post
    $stmtPost = $conn->prepare("INSERT INTO posts (user_id, title, body, status) VALUES (?, ?, ?, ?)");
    $stmtPost->bind_param("isss", $userId, $title, $body, $status);
    $stmtPost->execute();
    $postId = $conn->insert_id;
    $stmtPost->close();

    // insert options
    $stmtOpt = $conn->prepare("INSERT INTO post_options (post_id, label) VALUES (?, ?)");
    foreach ($options as $label) {
      $stmtOpt->bind_param("is", $postId, $label);
      $stmtOpt->execute();
    }
    $stmtOpt->close();

    $conn->commit(); // [web:145]
    header("location: ./post.php?id=".$postId);
    exit;

  } catch (Throwable $e) {
    $conn->rollback(); // [web:148][web:145]
    $_SESSION['blad'] = "Błąd zapisu: " . $e->getMessage();
    header("location: ./nowy_zaklad.php"); exit;
  }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nowy zakład</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body data-bs-theme="dark">

<div class="container mt-3 mb-5">
  <?php if(isset($_SESSION['blad'])) { echo "<div class='alert alert-danger'>".$_SESSION['blad']."</div>"; unset($_SESSION['blad']); } ?>

  <div class="card">
    <div class="card-body">
      <h5 class="card-title">Stwórz post (zakład)</h5>

      <form method="post">
        <div class="mb-3">
          <label class="form-label">Tytuł</label>
          <input class="form-control" name="title" maxlength="200" required>
        </div>

        <div class="mb-3">
          <label class="form-label">Opis</label>
          <textarea class="form-control" name="body" rows="5" required></textarea>
        </div>

        <div class="mb-3">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <option value="published" selected>published</option>
            <option value="draft">draft</option>
            <option value="archived">archived</option>
          </select>
        </div>

        <div class="mb-2"><strong>Opcje (2–8)</strong></div>
        <?php for($i=0;$i<8;$i++): ?>
          <div class="mb-2">
            <input class="form-control" name="option[]" placeholder="Opcja <?= $i+1 ?>">
          </div>
        <?php endfor; ?>

        <button class="btn btn-primary w-100 mt-2" type="submit">Zapisz</button>
      </form>
    </div>
  </div>
</div>

</body>
</html>
