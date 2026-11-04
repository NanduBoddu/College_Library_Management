<?php
require_once 'config.php';
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 verifyCsrf(); $id=trim($_POST['id']??''); $name=trim($_POST['name']??''); $cat=$_POST['category']??''; $pass=$_POST['password']??'';
 if (!$id||!$name||!in_array($cat,['SC','ST','OC','OBC'],true)||strlen($pass)<8) $error='Enter all fields. Password must be at least 8 characters.';
 else {
  try { $hash=password_hash($pass,PASSWORD_DEFAULT); $stmt=$conn->prepare("INSERT INTO users(roll_or_staff_id,full_name,password_hash,role,caste_category) VALUES (?,?,?,'STUDENT',?)"); $stmt->bind_param('ssss',$id,$name,$hash,$cat); $stmt->execute(); flash('Registration successful. Your token balance starts at 0. Please sign in.'); header('Location: login.php'); exit; }
  catch(Throwable $e) { $error='Could not register. The ID may already exist.'; }
 }
}
$pageTitle='Student Registration'; require 'includes/header.php';
?>
<div class="auth-wrap"><div class="auth-card"><div class="eyebrow">NEW STUDENT</div><h1 class="h3 fw-bold">Create student account</h1><p class="text-muted">Your category determines the borrowing quota.</p><?php if($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?><form method="post" class="vstack gap-3"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><div><label class="form-label">Roll number</label><input class="form-control" name="id" required maxlength="50"></div><div><label class="form-label">Full name</label><input class="form-control" name="name" required maxlength="100"></div><div><label class="form-label">Category</label><select class="form-select" name="category" required><option value="">Choose category</option><option>SC</option><option>ST</option><option>OC</option><option>OBC</option></select></div><div><label class="form-label">Password (8+ characters)</label><input type="password" class="form-control" name="password" minlength="8" required></div><button class="btn btn-primary">Create account</button><a href="login.php" class="text-center small">Already registered? Sign in</a></form></div></div><?php require 'includes/footer.php'; ?>
