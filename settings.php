<?php
require_once 'config.php'; requireLogin(); verifyCsrf(); $u=currentUser($conn); $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $current=$_POST['current_password']??''; $new=$_POST['new_password']??''; $confirm=$_POST['confirm_password']??'';
  $stmt=$conn->prepare('SELECT password_hash FROM users WHERE roll_or_staff_id=?'); $stmt->bind_param('s',$u['roll_or_staff_id']); $stmt->execute(); $row=$stmt->get_result()->fetch_assoc();
  if (!$row || !password_verify($current,$row['password_hash'])) $error='Current password is incorrect.';
  elseif (strlen($new)<8) $error='New password must contain at least 8 characters.';
  elseif ($new!==$confirm) $error='New password and confirmation do not match.';
  else { $hash=password_hash($new,PASSWORD_DEFAULT); $stmt=$conn->prepare('UPDATE users SET password_hash=? WHERE roll_or_staff_id=?'); $stmt->bind_param('ss',$hash,$u['roll_or_staff_id']); $stmt->execute(); flash('Password updated successfully.'); header('Location: settings.php'); exit; }
}
$pageTitle='Settings'; require 'includes/header.php';
?>
<div class="auth-wrap"><div class="auth-card"><div class="eyebrow">ACCOUNT SECURITY</div><h1 class="h3 fw-bold">Change password</h1><p class="text-muted">Signed in as <?= h($u['full_name']) ?> (<?= h($u['roll_or_staff_id']) ?>).</p>
<?php if($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<form method="post" class="vstack gap-3"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><div><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" required></div><div><label class="form-label">New password</label><input type="password" name="new_password" class="form-control" minlength="8" required></div><div><label class="form-label">Confirm new password</label><input type="password" name="confirm_password" class="form-control" minlength="8" required></div><button class="btn btn-primary">Update password</button></form></div></div>
<?php require 'includes/footer.php'; ?>
