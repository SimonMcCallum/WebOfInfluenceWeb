-- Web Of Influence — Normalized Schema (MySQL)
-- Goal: Collapse fragmented schemas into a single database with a clean, query-friendly model.

-- 1) Create dedicated database
CREATE DATABASE IF NOT EXISTS woi
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE woi;

-- 2) Core entities
CREATE TABLE IF NOT EXISTS people (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NOT NULL,
  last_name  VARCHAR(255)  NOT NULL,
  -- Assumption: application normalizes names to UPPER() before insert to ensure uniqueness
  CONSTRAINT uq_people_name UNIQUE (first_name, last_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parties (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  CONSTRAINT uq_parties_name UNIQUE (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS electorates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  CONSTRAINT uq_electorates_name UNIQUE (name)
) ENGINE=InnoDB;

-- Donors can be individuals or organizations
CREATE TABLE IF NOT EXISTS donors (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(255) NULL,
  last_name  VARCHAR(255) NULL,
  org_name   VARCHAR(255) NULL,
  normalized_name VARCHAR(255) NULL,
  -- Optional dedupe helper: when present, enforce uniqueness
  CONSTRAINT uq_donors_normalized UNIQUE (normalized_name)
) ENGINE=InnoDB;

-- Organizations
CREATE TABLE IF NOT EXISTS organizations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  normalized_name VARCHAR(255) NULL,
  CONSTRAINT uq_organizations_name UNIQUE (name),
  INDEX idx_org_normalized (normalized_name)
) ENGINE=InnoDB;

-- Link donors to organizations (optional)
ALTER TABLE donors ADD COLUMN organization_id INT NULL;
ALTER TABLE donors
  ADD CONSTRAINT fk_donors_org FOREIGN KEY (organization_id) REFERENCES woi.organizations(id);

-- 3) Candidate overview (yearly aggregates per candidate)
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
  CONSTRAINT fk_co_people     FOREIGN KEY (people_id)     REFERENCES woi.people(id),
  CONSTRAINT fk_co_parties    FOREIGN KEY (party_id)      REFERENCES woi.parties(id),
  CONSTRAINT fk_co_electorate FOREIGN KEY (electorate_id) REFERENCES woi.electorates(id),
  INDEX idx_co_year (year),
  INDEX idx_co_party_year (party_id, year),
  INDEX idx_co_electorate_year (electorate_id, year),
  INDEX idx_co_people_year (people_id, year)
) ENGINE=InnoDB;

-- 4) Donations (detail rows)
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
  CONSTRAINT fk_donations_donor    FOREIGN KEY (donor_id)            REFERENCES woi.donors(id),
  CONSTRAINT fk_donations_person   FOREIGN KEY (candidate_person_id) REFERENCES woi.people(id),
  CONSTRAINT fk_donations_overview FOREIGN KEY (candidate_overview_id) REFERENCES woi.candidate_overview(id),
  INDEX idx_donations_candidate_year (candidate_person_id, year),
  INDEX idx_donations_donor_year    (donor_id, year),
  INDEX idx_donations_year_amount   (year, amount)
) ENGINE=InnoDB;

-- 5) Ministerial meetings
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
  CONSTRAINT fk_meetings_minister FOREIGN KEY (minister_person_id) REFERENCES woi.people(id),
  INDEX idx_meetings_minister_date (minister_person_id, date),
  INDEX idx_meetings_date (date)
) ENGINE=InnoDB;

-- Meeting attendees (normalized)
CREATE TABLE IF NOT EXISTS meeting_attendees_people (
  meeting_id INT NOT NULL,
  person_id INT NOT NULL,
  PRIMARY KEY (meeting_id, person_id),
  CONSTRAINT fk_attp_meeting FOREIGN KEY (meeting_id) REFERENCES woi.meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_attp_person  FOREIGN KEY (person_id)  REFERENCES woi.people(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS meeting_attendees_organizations (
  meeting_id INT NOT NULL,
  organization_id INT NOT NULL,
  PRIMARY KEY (meeting_id, organization_id),
  CONSTRAINT fk_atto_meeting FOREIGN KEY (meeting_id) REFERENCES woi.meetings(id) ON DELETE CASCADE,
  CONSTRAINT fk_atto_org     FOREIGN KEY (organization_id) REFERENCES woi.organizations(id)
) ENGINE=InnoDB;

-- 6) Optional compatibility views (preserve legacy table names used by current UI)
--    This allows SELECT * FROM Overviews_Candidate_Donations_By_Year.2017_Candidate_Donation_Overview to continue working.
--    Note: Requires the legacy database (schema) to exist. Adjust permissions as needed.

CREATE DATABASE IF NOT EXISTS Overviews_Candidate_Donations_By_Year
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

-- 2011
DROP VIEW IF EXISTS Overviews_Candidate_Donations_By_Year.2011_Candidate_Donation_Overview;
CREATE ALGORITHM=MERGE SQL SECURITY INVOKER
VIEW Overviews_Candidate_Donations_By_Year.2011_Candidate_Donation_Overview AS
SELECT id, total_donations, total_expenses, people_id, party_id, electorate_id,
       part_a, part_b, part_c, part_d, part_f, part_g, part_h, year, original_id
FROM woi.candidate_overview WHERE year = '2011';

-- 2014
DROP VIEW IF EXISTS Overviews_Candidate_Donations_By_Year.2014_Candidate_Donation_Overview;
CREATE ALGORITHM=MERGE SQL SECURITY INVOKER
VIEW Overviews_Candidate_Donations_By_Year.2014_Candidate_Donation_Overview AS
SELECT id, total_donations, total_expenses, people_id, party_id, electorate_id,
       part_a, part_b, part_c, part_d, part_f, part_g, part_h, year, original_id
FROM woi.candidate_overview WHERE year = '2014';

-- 2017
DROP VIEW IF EXISTS Overviews_Candidate_Donations_By_Year.2017_Candidate_Donation_Overview;
CREATE ALGORITHM=MERGE SQL SECURITY INVOKER
VIEW Overviews_Candidate_Donations_By_Year.2017_Candidate_Donation_Overview AS
SELECT id, total_donations, total_expenses, people_id, party_id, electorate_id,
       part_a, part_b, part_c, part_d, part_f, part_g, part_h, year, original_id
FROM woi.candidate_overview WHERE year = '2017';

-- 2020 (no legacy overview table previously; included for completeness)
DROP VIEW IF EXISTS Overviews_Candidate_Donations_By_Year.2020_Candidate_Donation_Overview;
CREATE ALGORITHM=MERGE SQL SECURITY INVOKER
VIEW Overviews_Candidate_Donations_By_Year.2020_Candidate_Donation_Overview AS
SELECT id, total_donations, total_expenses, people_id, party_id, electorate_id,
       part_a, part_b, part_c, part_d, part_f, part_g, part_h, year, original_id
FROM woi.candidate_overview WHERE year = '2020';

-- 2023
DROP VIEW IF EXISTS Overviews_Candidate_Donations_By_Year.2023_Candidate_Donation_Overview;
CREATE ALGORITHM=MERGE SQL SECURITY INVOKER
VIEW Overviews_Candidate_Donations_By_Year.2023_Candidate_Donation_Overview AS
SELECT id, total_donations, total_expenses, people_id, party_id, electorate_id,
       part_a, part_b, part_c, part_d, part_f, part_g, part_h, year, original_id
FROM woi.candidate_overview WHERE year = '2023';

-- End of schema
