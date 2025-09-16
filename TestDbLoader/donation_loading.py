import mysql.connector
from mysql.connector import Error
import loader as ld
import pandas 
import os
import re
from datetime import datetime
import requests
import json 
import ministerial_load as ml
import overview_loading as ol

from pathlib import Path
from dotenv import load_dotenv, find_dotenv

# --- Path setup ---
load_dotenv(find_dotenv())

# Define the root directory and CSV data directory
REPO_ROOT = Path(__file__).resolve().parents[1]
CSV_ROOT = (REPO_ROOT / "csv_data").resolve()

# Ensure the csv_data directory exists
def csv_path(*parts: str) -> Path:
    return (CSV_ROOT.joinpath(*parts)).resolve()


# Establish DB connection
try:
    connection = mysql.connector.connect(
        host=os.environ.get("DB_HOST"),
        user=os.environ.get("DB_USER"),
        password=os.environ.get("DB_PASSWORD"),
        database=os.environ.get("DB_NAME")
    )
    mycursor = connection.cursor()
except Error as e:
    print(f"Database connection error: {e}")
    raise

# Check if DB_NAME is set
if not os.environ.get("DB_NAME"):
    print("⚠️  Warning: DB_NAME not set in environment or .env file.")

def create_donation_table(year):
    ld.use_db(mycursor, f"Donations_Individual")
    column_dict = {
        "id": "INT AUTO_INCREMENT PRIMARY KEY",
        "date": "DATE",
        "amount": "DECIMAL(12,2)",
        "MoneyOrGoodsServices": "VarChar(255)",
        "location": "VARCHAR(255)",
        "notes": "VARCHAR(255)",
        "original_id": "VARCHAR(64)",
        "donor_id": "INT",
        "minister_donated": "INT"
    }
    
    foreign_keys = [  
        ("donor_id", "Entities", "Donors", "id"),
        ("minister_donated", "Entities", "People", "id")
    ]

    
    
    ld.create_tb(mycursor, f"Donations_Log_{year}", column_dict, foreign_keys)


def load_donation(date, amount, MoneyOrGoodsServices, location, notes, original_id, donor_id, minister_donated_id, year):
    ld.use_db(mycursor, f"Donations_Individual")
    ld.import_data(connection, mycursor, f"Donations_Log_{year}", ("date", "amount", "MoneyOrGoodsServices", "location", "notes", "original_id", "donor_id", "minister_donated"), (date, amount, MoneyOrGoodsServices, location, notes, original_id, donor_id, minister_donated_id))
    connection.commit()

def convert_to_mysql_date(date_str):
    if not date_str or pandas.isna(date_str):
        return None
    try:
        date_obj = datetime.strptime(date_str, '%d/%m/%Y')
        return date_obj.strftime('%Y-%m-%d')
    except ValueError:
        return None
    
def read_donations_table(year):
    ld.use_db(mycursor, "Donations_Individual")

    # Ensure the CSV file exists
    file = pandas.read_csv(
        csv_path("donations_csv", f"{year}_donor_information_for_candidate.csv"),
        dtype=str, keep_default_na=True
    )

    for index, row in file.iterrows():
        date = convert_to_mysql_date(row['DateReceived'])
        amount = ol.clean_dollar_value(row['DonationAmount']) if pandas.notna(row['DonationAmount']) else 0
        MoneyOrGoodsServices = row['MoneyOrGoodsServices'] if pandas.notna(row['MoneyOrGoodsServices']) else ""
        notes = row['OtherDetail'] if pandas.notna(row['OtherDetail']) else ""
        location = " ".join(filter(None, [
            str(row['Address_Line1']) if not pandas.isna(row['Address_Line1']) else '',
            str(row['Address_Line2']) if not pandas.isna(row['Address_Line2']) else '',
            str(row['Address_City']) if not pandas.isna(row['Address_City']) else '',
            str(row['Address_Country']) if not pandas.isna(row['Address_Country']) else ''
        ]))        
        first_name = row['DonorName_First'] if pandas.notna(row['DonorName_First']) else ""
        last_name = row['DonorName_Last'] if pandas.notna(row['DonorName_Last']) else ""
        donor_name_first = str(first_name).strip() if first_name else ""
        donor_name_last = str(last_name).strip() if last_name else ""

        # Prefer organisation name when person name is missing
        company = row['CompanyOrOrganisation'] if pandas.notna(row.get('CompanyOrOrganisation')) else ""
        if not donor_name_first and not donor_name_last and company:
            donor_name_last = str(company).strip()

        donor_id = check_donor_id(donor_name_first.upper(), donor_name_last.upper())

        # Original donation entry id from CSV (e.g., PartADonationEntry_Id) for traceability
        original_id = row['PartADonationEntry_Id'] if pandas.notna(row.get('PartADonationEntry_Id')) else None

        minister_donated_id = row[f'_{year}CandidateDonations_Id'] if pandas.notna(row.get(f'_{year}CandidateDonations_Id')) else ""
        mycursor.execute(f"""
            SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview
            WHERE original_id = %s
        """, (minister_donated_id,))
        result = mycursor.fetchall()
        if not result:
            continue
        minister_id = result[0][0]
        load_donation(date, amount, MoneyOrGoodsServices, location, notes, original_id, donor_id, minister_id, year)
       

