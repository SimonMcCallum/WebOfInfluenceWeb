import mysql.connector
from mysql.connector import Error
import loader as ld
import pandas 
import re
import requests
from datetime import datetime
import json
import os
from dotenv import load_dotenv, find_dotenv
from pathlib import Path

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

if not os.environ.get("DB_NAME"):
    print("⚠️  Warning: DB_NAME not set in environment or .env file.")


# Time normaliser 
def get_time_tuple(time_string):
    pattern = r"(\d{1,2}:\d{2}\s?[APM]{2})"
    time_matches = re.findall(pattern, time_string)
    
    if not time_matches or len(time_matches) > 2:
       return ( None, None)
    
    time_1200_format = []

    for time in time_matches:
        time = time.replace(" ", "").upper()
        if "AM" in time or "PM" in time:
            time = time.replace("AM", " AM").replace("PM", " PM")
        try:
            time_obj = datetime.strptime(time, "%I:%M %p")
            time_1200_format.append(time_obj.strftime("%H%M"))
        except ValueError:
            raise ValueError(f"Invalid time format: {time}")

    if len(time_1200_format) == 1:
        time_1200_format.append(None)
    return tuple(time_1200_format)

# Location normaliser (Updated at Andrew Hoggard)
def get_location(location_string):
    wellington_based = [
        'Parliament Buildings', 
        'Te Papa', 
        'Government House', 
        'Welington', 
        'Wellingtgon', 
        'Wellington', 
        'Microsoft Teams Meeting',
        'Teams Meeting', 
        'Online', 
        'Video Conference', 
        'L1 Library meeting room; Room 013'
    ]
    if location_string in wellington_based:
        return 'Wellington'
    return location_string

def search_ministers(params):
#    base_url = "http://127.0.0.1:5050"
#    url = f"{base_url}/candidates/search"
    response = requests.get(url, params=params)
    try:
        data = response.json()  
        if isinstance(data, list) and len(data) > 1:
            return None  
        elif isinstance(data, list) and len(data) == 1:
            return data[0]  
        else:
            return None  
    except ValueError:
        return response.text
    
def parse_and_search(attendees):
    parsed_data = []

    names = [name.strip() for name in attendees.split(",")]

    for name in names:
        if "Minister" in name:
            name_parts = [part for part in name.split() if part != "Minister"]
            last_name = name_parts[-1]  
            params = {"last_name": last_name}
            minister_data = search_ministers(params)
            if minister_data:
                parsed_data.append(minister_data)
        
        elif name and not ("Event Attendees" in name or "Multiple Ministers" in name or "Representatives" in name):
            name_parts = name.split()
            first_name = name_parts[0]
            if len(name_parts) > 1:
                last_name = name_parts[-1] if name_parts[-1] not in ["MP", "MPs"] else name_parts[-2]
            else:
                last_name = name_parts[0]
            #last_name = name_parts[-1] if name_parts[-1] not in ["MP", "MPs"] else name_parts[-2]  
            params = {"first_name": first_name, "last_name": last_name}
            person_data = search_ministers(params)
            if person_data:
                parsed_data.append(person_data)

    if not parsed_data:
        return []

    return parsed_data

attendees_data_test = [
    "Minister R, Minister Smith",
    "Todd Stephenson MP, Andy Thompson",
    "Simeon Brown",
    "Simon Kebbell & Dan Kneebone",
    "NZ Avocado Representatives",
    "Minister Costello"
]

for attendee in attendees_data_test:
    print(parse_and_search(attendee))

def get_minister_id(minister_name_first, minister_name_last):
    ld.use_db(mycursor, "Entities")
    person_check = ld.check_tb(mycursor, (f"People WHERE first_name = \"{minister_name_first}\" AND last_name = \"{minister_name_last}\""))
    if not person_check:
        ld.import_data(connection, mycursor, "People", ("first_name", "last_name"), (minister_name_first.upper(), minister_name_last.upper()))
    return ((ld.check_tb(mycursor, (f"People WHERE first_name = \"{minister_name_first}\" AND last_name = \"{minister_name_last}\"")))[0])[0]
    
