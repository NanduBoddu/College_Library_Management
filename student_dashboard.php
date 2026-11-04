<?php
require_once 'config.php'; requireLogin();
if (($_SESSION['role']??'')!=='STUDENT') { header('Location: faculty_dashboard.php'); exit; }
$u=currentUser($conn); syncOverdue($conn); $roll=$u['roll_or_staff_id'];
$view=$_GET['view']??'overview';
$allowed=['overview','loans','reservations','tokens']; if(!in_array($view,$allowed,true)) $view='overview';
$stmt=$conn->prepare("SELECT l.*,p.catalog_id,c.title,c.author,p.book_qr_id FROM loans l JOIN physical_books p ON p.book_qr_id=l.book_qr_id JOIN book_catalog c ON c.catalog_id=p.catalog_id WHERE l.roll_number=? AND l.status IN ('ISSUED','OVERDUE') ORDER BY l.due_date"); $stmt->bind_param('s',$roll); $stmt->execute(); $loans=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt=$conn->prepare("SELECT r.*,c.title,c.author,c.book_type FROM reservations r JOIN book_catalog c ON c.catalog_id=r.catalog_id WHERE r.roll_number=? AND r.status='ACTIVE' ORDER BY r.expires_at"); $stmt->bind_param('s',$roll); $stmt->execute(); $reservations=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt=$conn->prepare("SELECT amount,reason,created_at FROM token_transactions WHERE roll_number=? ORDER BY created_at DESC LIMIT 50"); $stmt->bind_param('s',$roll); $stmt->execute(); $transactions=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$pageTitle='My Library'; require 'includes/header.php';
function studentNav(string $key,string $label,string $icon,string $active): void { echo '<a class="student-task '.($key===$active?'active':'').'" href="student_dashboard.php?view='.h($key).'"><span class="task-icon">'.$icon.'</span><span>'.h($label).'</span><span class="task-arrow">›</span></a>'; }
?>
<div class="page-heading"><div><div class="eyebrow">STUDENT PORTAL</div><h1 class="h2 fw-bold">Hi, <?= h($u['full_name']) ?> 👋</h1><p class="text-muted">Choose what you want to manage. Each task has its own clear page.</p></div><a class="btn btn-primary" href="index.php">Browse catalog</a></div>
<style>
.student-layout{display:grid;grid-template-columns:250px minmax(0,1fr);gap:24px;align-items:start}.student-menu,.student-content{background:#fff;border:1px solid #e6eaf0;border-radius:18px;padding:20px;box-shadow:0 4px 18px rgba(20,35,60,.035)}.student-menu h2{font-size:1rem;font-weight:750;margin-bottom:14px}.student-task{display:flex;align-items:center;gap:11px;padding:12px 13px;margin:5px 0;border-radius:11px;text-decoration:none;color:#354052;font-weight:600}.student-task:hover,.student-task.active{background:#eef4ff;color:#194db5}.task-icon{font-size:1.15rem;width:25px}.task-arrow{margin-left:auto;font-size:1.35rem}.student-content h2{font-weight:750;font-size:1.25rem}.student-stat{border:1px solid #e6eaf0;border-radius:14px;padding:15px;height:100%}.student-stat span{display:block;color:#687386;font-size:.85rem}.student-stat strong{display:block;font-size:1.55rem;margin:5px 0}.student-search{max-width:360px}.student-empty{padding:18px;border:1px dashed #d5dbe5;border-radius:12px;color:#697386}.student-help{background:#f6f8fc;border-radius:12px;padding:14px;color:#4d596c}.student-help p{margin:5px 0 0}@media(max-width:800px){.student-layout{grid-template-columns:1fr}.student-menu{padding:14px}.student-task{display:inline-flex;margin:3px;padding:9px 11px}.task-arrow{display:none}}
</style>
<div class="student-layout">
<aside class="student-menu"><h2>MY LIBRARY</h2>
<?php studentNav('overview','Overview','🏠',$view); ?>
<?php studentNav('loans','My Issued Books','📚',$view); ?>
<?php studentNav('reservations','My Reservations','⏳',$view); ?>
<?php studentNav('tokens','Token History','🪙',$view); ?>
<a class="student-task" href="index.php"><span class="task-icon">🔎</span><span>Browse Catalog</span><span class="task-arrow">›</span></a>
<div class="student-help mt-3"><strong>Need a book?</strong><p class="small">Reserve it from the catalog, then check My Reservations for the assigned copy ID and expiry time.</p></div>
</aside>
<main class="student-content">
<?php if($view==='overview'): ?>
<h2>Overview</h2><p class="text-muted">Your library activity at a glance.</p>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="student-stat"><span>Active quota</span><strong><?= activeQuotaCount($conn,$roll) ?> / <?= maxQuota($u) ?></strong><small>Books + reservations</small></div></div><div class="col-md-4"><div class="student-stat"><span>Token balance</span><strong>🪙 <?= (int)$u['token_balance'] ?></strong><small>Academic: 5 · Competitive: 10</small></div></div><div class="col-md-4"><div class="student-stat"><span>Fine balance</span><strong>₹<?= number_format((float)$u['fine_balance'],2) ?></strong><small>₹5 per overdue day</small></div></div></div>
<div class="section-head"><h2>Quick actions</h2></div>
<div class="row g-3"><div class="col-md-6"><a class="student-task border" href="student_dashboard.php?view=loans"><span class="task-icon">📚</span><span><strong>My Issued Books</strong><br><small class="text-muted"><?= count($loans) ?> active loan(s)</small></span><span class="task-arrow">›</span></a></div><div class="col-md-6"><a class="student-task border" href="student_dashboard.php?view=reservations"><span class="task-icon">⏳</span><span><strong>My Reservations</strong><br><small class="text-muted"><?= count($reservations) ?> active reservation(s)</small></span><span class="task-arrow">›</span></a></div><div class="col-md-6"><a class="student-task border" href="student_dashboard.php?view=tokens"><span class="task-icon">🪙</span><span><strong>Token History</strong><br><small class="text-muted">View token deductions and rewards</small></span><span class="task-arrow">›</span></a></div><div class="col-md-6"><a class="student-task border" href="index.php"><span class="task-icon">🔎</span><span><strong>Browse Catalog</strong><br><small class="text-muted">Find and reserve a book</small></span><span class="task-arrow">›</span></a></div></div>
<?php elseif($view==='loans'): ?>
<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2>My Issued Books</h2><p class="text-muted">Track your borrowed books and due dates.</p></div><span class="badge text-bg-light fs-6"><?= count($loans) ?> active</span></div>
<input class="form-control student-search mb-3" data-filter="loan-row" placeholder="Search by title, author or copy ID…">
<?php if(!$loans): ?><div class="student-empty">You do not have any active loans. Browse the catalog to find books.</div><?php else: foreach($loans as $l): ?><div class="list-entry loan-row"><div class="entry-icon">📖</div><div class="flex-grow-1"><strong><?= h($l['title']) ?></strong><div class="small text-muted"><?= h($l['author']) ?> · Copy ID: <?= h($l['book_qr_id']) ?></div><div class="small">Due: <?= h($l['due_date']) ?></div></div><span class="badge <?= $l['status']==='OVERDUE'?'text-bg-danger':'text-bg-light' ?>"><?= $l['status']==='OVERDUE'?'Overdue':'On loan' ?></span></div><?php endforeach; endif; ?>
<div class="student-help mt-3"><strong>Renewal</strong><p class="small">Renewals are handled at the faculty counter on Day 15 or the morning of Day 16. Early renewals are blocked.</p></div>
<?php elseif($view==='reservations'): ?>
<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2>My Reservations</h2><p class="text-muted">Use the copy ID below to identify the exact book reserved for you.</p></div><span class="badge text-bg-light fs-6"><?= count($reservations) ?> active</span></div>
<input class="form-control student-search mb-3" data-filter="reservation-row" placeholder="Search by title, author, catalog or copy ID…">
<?php if(!$reservations): ?><div class="student-empty">No active reservations. Browse the catalog and reserve a book to see it here.</div><?php else: foreach($reservations as $r): ?><div class="list-entry reservation-row"><div class="entry-icon">⏳</div><div class="flex-grow-1"><strong><?= h($r['title']) ?></strong><div class="small text-muted"><?= h($r['author']) ?> · <?= h($r['book_type']) ?></div><div class="small"><strong>Catalog ID:</strong> <?= h($r['catalog_id']) ?></div><div class="small"><strong>Your reserved copy:</strong> <?= h($r['book_qr_id'] ?: 'Not assigned (older reservation)') ?></div><div class="small">Reservation #<?= (int)$r['reservation_id'] ?> · Reserved: <?= h($r['reserved_at']) ?></div><div class="small">Expires: <span class="countdown" data-expires="<?= h(date('c',strtotime($r['expires_at']))) ?>"><?= h($r['expires_at']) ?></span></div></div><span class="badge text-bg-warning">Reserved</span></div><?php endforeach; endif; ?>
<div class="student-help mt-3"><strong>At the library</strong><p class="small">Show the title and copy ID to the library staff member who helps locate books. Take that exact copy to the faculty counter for issue.</p></div>
<?php else: ?>
<div class="d-flex justify-content-between align-items-start gap-3 flex-wrap"><div><h2>Token History</h2><p class="text-muted">See token deductions and rewards from your library activity.</p></div><span class="badge text-bg-light fs-6">Latest 50</span></div>
<div class="student-stat mb-3"><span>Current token balance</span><strong>🪙 <?= (int)$u['token_balance'] ?></strong><small>Academic reservation: 5 tokens · Competitive reservation: 10 tokens</small></div>
<input class="form-control student-search mb-3" data-filter="transaction-row" placeholder="Search token activity…">
<?php if(!$transactions): ?><div class="student-empty">No token activity yet.</div><?php else: foreach($transactions as $t): ?><div class="transaction transaction-row"><div><strong><?= h(str_replace('_',' ', $t['reason'])) ?></strong><div class="small text-muted"><?= h($t['created_at']) ?></div></div><strong class="<?= (int)$t['amount']>0?'positive':((int)$t['amount']<0?'negative':'') ?>"><?= (int)$t['amount']>0?'+':'' ?><?= (int)$t['amount'] ?></strong></div><?php endforeach; endif; ?>
<?php endif; ?>
</main></div>
<script>
document.querySelectorAll('[data-filter]').forEach(input=>input.addEventListener('input',()=>{const cls=input.dataset.filter,q=input.value.toLowerCase();document.querySelectorAll('.'+cls).forEach(row=>row.hidden=!row.innerText.toLowerCase().includes(q));}));
</script>
<?php require 'includes/footer.php'; ?>
