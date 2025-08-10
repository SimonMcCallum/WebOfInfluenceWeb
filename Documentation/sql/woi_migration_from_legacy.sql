-- Web Of Influence — Migration from legacy schemas into normalized "woi"
-- Assumptions:
--  - Legacy schemas exist and are populated:
--      Entities (People, Parties, Electorates, Donors)
--      Overviews_Candidate_Donations_By_Year (2011/2014/2017/2023 tables)
--      Donations_Individual (Donations_Log_2011/2014/2017/2023)
--      Ministerial_Meetings (Meetings_Log)
--  - Run Documentation/sql/woi_schema.sql first to create "woi" schema and tables.
--  - Names in Entities are already uppercased by loaders; if not, this script UPPER()s for joins.

SET sql_safe_updates = 0;

USE woi;

-- 1) Seed parties
INSERT INTO woi.parties(name)
SELECT DISTINCT UPPER(p.party_name) AS name
FROM Entities.Parties p
WHERE p.party_name IS NOT NULL AND TRIM(p.party_name) != ''
ON DUPLICATE KEY UPDATE id = id;

-- 2) Seed people (candidates/ministers)
INSERT INTO woi.people(first_name, last_name)
SELECT DISTINCT UPPER(pe.first_name) AS first_name, UPPER(pe.last_name) AS last_name
FROM Entities.People pe
WHERE pe.first_name IS NOT NULL AND pe.last_name IS NOT NULL
  AND TRIM(pe.first_name) != '' AND TRIM(pe.last_name) != ''
ON DUPLICATE KEY UPDATE id = id;

-- 3) Seed electorates
INSERT INTO woi.electorates(name)
SELECT DISTINCT UPPER(e.electorate_name) AS name
FROM Entities.Electorates e
WHERE e.electorate_name IS NOT NULL AND TRIM(e.electorate_name) != ''
ON DUPLICATE KEY UPDATE id = id;

-- 4) Seed donors
-- Normalized name used for dedup (individual donors). Organizations (if any) can be set via org_name later.
INSERT INTO woi.donors(first_name, last_name, org_name, normalized_name)
SELECT DISTINCT
  NULLIF(UPPER(d.first_name), '') AS first_name,
  NULLIF(UPPER(d.last_name),  '') AS last_name,
  NULL, -- org_name not present in legacy tables; extend as needed
  NULLIF(TRIM(CONCAT_WS(' ',
    UPPER(COALESCE(d.first_name,'')),
    UPPER(COALESCE(d.last_name,'')))
  ), '') AS normalized_name
FROM Entities.Donors d
ON DUPLICATE KEY UPDATE id = id;

-- 5) Backfill candidate_overview from per-year overview tables
-- 2023
INSERT INTO woi.candidate_overview(
  year, people_id, party_id, electorate_id,
  total_donations, total_expenses, part_a, part_b, part_c, part_d, part_f, part_g, part_h, original_id
)
SELECT
  '2023' AS year,
  p.id AS people_id,
  pr.id AS party_id,
  el.id AS electorate_id,
  ov.total_donations, ov.total_expenses,
  ov.part_a, ov.part_b, ov.part_c, ov.part_d, ov.part_f, ov.part_g, ov.part_h,
  ov.original_id
FROM Overviews_Candidate_Donations_By_Year.2023_Candidate_Donation_Overview ov
JOIN Entities.People      ep ON ep.id = ov.people_id
JOIN Entities.Parties     epr ON epr.id = ov.party_id
JOIN Entities.Electorates eel ON eel.id = ov.electorate_id
JOIN woi.people      p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name)
JOIN woi.parties     pr ON pr.name       = UPPER(epr.party_name)
JOIN woi.electorates el ON el.name       = UPPER(eel.electorate_name)
ON DUPLICATE KEY UPDATE
  total_donations = VALUES(total_donations),
  total_expenses  = VALUES(total_expenses),
  part_a = VALUES(part_a), part_b = VALUES(part_b), part_c = VALUES(part_c), part_d = VALUES(part_d),
  part_f = VALUES(part_f), part_g = VALUES(part_g), part_h = VALUES(part_h),
  original_id = VALUES(original_id);

-- 2017
INSERT INTO woi.candidate_overview(
  year, people_id, party_id, electorate_id,
  total_donations, total_expenses, part_a, part_b, part_c, part_d, part_f, part_g, part_h, original_id
)
SELECT
  '2017' AS year,
  p.id AS people_id,
  pr.id AS party_id,
  el.id AS electorate_id,
  ov.total_donations, ov.total_expenses,
  ov.part_a, ov.part_b, ov.part_c, ov.part_d, ov.part_f, ov.part_g, ov.part_h,
  ov.original_id
