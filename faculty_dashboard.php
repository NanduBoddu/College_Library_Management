<?php
require_once 'config.php'; requireFaculty(); verifyCsrf();
$msg='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $action=$_POST['action']??'';
  try {
    if ($action==='save_book') {
      $id=trim($_POST['catalog_id']??''); $title=trim($_POST['title']??''); $author=trim($_POST['author']??''); $type=$_POST['book_type']??'ACADEMIC';
      $initialCopies=filter_var($_POST['initial_copies']??'0', FILTER_VALIDATE_INT);
      if (!$id||!$title||!$author||!in_array($type,['ACADEMIC','COMPETITIVE'],true)||$initialCopies===false||$initialCopies<0||$initialCopies>500) throw new Exception('Enter valid catalog details and a copy count from 0 to 500.');
      $stmt=$conn->prepare('SELECT * FROM book_catalog WHERE catalog_id=?'); $stmt->bind_param('s',$id); $stmt->execute(); $existing=$stmt->get_result()->fetch_assoc();
      $imagePath=$existing['image_path']??null;
      if (isset($_FILES['book_image']) && $_FILES['book_image']['error']!==UPLOAD_ERR_NO_FILE) {
        if ($_FILES['book_image']['error']!==UPLOAD_ERR_OK) throw new Exception('Book image upload failed. Try again.');
        if ((int)$_FILES['book_image']['size']>3*1024*1024) throw new Exception('Book image must be 3 MB or smaller.');
        $finfo=new finfo(FILEINFO_MIME_TYPE); $mime=$finfo->file($_FILES['book_image']['tmp_name']);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($allowed[$mime])) throw new Exception('Upload a JPG, PNG or WEBP book image.');
        $uploadDir=__DIR__.'/uploads/book-covers';
        if (!is_dir($uploadDir) && !mkdir($uploadDir,0755,true) && !is_dir($uploadDir)) throw new Exception('Could not create book image folder.');
        $fileName=bin2hex(random_bytes(16)).'.'.$allowed[$mime];
        if (!move_uploaded_file($_FILES['book_image']['tmp_name'],$uploadDir.'/'.$fileName)) throw new Exception('Could not save the book image.');
        $imagePath='uploads/book-covers/'.$fileName;
      }
      if ($existing) {
        $stmt=$conn->prepare('UPDATE book_catalog SET title=?,author=?,book_type=?,image_path=? WHERE catalog_id=?'); $stmt->bind_param('sssss',$title,$author,$type,$imagePath,$id); $stmt->execute();
        flash('Catalog details updated. To add more copies, use Add physical copy below.');
      } else {
        $conn->begin_transaction();
        $stmt=$conn->prepare('INSERT INTO book_catalog(catalog_id,title,author,image_path,book_type,total_copies,available_copies) VALUES (?,?,?,?,?,0,0)'); $stmt->bind_param('sssss',$id,$title,$author,$imagePath,$type); $stmt->execute();
        for ($i=1; $i<=$initialCopies; $i++) {
          $qr=sprintf('%s-COPY-%03d',$id,$i);
          $stmt=$conn->prepare("INSERT INTO physical_books(book_qr_id,catalog_id,status) VALUES (?, ?, 'AVAILABLE')"); $stmt->bind_param('ss',$qr,$id); $stmt->execute();
          $stmt=$conn->prepare('UPDATE book_catalog SET total_copies=total_copies+1, available_copies=available_copies+1 WHERE catalog_id=?'); $stmt->bind_param('s',$id); $stmt->execute();
        }
        $conn->commit();
        flash('Catalog title saved with '.$initialCopies.' physical copy/copies. QR IDs were generated automatically.');
      }
    } elseif ($action==='add_copy') {
      $catalog=trim($_POST['catalog_id']??''); $qr=trim($_POST['book_qr_id']??'');
      if (!$catalog||!$qr) throw new Exception('Catalog ID and QR ID are required.');
      $conn->begin_transaction();
      $stmt=$conn->prepare('SELECT catalog_id FROM book_catalog WHERE catalog_id=? FOR UPDATE'); $stmt->bind_param('s',$catalog); $stmt->execute(); if (!$stmt->get_result()->fetch_assoc()) throw new Exception('Catalog entry not found.');
      $stmt=$conn->prepare("INSERT INTO physical_books(book_qr_id,catalog_id,status) VALUES (?,?, 'AVAILABLE')"); $stmt->bind_param('ss',$qr,$catalog); $stmt->execute();
      $stmt=$conn->prepare('UPDATE book_catalog SET total_copies=total_copies+1, available_copies=available_copies+1 WHERE catalog_id=?'); $stmt->bind_param('s',$catalog); $stmt->execute(); $conn->commit(); flash('Physical copy added.');
    } elseif ($action==='counter') {
      $roll=trim($_POST['roll_number']??''); $qr=trim($_POST['book_qr_id']??''); $op=$_POST['operation']??'ISSUE';
      $conn->begin_transaction();
      $stmt=$conn->prepare('SELECT * FROM users WHERE roll_or_staff_id=? FOR UPDATE'); $stmt->bind_param('s',$roll); $stmt->execute(); $student=$stmt->get_result()->fetch_assoc();
      if (!$student || $student['role']!=='STUDENT') throw new Exception('Student ID not found.');
      $stmt=$conn->prepare('SELECT p.*,c.catalog_id,c.book_type,c.title FROM physical_books p JOIN book_catalog c ON c.catalog_id=p.catalog_id WHERE p.book_qr_id=? FOR UPDATE'); $stmt->bind_param('s',$qr); $stmt->execute(); $book=$stmt->get_result()->fetch_assoc();
      if (!$book) throw new Exception('Physical book QR ID not found.');
      if ($op==='ISSUE') {
        if ($book['status']==='RESERVED') {
          $stmt=$conn->prepare("SELECT * FROM reservations WHERE roll_number=? AND catalog_id=? AND book_qr_id=? AND status='ACTIVE' AND expires_at>NOW() ORDER BY reserved_at LIMIT 1 FOR UPDATE"); $stmt->bind_param('sss',$roll,$book['catalog_id'],$qr); $stmt->execute(); $res=$stmt->get_result()->fetch_assoc();
          if (!$res) throw new Exception('This copy is reserved by another student or the reservation expired.');
          // The reservation already occupies one quota slot, so fulfilling it must not count that slot twice.
          if (activeQuotaCount($conn,$roll)>maxQuota($student)) throw new Exception('Student quota is full.');
          $stmt=$conn->prepare("UPDATE reservations SET status='FULFILLED' WHERE reservation_id=?"); $stmt->bind_param('i',$res['reservation_id']); $stmt->execute();
        } elseif ($book['status']==='AVAILABLE') {
          if (activeQuotaCount($conn,$roll)>=maxQuota($student)) throw new Exception('Student quota is full.');
          $stmt=$conn->prepare('UPDATE book_catalog SET available_copies=available_copies-1 WHERE catalog_id=? AND available_copies>0'); $stmt->bind_param('s',$book['catalog_id']); $stmt->execute(); if(!$stmt->affected_rows) throw new Exception('No available stock.');
        } else throw new Exception('This copy is not available.');
        $stmt=$conn->prepare("UPDATE physical_books SET status='ISSUED' WHERE book_qr_id=?"); $stmt->bind_param('s',$qr); $stmt->execute();
        $stmt=$conn->prepare("INSERT INTO loans(roll_number,book_qr_id,issue_date,due_date,status) VALUES (?,?,NOW(),DATE_ADD(NOW(),INTERVAL 15 DAY),'ISSUED')"); $stmt->bind_param('ss',$roll,$qr); $stmt->execute();
        flash('Book issued. Due date is 15 days from now.');
      } elseif ($op==='RETURN') {
        $stmt=$conn->prepare("SELECT * FROM loans WHERE roll_number=? AND book_qr_id=? AND status IN ('ISSUED','OVERDUE') ORDER BY issue_date DESC LIMIT 1 FOR UPDATE"); $stmt->bind_param('ss',$roll,$qr); $stmt->execute(); $loan=$stmt->get_result()->fetch_assoc();
        if (!$loan) throw new Exception('No active loan matches this student and QR ID.');
        accrueFine($conn,(int)$loan['loan_id'],$roll,$loan['due_date'],date('Y-m-d H:i:s'));
        $stmt=$conn->prepare("UPDATE loans SET status='RETURNED',returned_date=NOW() WHERE loan_id=?"); $stmt->bind_param('i',$loan['loan_id']); $stmt->execute();
        $stmt=$conn->prepare("UPDATE physical_books SET status='AVAILABLE' WHERE book_qr_id=?"); $stmt->bind_param('s',$qr); $stmt->execute();
        $stmt=$conn->prepare('UPDATE book_catalog SET available_copies=LEAST(total_copies,available_copies+1) WHERE catalog_id=?'); $stmt->bind_param('s',$book['catalog_id']); $stmt->execute();
        if (strtotime($loan['due_date']) >= strtotime(date('Y-m-d H:i:s'))) {
          $stmt=$conn->prepare('UPDATE users SET token_balance=token_balance+3 WHERE roll_or_staff_id=?'); $stmt->bind_param('s',$roll); $stmt->execute();
          $stmt=$conn->prepare("INSERT INTO token_transactions(roll_number,amount,reason) VALUES (?,3,'RETURN_REWARD')"); $stmt->bind_param('s',$roll); $stmt->execute();
        }
        flash('Book returned. Fine updated if overdue; eligible on-time return earned 3 tokens.');
      } elseif ($op==='RENEW') {
        $stmt=$conn->prepare("SELECT * FROM loans WHERE roll_number=? AND book_qr_id=? AND status IN ('ISSUED','OVERDUE') ORDER BY issue_date DESC LIMIT 1 FOR UPDATE"); $stmt->bind_param('ss',$roll,$qr); $stmt->execute(); $loan=$stmt->get_result()->fetch_assoc();
        if (!$loan) throw new Exception('No active loan matches this student and QR ID.');
        $now=new DateTime(); $issue=new DateTime($loan['issue_date']); $day=(int)$issue->diff($now)->format('%r%a');
        if ($day < 14 || $day > 15) throw new Exception('Renewal allowed only on Day 15 or Day 16 morning. This loan is currently Day '.($day+1).'.');
        if (strtotime($loan['due_date']) < strtotime(date('Y-m-d H:i:s'))) accrueFine($conn,(int)$loan['loan_id'],$roll,$loan['due_date'],date('Y-m-d H:i:s'));
        $stmt=$conn->prepare("UPDATE loans SET issue_date=NOW(), due_date=DATE_ADD(NOW(),INTERVAL 15 DAY), status='ISSUED' WHERE loan_id=?"); $stmt->bind_param('i',$loan['loan_id']); $stmt->execute();
        if (strtotime($loan['due_date']) >= strtotime(date('Y-m-d H:i:s'))) {
          $stmt=$conn->prepare('UPDATE users SET token_balance=token_balance+3 WHERE roll_or_staff_id=?'); $stmt->bind_param('s',$roll); $stmt->execute();
          $stmt=$conn->prepare("INSERT INTO token_transactions(roll_number,amount,reason) VALUES (?,3,'RETURN_REWARD')"); $stmt->bind_param('s',$roll); $stmt->execute();
        }
        flash('Renewed for 15 days from now. Eligible on-time renewal earned 3 tokens.');
      } else throw new Exception('Unknown counter action.');
      $conn->commit();
    } elseif ($action==='delete_book') {
      $id=trim($_POST['catalog_id']??'');
      $stmt=$conn->prepare("SELECT COUNT(*) n FROM physical_books p WHERE p.catalog_id=? AND p.status<>'AVAILABLE'"); $stmt->bind_param('s',$id); $stmt->execute();
      if ((int)$stmt->get_result()->fetch_assoc()['n']>0) throw new Exception('Cannot delete: a copy is reserved, issued or in maintenance.');
      $stmt=$conn->prepare('DELETE FROM physical_books WHERE catalog_id=?'); $stmt->bind_param('s',$id); $stmt->execute();
      $stmt=$conn->prepare('DELETE FROM book_catalog WHERE catalog_id=?'); $stmt->bind_param('s',$id); $stmt->execute(); flash('Catalog entry deleted.');
    }
  } catch(Throwable $e) { if ($conn->errno || $conn->connect_errno===0) { try{$conn->rollback();}catch(Throwable $ignore){} } flash($e->getMessage(),'danger'); }
  $redirectSection = ['counter'=>'counter','add_copy'=>'inventory','save_book'=>'catalog','delete_book'=>'inventory'];
  header('Location: faculty_dashboard.php?section='.urlencode($redirectSection[$action] ?? 'dashboard')); exit;
}
$books=$conn->query('SELECT * FROM book_catalog ORDER BY title')->fetch_all(MYSQLI_ASSOC);
$students=$conn->query("SELECT roll_or_staff_id,full_name,caste_category,token_balance,fine_balance FROM users WHERE role='STUDENT' ORDER BY full_name LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$reservationsAdmin=$conn->query("SELECT r.reservation_id,r.roll_number,r.catalog_id,r.book_qr_id,r.reserved_at,r.expires_at,r.status,u.full_name,c.title,c.book_type FROM reservations r JOIN users u ON u.roll_or_staff_id=r.roll_number JOIN book_catalog c ON c.catalog_id=r.catalog_id ORDER BY r.reserved_at DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$section = $_GET['section'] ?? 'dashboard';
$allowedSections = ['dashboard','counter','catalog','inventory','reservations','students'];
if (!in_array($section, $allowedSections, true)) $section = 'dashboard';
$pageTitles = [
  'dashboard'=>'Admin Dashboard', 'counter'=>'Issue / Return / Renew', 'catalog'=>'Manage Catalog',
  'inventory'=>'Book Inventory', 'reservations'=>'Reservations Management', 'students'=>'Student Accounts'
];
$pageTitle = $pageTitles[$section] . ' · Campus Library';
require 'includes/header.php';
$activeReservations = 0; $expiringReservations = 0;
foreach ($reservationsAdmin as $rr) {
  if ($rr['status']==='ACTIVE' && strtotime($rr['expires_at'])>time()) {
    $activeReservations++;
    if (strtotime($rr['expires_at']) <= time()+12*3600) $expiringReservations++;
  }
}
?>
<div class="admin-shell">
  <aside class="admin-sidebar">
    <div class="admin-brand"><div class="admin-brand-icon">📚</div><div><strong>Library Admin</strong><span>Management workspace</span></div></div>
    <div class="admin-nav-label">WORKSPACE</div>
    <a class="admin-nav-link <?= $section==='dashboard'?'active':'' ?>" href="faculty_dashboard.php?section=dashboard"><span>▦</span> Overview</a>
    <a class="admin-nav-link <?= $section==='counter'?'active':'' ?>" href="faculty_dashboard.php?section=counter"><span>⇄</span> Issue / Return / Renew</a>
    <a class="admin-nav-link <?= $section==='catalog'?'active':'' ?>" href="faculty_dashboard.php?section=catalog"><span>＋</span> Add / Edit Books</a>
    <a class="admin-nav-link <?= $section==='inventory'?'active':'' ?>" href="faculty_dashboard.php?section=inventory"><span>▤</span> Inventory & Copies</a>
    <a class="admin-nav-link <?= $section==='reservations'?'active':'' ?>" href="faculty_dashboard.php?section=reservations"><span>◷</span> Reservations <span class="admin-nav-count"><?= $activeReservations ?></span></a>
    <a class="admin-nav-link <?= $section==='students'?'active':'' ?>" href="faculty_dashboard.php?section=students"><span>♙</span> Student Accounts</a>
    <div class="admin-sidebar-bottom"><span class="admin-status-dot"></span> Local library system <a href="index.php">View public catalog ↗</a></div>
  </aside>
  <div class="admin-main">
    <div class="admin-topbar"><div><div class="eyebrow">CAMPUS LIBRARY · ADMIN</div><h1><?= h($pageTitles[$section]) ?></h1></div><a class="btn btn-outline-secondary btn-sm" href="index.php">View Catalog ↗</a></div>
    <?php if ($section==='dashboard'): ?>
      <div class="admin-welcome"><div><div class="eyebrow">YOUR WORKSPACE</div><h2>Good day, Library Admin</h2><p>Choose a task to get started. Each workspace keeps the tools you need in one place.</p></div><div class="admin-welcome-art">📚</div></div>
      <div class="admin-stat-grid">
        <div class="admin-stat"><span class="stat-icon blue">▤</span><span class="stat-label">Book titles</span><strong><?= count($books) ?></strong><small>Catalog entries</small></div>
        <div class="admin-stat"><span class="stat-icon green">✓</span><span class="stat-label">Physical copies</span><strong><?= (int)$conn->query('SELECT COUNT(*) n FROM physical_books')->fetch_assoc()['n'] ?></strong><small>Across all titles</small></div>
        <div class="admin-stat"><span class="stat-icon amber">◷</span><span class="stat-label">Active reservations</span><strong><?= $activeReservations ?></strong><small><?= $expiringReservations ?> expiring within 12 hours</small></div>
        <div class="admin-stat"><span class="stat-icon purple">♙</span><span class="stat-label">Student accounts</span><strong><?= count($students) ?></strong><small>Latest 100 accounts</small></div>
      </div>
      <div class="admin-section-heading"><div><h2>Quick actions</h2><p>Open a workspace to complete a task.</p></div></div>
      <div class="admin-action-grid">
        <a class="admin-action-card" href="faculty_dashboard.php?section=counter"><span class="action-icon blue">⇄</span><strong>Counter operations</strong><p>Issue books, accept returns, or renew a loan.</p><span class="action-link">Open counter →</span></a>
        <a class="admin-action-card" href="faculty_dashboard.php?section=catalog"><span class="action-icon green">＋</span><strong>Add or edit a book</strong><p>Add a title, cover image, book type, and initial copies.</p><span class="action-link">Manage catalog →</span></a>
        <a class="admin-action-card" href="faculty_dashboard.php?section=inventory"><span class="action-icon purple">▤</span><strong>Inventory & copies</strong><p>Search titles, check stock, and add later copies.</p><span class="action-link">View inventory →</span></a>
        <a class="admin-action-card" href="faculty_dashboard.php?section=reservations"><span class="action-icon amber">◷</span><strong>Reservations</strong><p>Review reserved copy IDs, students, and expiry times.</p><span class="action-link">Review reservations →</span></a>
        <a class="admin-action-card" href="faculty_dashboard.php?section=students"><span class="action-icon rose">♙</span><strong>Student accounts</strong><p>Find a student and view their category, tokens, and fines.</p><span class="action-link">View students →</span></a>
      </div>
      <div class="admin-note"><strong>Tip:</strong> Use the left navigation to switch between tasks. Your book and student records remain in the same database.</div>
    <?php elseif ($section==='counter'): ?>
      <div class="admin-page-intro"><p>Process a student's physical book at the library counter.</p></div>
      <div class="admin-content-grid">
        <section class="panel"><div class="section-head"><div><h2>Counter operation</h2><p>Enter the student and exact physical copy.</p></div><span class="admin-mini-icon">⇄</span></div>
          <form method="post" class="vstack gap-3"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="counter">
            <div><label class="form-label">Student roll number</label><input class="form-control" name="roll_number" required placeholder="e.g. 25H75A0502"></div>
            <div><label class="form-label">Physical book QR ID</label><input class="form-control" name="book_qr_id" required placeholder="e.g. 35817-COPY-002" autofocus></div>
            <div><label class="form-label">What do you want to do?</label><select class="form-select" name="operation"><option value="ISSUE">Issue book</option><option value="RETURN">Return book</option><option value="RENEW">Renew book</option></select></div>
            <button class="btn btn-primary w-100">Process counter action</button><p class="small text-muted mb-0">QR scanner devices that type into the focused field are supported. Camera scanning is not enabled.</p>
          </form>
        </section>
        <section class="panel help-panel"><span class="action-icon blue">ⓘ</span><h2>Counter checklist</h2><ol><li>Confirm the student's roll number.</li><li>Scan or enter the physical copy ID.</li><li>Select Issue, Return, or Renew.</li><li>Check the success message before moving to the next student.</li></ol><a href="faculty_dashboard.php?section=reservations" class="btn btn-outline-primary">Check reservations</a></section>
      </div>
    <?php elseif ($section==='catalog'): ?>
      <div class="admin-page-intro"><p>Create a new catalog title or update an existing title's details and cover.</p></div>
      <section class="panel"><div class="section-head"><div><h2>Book details</h2><p>Initial copies are generated automatically for new titles.</p></div><span class="admin-mini-icon">＋</span></div>
        <form method="post" enctype="multipart/form-data" class="row g-3"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="save_book">
          <div class="col-md-4"><label class="form-label">Catalog ID</label><input class="form-control" name="catalog_id" required placeholder="e.g. 46721"></div><div class="col-md-8"><label class="form-label">Book title</label><input class="form-control" name="title" required placeholder="Enter full title"></div>
          <div class="col-md-6"><label class="form-label">Author / Publisher</label><input class="form-control" name="author" required placeholder="Author or publisher"></div><div class="col-md-6"><label class="form-label">Book category</label><select class="form-select" name="book_type"><option value="ACADEMIC">Academic</option><option value="COMPETITIVE">Competitive</option></select></div>
          <div class="col-md-6"><label class="form-label">Initial number of copies</label><input class="form-control" type="number" name="initial_copies" min="0" max="500" value="1" required><div class="form-text">Used only when creating a new title.</div></div><div class="col-md-6"><label class="form-label">Book cover image</label><input class="form-control" type="file" name="book_image" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG, or WEBP · maximum 3 MB · optional.</div></div>
          <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary">Save book details</button><a class="btn btn-outline-secondary" href="faculty_dashboard.php?section=inventory">View inventory</a></div>
        </form>
      </section>
      <div class="admin-note mt-3"><strong>For an existing Catalog ID:</strong> title, author, type, and image are updated. To add more copies later, open Inventory & Copies.</div>
    <?php elseif ($section==='inventory'): ?>
      <div class="admin-page-intro"><p>Search the catalog, check available stock, and add new physical copies when books are purchased.</p></div>
      <section class="panel"><div class="section-head"><div><h2>Book inventory</h2><p><?= count($books) ?> catalog titles</p></div><a class="btn btn-primary btn-sm" href="faculty_dashboard.php?section=catalog">＋ Add title</a></div>
        <div class="admin-toolbar"><input class="form-control table-search" data-target="inventory-table" placeholder="Search title, catalog ID, author…"><span class="small text-muted">Available / Total copies</span></div>
        <div class="table-responsive"><table id="inventory-table" class="table align-middle admin-table"><thead><tr><th>Book</th><th>Type</th><th>Stock</th><th>Action</th></tr></thead><tbody>
        <?php foreach($books as $b): ?><tr><td><div class="admin-book-cell"><?php if (!empty($b['image_path'])): ?><img src="<?= h($b['image_path']) ?>" alt="Book cover"><?php else: ?><div class="admin-cover-placeholder">📘</div><?php endif; ?><div><strong><?= h($b['title']) ?></strong><small><?= h($b['catalog_id']) ?> · <?= h($b['author']) ?></small></div></div></td><td><span class="type-pill <?= $b['book_type']==='ACADEMIC'?'academic':'competitive' ?>"><?= h($b['book_type']) ?></span></td><td><strong><?= (int)$b['available_copies'] ?></strong><span class="text-muted"> / <?= (int)$b['total_copies'] ?></span></td><td><button type="button" class="btn btn-outline-primary btn-sm" onclick="openCopyModal('<?= h($b['catalog_id']) ?>','<?= h($b['title']) ?>')">＋ Add copy</button> <form class="d-inline" method="post" onsubmit="return confirm('Delete this catalog title and its available copies?')"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete_book"><input type="hidden" name="catalog_id" value="<?= h($b['catalog_id']) ?>"><button class="btn btn-outline-danger btn-sm">Delete</button></form></td></tr><?php endforeach; ?>
        <?php if(!$books): ?><tr><td colspan="4" class="text-muted text-center py-4">No catalog titles found.</td></tr><?php endif; ?></tbody></table></div>
      </section>
      <div class="modal fade" id="copyModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post"><input type="hidden" name="csrf" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="add_copy"><div class="modal-header"><div><h2 class="modal-title fs-5">Add physical copy</h2><p class="small text-muted mb-0" id="copyModalBook"></p></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="mb-3"><label class="form-label">Catalog ID</label><input id="copyCatalogId" class="form-control" name="catalog_id" required readonly></div><div><label class="form-label">Unique QR ID</label><input class="form-control" name="book_qr_id" required placeholder="e.g. 46721-COPY-104"><div class="form-text">Enter the unique ID printed on the new copy.</div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Add copy</button></div></form></div></div></div>
    <?php elseif ($section==='reservations'): ?>
      <div class="admin-page-intro"><p>Track which student reserved which exact physical copy and when the 48-hour hold expires.</p></div>
      <section class="panel"><div class="section-head"><div><h2>Reservations</h2><p><?= count($reservationsAdmin) ?> recent records · <?= $activeReservations ?> active holds</p></div><span class="admin-mini-icon">◷</span></div>
        <div class="admin-toolbar"><input class="form-control table-search" data-target="reservations-table" placeholder="Search student, roll number, book, copy ID…"><select id="reservationStatusFilter" class="form-select"><option value="">All statuses</option><option>ACTIVE</option><option>FULFILLED</option><option>EXPIRED</option><option>CANCELLED</option></select></div>
        <div class="table-responsive"><table id="reservations-table" class="table align-middle admin-table"><thead><tr><th>Student</th><th>Book / Copy ID</th><th>Reservation timing</th><th>Status</th></tr></thead><tbody>
        <?php foreach($reservationsAdmin as $r): ?><tr data-status="<?= h($r['status']) ?>"><td><strong><?= h($r['full_name']) ?></strong><small class="d-block text-muted"><?= h($r['roll_number']) ?></small></td><td><strong><?= h($r['title']) ?></strong><small class="d-block text-muted">Catalog: <?= h($r['catalog_id']) ?></small><span class="copy-id"><?= h($r['book_qr_id'] ?: 'Copy not linked (older reservation)') ?></span><small class="d-block text-muted"><?= h($r['book_type']) ?></small></td><td><small class="d-block">Reserved: <?= h($r['reserved_at']) ?></small><small class="d-block text-muted">Expires: <?= h($r['expires_at']) ?></small><?php if($r['status']==='ACTIVE' && strtotime($r['expires_at'])>time() && strtotime($r['expires_at'])<=time()+12*3600): ?><span class="badge text-bg-warning mt-1">Expiring soon</span><?php endif; ?></td><td><?php $statusClass=['ACTIVE'=>'text-bg-warning','FULFILLED'=>'text-bg-success','EXPIRED'=>'text-bg-secondary','CANCELLED'=>'text-bg-light']; ?><span class="badge <?= $statusClass[$r['status']] ?? 'text-bg-light' ?>"><?= h($r['status']) ?></span></td></tr><?php endforeach; ?>
        <?php if(!$reservationsAdmin): ?><tr><td colspan="4" class="text-muted text-center py-4">No reservations recorded yet.</td></tr><?php endif; ?></tbody></table></div><div id="reservationEmpty" class="text-muted small pt-2" hidden>No reservations match this search.</div>
      </section>
    <?php elseif ($section==='students'): ?>
      <div class="admin-page-intro"><p>Search student accounts and review their category, token balance, and outstanding fines.</p></div>
      <section class="panel"><div class="section-head"><div><h2>Student accounts</h2><p>Showing latest <?= count($students) ?> student accounts</p></div><span class="admin-mini-icon">♙</span></div>
        <div class="admin-toolbar"><input class="form-control table-search" data-target="students-table" placeholder="Search name or roll number…"><span class="small text-muted">Token balance · Fine balance</span></div>
        <div class="table-responsive"><table id="students-table" class="table align-middle admin-table"><thead><tr><th>Student</th><th>Category</th><th>Tokens</th><th>Fine balance</th></tr></thead><tbody><?php foreach($students as $s): ?><tr><td><strong><?= h($s['full_name']) ?></strong><small class="d-block text-muted"><?= h($s['roll_or_staff_id']) ?></small></td><td><span class="type-pill"><?= h($s['caste_category']) ?></span></td><td><strong><?= (int)$s['token_balance'] ?></strong></td><td>₹<?= number_format((float)$s['fine_balance'],2) ?></td></tr><?php endforeach; ?><?php if(!$students): ?><tr><td colspan="4" class="text-muted text-center py-4">No student accounts found.</td></tr><?php endif; ?></tbody></table></div>
      </section>
    <?php endif; ?>
  </div>
</div>
<script>
function openCopyModal(catalogId, title) {
  document.getElementById('copyCatalogId').value = catalogId;
  document.getElementById('copyModalBook').textContent = title;
  bootstrap.Modal.getOrCreateInstance(document.getElementById('copyModal')).show();
}
document.querySelectorAll('.table-search').forEach(function(input) {
  input.addEventListener('input', function() {
    var table = document.getElementById(input.dataset.target); if (!table) return;
    var q = input.value.toLowerCase();
    table.querySelectorAll('tbody tr').forEach(function(row) { row.hidden = !row.innerText.toLowerCase().includes(q); });
    updateReservationEmpty();
  });
});
var statusFilter = document.getElementById('reservationStatusFilter');
if (statusFilter) statusFilter.addEventListener('change', function() {
  document.querySelectorAll('#reservations-table tbody tr').forEach(function(row) {
    row.hidden = (statusFilter.value && row.dataset.status !== statusFilter.value) || !row.innerText.toLowerCase().includes((document.querySelector('[data-target="reservations-table"]').value || '').toLowerCase());
  }); updateReservationEmpty();
});
function updateReservationEmpty() {
  var table = document.getElementById('reservations-table'), empty = document.getElementById('reservationEmpty');
  if (table && empty) empty.hidden = Array.from(table.querySelectorAll('tbody tr')).some(function(row){return !row.hidden;});
}
</script>
<?php require 'includes/footer.php'; ?>
