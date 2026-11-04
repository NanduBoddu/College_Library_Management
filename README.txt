COLLEGE LIBRARY MANAGEMENT SYSTEM — XAMPP LOCAL SETUP

REQUIREMENTS
- XAMPP with Apache, MySQL/MariaDB
- PHP 8+ recommended
- Internet is only needed for Bootstrap/Google Fonts CDN styling; core backend runs locally.

INSTALL
1. Extract college_library.zip into C:\xampp\htdocs\
   Expected folder: C:\xampp\htdocs\college_library\
2. Open XAMPP Control Panel and start Apache and MySQL.
3. Visit http://localhost/phpmyadmin
4. Import database.sql (it creates library_db and all tables).
5. If you already imported an older database, run the one-time image-column migration shown below.
6. Create the first faculty account using the one-time setup snippet below.
7. Visit http://localhost/college_library/login.php
8. Register students via http://localhost/college_library/register.php

UPGRADE EXISTING DATABASE (RUN ONCE ONLY IF DATABASE ALREADY EXISTS)
In phpMyAdmin select library_db > SQL and run:
ALTER TABLE book_catalog ADD COLUMN image_path VARCHAR(255) NULL AFTER author;
Do not run this migration if image_path already exists, otherwise MySQL reports a duplicate-column error.

BOOK COVERS AND COPIES
- Add/edit catalog title supports an optional JPG, PNG or WEBP cover image (max 3 MB).
- When creating a new title, set Initial number of copies; QR IDs are generated as CATALOGID-COPY-001, etc.
- Existing catalog IDs update title details only. Use Add physical copy to add more stock later with your own unique QR ID.
- Cover images are stored in uploads/book-covers/.

CREATE FIRST FACULTY USER
Open a terminal in the project folder and run:
php -r "echo password_hash('ChangeThisPassword!', PASSWORD_DEFAULT), PHP_EOL;"
Copy the printed hash. In phpMyAdmin SQL tab, run (replace HASH_HERE):
INSERT INTO users (roll_or_staff_id, full_name, password_hash, role, caste_category)
VALUES ('FAC001', 'Library Faculty', 'HASH_HERE', 'FACULTY', 'FACULTY');

Then log in with ID FAC001 and password ChangeThisPassword!
Change this password immediately from Settings.

FEATURES
- Student registration with category quota (SC/ST 5, OC/OBC 2)
- Unified token balance; academic reservation 5 tokens, competitive 10
- 48-hour online reservation expiry; expired tokens are not refunded
- Physical book copy QR IDs; keyboard-wedge USB scanners work by typing into QR input
- Catalog cover image upload and initial-copy generation; later copies can be added individually
- Faculty issue, return and renewal counter
- ₹5/day overdue fine calculation on return/renewal
- On-time return/renewal reward of 3 tokens
- Password change, student dashboard, reservation countdown, token history

IMPORTANT IMPLEMENTATION NOTES
- The browser's countdown is visual only. Server-side expiry is enforced on each request.
- For production, add HTTPS, rate limiting, audit logs, backups, stronger admin provisioning and scheduled fine accrual.
- Fine calculation in this starter applies the overdue amount when the book is returned or renewed; it is not a payment gateway.
- This project is a runnable starter implementation. Test all business rules with sample accounts/books before using real library data.

RESERVATION COPY TRACKING UPDATE (for an existing database)
1. Back up library_db in phpMyAdmin (Export).
2. In phpMyAdmin select library_db, open SQL, and run migration_reservation_copy_tracking.sql once.
3. Replace the old college_library folder with the updated folder, preserving uploads/book-covers if you have uploaded covers.
4. Do not re-import database.sql into an existing database.
5. New reservations store the exact physical copy ID. Older reservations created before the migration may show “Copy not linked”; verify/reconcile these before issuing.
