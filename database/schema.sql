CREATE DATABASE IF NOT EXISTS btsu
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE btsu;

CREATE TABLE IF NOT EXISTS admins (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  username VARCHAR(60) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('Admin', 'Super Admin') NOT NULL DEFAULT 'Admin',
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  failed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_username_time (username, failed_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS units (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS right_categories (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS districts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS villages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  district_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_villages_district_name (district_id, name),
  INDEX idx_villages_name (name),
  CONSTRAINT fk_villages_district FOREIGN KEY (district_id) REFERENCES districts(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS borrowers (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  employee_id VARCHAR(60) NULL,
  unit_id BIGINT UNSIGNED NULL,
  phone VARCHAR(40) NULL,
  email VARCHAR(190) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_borrowers_name (name),
  UNIQUE KEY uq_borrowers_employee_id (employee_id),
  CONSTRAINT fk_borrowers_unit FOREIGN KEY (unit_id) REFERENCES units(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS documents (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  province VARCHAR(80) NOT NULL DEFAULT 'Jawa Tengah',
  city VARCHAR(100) NOT NULL DEFAULT 'Semarang',
  district VARCHAR(100) NOT NULL,
  village VARCHAR(120) NOT NULL,
  right_category_id BIGINT UNSIGNED NOT NULL,
  right_number VARCHAR(80) NOT NULL,
  document_date DATE NULL,
  floor INT UNSIGNED NOT NULL,
  cabinet INT UNSIGNED NOT NULL,
  rack VARCHAR(30) NOT NULL,
  album VARCHAR(80) NULL,
  condition_state ENUM('Baik','Perlu perbaikan','Rusak') NOT NULL DEFAULT 'Baik',
  status ENUM('Tersedia','Dipinjam','Hilang','Nonaktif') NOT NULL DEFAULT 'Tersedia',
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_documents_location_number (district, village, right_category_id, right_number),
  INDEX idx_documents_status (status),
  INDEX idx_documents_number (right_number),
  INDEX idx_documents_village (village),
  INDEX idx_documents_district (district),
  INDEX idx_documents_category (right_category_id),
  CONSTRAINT fk_documents_category FOREIGN KEY (right_category_id) REFERENCES right_categories(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loans (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  borrower_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  loan_date DATE NOT NULL,
  due_date DATE NOT NULL,
  purpose VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_loans_borrower (borrower_id),
  INDEX idx_loans_due_date (due_date),
  INDEX idx_loans_admin (admin_id),
  CONSTRAINT fk_loans_borrower FOREIGN KEY (borrower_id) REFERENCES borrowers(id),
  CONSTRAINT fk_loans_admin FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loan_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL,
  document_id BIGINT UNSIGNED NOT NULL,
  returned_at DATE NULL,
  return_condition ENUM('Baik','Perlu perbaikan','Rusak','Hilang') NULL,
  return_note TEXT NULL,
  active_document_id BIGINT UNSIGNED GENERATED ALWAYS AS (
    CASE WHEN returned_at IS NULL THEN document_id ELSE NULL END
  ) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_one_active_loan_per_document (active_document_id),
  INDEX idx_loan_items_loan (loan_id),
  INDEX idx_loan_items_document (document_id),
  INDEX idx_loan_items_returned (returned_at),
  CONSTRAINT fk_loan_items_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
  CONSTRAINT fk_loan_items_document FOREIGN KEY (document_id) REFERENCES documents(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loan_extensions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  loan_id BIGINT UNSIGNED NOT NULL,
  admin_id BIGINT UNSIGNED NOT NULL,
  old_due_date DATE NOT NULL,
  new_due_date DATE NOT NULL,
  reason VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_loan_extensions_loan (loan_id),
  CONSTRAINT fk_extensions_loan FOREIGN KEY (loan_id) REFERENCES loans(id),
  CONSTRAINT fk_extensions_admin FOREIGN KEY (admin_id) REFERENCES admins(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(60) NOT NULL PRIMARY KEY,
  setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  admin_id BIGINT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_logs_created_at (created_at),
  CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('default_loan_days', '14'),
  ('max_extensions', '2');

INSERT IGNORE INTO right_categories (name) VALUES
  ('Hak Milik'),
  ('Hak Guna Usaha'),
  ('Hak Guna Bangunan'),
  ('Hak Pakai'),
  ('Hak Pengelolaan'),
  ('Hak Wakaf'),
  ('Hak Milik Satuan Rumah Susun');

INSERT IGNORE INTO units (name) VALUES
  ('Seksi Penetapan Hak dan Pendaftaran'),
  ('Seksi Survei dan Pemetaan'),
  ('Subbagian Tata Usaha');

INSERT IGNORE INTO districts (name) VALUES
  ('Banyumanik'),
  ('Candisari'),
  ('Gajahmungkur'),
  ('Gayamsari'),
  ('Genuk'),
  ('Gunungpati'),
  ('Mijen'),
  ('Ngaliyan'),
  ('Pedurungan'),
  ('Semarang Barat'),
  ('Semarang Selatan'),
  ('Semarang Tengah'),
  ('Semarang Timur'),
  ('Semarang Utara'),
  ('Tembalang'),
  ('Tugu');
