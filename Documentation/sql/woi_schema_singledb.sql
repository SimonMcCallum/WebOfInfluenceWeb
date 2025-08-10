-- Web Of Influence — Normalized Schema (Single-Database Variant)
-- Use this when you want all tables created in the CURRENT database (no CREATE DATABASE/USE here).
-- Ensure you have selected/connected to the desired database first (e.g., ludog319_webofinfluence).

-- Core entities
CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255) NOT NULL,
  CONSTRAINT uq_people_name UNIQUE (first_name, last_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  CONSTRAINT uq_parties_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS electorates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  CONSTRAINT uq_electorates_name UNIQUE (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donors can be individuals or organizations
CREATE TABLE IF NOT EXISTS donors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NULL,
  last_name  VARCHAR(255) NULL,
  org_name   VARCHAR(255) NULL,
  normalized_name VARCHAR(255) NULL,
  CONSTRAINT uq_donors_normalized UNIQUE (normalized_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Candidate overview (yearly aggregates per candidate)
CREATE TABLE IF NOT EXISTS candidate_overview (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year CHAR(4) NOT NULL,
  people_id INT NOT NULL,
  party_id INT NOT NULL,
  electorate_id INT NOT NULL,
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
  CONSTRAINT uq_candidate_overview UNIQUE (year, people_id),
  CONSTRAINT fk_co_people     FOREIGN KEY (people_id)     REFERENCES people(id),
  CONSTRAINT fk_co_parties    FOREIGN KEY (party_id)      REFERENCES parties(id),
  CONSTRAINT fk_co_electorate FOREIGN KEY (electorate_id) REFERENCES electorates(id),
  INDEX idx_co_year (year),
  INDEX idx_co_party_year (party_id, year),
  INDEX idx_co_electorate_year (electorate_id, year),
  INDEX idx_co_people_year (people_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Donations (detail rows)
CREATE TABLE IF NOT EXISTS donations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  year CHAR(4) NOT NULL,
  date DATE NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  money_or_goods_services VARCHAR(255) NULL,
  location VARCHAR(255) NULL,
  notes TEXT NULL,
  donor_id INT NOT NULL,
  candidate_person_id INT NOT NULL,
  candidate_overview_id INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
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
  CONSTRAINT fk_meetings_minister FOREIGN KEY (minister_person_id) REFERENCES people(id),
  INDEX idx_meetings_minister_date (minister_person_id, date),
  INDEX idx_meetings_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes:
-- 1) This variant intentionally omits CREATE DATABASE/USE and any cross-database views.
-- 2) Point your PHP API's DB_NAME to this single database in config.php or environment.
-- 3) If migrating from legacy schemas, adapt the migration script to target this DB (remove schema prefixes).