FROM Overviews_Candidate_Donations_By_Year.2017_Candidate_Donation_Overview ov
JOIN Entities.People      ep ON ep.id = ov.people_id
JOIN Entities.Parties     epr ON epr.id = ov.party_id
JOIN Entities.Electorates eel ON eel.id = ov.electorate_id
JOIN woi.people      p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name)
JOIN woi.parties     pr ON pr.name       = UPPER(epr.party_name)
JOIN woi.electorates el ON el.name       = UPPER(eel.electorate_name)
ON DUPLICATE KEY UPDATE
  total_donations = VALUES(total_donations),
  total_expenses  = VALUES(total_expenses),
  part_a = VALUES(part_a), part_b = VALUES(part_b), part_c = VALUES(part_c), part_d = VALUES(part_d),
  part_f = VALUES(part_f), part_g = VALUES(part_g), part_h = VALUES(part_h),
  original_id = VALUES(original_id);

-- 2014 (no F/G/H in legacy)
INSERT INTO woi.candidate_overview(
  year, people_id, party_id, electorate_id,
  total_donations, total_expenses, part_a, part_b, part_c, part_d, part_f, part_g, part_h, original_id
)
SELECT
  '2014' AS year,
  p.id AS people_id,
  pr.id AS party_id,
  el.id AS electorate_id,
  ov.total_donations, ov.total_expenses,
  ov.part_a, ov.part_b, ov.part_c, ov.part_d,
  NULL AS part_f, NULL AS part_g, NULL AS part_h,
  ov.original_id
FROM Overviews_Candidate_Donations_By_Year.2014_Candidate_Donation_Overview ov
JOIN Entities.People      ep ON ep.id = ov.people_id
JOIN Entities.Parties     epr ON epr.id = ov.party_id
JOIN Entities.Electorates eel ON eel.id = ov.electorate_id
JOIN woi.people      p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name)
JOIN woi.parties     pr ON pr.name       = UPPER(epr.party_name)
JOIN woi.electorates el ON el.name       = UPPER(eel.electorate_name)
ON DUPLICATE KEY UPDATE
  total_donations = VALUES(total_donations),
  total_expenses  = VALUES(total_expenses),
  part_a = VALUES(part_a), part_b = VALUES(part_b), part_c = VALUES(part_c), part_d = VALUES(part_d),
  part_f = VALUES(part_f), part_g = VALUES(part_g), part_h = VALUES(part_h),
  original_id = VALUES(original_id);

-- 2011 (no F/G/H in legacy)
INSERT INTO woi.candidate_overview(
  year, people_id, party_id, electorate_id,
  total_donations, total_expenses, part_a, part_b, part_c, part_d, part_f, part_g, part_h, original_id
)
SELECT
  '2011' AS year,
  p.id AS people_id,
  pr.id AS party_id,
  el.id AS electorate_id,
  ov.total_donations, ov.total_expenses,
  ov.part_a, ov.part_b, ov.part_c, ov.part_d,
  NULL AS part_f, NULL AS part_g, NULL AS part_h,
  ov.original_id
FROM Overviews_Candidate_Donations_By_Year.2011_Candidate_Donation_Overview ov
JOIN Entities.People      ep ON ep.id = ov.people_id
JOIN Entities.Parties     epr ON epr.id = ov.party_id
JOIN Entities.Electorates eel ON eel.id = ov.electorate_id
JOIN woi.people      p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name)
JOIN woi.parties     pr ON pr.name       = UPPER(epr.party_name)
JOIN woi.electorates el ON el.name       = UPPER(eel.electorate_name)
ON DUPLICATE KEY UPDATE
  total_donations = VALUES(total_donations),
  total_expenses  = VALUES(total_expenses),
  part_a = VALUES(part_a), part_b = VALUES(part_b), part_c = VALUES(part_c), part_d = VALUES(part_d),
  part_f = VALUES(part_f), part_g = VALUES(part_g), part_h = VALUES(part_h),
  original_id = VALUES(original_id);

-- 6) Backfill donations by joining via normalized donor names and people names.
-- 2011
INSERT INTO woi.donations(
  year, date, amount, money_or_goods_services, location, notes,
  donor_id, candidate_person_id, candidate_overview_id
)
SELECT
  '2011' AS year,
  di.date, di.amount, di.MoneyOrGoodsServices, di.location, di.notes,
  wd.id AS donor_id,
  p.id  AS candidate_person_id,
  NULL AS candidate_overview_id
