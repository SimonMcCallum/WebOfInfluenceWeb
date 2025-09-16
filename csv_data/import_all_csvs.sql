-- Web Of Influence - CSV Data Import Script
-- This script imports all CSV files into the ludog319_webofinfluence database
-- Run this script after creating the database schema using woi_schema_singledb.sql

-- Use the database specified in config.php
USE ludog319_webofinfluence;

-- Disable foreign key checks temporarily for bulk loading
SET FOREIGN_KEY_CHECKS = 0;

-- Set SQL mode to handle data import issues
SET sql_mode = '';

-- =====================================================
-- 1. IMPORT CANDIDATE OVERVIEW DATA
-- =====================================================

-- Load 2023 candidate donations data
LOAD DATA LOCAL INFILE 'csv_data/candidate_csv/2023_candidate_donations.csv'
INTO TABLE candidate_overview
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    original_id,
    @username,
    @login_code,
    @first_name,
    @last_name,
    @prefix,
    @electorate_name,
    @party_name,
    @total_donations,
    @total_expenses,
    @part_a,
    @part_a_calc,
    @part_a_match,
    @part_b,
    @part_c,
    @part_d_calc,
    @part_c_match,
    @part_d,
    @part_d_calc2,
    @part_d_match,
    @part_f,
    @part_g,
    @part_h,
    @part_h_calc,
    @part_h_match,
    @entry_status,
    @entry_created,
    @entry_submitted,
    @entry_updated
)
SET
    year = '2023',
    people_id = (
        SELECT id FROM people 
        WHERE first_name = TRIM(@first_name) AND last_name = TRIM(@last_name)
        LIMIT 1
    ),
    party_id = (
        SELECT id FROM parties 
        WHERE name = TRIM(@party_name)
        LIMIT 1
    ),
    electorate_id = (
        SELECT id FROM electorates 
        WHERE name = TRIM(@electorate_name)
        LIMIT 1
    ),
    total_donations = CAST(REPLACE(REPLACE(@total_donations, '$', ''), ',', '') AS DECIMAL(12,2)),
    total_expenses = CAST(REPLACE(REPLACE(@total_expenses, '$', ''), ',', '') AS DECIMAL(12,2)),
    part_a = CASE WHEN @part_a = '' OR @part_a = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_a, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_b = CASE WHEN @part_b = '' OR @part_b = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_b, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_c = CASE WHEN @part_c = '' OR @part_c = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_c, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_d = CASE WHEN @part_d = '' OR @part_d = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_d, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_f = CASE WHEN @part_f = '' OR @part_f = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_f, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_g = CASE WHEN @part_g = '' OR @part_g = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_g, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_h = CASE WHEN @part_h = '' OR @part_h = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_h, '$', ''), ',', '') AS DECIMAL(12,2)) END;

-- Load other years' candidate data
LOAD DATA LOCAL INFILE 'csv_data/candidate_csv/2020_candidate_donations.csv'
INTO TABLE candidate_overview
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    original_id,
    @username,
    @login_code,
    @first_name,
    @last_name,
    @prefix,
    @electorate_name,
    @party_name,
    @total_donations,
    @total_expenses,
    @part_a,
    @part_a_calc,
    @part_a_match,
    @part_b,
    @part_c,
    @part_d_calc,
    @part_c_match,
    @part_d,
    @part_d_calc2,
    @part_d_match,
    @part_f,
    @part_g,
    @part_h,
    @part_h_calc,
    @part_h_match,
    @entry_status,
    @entry_created,
    @entry_submitted,
    @entry_updated
)
SET
    year = '2020',
    people_id = (
        SELECT id FROM people 
        WHERE first_name = TRIM(@first_name) AND last_name = TRIM(@last_name)
        LIMIT 1
    ),
    party_id = (
        SELECT id FROM parties 
        WHERE name = TRIM(@party_name)
        LIMIT 1
    ),
    electorate_id = (
        SELECT id FROM electorates 
        WHERE name = TRIM(@electorate_name)
        LIMIT 1
    ),
    total_donations = CAST(REPLACE(REPLACE(@total_donations, '$', ''), ',', '') AS DECIMAL(12,2)),
    total_expenses = CAST(REPLACE(REPLACE(@total_expenses, '$', ''), ',', '') AS DECIMAL(12,2)),
    part_a = CASE WHEN @part_a = '' OR @part_a = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_a, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_b = CASE WHEN @part_b = '' OR @part_b = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_b, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_c = CASE WHEN @part_c = '' OR @part_c = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_c, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_d = CASE WHEN @part_d = '' OR @part_d = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_d, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_f = CASE WHEN @part_f = '' OR @part_f = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_f, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_g = CASE WHEN @part_g = '' OR @part_g = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_g, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_h = CASE WHEN @part_h = '' OR @part_h = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_h, '$', ''), ',', '') AS DECIMAL(12,2)) END;

