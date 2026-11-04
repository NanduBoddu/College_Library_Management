<?php
require_once 'config.php';
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['reserve_catalog_id'])) {
    requireLogin(); verifyCsrf();
    $u=currentUser($conn);
    if (!$u || $u['role']!=='STUDENT') { flash('Only students can reserve books.','danger'); header('Location: index.php'); exit; }
    $catalog=trim($_POST['reserve_catalog_id']);
    $conn->begin_transaction();
    try {
        $stmt=$conn->prepare('SELECT * FROM users WHERE roll_or_staff_id=? FOR UPDATE'); $stmt->bind_param('s',$u['roll_or_staff_id']); $stmt->execute(); $lockedUser=$stmt->get_result()->fetch_assoc();
        $stmt=$conn->prepare('SELECT * FROM book_catalog WHERE catalog_id=? FOR UPDATE'); $stmt->bind_param('s',$catalog); $stmt->execute(); $book=$stmt->get_result()->fetch_assoc();
        if (!$book) throw new Exception('Book not found.');
        if ($book['available_copies']<1) throw new Exception('No copies are currently available.');
        if (activeQuotaCount($conn,$u['roll_or_staff_id']) >= maxQuota($lockedUser)) throw new Exception('Your active borrowing quota is full.');
        $cost=tokenCost($book['book_type']); if ((int)$lockedUser['token_balance']<$cost) throw new Exception("You need $cost tokens to reserve this book.");
        $stmt=$conn->prepare("SELECT book_qr_id FROM physical_books WHERE catalog_id=? AND status='AVAILABLE' LIMIT 1 FOR UPDATE"); $stmt->bind_param('s',$catalog); $stmt->execute(); $copy=$stmt->get_result()->fetch_assoc();
        if (!$copy) throw new Exception('No available physical copy was found.');
        $stmt=$conn->prepare("UPDATE users SET token_balance=token_balance-? WHERE roll_or_staff_id=?"); $stmt->bind_param('is',$cost,$u['roll_or_staff_id']); $stmt->execute();
        $stmt=$conn->prepare("INSERT INTO token_transactions (roll_number,amount,reason) VALUES (?,?,'RESERVATION')"); $negative=-$cost; $stmt->bind_param('si',$u['roll_or_staff_id'],$negative); $stmt->execute();
        $stmt=$conn->prepare("INSERT INTO reservations (roll_number,catalog_id,book_qr_id,reserved_at,expires_at,status) VALUES (?,?,?,NOW(),DATE_ADD(NOW(),INTERVAL 48 HOUR),'ACTIVE')"); $stmt->bind_param('sss',$u['roll_or_staff_id'],$catalog,$copy['book_qr_id']); $stmt->execute();
        $stmt=$conn->prepare("UPDATE physical_books SET status='RESERVED' WHERE book_qr_id=?"); $stmt->bind_param('s',$copy['book_qr_id']); $stmt->execute();
        $stmt=$conn->prepare('UPDATE book_catalog SET available_copies=available_copies-1 WHERE catalog_id=?'); $stmt->bind_param('s',$catalog); $stmt->execute();
        $conn->commit(); flash('Book reserved for 48 hours. Your reserved copy ID is '.$copy['book_qr_id'].'. Check My Reservations for details.');
    } catch(Throwable $e) { $conn->rollback(); flash($e->getMessage(),'danger'); }
    header('Location: index.php'); exit;
}
$q=trim($_GET['q'] ?? '');
if ($q!=='') { $like='%'.$q.'%'; $stmt=$conn->prepare('SELECT * FROM book_catalog WHERE title LIKE ? OR author LIKE ? OR catalog_id LIKE ? ORDER BY title'); $stmt->bind_param('sss',$like,$like,$like); $stmt->execute(); $books=$stmt->get_result()->fetch_all(MYSQLI_ASSOC); }
else $books=$conn->query('SELECT * FROM book_catalog ORDER BY title')->fetch_all(MYSQLI_ASSOC);
$pageTitle='Book Catalog'; require 'includes/header.php';
?>
<section class="hero mb-4"><div><div class="eyebrow">YOUR CAMPUS, YOUR LIBRARY</div><h1>Find your next<br><span>great read.</span></h1><p>Search academic and competitive books, reserve a copy, and collect it from the library counter.</p><form class="searchbar" method="get"><input name="q" value="<?= h($q) ?>" placeholder="Search title, author or catalog ID…"><button class="btn btn-primary">Search</button></form></div><div class="hero-art"><div class="book-stack">📚</div><div class="floating-note">48-hour<br><strong>reservation</strong></div></div></section>
<div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mb-3"><div><div class="eyebrow">DISCOVER</div><h2 class="h4 fw-bold mb-0">Library catalog</h2></div><span class="text-muted small"><?= count($books) ?> title(s)</span></div>
<div class="row g-3">
<?php foreach($books as $b): $cost=tokenCost($b['book_type']); ?>
<div class="col-md-6 col-xl-4"><div class="book-card h-100"><div class="book-cover" <?php if (!empty($b['image_path'])): ?>style="background-image:url('<?= h($b['image_path']) ?>');background-size:contain;background-repeat:no-repeat;background-position:center;background-color:#f7f8ff"<?php endif; ?>><span><?= $b['book_type']==='ACADEMIC'?'ACADEMIC':'COMPETITIVE' ?></span><?php if (empty($b['image_path'])): ?><div>📘</div><?php endif; ?><small><?= h($b['catalog_id']) ?></small></div><div class="book-info"><span class="category"><?= h($b['book_type']) ?></span><h3><?= h($b['title']) ?></h3><p class="text-muted mb-3">by <?= h($b['author']) ?></p><div class="d-flex justify-content-between small mb-3"><span>Stock: <strong><?= (int)$b['available_copies'] ?></strong> / <?= (int)$b['total_copies'] ?></span><span>🪙 <?= $cost ?> tokens</span></div>
<?php if ($b['available_copies']>0 && !empty($_SESSION['role']) && $_SESSION['role']==='STUDENT'): ?><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="reserve_catalog_id" value="<?= h($b['catalog_id']) ?>"><button class="btn btn-primary w-100">Reserve for 48 hours</button></form>
<?php elseif (empty($_SESSION['user_id'])): ?><a class="btn btn-outline-primary w-100" href="login.php">Sign in to reserve</a>
<?php else: ?><button class="btn btn-outline-secondary w-100" disabled><?= $b['available_copies']<1?'Currently unavailable':'Reservation for students only' ?></button><?php endif; ?>
</div></div></div>
<?php endforeach; ?>
<?php if (!$books): ?><div class="col-12"><div class="empty-state">No books matched your search. Try another title or author.</div></div><?php endif; ?>
</div>
<?php require 'includes/footer.php'; ?>