def create_meeting_table():
    ld.use_db(mycursor, "Ministerial_Meetings")
    column_dict = {
        "id": "INT AUTO_INCREMENT PRIMARY KEY",
        "date": "DATE",
        "start_time": "TIME",
        "end_time": "TIME",
        "location": "VARCHAR(255)",
        "notes": "VARCHAR(255)",
        "type": "VARCHAR(255)",
        "portfolio": "VARCHAR(255)",
        "title": "VARCHAR(255)",
        "minister_logged_id": "INT",
        "with_text" : "VARCHAR(255)"
    }
    
    foreign_keys = [
    ("minister_logged_id","Entities", "People", "id")
]

    
    
    ld.create_tb(mycursor, "Meetings_Log", column_dict, foreign_keys)
def create_db():
    ld.create_db(mycursor, "Ministerial_Meetings")
    connection.commit()
    
def load_meetings():
    create_meeting_table()
    #create_meeting_link_table()


def load_meeting(date, time_string, location, notes, portfolio, title, m_type, attendees, meeting_with_id):
    times = get_time_tuple(time_string)
    start = times[0]
    end = times[1]
    location_adapt = get_location(location)
    if location_adapt == "Wellington":
        notes += ", Wellington based @ " + location
    ld.use_db(mycursor, "Ministerial_Meetings")
    ld.import_data(connection, mycursor, "Meetings_Log", ("date", "start_time", "end_time","type", "location", "notes", "portfolio", "title", "minister_logged_id", "with_text"), (date, start, end, m_type, location_adapt, notes, portfolio, title, meeting_with_id, attendees))
    #connection.commit()
    #meeting_id = (ld.check_tb(mycursor, "Meetings_Log ORDER BY id DESC LIMIT 1"))[0][0]
    #attendees_ids = parse_and_search(attendees)
    """if attendees_ids:
        for attendee in attendees_ids:
            print("Attende3"+attendee)
            ld.import_data(connection, mycursor, "Meeting_Link", ("meeting_id", "person_logged", "person_attended"), (meeting_id, meeting_with_id, int(attendee["id"])))"""
    connection.commit()
    
    

def read_meeting_file(file_id, period_from):
    ld.use_db(mycursor, "Ministerial_Meetings")

    # Ensure the CSV file exists
    file = pandas.read_csv(
        csv_path("diaries_csv", period_from, f"{file_id}.csv"),
        dtype=str, keep_default_na=True
    )

    for index, row in file.iterrows():
        date = normalize_date(row["Date or Date Started"])
        time = row["Schedule Time"]
        location = row["Location"]
        notes = ""
        portfolio = row["Portfolio"]
        title = row["Meeting"]
        type = row["Type"]
        attendees = row["With"]
        minister_named = extract_first_last_tuple(file_id)
        meeting_with_id = get_minister_id(minister_named[0], minister_named[1])
        load_meeting(date, time, location, notes, portfolio, title, type, attendees, meeting_with_id)

def get_meeting_details(id):
    ld.use_db(mycursor, "Ministerial_Meetings")
    ld.execute_query(mycursor, f"SELECT * FROM Meetings_Log WHERE id = {id}")
    return ld.fetch_all(mycursor)
    
import os
def extract_first_last_tuple(file_id):
    name_part = os.path.splitext(file_id)[0]  

    first, last = name_part.split("_")
    return (first, last)

def normalize_date(date_string):
    try:
        return datetime.strptime(date_string, "%m/%d/%Y").strftime("%Y-%m-%d")
    except ValueError:
        raise ValueError(f"Invalid date format: {date_string}")

# === Meeting Link Table Creation ===
""" 
For when data is segmented with multiple people for who met with
def create_meeting_link_table():
    ld.use_db(mycursor, "Ministerial_Meetings")
    column_dict = {
        "id": "INT AUTO_INCREMENT PRIMARY KEY"
    }
    foreign_keys = { 
        "FOREIGN KEY (meeting_id)": "REFERENCES Meetings_Log(id)",
        "FOREIGN KEY (person_logged)": "REFERENCES Entities.People(id)",
        "FOREIGN KEY (person_attended)": "REFERENCES Entities.People(id)"
    }
    
    ld.create_tb(mycursor, "Meeting_Link", column_dict, foreign_keys)
"""

if __name__ == "__main__":
    create_db()
    load_meetings()
    read_meeting_file("ANDREW_HOGGARD", "APROCT24")