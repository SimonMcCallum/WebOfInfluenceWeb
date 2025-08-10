-- Web Of Influence — Enhanced Schema with Better Person Identification
-- This schema adds address, electorate, and party information to people table
-- for better duplicate detection and person matching

-- Enhanced people table with additional identification fields
CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255) NOT NULL,
  address TEXT NULL,
  suburb VARCHAR(255) NULL,
  city VARCHAR(255) NULL,
  postal_code VARCHAR(20) NULL,
  electorate_name VARCHAR(255) NULL,
  party_affiliation VARCHAR(255) NULL,
  comments TEXT NULL,
  normalized_name VARCHAR(512) NULL, -- for fuzzy matching
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_people_names (first_name, last_name),
  INDEX idx_people_normalized (normalized_name),
  INDEX idx_people_electorate (electorate_name),
  INDEX idx_people_suburb_city (suburb, city)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  short_name VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_parties_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS electorates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  region VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_electorates_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhanced donors table
CREATE TABLE IF NOT EXISTS donors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NULL,
  last_name  VARCHAR(255) NULL,
  org_name   VARCHAR(255) NULL,
  address TEXT NULL,
  normalized_name VARCHAR(512) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_donors_normalized (normalized_name),
  INDEX idx_donors_names (first_name, last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Candidate overview (yearly aggregates per candidate)
CREATE TABLE IF NOT EXISTS candidate_overview (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year CHAR(4) NOT NULL,
  people_id INT NOT NULL,
  party_id INT NULL,
  electorate_id INT NULL,
  total_donations DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_expenses  DECIMAL(12,2) NOT NULL DEFAULT 0,
  part_a DECIMAL(12,2) NULL,
  part_b DECIMAL(12,2) NULL,
  part_c DECIMAL(12,2) NULL,
  part_d DECIMAL(12,2) NULL,
  part_f DECIMAL(12,2) NULL,
  part_g DECIMAL(12,2) NULL,
  part_h DECIMAL(12,2) NULL,
  original_id VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT uq_candidate_overview UNIQUE (year, people_id),
  CONSTRAINT fk_co_people     FOREIGN KEY (people_id)     REFERENCES people(id),
  CONSTRAINT fk_co_parties    FOREIGN KEY (party_id)      REFERENCES parties(id),
  CONSTRAINT fk_co_electorate FOREIGN KEY (electorate_id) REFERENCES electorates(id),
  INDEX idx_co_year (year),
  INDEX idx_co_party_year (party_id, year),
  INDEX idx_co_electorate_year (electorate_id, year),
  INDEX idx_co_people_year (people_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enhanced donations table
CREATE TABLE IF NOT EXISTS donations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year CHAR(4) NOT NULL,
  date DATE NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  money_or_goods_services VARCHAR(255) NULL,
  location VARCHAR(255) NULL,
  notes TEXT NULL,
  donor_id INT NULL,
  candidate_person_id INT NOT NULL,
  candidate_overview_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_donations_donor    FOREIGN KEY (donor_id)            REFERENCES donors(id),
  CONSTRAINT fk_donations_person   FOREIGN KEY (candidate_person_id) REFERENCES people(id),
  CONSTRAINT fk_donations_overview FOREIGN KEY (candidate_overview_id) REFERENCES candidate_overview(id),
  INDEX idx_donations_candidate_year (candidate_person_id, year),
  INDEX idx_donations_donor_year    (donor_id, year),
  INDEX idx_donations_year_amount   (year, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ministerial meetings
CREATE TABLE IF NOT EXISTS meetings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  date DATE NULL,
  start_time TIME NULL,
  end_time TIME NULL,
  location VARCHAR(255) NULL,
  notes TEXT NULL,
  type VARCHAR(255) NULL,
  portfolio VARCHAR(255) NULL,
  title VARCHAR(255) NULL,
  minister_person_id INT NOT NULL,
  with_text TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_meetings_minister FOREIGN KEY (minister_person_id) REFERENCES people(id),
  INDEX idx_meetings_minister_date (minister_person_id, date),
  INDEX idx_meetings_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import log table for tracking CSV imports and errors
CREATE TABLE IF NOT EXISTS import_logs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  table_name VARCHAR(255) NOT NULL,
  total_rows INT NOT NULL DEFAULT 0,
  successful_rows INT NOT NULL DEFAULT 0,
  failed_rows INT NOT NULL DEFAULT 0,
  duplicate_rows INT NOT NULL DEFAULT 0,
  status ENUM('processing', 'completed', 'failed', 'partial') NOT NULL DEFAULT 'processing',
  error_summary TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at TIMESTAMP NULL,
  INDEX idx_import_logs_status (status),
  INDEX idx_import_logs_table (table_name),
  INDEX idx_import_logs_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Import errors table for detailed error tracking
CREATE TABLE IF NOT EXISTS import_errors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  import_log_id INT NOT NULL,
  row_number INT NOT NULL,
  error_type ENUM('validation', 'duplicate', 'constraint', 'other') NOT NULL,
  error_message TEXT NOT NULL,
  row_data JSON NULL,
  suggested_action VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_import_errors_log FOREIGN KEY (import_log_id) REFERENCES import_logs(id) ON DELETE CASCADE,
  INDEX idx_import_errors_log (import_log_id),
  INDEX idx_import_errors_type (error_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Duplicate candidates table for interactive resolution
CREATE TABLE IF NOT EXISTS duplicate_candidates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  import_log_id INT NOT NULL,
  row_number INT NOT NULL,
  new_person_data JSON NOT NULL,
  matched_person_id INT NULL,
  match_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  match_reasons TEXT NULL,
  status ENUM('pending', 'approved', 'rejected', 'merged') NOT NULL DEFAULT 'pending',
  resolved_by VARCHAR(255) NULL,
  resolved_at TIMESTAMP NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_duplicate_import_log FOREIGN KEY (import_log_id) REFERENCES import_logs(id) ON DELETE CASCADE,
  CONSTRAINT fk_duplicate_matched_person FOREIGN KEY (matched_person_id) REFERENCES people(id),
  INDEX idx_duplicate_candidates_status (status),
  INDEX idx_duplicate_candidates_import (import_log_id),
  INDEX idx_duplicate_candidates_score (match_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