-- Repeat for other years (2017, 2014, 2011)
LOAD DATA LOCAL INFILE 'csv_data/candidate_csv/2017_candidate_donations.csv'
INTO TABLE candidate_overview
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    original_id,
    @username,
    @login_code,
    @first_name,
    @last_name,
    @prefix,
    @electorate_name,
    @party_name,
    @total_donations,
    @total_expenses,
    @part_a,
    @part_a_calc,
    @part_a_match,
    @part_b,
    @part_c,
    @part_d_calc,
    @part_c_match,
    @part_d,
    @part_d_calc2,
    @part_d_match,
    @part_f,
    @part_g,
    @part_h,
    @part_h_calc,
    @part_h_match,
    @entry_status,
    @entry_created,
    @entry_submitted,
    @entry_updated
)
SET
    year = '2017',
    people_id = (
        SELECT id FROM people 
        WHERE first_name = TRIM(@first_name) AND last_name = TRIM(@last_name)
        LIMIT 1
    ),
    party_id = (
        SELECT id FROM parties 
        WHERE name = TRIM(@party_name)
        LIMIT 1
    ),
    electorate_id = (
        SELECT id FROM electorates 
        WHERE name = TRIM(@electorate_name)
        LIMIT 1
    ),
    total_donations = CAST(REPLACE(REPLACE(@total_donations, '$', ''), ',', '') AS DECIMAL(12,2)),
    total_expenses = CAST(REPLACE(REPLACE(@total_expenses, '$', ''), ',', '') AS DECIMAL(12,2)),
    part_a = CASE WHEN @part_a = '' OR @part_a = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_a, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_b = CASE WHEN @part_b = '' OR @part_b = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_b, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_c = CASE WHEN @part_c = '' OR @part_c = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_c, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_d = CASE WHEN @part_d = '' OR @part_d = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_d, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_f = CASE WHEN @part_f = '' OR @part_f = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_f, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_g = CASE WHEN @part_g = '' OR @part_g = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_g, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_h = CASE WHEN @part_h = '' OR @part_h = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_h, '$', ''), ',', '') AS DECIMAL(12,2)) END;

LOAD DATA LOCAL INFILE 'csv_data/candidate_csv/2014_candidate_donations.csv'
INTO TABLE candidate_overview
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    original_id,
    @username,
    @login_code,
    @first_name,
    @last_name,
    @prefix,
    @electorate_name,
    @party_name,
    @total_donations,
    @total_expenses,
    @part_a,
    @part_a_calc,
    @part_a_match,
    @part_b,
    @part_c,
    @part_d_calc,
    @part_c_match,
    @part_d,
    @part_d_calc2,
    @part_d_match,
    @part_f,
    @part_g,
    @part_h,
    @part_h_calc,
    @part_h_match,
    @entry_status,
    @entry_created,
    @entry_submitted,
    @entry_updated
)
SET
    year = '2014',
    people_id = (
        SELECT id FROM people 
        WHERE first_name = TRIM(@first_name) AND last_name = TRIM(@last_name)
        LIMIT 1
    ),
    party_id = (
        SELECT id FROM parties 
        WHERE name = TRIM(@party_name)
        LIMIT 1
    ),
    electorate_id = (
        SELECT id FROM electorates 
        WHERE name = TRIM(@electorate_name)
        LIMIT 1
    ),
    total_donations = CAST(REPLACE(REPLACE(@total_donations, '$', ''), ',', '') AS DECIMAL(12,2)),
    total_expenses = CAST(REPLACE(REPLACE(@total_expenses, '$', ''), ',', '') AS DECIMAL(12,2)),
    part_a = CASE WHEN @part_a = '' OR @part_a = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_a, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_b = CASE WHEN @part_b = '' OR @part_b = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_b, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_c = CASE WHEN @part_c = '' OR @part_c = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_c, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_d = CASE WHEN @part_d = '' OR @part_d = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_d, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_f = CASE WHEN @part_f = '' OR @part_f = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_f, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_g = CASE WHEN @part_g = '' OR @part_g = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_g, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_h = CASE WHEN @part_h = '' OR @part_h = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_h, '$', ''), ',', '') AS DECIMAL(12,2)) END;

