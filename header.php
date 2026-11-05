<?php
$user = currentUser($conn);
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($pageTitle ?? 'College Library') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head><body>
<nav class="navbar navbar-expand-lg navbar-light bg-white border-bottom sticky-top">
 <div class="container"><a class="navbar-brand fw-bold" href="index.php">📚 Campus Library</a>
 <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
 <div class="collapse navbar-collapse" id="nav"><div class="navbar-nav ms-auto align-items-lg-center gap-lg-2">
 <a class="nav-link" href="index.php">Catalog</a>
 <?php if ($user && $user['role']==='STUDENT'): ?><a class="nav-link" href="student_dashboard.php">My Library</a><?php endif; ?>
 <?php if ($user && $user['role']==='FACULTY'): ?><a class="nav-link" href="faculty_dashboard.php">Admin Panel</a><?php endif; ?>
 <?php if ($user): ?>
 <?php if ($user['role']==='STUDENT'): ?><span class="token-pill">🪙 <?= (int)$user['token_balance'] ?> tokens</span><?php endif; ?>
 <a class="nav-link" href="settings.php">Settings</a><a class="btn btn-outline-secondary btn-sm" href="logout.php">Logout</a>
 <?php else: ?><a class="nav-link" href="register.php">Student Sign Up</a><a class="btn btn-primary btn-sm" href="login.php">Login</a><?php endif; ?>
 </div></div></div>
</nav><main class="container py-4"><?php showFlash(); ?>