def read_donations_table_23():
    year = "2023"
    ld.use_db(mycursor, "Donations_Individual")

    # Ensure the CSV file exists
    file = pandas.read_csv(
        csv_path("donations_csv", f"{year}_donor_information_for_candidate.csv"),
        dtype=str, keep_default_na=True
    )

    for index, row in file.iterrows():
        date = convert_to_mysql_date(row['DateReceived'])
        amount = ol.clean_dollar_value(row['DonationAmount']) if pandas.notna(row['DonationAmount']) else 0
        MoneyOrGoodsServices = row['MoneyOrGoodsServices'] if pandas.notna(row['MoneyOrGoodsServices']) else ""
        notes = row['OtherDetail'] if pandas.notna(row['OtherDetail']) else ""
        location = " ".join(filter(None, [
            str(row['Address_Line1']) if not pandas.isna(row['Address_Line1']) else '',
            str(row['Address_Line2']) if not pandas.isna(row['Address_Line2']) else '',
            str(row['Address_City']) if not pandas.isna(row['Address_City']) else '',
            str(row['Address_Country']) if not pandas.isna(row['Address_Country']) else ''
        ]))        
        first_name = row['DonorName_First'] if pandas.notna(row['DonorName_First']) else ""
        last_name = row['DonorName_Last'] if pandas.notna(row['DonorName_Last']) else ""
        donor_name_first = str(first_name).strip() if first_name else ""
        donor_name_last = str(last_name).strip() if last_name else ""

        # Prefer organisation name when person name is missing
        company = row['CompanyOrOrganisation'] if pandas.notna(row.get('CompanyOrOrganisation')) else ""
        if not donor_name_first and not donor_name_last and company:
            donor_name_last = str(company).strip()

        donor_id = check_donor_id(donor_name_first.upper(), donor_name_last.upper())

        # Original donation entry id from CSV if present
        original_id = row['PartADonationEntry_Id'] if pandas.notna(row.get('PartADonationEntry_Id')) else None

        minister_donated_id = row[f'CandidateDonations2023Test_Id'] if pandas.notna(row.get(f'CandidateDonations2023Test_Id')) else ""
        mycursor.execute(f"""
            SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview
            WHERE original_id = %s
        """, (minister_donated_id,))
        result = mycursor.fetchall()
        if not result:
            continue
        minister_id = result[0][0]
        load_donation(date, amount, MoneyOrGoodsServices, location, notes, original_id, donor_id, minister_id, year)
       
def check_donor_id(first_name, last_name):
    ld.use_db(mycursor, "Entities")
    donor_id = ld.get_id_match(mycursor, "Entities",  "Donors", {"first_name": first_name, "last_name": last_name})
    if donor_id:
        return donor_id
    else:
        ld.import_data(connection, mycursor, "Donors", ("first_name", "last_name"), (first_name, last_name))
        connection.commit()
        return (ld.check_tb(mycursor, "Donors ORDER BY id DESC LIMIT 1"))[0][0]

def create_db():
    ld.create_db(mycursor, "Ministerial_Meetings")
    connection.commit()

def create_db_2():
    ld.create_db(mycursor, "Donations_Individual")
    connection.commit()

def create_donation_db_and_tables():
    create_db()
    create_db_2()
    create_donation_table("2023")
    create_donation_table("2017")
    create_donation_table("2014")
    create_donation_table("2011")
    read_donations_table("2017")
    read_donations_table("2014")
    read_donations_table("2011")
    read_donations_table_23()