LOAD DATA LOCAL INFILE 'csv_data/candidate_csv/2011_candidate_donations.csv'
INTO TABLE candidate_overview
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    original_id,
    @username,
    @login_code,
    @first_name,
    @last_name,
    @prefix,
    @electorate_name,
    @party_name,
    @total_donations,
    @total_expenses,
    @part_a,
    @part_a_calc,
    @part_a_match,
    @part_b,
    @part_c,
    @part_d_calc,
    @part_c_match,
    @part_d,
    @part_d_calc2,
    @part_d_match,
    @part_f,
    @part_g,
    @part_h,
    @part_h_calc,
    @part_h_match,
    @entry_status,
    @entry_created,
    @entry_submitted,
    @entry_updated
)
SET
    year = '2011',
    people_id = (
        SELECT id FROM people 
        WHERE first_name = TRIM(@first_name) AND last_name = TRIM(@last_name)
        LIMIT 1
    ),
    party_id = (
        SELECT id FROM parties 
        WHERE name = TRIM(@party_name)
        LIMIT 1
    ),
    electorate_id = (
        SELECT id FROM electorates 
        WHERE name = TRIM(@electorate_name)
        LIMIT 1
    ),
    total_donations = CAST(REPLACE(REPLACE(@total_donations, '$', ''), ',', '') AS DECIMAL(12,2)),
    total_expenses = CAST(REPLACE(REPLACE(@total_expenses, '$', ''), ',', '') AS DECIMAL(12,2)),
    part_a = CASE WHEN @part_a = '' OR @part_a = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_a, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_b = CASE WHEN @part_b = '' OR @part_b = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_b, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_c = CASE WHEN @part_c = '' OR @part_c = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_c, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_d = CASE WHEN @part_d = '' OR @part_d = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_d, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_f = CASE WHEN @part_f = '' OR @part_f = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_f, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_g = CASE WHEN @part_g = '' OR @part_g = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_g, '$', ''), ',', '') AS DECIMAL(12,2)) END,
    part_h = CASE WHEN @part_h = '' OR @part_h = '$0.00' THEN NULL 
             ELSE CAST(REPLACE(REPLACE(@part_h, '$', ''), ',', '') AS DECIMAL(12,2)) END;

-- =====================================================
-- 2. IMPORT DONATION DETAILS DATA
-- =====================================================

-- Load 2023 donor information
LOAD DATA LOCAL INFILE 'csv_data/donations_csv/2023_donor_information_for_candidate.csv'
INTO TABLE donations
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    @candidate_id,
    @donation_entry_id,
    @date_received,
    @date_range_finish,
    @additional_date2,
    @additional_date,
    @additional_date3,
    @additional_date4,
    @additional_date5,
    @additional_date6,
    @donation_amount,
    @contributions,
    @money_or_goods,
    @donor_first_name,
    @donor_last_name,
    @donor_prefix,
    @company_org,
    @address_line1,
    @address_line2,
    @address_city,
    @address_state,
    @address_postal,
    @address_country,
    @address_country_code,
    @other_detail
)
SET
    year = '2023',
    date = STR_TO_DATE(@date_received, '%d/%m/%Y'),
    amount = CAST(REPLACE(REPLACE(@donation_amount, '$', ''), ',', '') AS DECIMAL(12,2)),
    money_or_goods_services = @money_or_goods,
    notes = @other_detail,
    donor_id = (
        SELECT id FROM donors 
        WHERE (
            (first_name = TRIM(@donor_first_name) AND last_name = TRIM(@donor_last_name))
            OR org_name = TRIM(@company_org)
        )
        LIMIT 1
    ),
    candidate_person_id = (
        SELECT people_id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2023'
        LIMIT 1
    ),
    candidate_overview_id = (
        SELECT id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2023'
        LIMIT 1
    );

