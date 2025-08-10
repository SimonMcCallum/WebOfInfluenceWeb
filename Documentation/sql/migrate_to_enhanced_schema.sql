-- Migration script to update existing WoI database to enhanced schema
-- Run this to add new fields and tables to your existing database

-- Ensure import tracking tables exist
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

-- Add new columns to existing people table
ALTER TABLE people 
ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER last_name,
ADD COLUMN IF NOT EXISTS suburb VARCHAR(255) NULL AFTER address,
ADD COLUMN IF NOT EXISTS city VARCHAR(255) NULL AFTER suburb,
ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NULL AFTER city,
ADD COLUMN IF NOT EXISTS electorate_name VARCHAR(255) NULL AFTER postal_code,
ADD COLUMN IF NOT EXISTS party_affiliation VARCHAR(255) NULL AFTER electorate_name,
ADD COLUMN IF NOT EXISTS comments TEXT NULL AFTER party_affiliation,
ADD COLUMN IF NOT EXISTS normalized_name VARCHAR(512) NULL AFTER comments,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER normalized_name,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add indexes for new people columns
ALTER TABLE people 
ADD INDEX IF NOT EXISTS idx_people_names (first_name, last_name),
ADD INDEX IF NOT EXISTS idx_people_normalized (normalized_name),
ADD INDEX IF NOT EXISTS idx_people_electorate (electorate_name),
ADD INDEX IF NOT EXISTS idx_people_suburb_city (suburb, city);

-- Remove the unique constraint on people names if it exists (to allow better duplicate handling)
ALTER TABLE people DROP INDEX IF EXISTS uq_people_name;

-- Add new columns to existing parties table
ALTER TABLE parties 
ADD COLUMN IF NOT EXISTS short_name VARCHAR(50) NULL AFTER name,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER short_name,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add new columns to existing electorates table
ALTER TABLE electorates 
ADD COLUMN IF NOT EXISTS region VARCHAR(255) NULL AFTER name,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER region,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add new columns to existing donors table
ALTER TABLE donors 
ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER normalized_name,
ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER address,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add indexes for donors
ALTER TABLE donors 
ADD INDEX IF NOT EXISTS idx_donors_names (first_name, last_name);

-- Update existing donations table constraints (make donor_id nullable)
ALTER TABLE donations MODIFY COLUMN donor_id INT NULL;

-- Add new columns to existing donations table
ALTER TABLE donations 
ADD COLUMN IF NOT EXISTS location VARCHAR(255) NULL AFTER money_or_goods_services,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Add new columns to existing candidate_overview table
ALTER TABLE candidate_overview 
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Make party_id and electorate_id nullable in candidate_overview
ALTER TABLE candidate_overview 
MODIFY COLUMN party_id INT NULL,
MODIFY COLUMN electorate_id INT NULL;

-- Add new columns to existing meetings table
ALTER TABLE meetings 
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Populate normalized_name for existing people records
UPDATE people SET normalized_name = LOWER(TRIM(CONCAT(IFNULL(first_name, ''), ' ', IFNULL(last_name, '')))) 
WHERE normalized_name IS NULL AND (first_name IS NOT NULL OR last_name IS NOT NULL);
