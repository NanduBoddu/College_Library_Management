<?php
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8'); }

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}
function showFlash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash']; unset($_SESSION['flash']);
        echo '<div class="alert alert-'.h($f['type']).'">'.h($f['message']).'</div>';
    }
}
function requireLogin(): void {
    if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
}
function requireFaculty(): void {
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'FACULTY') { http_response_code(403); exit('Faculty access only.'); }
}
function currentUser(mysqli $conn): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $stmt = $conn->prepare('SELECT roll_or_staff_id, full_name, role, caste_category, token_balance, fine_balance FROM users WHERE roll_or_staff_id=?');
    $stmt->bind_param('s', $_SESSION['user_id']); $stmt->execute();
    return $stmt->get_result()->fetch_assoc() ?: null;
}
function maxQuota(array $user): int {
    return in_array($user['caste_category'], ['SC','ST'], true) ? 5 : 2;
}
function activeQuotaCount(mysqli $conn, string $roll): int {
    $stmt = $conn->prepare("SELECT
      (SELECT COUNT(*) FROM loans WHERE roll_number=? AND status IN ('ISSUED','OVERDUE')) +
      (SELECT COUNT(*) FROM reservations WHERE roll_number=? AND status='ACTIVE' AND expires_at > NOW()) AS n");
    $stmt->bind_param('ss', $roll, $roll); $stmt->execute();
    return (int)$stmt->get_result()->fetch_assoc()['n'];
}
function syncOverdue(mysqli $conn): void {
    // Fine is assessed once per overdue calendar day after the due date; returns/renewals settle through the same calculation.
    $conn->query("UPDATE loans SET status='OVERDUE' WHERE status='ISSUED' AND due_date < NOW() AND returned_date IS NULL");
}
function accrueFine(mysqli $conn, int $loanId, string $roll, string $dueDate, ?string $returnedDate = null): void {
    $end = $returnedDate ?: date('Y-m-d H:i:s');
    $due = new DateTime($dueDate); $to = new DateTime($end);
    $dueDay = new DateTime($due->format('Y-m-d'));
    $toDay = new DateTime($to->format('Y-m-d'));
    $days = (int)$dueDay->diff($toDay)->format('%r%a');
    if ($days <= 0) return;
    $stmt = $conn->prepare('UPDATE users SET fine_balance=fine_balance + ? WHERE roll_or_staff_id=?');
    $fine = $days * 5.0; $stmt->bind_param('ds', $fine, $roll); $stmt->execute();
}
function expireReservations(mysqli $conn): void {
    // Atomic expiry: release reserved physical copy, restore catalog availability and forfeit already-spent tokens.
    $conn->begin_transaction();
    try {
        $rows = $conn->query("SELECT reservation_id, roll_number, catalog_id, book_qr_id FROM reservations WHERE status='ACTIVE' AND expires_at <= NOW() FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $stmt = $conn->prepare("UPDATE reservations SET status='EXPIRED' WHERE reservation_id=? AND status='ACTIVE'");
            $stmt->bind_param('i', $r['reservation_id']); $stmt->execute();
            $stmt = $conn->prepare("UPDATE physical_books SET status='AVAILABLE' WHERE book_qr_id=? AND status='RESERVED'");
            $stmt->bind_param('s', $r['book_qr_id']); $stmt->execute();
            if ($stmt->affected_rows > 0) {
                $stmt = $conn->prepare("UPDATE book_catalog SET available_copies=LEAST(total_copies, available_copies+1) WHERE catalog_id=?");
                $stmt->bind_param('s', $r['catalog_id']); $stmt->execute();
            }
            $stmt = $conn->prepare("INSERT INTO token_transactions (roll_number, amount, reason) VALUES (?,0,'EXPIRATION_PENALTY')");
            $stmt->bind_param('s', $r['roll_number']); $stmt->execute();
        }
        $conn->commit();
    } catch (Throwable $e) { $conn->rollback(); }
}
function tokenCost(string $type): int { return $type === 'COMPETITIVE' ? 10 : 5; }
function csrfToken(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function verifyCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid security token. Refresh the page and try again.');
    }
}