-- Load other years' donation data
LOAD DATA LOCAL INFILE 'csv_data/donations_csv/2017_donor_information_for_candidate.csv'
INTO TABLE donations
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    @candidate_id,
    @donation_entry_id,
    @date_received,
    @date_range_finish,
    @additional_date2,
    @additional_date,
    @additional_date3,
    @additional_date4,
    @additional_date5,
    @additional_date6,
    @donation_amount,
    @contributions,
    @money_or_goods,
    @donor_first_name,
    @donor_last_name,
    @donor_prefix,
    @company_org,
    @address_line1,
    @address_line2,
    @address_city,
    @address_state,
    @address_postal,
    @address_country,
    @address_country_code,
    @other_detail
)
SET
    year = '2017',
    date = STR_TO_DATE(@date_received, '%d/%m/%Y'),
    amount = CAST(REPLACE(REPLACE(@donation_amount, '$', ''), ',', '') AS DECIMAL(12,2)),
    money_or_goods_services = @money_or_goods,
    notes = @other_detail,
    donor_id = (
        SELECT id FROM donors 
        WHERE (
            (first_name = TRIM(@donor_first_name) AND last_name = TRIM(@donor_last_name))
            OR org_name = TRIM(@company_org)
        )
        LIMIT 1
    ),
    candidate_person_id = (
        SELECT people_id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2017'
        LIMIT 1
    ),
    candidate_overview_id = (
        SELECT id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2017'
        LIMIT 1
    );

LOAD DATA LOCAL INFILE 'csv_data/donations_csv/2014_donor_information_for_candidate.csv'
INTO TABLE donations
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    @candidate_id,
    @donation_entry_id,
    @date_received,
    @date_range_finish,
    @additional_date2,
    @additional_date,
    @additional_date3,
    @additional_date4,
    @additional_date5,
    @additional_date6,
    @donation_amount,
    @contributions,
    @money_or_goods,
    @donor_first_name,
    @donor_last_name,
    @donor_prefix,
    @company_org,
    @address_line1,
    @address_line2,
    @address_city,
    @address_state,
    @address_postal,
    @address_country,
    @address_country_code,
    @other_detail
)
SET
    year = '2014',
    date = STR_TO_DATE(@date_received, '%d/%m/%Y'),
    amount = CAST(REPLACE(REPLACE(@donation_amount, '$', ''), ',', '') AS DECIMAL(12,2)),
    money_or_goods_services = @money_or_goods,
    notes = @other_detail,
    donor_id = (
        SELECT id FROM donors 
        WHERE (
            (first_name = TRIM(@donor_first_name) AND last_name = TRIM(@donor_last_name))
            OR org_name = TRIM(@company_org)
        )
        LIMIT 1
    ),
    candidate_person_id = (
        SELECT people_id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2014'
        LIMIT 1
    ),
    candidate_overview_id = (
        SELECT id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2014'
        LIMIT 1
    );

LOAD DATA LOCAL INFILE 'csv_data/donations_csv/2011_donor_information_for_candidate.csv'
INTO TABLE donations
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    @candidate_id,
    @donation_entry_id,
    @date_received,
    @date_range_finish,
    @additional_date2,
    @additional_date,
    @additional_date3,
    @additional_date4,
    @additional_date5,
    @additional_date6,
    @donation_amount,
    @contributions,
    @money_or_goods,
    @donor_first_name,
    @donor_last_name,
    @donor_prefix,
    @company_org,
    @address_line1,
    @address_line2,
    @address_city,
    @address_state,
    @address_postal,
    @address_country,
    @address_country_code,
    @other_detail
)
SET
    year = '2011',
    date = STR_TO_DATE(@date_received, '%d/%m/%Y'),
    amount = CAST(REPLACE(REPLACE(@donation_amount, '$', ''), ',', '') AS DECIMAL(12,2)),
    money_or_goods_services = @money_or_goods,
    notes = @other_detail,
    donor_id = (
        SELECT id FROM donors 
        WHERE (
            (first_name = TRIM(@donor_first_name) AND last_name = TRIM(@donor_last_name))
            OR org_name = TRIM(@company_org)
        )
        LIMIT 1
    ),
    candidate_person_id = (
        SELECT people_id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2011'
        LIMIT 1
    ),
    candidate_overview_id = (
        SELECT id FROM candidate_overview 
        WHERE original_id = @candidate_id AND year = '2011'
        LIMIT 1
    );

-- =====================================================
-- 3. IMPORT MINISTERIAL MEETINGS DATA
-- =====================================================

