from flask import Flask, jsonify, request
from flask_cors import CORS
import mysql.connector
from mysql.connector import Error
import os
from dotenv import load_dotenv

# Load environment from .env if present (optional)
load_dotenv()

# Flask app for production (Passenger will import this module)
app = Flask(__name__)
CORS(app)

def get_db_connection():
    try:
        connection = mysql.connector.connect(
            host=os.environ.get("DB_HOST", "localhost"),
            user=os.environ.get("DB_USER"),
            password=os.environ.get("DB_PASSWORD"),
            database=os.environ.get("DB_NAME")
        )
        return connection
    except Error as e:
        print(f"Error connecting to database: {e}")
        return None

@app.route('/candidates', methods=['GET'])
def get_candidates():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT * FROM Entities.People")
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/party', methods=['GET'])
def get_parties():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT * FROM Entities.Parties")
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/electorate', methods=['GET'])
def get_electorates():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT * FROM Entities.Electorates")
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/donor/search-id', methods=['GET'])
def get_donor_by_id():
    donor_id = request.args.get('donor_id')
    if not donor_id:
        return jsonify({"error": "donor_id is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.Donors WHERE id = %s"
        cursor.execute(query, (donor_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/party/search-id', methods=['GET'])
def get_parties_by_id():
    party_id = request.args.get('party_id')
    if not party_id:
        return jsonify({"error": "party_id is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.Parties WHERE id = %s"
        cursor.execute(query, (party_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/electorate/search-id', methods=['GET'])
def get_electorates_by_id():
    electorate_id = request.args.get('electorate_id')
    if not electorate_id:
        return jsonify({"error": "electorate_id is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.Electorates WHERE id = %s"
        cursor.execute(query, (electorate_id,))
        row = cursor.fetchone()
        if not row:
            return jsonify({"error": "not found"}), 404
        return jsonify(row)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/search-id', methods=['GET'])
def get_candidate_by_id():
    people_id = request.args.get('people_id')
    if not people_id:
        return jsonify({"error": "people_id is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.People WHERE id = %s"
        cursor.execute(query, (people_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/party/search', methods=['GET'])
def get_parties_by_name():
    party_name = request.args.get('party_name')
    if not party_name:
        return jsonify({"error": "party_name is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.Parties WHERE party_name = %s"
        cursor.execute(query, (party_name,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/electorate/search', methods=['GET'])
def get_electorates_by_name():
    electorate_name = request.args.get('electorate_name')
    if not electorate_name:
        return jsonify({"error": "electorate_name is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.Electorates WHERE electorate_name = %s"
        cursor.execute(query, (electorate_name,))
        row = cursor.fetchone()
        if not row:
            return jsonify({"error": "not found"}), 404
        return jsonify(row)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/search', methods=['GET'])
def get_candidate_by_name():
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    if not first_name and not last_name:
        return jsonify({"error": "At least one parameter (first_name or last_name) is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = "SELECT * FROM Entities.People WHERE"
        values = []
        if first_name and last_name:
            query += " first_name = %s AND last_name = %s"
            values.extend([first_name, last_name])
        elif last_name:
            query += " last_name = %s"
            values.append(last_name)
        elif first_name:
            query += " first_name = %s"
            values.append(first_name)
        cursor.execute(query, values)
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/election-overview/<year>', methods=['GET'])
def get_candidates_by_election_23(year):
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = f"SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview"
        cursor.execute(query)
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/election-overview/<year>/search/electorate', methods=['GET'])
def get_candidates_by_electorate(year):
    electorate = request.args.get('electorate_name')
    if not electorate:
        return jsonify({"error": "electorate_name is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT id FROM Entities.Electorates WHERE electorate_name = %s", (electorate,))
        result = cursor.fetchone()
        if not result:
            return jsonify({"error": "Electorate not found"}), 404
        electorate_id = result['id']
        query = f"SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview WHERE electorate_id = %s"
        cursor.execute(query, (electorate_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/election-overview/<year>/search/party', methods=['GET'])
def get_candidates_by_party(year):
    party = request.args.get('party_name')
    if not party:
        return jsonify({"error": "party_name is required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT id FROM Entities.Parties WHERE party_name = %s", (party,))
        result = cursor.fetchone()
        if not result:
            return jsonify({"error": "party not found"}), 404
        party_id = result['id']
        query = f"SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview WHERE party_id = %s"
        cursor.execute(query, (party_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/election-overview/<year>/search/name', methods=['GET'])
def get_candidates_by_name(year):
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    if not first_name or not last_name:
        return jsonify({"error": "Both first_name and last_name are required"}), 400
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        cursor.execute("SELECT id FROM Entities.People WHERE first_name = %s AND last_name = %s", (first_name, last_name,))
        result = cursor.fetchone()
        if not result:
            return jsonify({"error": "Name not found "}), 404
        people_id = result['id']
        query = f"SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview WHERE people_id = %s"
        cursor.execute(query, (people_id,))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "not found"}), 404
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/candidates/election-overview/<year>/search/combined', methods=['GET'])
def get_candidates_combined_search(year):
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    party_name = request.args.get('party_name')
    electorate_name = request.args.get('electorate_name')

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query = f"""
            SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview overview
        """

        conditions = []
        params = []

        if first_name or last_name:
            name_conditions = []
            if first_name:
                name_conditions.append("first_name = %s")
                params.append(first_name)
            if last_name:
                name_conditions.append("last_name = %s")
                params.append(last_name)

            conditions.append(f"""
                overview.people_id IN (
                    SELECT id FROM Entities.People 
                    WHERE {' AND '.join(name_conditions)}
                )
            """)

        if party_name:
            conditions.append("""
                overview.party_id IN (
                    SELECT id FROM Entities.Parties 
                    WHERE party_name = %s
                )
            """)
            params.append(party_name)

        if electorate_name:
            conditions.append("""
                overview.electorate_id IN (
                    SELECT id FROM Entities.Electorates 
                    WHERE electorate_name = %s
                )
            """)
            params.append(electorate_name)

        if conditions:
            query += " WHERE " + " AND ".join(conditions)

        cursor.execute(query, tuple(params))
        rows = cursor.fetchall()
        if not rows:
            return jsonify({"error": "No results found"}), 404
        return jsonify(rows)
    except mysql.connector.Error as err:
        return jsonify({"error": str(err)}), 500
    finally:
        cursor.close()
        connection.close()

from datetime import timedelta
def convert_timedelta(obj):
    if isinstance(obj, timedelta):
        return str(obj)
    return obj

@app.route('/donations/<year>', methods=['GET'])
def get_candidate_donations_list_by_year(year):
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    if not first_name or not last_name:
        return jsonify({"error": "Both first_name and last_name are required"}), 400

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query1 = "SELECT * FROM Entities.People where first_name = %s AND last_name = %s"
        cursor.execute(query1, (first_name, last_name))
        result = cursor.fetchone()
        if not result:
            return jsonify({"error": "Candidate not found"}), 404
        people_id = result['id']
        query2 = f"SELECT * FROM Donations_Individual.Donations_Log_{year} WHERE minister_donated = %s"
        cursor.execute(query2, (people_id,))
        rows = cursor.fetchall()
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/ministerial_diaries/search-cand', methods=['GET'])
def get_candidate_meetings():
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    if not first_name or not last_name:
        return jsonify({"error": "Both first_name and last_name are required"}), 400

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)
    try:
        query1 = "SELECT * FROM Entities.People where first_name = %s AND last_name = %s"
        cursor.execute(query1, (first_name, last_name))
        result = cursor.fetchone()
        if not result:
            return jsonify({"error": "Candidate not found"}), 404
        people_id = result['id']
        query2 = "SELECT * FROM Ministerial_Meetings.Meetings_Log WHERE minister_logged_id = %s"
        cursor.execute(query2, (people_id,))
        rows = cursor.fetchall()
        for row in rows:
            for key, value in row.items():
                row[key] = convert_timedelta(value)
        return jsonify(rows)
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/ministerial_diaries/search-cand-filter', methods=['GET'])
def search_ministerial_diaries():
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    start_date = request.args.get('start_date')
    end_date = request.args.get('end_date')

    if not first_name or not last_name:
        return jsonify({"error": "Both first name and last name are required"}), 400

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    cursor = connection.cursor(dictionary=True)

    try:
        query1 = "SELECT * FROM Entities.People WHERE first_name = %s AND last_name = %s"
        cursor.execute(query1, (first_name, last_name))
        result = cursor.fetchone()

        if not result:
            return jsonify({"error": "Candidate not found"}), 404

        people_id = result['id']

        query2 = "SELECT * FROM Ministerial_Meetings.Meetings_Log WHERE minister_logged_id = %s"
        params = [people_id]

        if start_date and end_date:
            query2 += " AND (date BETWEEN %s AND %s)"
            params.extend([start_date, end_date])

        cursor.execute(query2, params)
        rows = cursor.fetchall()

        for row in rows:
            for key, value in row.items():
                row[key] = convert_timedelta(value)

        if rows:
            return jsonify(rows), 200
        else:
            return jsonify({"error": "No meetings found"}), 404
    except Error as e:
        return jsonify({"error": str(e)}), 500
    finally:
        cursor.close()
        connection.close()

@app.route('/')
def health():
    return "API is running!"

# Note: No __main__ section needed when running under Passenger.
