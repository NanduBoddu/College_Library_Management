CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

CREATE TABLE users (
  roll_or_staff_id VARCHAR(50) PRIMARY KEY,
  full_name VARCHAR(100) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('STUDENT','FACULTY') NOT NULL DEFAULT 'STUDENT',
  caste_category ENUM('SC','ST','OC','OBC','FACULTY') NOT NULL DEFAULT 'OC',
  token_balance INT NOT NULL DEFAULT 0,
  fine_balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (token_balance >= 0),
  CHECK (fine_balance >= 0)
) ENGINE=InnoDB;

CREATE TABLE book_catalog (
  catalog_id VARCHAR(50) PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  author VARCHAR(100) NOT NULL,
  image_path VARCHAR(255) NULL,
  book_type ENUM('ACADEMIC','COMPETITIVE') NOT NULL,
  total_copies INT NOT NULL DEFAULT 0,
  available_copies INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CHECK (total_copies >= 0),
  CHECK (available_copies >= 0 AND available_copies <= total_copies)
) ENGINE=InnoDB;

CREATE TABLE physical_books (
  book_qr_id VARCHAR(50) PRIMARY KEY,
  catalog_id VARCHAR(50) NOT NULL,
  status ENUM('AVAILABLE','RESERVED','ISSUED','MAINTENANCE') NOT NULL DEFAULT 'AVAILABLE',
  CONSTRAINT fk_physical_catalog FOREIGN KEY (catalog_id) REFERENCES book_catalog(catalog_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_physical_catalog_status (catalog_id, status)
) ENGINE=InnoDB;

CREATE TABLE reservations (
  reservation_id INT AUTO_INCREMENT PRIMARY KEY,
  roll_number VARCHAR(50) NOT NULL,
  catalog_id VARCHAR(50) NOT NULL,
  book_qr_id VARCHAR(50) NULL,
  reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  status ENUM('ACTIVE','FULFILLED','EXPIRED','CANCELLED') NOT NULL DEFAULT 'ACTIVE',
  CONSTRAINT fk_res_user FOREIGN KEY (roll_number) REFERENCES users(roll_or_staff_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_res_catalog FOREIGN KEY (catalog_id) REFERENCES book_catalog(catalog_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_res_physical_book FOREIGN KEY (book_qr_id) REFERENCES physical_books(book_qr_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_res_copy_status (book_qr_id, status),
  INDEX idx_res_expiry_status (status, expires_at),
  INDEX idx_res_user_status (roll_number, status)
) ENGINE=InnoDB;

CREATE TABLE loans (
  loan_id INT AUTO_INCREMENT PRIMARY KEY,
  roll_number VARCHAR(50) NOT NULL,
  book_qr_id VARCHAR(50) NOT NULL,
  issue_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_date DATETIME NOT NULL,
  returned_date DATETIME NULL,
  status ENUM('ISSUED','RETURNED','OVERDUE') NOT NULL DEFAULT 'ISSUED',
  last_fine_calculated_date DATE NULL,
  CONSTRAINT fk_loan_user FOREIGN KEY (roll_number) REFERENCES users(roll_or_staff_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_loan_book FOREIGN KEY (book_qr_id) REFERENCES physical_books(book_qr_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_loan_user_status (roll_number, status),
  INDEX idx_loan_due_status (due_date, status)
) ENGINE=InnoDB;

CREATE TABLE token_transactions (
  transaction_id INT AUTO_INCREMENT PRIMARY KEY,
  roll_number VARCHAR(50) NOT NULL,
  amount INT NOT NULL,
  reason ENUM('RESERVATION','RETURN_REWARD','EXPIRATION_PENALTY') NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_token_user FOREIGN KEY (roll_number) REFERENCES users(roll_or_staff_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  INDEX idx_token_user_created (roll_number, created_at)
) ENGINE=InnoDB;

-- Create a faculty password hash in PHP using:
-- echo password_hash('ChangeThisPassword!', PASSWORD_DEFAULT);
-- Then insert a faculty account with that generated hash.