-- Load Andrew Hoggard ministerial diary
LOAD DATA LOCAL INFILE 'csv_data/diaries_csv/APROCT24/ANDREW_HOGGARD.csv'
INTO TABLE meetings
FIELDS TERMINATED BY ',' 
ENCLOSED BY '"'
LINES TERMINATED BY '\n'
IGNORE 1 ROWS
(
    @minister_name,
    @date_or_started,
    @date_finished,
    @schedule_time,
    @type,
    @meeting_title,
    @location,
    @with_text,
    @portfolio
)
SET
    date = STR_TO_DATE(@date_or_started, '%m/%d/%Y'),
    start_time = CASE 
        WHEN @schedule_time REGEXP '^[0-9]{1,2}:[0-9]{2} [AP]M' THEN 
            STR_TO_DATE(SUBSTRING_INDEX(@schedule_time, ' - ', 1), '%h:%i %p')
        ELSE NULL 
    END,
    end_time = CASE 
        WHEN @schedule_time REGEXP ' - [0-9]{1,2}:[0-9]{2} [AP]M' THEN 
            STR_TO_DATE(SUBSTRING_INDEX(@schedule_time, ' - ', -1), '%h:%i %p')
        ELSE NULL 
    END,
    location = @location,
    notes = @meeting_title,
    type = @type,
    portfolio = @portfolio,
    title = @meeting_title,
    minister_person_id = (
        SELECT id FROM people 
        WHERE first_name = 'Andrew' AND last_name = 'Hoggard'
        LIMIT 1
    ),
    with_text = @with_text;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- 4. DATA CLEANUP AND NORMALIZATION
-- =====================================================

-- Insert missing people from candidate data
INSERT IGNORE INTO people (first_name, last_name)
SELECT DISTINCT 
    TRIM(SUBSTRING_INDEX(CandidateName_First, '#', 1)) as first_name,
    TRIM(SUBSTRING_INDEX(CandidateName_Last, '#', 1)) as last_name
FROM (
    SELECT CandidateName_First, CandidateName_Last FROM candidate_csv_2023
    UNION
    SELECT CandidateName_First, CandidateName_Last FROM candidate_csv_2020
    UNION
    SELECT CandidateName_First, CandidateName_Last FROM candidate_csv_2017
    UNION
    SELECT CandidateName_First, CandidateName_Last FROM candidate_csv_2014
    UNION
    SELECT CandidateName_First, CandidateName_Last FROM candidate_csv_2011
) AS all_candidates
WHERE TRIM(SUBSTRING_INDEX(CandidateName_First, '#', 1)) != ''
  AND TRIM(SUBSTRING_INDEX(CandidateName_Last, '#', 1)) != '';

-- Insert missing parties
INSERT IGNORE INTO parties (name)
SELECT DISTINCT TRIM(Party) as name
FROM (
    SELECT Party FROM candidate_csv_2023
    UNION
    SELECT Party FROM candidate_csv_2020
    UNION
    SELECT Party FROM candidate_csv_2017
    UNION
    SELECT Party FROM candidate_csv_2014
    UNION
    SELECT Party FROM candidate_csv_2011
) AS all_parties
WHERE TRIM(Party) != '';

-- Insert missing electorates
INSERT IGNORE INTO electorates (name)
SELECT DISTINCT TRIM(Electorate) as name
FROM (
    SELECT Electorate FROM candidate_csv_2023
    UNION
    SELECT Electorate FROM candidate_csv_2020
    UNION
    SELECT Electorate FROM candidate_csv_2017
    UNION
    SELECT Electorate FROM candidate_csv_2014
    UNION
    SELECT Electorate FROM candidate_csv_2011
) AS all_electorates
WHERE TRIM(Electorate) != '';

-- Insert missing donors from donation data
INSERT INTO donors (first_name, last_name, org_name, normalized_name)
SELECT DISTINCT 
    CASE WHEN TRIM(DonorName_First) != '' THEN TRIM(SUBSTRING_INDEX(DonorName_First, '#', 1)) ELSE NULL END as first_name,
    CASE WHEN TRIM(DonorName_Last) != '' THEN TRIM(SUBSTRING_INDEX(DonorName_Last, '#', 1)) ELSE NULL END as last_name,
    CASE WHEN TRIM(CompanyOrOrganisation) != '' THEN TRIM(CompanyOrOrganisation) ELSE NULL END as org_name,
    CASE 
        WHEN TRIM(CompanyOrOrganisation) != '' THEN UPPER(TRIM(CompanyOrOrganisation))
        WHEN TRIM(DonorName_First) != '' AND TRIM(DonorName_Last) != '' THEN 
            UPPER(CONCAT(TRIM(SUBSTRING_INDEX(DonorName_First, '#', 1)), ' ', TRIM(SUBSTRING_INDEX(DonorName_Last, '#', 1))))
        ELSE NULL
    END as normalized_name
