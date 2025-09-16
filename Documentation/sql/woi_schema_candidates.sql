-- Web Of Influence — Candidates table schema
-- Purpose: canonical per-election candidate instances linked to people

CREATE TABLE IF NOT EXISTS `candidates` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `people_id` INT NOT NULL,
  `party_id` INT NULL,
  `electorate_id` INT NULL,
  `party_name` VARCHAR(255) NULL,
  `electorate_name` VARCHAR(255) NULL,
  `year` INT NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_candidates_year` (`year`),
  KEY `idx_candidates_people` (`people_id`),
  KEY `idx_candidates_party` (`party_id`),
  KEY `idx_candidates_electorate` (`electorate_id`),
  UNIQUE KEY `uniq_people_year_party_electorate` (`people_id`, `year`, `party_id`, `electorate_id`),
  CONSTRAINT `fk_candidates_people` FOREIGN KEY (`people_id`) REFERENCES `people` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_party` FOREIGN KEY (`party_id`) REFERENCES `parties` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_candidates_electorate` FOREIGN KEY (`electorate_id`) REFERENCES `electorates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note:
-- - The UNIQUE constraint prevents duplicate candidate entries per people/year/party/electorate.
--   Because MySQL treats NULLs as distinct in UNIQUE indexes, duplicates with NULL party_id or
--   electorate_id could still sneak in. Prefer supplying both party_id and electorate_id where possible.
-- - party_name and electorate_name are denormalized copies for convenience and historical text preservation.

-- Optional: If you want to map donations directly to candidates, add a candidate_id column to donations:
-- ALTER TABLE `donations`
--   ADD COLUMN `candidate_id` INT NULL AFTER `candidate_person_id`,
--   ADD KEY `idx_donations_candidate_id` (`candidate_id`),
--   ADD CONSTRAINT `fk_donations_candidate` FOREIGN KEY (`candidate_id`) REFERENCES `candidates` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
