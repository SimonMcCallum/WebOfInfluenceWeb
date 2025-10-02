-- Web Of Influence — Name Alias Mapping Schema
-- This schema adds name alias mapping functionality to handle variations
-- like "Todd STEPHENSON" and "Todd Stephenson MP" as the same person

-- Name aliases table to map various name formats to canonical people
CREATE TABLE IF NOT EXISTS name_aliases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  alias_name VARCHAR(512) NOT NULL,
  canonical_person_id INT NOT NULL,
  alias_type ENUM('person', 'organization', 'title', 'other') DEFAULT 'person',
  notes TEXT NULL,
  created_by VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  CONSTRAINT fk_aliases_person FOREIGN KEY (canonical_person_id) REFERENCES people(id) ON DELETE CASCADE,
  INDEX idx_alias_name (alias_name),
  INDEX idx_alias_person (canonical_person_id),
  INDEX idx_alias_type (alias_type),
  UNIQUE KEY uk_alias_name (alias_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- View to easily see all aliases for a person
CREATE VIEW IF NOT EXISTS person_aliases AS
SELECT 
    p.id as person_id,
    CONCAT(p.first_name, ' ', p.last_name) as canonical_name,
    na.alias_name,
    na.alias_type,
    na.notes,
    na.created_by,
    na.created_at
FROM people p
LEFT JOIN name_aliases na ON p.id = na.canonical_person_id
ORDER BY p.last_name, p.first_name, na.alias_name;

-- Function to resolve alias to canonical person ID
DELIMITER //
CREATE OR REPLACE FUNCTION resolve_person_alias(input_name VARCHAR(512))
RETURNS INT
READS SQL DATA
DETERMINISTIC
BEGIN
    DECLARE person_id INT DEFAULT NULL;
    
    -- First try exact match in aliases
    SELECT canonical_person_id INTO person_id 
    FROM name_aliases 
    WHERE alias_name = input_name 
    LIMIT 1;
    
    -- If not found, try normalized matching in people table
    IF person_id IS NULL THEN
        SELECT id INTO person_id
        FROM people 
        WHERE CONCAT(first_name, ' ', last_name) = input_name
           OR normalized_name = input_name
        LIMIT 1;
    END IF;
    
    RETURN person_id;
END//
DELIMITER ;

-- Insert some example aliases for common patterns
INSERT IGNORE INTO name_aliases (alias_name, canonical_person_id, alias_type, notes, created_by) 
SELECT 
    CONCAT(p.first_name, ' ', UPPER(p.last_name)) as alias_name,
    p.id as canonical_person_id,
    'person' as alias_type,
    'Auto-generated uppercase variant' as notes,
    'system' as created_by
FROM people p
WHERE EXISTS (
    SELECT 1 FROM candidate_overview co WHERE co.people_id = p.id
)
AND NOT EXISTS (
    SELECT 1 FROM name_aliases na WHERE na.alias_name = CONCAT(p.first_name, ' ', UPPER(p.last_name))
);