FROM (
    SELECT DonorName_First, DonorName_Last, CompanyOrOrganisation FROM donations_csv_2023
    UNION
    SELECT DonorName_First, DonorName_Last, CompanyOrOrganisation FROM donations_csv_2017
    UNION
    SELECT DonorName_First, DonorName_Last, CompanyOrOrganisation FROM donations_csv_2014
    UNION
    SELECT DonorName_First, DonorName_Last, CompanyOrOrganisation FROM donations_csv_2011
) AS all_donors
WHERE (TRIM(DonorName_First) != '' AND TRIM(DonorName_Last) != '') 
   OR TRIM(CompanyOrOrganisation) != '';

-- =====================================================
-- 5. CREATE INDEXES FOR BETTER PERFORMANCE
-- =====================================================

-- Add indexes for common query patterns
CREATE INDEX idx_candidate_overview_year ON candidate_overview(year);
CREATE INDEX idx_candidate_overview_people ON candidate_overview(people_id);
CREATE INDEX idx_candidate_overview_party ON candidate_overview(party_id);
CREATE INDEX idx_candidate_overview_electorate ON candidate_overview(electorate_id);

CREATE INDEX idx_donations_year ON donations(year);
CREATE INDEX idx_donations_candidate ON donations(candidate_person_id);
CREATE INDEX idx_donations_donor ON donations(donor_id);
CREATE INDEX idx_donations_date ON donations(date);
CREATE INDEX idx_donations_amount ON donations(amount);

CREATE INDEX idx_meetings_date ON meetings(date);
CREATE INDEX idx_meetings_minister ON meetings(minister_person_id);
CREATE INDEX idx_meetings_location ON meetings(location);

CREATE INDEX idx_people_names ON people(first_name, last_name);
CREATE INDEX idx_donors_normalized ON donors(normalized_name);
CREATE INDEX idx_parties_name ON parties(name);
CREATE INDEX idx_electorates_name ON electorates(name);

-- =====================================================
-- 6. DATA VERIFICATION QUERIES
-- =====================================================

-- Show summary statistics
SELECT 
    'Candidate Overview Records' as table_name,
    COUNT(*) as count,
    COUNT(DISTINCT year) as years,
    COUNT(DISTINCT people_id) as unique_people
FROM candidate_overview
UNION ALL
SELECT 
    'Donations Records' as table_name,
    COUNT(*) as count,
    COUNT(DISTINCT year) as years,
    COUNT(DISTINCT candidate_person_id) as unique_people
FROM donations
UNION ALL
SELECT 
    'Meetings Records' as table_name,
    COUNT(*) as count,
    COUNT(DISTINCT YEAR(date)) as years,
    COUNT(DISTINCT minister_person_id) as unique_people
FROM meetings
UNION ALL
SELECT 
    'People Records' as table_name,
    COUNT(*) as count,
    NULL as years,
    NULL as unique_people
FROM people
UNION ALL
SELECT 
    'Donors Records' as table_name,
    COUNT(*) as count,
    NULL as years,
    NULL as unique_people
FROM donors
UNION ALL
SELECT 
    'Parties Records' as table_name,
    COUNT(*) as count,
    NULL as years,
    NULL as unique_people
FROM parties
UNION ALL
SELECT 
    'Electorates Records' as table_name,
    COUNT(*) as count,
    NULL as years,
    NULL as unique_people
FROM electorates;

-- Show top donors by total amount
SELECT 
    COALESCE(d.org_name, CONCAT(d.first_name, ' ', d.last_name)) as donor_name,
    COUNT(*) as donation_count,
    SUM(don.amount) as total_amount,
    GROUP_CONCAT(DISTINCT don.year ORDER BY don.year) as years
FROM donations don
JOIN donors d ON don.donor_id = d.id
WHERE don.amount > 0
GROUP BY don.donor_id
ORDER BY total_amount DESC
LIMIT 10;

-- Show top recipients by total donations
SELECT 
    CONCAT(p.first_name, ' ', p.last_name) as candidate_name,
    party.name as party,
    COUNT(*) as donation_count,
    SUM(don.amount) as total_received,
    GROUP_CONCAT(DISTINCT don.year ORDER BY don.year) as years
FROM donations don
JOIN people p ON don.candidate_person_id = p.id
LEFT JOIN candidate_overview co ON don.candidate_overview_id = co.id
LEFT JOIN parties party ON co.party_id = party.id
WHERE don.amount > 0
GROUP BY don.candidate_person_id
ORDER BY total_received DESC
LIMIT 10;

COMMIT;
