-- Add "Also known as" field to people table
-- This allows storing name variations like "Todd STEPHENSON, Todd Stephenson MP" directly on the person record

ALTER TABLE people ADD COLUMN also_known_as TEXT NULL COMMENT 'Comma-separated list of name variations and aliases';

-- Add index for searching aliases
ALTER TABLE people ADD INDEX idx_people_also_known_as (also_known_as(255));

-- Update some example records with common patterns
-- Note: This is just an example - in practice these would be set manually through the admin interface
UPDATE people SET also_known_as = CONCAT(first_name, ' ', UPPER(last_name)) 
WHERE id IN (
  SELECT DISTINCT people_id FROM candidate_overview WHERE people_id IS NOT NULL LIMIT 5
);
