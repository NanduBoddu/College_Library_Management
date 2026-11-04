<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
date_default_timezone_set('Asia/Kolkata');

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'library_db';

try {
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    $conn->set_charset('utf8mb4');
} catch (Throwable $e) {
    http_response_code(500);
    exit('Database connection failed. Start Apache/MySQL in XAMPP and import database.sql in phpMyAdmin.');
}

require_once __DIR__ . '/includes/functions.php';
expireReservations($conn);