FROM Donations_Individual.Donations_Log_2011 di
JOIN Entities.Donors d  ON d.id  = di.donor_id
JOIN woi.donors     wd ON wd.normalized_name = NULLIF(TRIM(CONCAT_WS(' ',
                              UPPER(COALESCE(d.first_name,'')),
                              UPPER(COALESCE(d.last_name,'')))
                            ), '')
JOIN Entities.People ep ON ep.id = di.minister_donated
JOIN woi.people     p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name);

-- 2014
INSERT INTO woi.donations(
  year, date, amount, money_or_goods_services, location, notes,
  donor_id, candidate_person_id, candidate_overview_id
)
SELECT
  '2014' AS year,
  di.date, di.amount, di.MoneyOrGoodsServices, di.location, di.notes,
  wd.id AS donor_id,
  p.id  AS candidate_person_id,
  NULL AS candidate_overview_id
FROM Donations_Individual.Donations_Log_2014 di
JOIN Entities.Donors d  ON d.id  = di.donor_id
JOIN woi.donors     wd ON wd.normalized_name = NULLIF(TRIM(CONCAT_WS(' ',
                              UPPER(COALESCE(d.first_name,'')),
                              UPPER(COALESCE(d.last_name,'')))
                            ), '')
JOIN Entities.People ep ON ep.id = di.minister_donated
JOIN woi.people     p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name);

-- 2017
INSERT INTO woi.donations(
  year, date, amount, money_or_goods_services, location, notes,
  donor_id, candidate_person_id, candidate_overview_id
)
SELECT
  '2017' AS year,
  di.date, di.amount, di.MoneyOrGoodsServices, di.location, di.notes,
  wd.id AS donor_id,
  p.id  AS candidate_person_id,
  NULL AS candidate_overview_id
FROM Donations_Individual.Donations_Log_2017 di
JOIN Entities.Donors d  ON d.id  = di.donor_id
JOIN woi.donors     wd ON wd.normalized_name = NULLIF(TRIM(CONCAT_WS(' ',
                              UPPER(COALESCE(d.first_name,'')),
                              UPPER(COALESCE(d.last_name,'')))
                            ), '')
JOIN Entities.People ep ON ep.id = di.minister_donated
JOIN woi.people     p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name);

-- 2023
INSERT INTO woi.donations(
  year, date, amount, money_or_goods_services, location, notes,
  donor_id, candidate_person_id, candidate_overview_id
)
SELECT
  '2023' AS year,
  di.date, di.amount, di.MoneyOrGoodsServices, di.location, di.notes,
  wd.id AS donor_id,
  p.id  AS candidate_person_id,
  NULL AS candidate_overview_id
FROM Donations_Individual.Donations_Log_2023 di
JOIN Entities.Donors d  ON d.id  = di.donor_id
JOIN woi.donors     wd ON wd.normalized_name = NULLIF(TRIM(CONCAT_WS(' ',
                              UPPER(COALESCE(d.first_name,'')),
                              UPPER(COALESCE(d.last_name,'')))
                            ), '')
JOIN Entities.People ep ON ep.id = di.minister_donated
JOIN woi.people     p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name);

-- 7) Backfill ministerial meetings
INSERT INTO woi.meetings(
  date, start_time, end_time, location, notes, type, portfolio, title, minister_person_id, with_text
)
SELECT
  m.date, m.start_time, m.end_time, m.location, m.notes, m.type, m.portfolio, m.title,
  p.id AS minister_person_id,
  m.with_text
FROM Ministerial_Meetings.Meetings_Log m
JOIN Entities.People ep ON ep.id = m.minister_logged_id
JOIN woi.people     p  ON p.first_name = UPPER(ep.first_name) AND p.last_name = UPPER(ep.last_name);

-- Optional post-load: link donations to candidate_overview when available
-- (Not strictly required for UI; speeds some joins)
-- UPDATE woi.donations d
-- JOIN woi.candidate_overview co
--   ON co.people_id = d.candidate_person_id AND co.year = d.year
-- SET d.candidate_overview_id = co.id
-- WHERE d.candidate_overview_id IS NULL;

-- Verification examples (read-only)
-- SELECT year, COUNT(*) FROM woi.candidate_overview GROUP BY year;
-- SELECT year, COUNT(*) FROM woi.donations GROUP BY year;
-- SELECT COUNT(*) FROM woi.meetings;

SET sql_safe_updates = 1;
