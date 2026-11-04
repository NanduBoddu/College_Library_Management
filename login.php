<?php
require_once 'config.php';
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verifyCsrf();
    $id = trim($_POST['roll_or_staff_id'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $conn->prepare('SELECT roll_or_staff_id, full_name, password_hash, role, caste_category FROM users WHERE roll_or_staff_id=?');
    $stmt->bind_param('s',$id); $stmt->execute(); $u=$stmt->get_result()->fetch_assoc();
    if ($u && password_verify($password,$u['password_hash'])) {
        session_regenerate_id(true); $_SESSION['user_id']=$u['roll_or_staff_id']; $_SESSION['role']=$u['role'];
        header('Location: index.php'); exit;
    } else $error='Invalid ID or password.';
}
$pageTitle='Login'; require 'includes/header.php';
?>
<div class="auth-wrap"><div class="auth-card">
<div class="eyebrow">WELCOME BACK</div><h1 class="h3 fw-bold mb-2">Sign in to your library</h1><p class="text-muted mb-4">Use your student roll number or faculty staff ID.</p>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post">
<input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>">
<div class="mb-3"><label class="form-label">Roll number / Staff ID</label><input class="form-control" name="roll_or_staff_id" required maxlength="50" autocomplete="username"></div>
<div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required autocomplete="current-password"></div>
<div class="small text-muted mb-3">Category and permissions are taken from the registered account, not trusted from the login form.</div>
<button class="btn btn-primary w-100">Sign in</button>
</form>
<div class="text-center mt-4 pt-3 border-top">
  <span class="text-muted small">New student?</span>
  <a class="fw-semibold text-decoration-none ms-1" href="register.php">Create an account</a>
</div>
</div></div>
<?php require 'includes/footer.php'; ?>
