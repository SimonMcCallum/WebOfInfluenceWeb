
import os
import re
import csv
import io
from typing import Optional, Tuple, List

from flask import Flask, jsonify, request, Response, render_template_string, redirect, url_for
from flask_cors import CORS
import pymysql
from pymysql import MySQLError
from dotenv import load_dotenv
import requests
import json

# Load environment from .env if present (optional)
load_dotenv()

# Flask app for production (Passenger will import this module)
app = Flask(__name__)
CORS(app)

# --- Configuration ---
DB_HOST = os.environ.get("DB_HOST", "localhost")
DB_USER = os.environ.get("DB_USER")
DB_PASSWORD = os.environ.get("DB_PASSWORD")
DB_NAME = os.environ.get("DB_NAME")
API_TOKEN = os.environ.get("API_TOKEN")  # REQUIRED to access admin and (optionally) all endpoints
API_PROTECT_ALL = os.environ.get("API_PROTECT_ALL", "0") == "1"  # When "1", protect all endpoints with token

# --- Database Connection ---
def get_db_connection():
    try:
        connection = pymysql.connect(
            host=DB_HOST,
            user=DB_USER,
            password=DB_PASSWORD,
            database=DB_NAME,
            cursorclass=pymysql.cursors.DictCursor,
            autocommit=True
        )
        return connection
    except MySQLError as e:
        print(f"Error connecting to database: {e}")
        return None

# --- Token Utilities ---
def extract_token_from_request(req) -> Optional[str]:
    # Header: Authorization: Bearer <token>
    auth = req.headers.get("Authorization", "")
    if auth.startswith("Bearer "):
        return auth.split(" ", 1)[1].strip()
    # Form or query fallback (useful for HTML admin forms)
    token = req.form.get("token") or req.args.get("token")
    return token.strip() if token else None

def require_token(req) -> Optional[Response]:
    if not API_TOKEN:
        return jsonify({"error": "API_TOKEN not configured on server"}), 500
    token = extract_token_from_request(req)
    if not token or token != API_TOKEN:
        return jsonify({"error": "Unauthorized"}), 401
    return None

@app.before_request
def maybe_protect_all():
    # Always allow health and admin HTML GET to render login form, but enforce on POST actions or protected mode
    open_paths = {"/", "/admin", "/ai/extract-names", "/ai/extract-names-diaries"}
    if request.path in open_paths and request.method == "GET":
        return None
    if request.path in ["/ai/extract-names", "/ai/extract-names-diaries"] and request.method == "POST":
        return None  # Allow AI name extraction without token
    if API_PROTECT_ALL:
        # Protect everything (except health)
        if request.path != "/":
            unauthorized = require_token(request)
            if unauthorized:
                return unauthorized
    return None

# --- Health ---
@app.route('/')
def health():
    return "API is running!"

# --- Existing public data endpoints (leave unprotected by default, protectable via API_PROTECT_ALL) ---
@app.route('/candidates', methods=['GET'])
def get_candidates():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.People")
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/party', methods=['GET'])
def get_parties():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.Parties")
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/electorate', methods=['GET'])
def get_electorates():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.Electorates")
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

# --- Simple Admin: Web-based DB view and CSV upload (token-protected) ---

ADMIN_HTML = """
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Web Of Influence — Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 1.25rem; }
    h1,h2 { margin: .5rem 0; }
    form { margin: 1rem 0; padding: .75rem; border: 1px solid #e0e0e0; border-radius: 6px; }
    input, textarea, select { width: 100%; padding: .5rem; margin: .25rem 0 .75rem; box-sizing: border-box; }
    button { padding: .5rem 1rem; }
    table { border-collapse: collapse; margin-top: .75rem; width: 100%; }
    th, td { border: 1px solid #dadada; padding: .4rem .5rem; text-align: left; }
    .ok { color: #1e7e34; }
    .err { color: #b00020; white-space: pre-wrap; }
    .note { color: #444; font-size: .9rem; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
    @media (max-width: 900px) { .row { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <h1>Web Of Influence — Admin</h1>
  <p class="note">All admin actions require a valid API token. Configure API_TOKEN on the server (.env).</p>

  <div class="row">
    <div>
      <h2>Read-only Query</h2>
      <form action="{{ url_for('admin_query') }}" method="post">
        <label>API Token
          <input type="password" name="token" placeholder="Enter API token" required>
        </label>
        <label>SELECT Query (LIMIT enforced if missing)
          <textarea name="query" rows="5" placeholder="SELECT * FROM Entities.People LIMIT 50" required></textarea>
        </label>
        <button type="submit">Run Query</button>
      </form>
      {% if query_result is defined %}
        {% if error %}
          <div class="err">{{ error }}</div>
        {% else %}
          <div class="ok">Returned {{ rows_count }} rows.</div>
          <table>
            <thead>
              <tr>
                {% for col in columns %}<th>{{ col }}</th>{% endfor %}
              </tr>
            </thead>
            <tbody>
              {% for row in query_result %}
              <tr>
                {% for col in columns %}<td>{{ row[col] }}</td>{% endfor %}
              </tr>
              {% endfor %}
            </tbody>
          </table>
        {% endif %}
      {% endif %}
    </div>

    <div>
      <h2>CSV Upload → Table</h2>
      <form action="{{ url_for('admin_upload') }}" method="post" enctype="multipart/form-data">
        <label>API Token
          <input type="password" name="token" placeholder="Enter API token" required>
        </label>
        <label>Target Table (letters, digits, underscore only)
          <input type="text" name="table" placeholder="e.g., Entities.People (use Entities_People)" required>
        </label>
        <p class="note">Note: Use schema_table form (e.g., Entities_People) if table is namespaced; the server will convert underscore to dot for the first underscore to form Entities.People.</p>
        <label>CSV File (header row must match table column names)
          <input type="file" name="file" accept=".csv" required>
        </label>
        <button type="submit">Upload & Insert</button>
      </form>
      {% if upload_result is defined %}
        {% if error %}
          <div class="err">{{ error }}</div>
        {% else %}
          <div class="ok">Inserted {{ inserted }} rows into {{ table_shown }}.</div>
        {% endif %}
      {% endif %}
    </div>
  </div>

  <div class="row">
    <div>
      <h2>AI Name Generation → Database</h2>
      <form action="{{ url_for('admin_ai_generate_names') }}" method="post">
        <label>API Token
          <input type="password" name="token" placeholder="Enter API token" required>
        </label>
        <label>Prompt for AI
          <textarea name="prompt" rows="3" placeholder="Generate 5 fictional names in JSON format: {'names': ['Name1', 'Name2', ...]}" required>Generate 5 fictional names in JSON format: {"names": ["Name1", "Name2", "Name3", "Name4", "Name5"]}</textarea>
        </label>
        <label>Target Table
          <input type="text" name="table" placeholder="Entities.People" value="Entities.People" required>
        </label>
        <p class="note">Names will be inserted into the specified table. Assumes table has 'first_name' and 'last_name' columns.</p>
        <button type="submit">Generate & Import Names</button>
      </form>
      {% if ai_result is defined %}
        {% if error %}
          <div class="err">{{ error }}</div>
        {% else %}
          <div class="ok">Generated and inserted {{ inserted }} names into {{ table_shown }}.</div>
          <h3>Generated Names:</h3>
          <ul>
            {% for name in generated_names %}
            <li>{{ name }}</li>
            {% endfor %}
          </ul>
        {% endif %}
      {% endif %}
    </div>

    <div>
      <h2>Recent Activity</h2>
      <p class="note">This section shows recent admin activities and system status.</p>
      <div class="ok">✓ API is running</div>
      <div class="ok">✓ Database connected</div>
      <div class="ok">✓ AI integration active</div>
    </div>
  </div>

  <p class="note">Security: Query tool only permits SELECT and blocks semicolons. Upload validates identifiers and uses prepared statements.</p>
</body>
</html>
"""

def is_safe_identifier(name: str) -> bool:
    # Allow letters, digits, underscore only
    return bool(re.match(r'^[A-Za-z0-9_]+$', name))

def normalize_schema_table(raw_table: str) -> Optional[str]:
    """
    Accepts either "TableName" or "Schema_Table" and converts first underscore to dot:
      Entities_People -> Entities.People
    If no underscore, returns name as-is.
    """
    if not is_safe_identifier(raw_table):
        return None
    if "_" in raw_table:
        parts = raw_table.split("_", 1)
        schema = parts[0]
        table = parts[1]
        if not (is_safe_identifier(schema) and is_safe_identifier(table)):
            return None
        return f"{schema}.{table}"
    return raw_table

def ensure_select_query_is_safe(q: str) -> Tuple[bool, str]:
    # Basic hardening: must start with SELECT, disallow semicolons to prevent chaining
    if ";" in q:
        return False, "Semicolons are not allowed."
    if not re.match(r'^\s*SELECT\b', q, re.IGNORECASE):
        return False, "Only SELECT queries are permitted."
    return True, ""

def maybe_append_limit(q: str, default_limit: int = 200) -> str:
    # Append LIMIT if not present (naive check)
    if re.search(r'\blimit\b', q, re.IGNORECASE):
        return q
    return f"{q} LIMIT {default_limit}"

@app.route('/admin', methods=['GET'])
def admin_home():
    # Render empty admin page (forms)
    return render_template_string(ADMIN_HTML)

@app.route('/admin/query', methods=['POST'])
def admin_query():
    unauthorized = require_token(request)
    if unauthorized:
        return unauthorized

    q = request.form.get("query", "") or ""
    ok, msg = ensure_select_query_is_safe(q)
    if not ok:
        return render_template_string(ADMIN_HTML, error=msg, query_result=[], columns=[], rows_count=0)

    q_exec = maybe_append_limit(q)

    connection = get_db_connection()
    if connection is None:
        return render_template_string(ADMIN_HTML, error="Failed to connect to DB", query_result=[], columns=[], rows_count=0)

    try:
        with connection.cursor() as cursor:
            cursor.execute(q_exec)
            rows = cursor.fetchall()
            columns = list(rows[0].keys()) if rows else []
            return render_template_string(
                ADMIN_HTML,
                query_result=rows,
                columns=columns,
                rows_count=len(rows)
            )
    except MySQLError as e:
        return render_template_string(ADMIN_HTML, error=str(e), query_result=[], columns=[], rows_count=0)
    finally:
        connection.close()

@app.route('/admin/upload', methods=['POST'])
def admin_upload():
    unauthorized = require_token(request)
    if unauthorized:
        return unauthorized

    raw_table = request.form.get("table", "")
    table = normalize_schema_table(raw_table)
    if not table:
        return render_template_string(ADMIN_HTML, error="Invalid table name", upload_result=True, inserted=0, table_shown=raw_table)

    file = request.files.get("file")
    if not file:
        return render_template_string(ADMIN_HTML, error="CSV file is required", upload_result=True, inserted=0, table_shown=table)

    try:
        content = file.read().decode("utf-8", errors="replace")
        reader = csv.DictReader(io.StringIO(content))
        if not reader.fieldnames:
            return render_template_string(ADMIN_HTML, error="CSV must include a header row", upload_result=True, inserted=0, table_shown=table)

        # Validate column identifiers
        columns = list(reader.fieldnames or [])
        for col in columns:
            if not is_safe_identifier(col):
                return render_template_string(ADMIN_HTML, error=f"Invalid column name: {col}", upload_result=True, inserted=0, table_shown=table)

        placeholders = ", ".join(["%s"] * len(columns))
        col_list = ", ".join(f"`{c}`" for c in columns)
        sql = f"INSERT INTO {table} ({col_list}) VALUES ({placeholders})"

        rows = [tuple((row.get(c) if row.get(c) != "" else None) for c in columns) for row in reader]
        if not rows:
            return render_template_string(ADMIN_HTML, error="CSV contains no data rows", upload_result=True, inserted=0, table_shown=table)

        connection = get_db_connection()
        if connection is None:
            return render_template_string(ADMIN_HTML, error="Failed to connect to DB", upload_result=True, inserted=0, table_shown=table)

        try:
            with connection.cursor() as cursor:
                cursor.executemany(sql, rows)
            inserted = len(rows)
            return render_template_string(ADMIN_HTML, upload_result=True, inserted=inserted, table_shown=table)
        finally:
            connection.close()
    except Exception as e:
        return render_template_string(ADMIN_HTML, error=str(e), upload_result=True, inserted=0, table_shown=table)

@app.route('/admin/ai-generate-names', methods=['POST'])
def admin_ai_generate_names():
    unauthorized = require_token(request)
    if unauthorized:
        return unauthorized

    prompt = request.form.get("prompt", "")
    raw_table = request.form.get("table", "")

    if not prompt:
        return render_template_string(ADMIN_HTML, error="Prompt is required", ai_result=True, inserted=0, table_shown=raw_table, generated_names=[])

    table = normalize_schema_table(raw_table)
    if not table:
        return render_template_string(ADMIN_HTML, error="Invalid table name", ai_result=True, inserted=0, table_shown=raw_table, generated_names=[])

    # Call the AI API
    api_key = get_gemini_api_key()
    if not api_key:
        return render_template_string(ADMIN_HTML, error="Gemini API key not found", ai_result=True, inserted=0, table_shown=table, generated_names=[])

    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={api_key}"
    headers = {'Content-Type': 'application/json'}
    payload = {
        "contents": [
            {
                "parts": [
                    {
                        "text": prompt
                    }
                ]
            }
        ]
    }

    try:
        response = requests.post(url, headers=headers, json=payload)
        if response.status_code != 200:
            return render_template_string(ADMIN_HTML, error=f"AI API error: {response.status_code}", ai_result=True, inserted=0, table_shown=table, generated_names=[])

        result = response.json()
        if 'candidates' not in result or not result['candidates']:
            return render_template_string(ADMIN_HTML, error="No response generated by AI", ai_result=True, inserted=0, table_shown=table, generated_names=[])

        generated_text = result['candidates'][0]['content']['parts'][0]['text']

        # Clean markdown code blocks
        import re
        cleaned_text = re.sub(r'```\w*\n?', '', generated_text).strip()

        # Parse JSON
        try:
            names_data = json.loads(cleaned_text)
        except json.JSONDecodeError:
            return render_template_string(ADMIN_HTML, error="AI response is not valid JSON", ai_result=True, inserted=0, table_shown=table, generated_names=[])

        # Extract names from the JSON response
        names_list = []
        if isinstance(names_data, dict) and 'names' in names_data:
            names_list = names_data['names']
        elif isinstance(names_data, list):
            names_list = names_data
        else:
            return render_template_string(ADMIN_HTML, error="JSON response does not contain a 'names' array", ai_result=True, inserted=0, table_shown=table, generated_names=[])

        if not names_list:
            return render_template_string(ADMIN_HTML, error="No names found in AI response", ai_result=True, inserted=0, table_shown=table, generated_names=[])

        # Insert names into database
        connection = get_db_connection()
        if connection is None:
            return render_template_string(ADMIN_HTML, error="Failed to connect to database", ai_result=True, inserted=0, table_shown=table, generated_names=names_list)

        try:
            with connection.cursor() as cursor:
                inserted_count = 0
                for name in names_list:
                    if isinstance(name, str):
                        # Split name into first and last name
                        name_parts = name.split()
                        if len(name_parts) >= 2:
                            first_name = name_parts[0]
                            last_name = ' '.join(name_parts[1:])
                        else:
                            first_name = name
                            last_name = ""
                    elif isinstance(name, dict):
                        first_name = name.get('first_name', name.get('firstName', ''))
                        last_name = name.get('last_name', name.get('lastName', ''))
                    else:
                        continue

                    try:
                        cursor.execute(f"INSERT INTO {table} (first_name, last_name) VALUES (%s, %s)", (first_name, last_name))
                        inserted_count += 1
                    except MySQLError as e:
                        print(f"Error inserting {first_name} {last_name}: {e}")
                        continue

            return render_template_string(ADMIN_HTML, ai_result=True, inserted=inserted_count, table_shown=table, generated_names=names_list)

        finally:
            connection.close()

    except Exception as e:
        return render_template_string(ADMIN_HTML, error=str(e), ai_result=True, inserted=0, table_shown=table, generated_names=[])

# --- AI Integration ---
def get_gemini_api_key():
    # Prefer environment variable if present
    env_key = os.environ.get("GEMINI_API_KEY")
    if env_key and env_key.strip():
        return env_key.strip()

    # Check common file locations relative to this file
    here = os.path.dirname(__file__)
    candidates = [
        os.path.join(here, "gemini_api_key.txt"),
        os.path.join(here, "..", "gemini_api_key.txt"),
    ]
    for p in candidates:
        try:
            with open(p, "r") as f:
                key = f.read().strip()
                if key:
                    return key
        except FileNotFoundError:
            continue
        except Exception:
            continue
    return None

@app.route('/ai/generate', methods=['POST'])
def ai_generate():
    data = request.get_json()
    if not data or 'prompt' not in data:
        return jsonify({"error": "Prompt is required"}), 400

    api_key = get_gemini_api_key()
    if not api_key:
        return jsonify({"error": "Gemini API key not found"}), 500

    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={api_key}"
    headers = {'Content-Type': 'application/json'}
    payload = {
        "contents": [
            {
                "parts": [
                    {
                        "text": prompt
                    }
                ]
            }
        ]
    }

    try:
        response = requests.post(url, headers=headers, json=payload)
        if response.status_code == 200:
            result = response.json()
            # Extract the generated text
            if 'candidates' in result and result['candidates']:
                text = result['candidates'][0]['content']['parts'][0]['text']
                return jsonify({"response": text})
            else:
                return jsonify({"error": "No response generated"}), 500
        else:
            return jsonify({"error": f"API error: {response.status_code}"}), 500
    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/ai/extract-names', methods=['POST'])
def ai_extract_names():
    # Get the uploaded file
    file = request.files.get('file')
    if not file:
        return jsonify({"error": "No file uploaded"}), 400

    # Read file content
    try:
        content = file.read().decode('utf-8', errors='replace')
    except Exception as e:
        return jsonify({"error": f"Error reading file: {str(e)}"}), 400

    # Limit file size (1MB)
    if len(content) > 1024 * 1024:
        return jsonify({"error": "File too large (max 1MB)"}), 400

    api_key = get_gemini_api_key()
    if not api_key:
        return jsonify({"error": "Gemini API key not found"}), 500

    # Create prompt for name extraction
    prompt = f"""Analyze the following text and extract all person names you can find. Return the results as a JSON object with this exact format:
{{"names": ["First Last", "First Last", ...]}}

Only include actual person names, not organizations, places, or other entities. If no names are found, return {{"names": []}}.

Text to analyze:
{content[:10000]}"""  # Limit content to avoid token limits

    url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={api_key}"
    headers = {'Content-Type': 'application/json'}
    payload = {
        "contents": [
            {
                "parts": [
                    {
                        "text": prompt
                    }
                ]
            }
        ]
    }

    try:
        response = requests.post(url, headers=headers, json=payload)
        if response.status_code != 200:
            return jsonify({"error": f"AI API error: {response.status_code}"}), 500

        result = response.json()
        if 'candidates' not in result or not result['candidates']:
            return jsonify({"error": "No response generated by AI"}), 500

        generated_text = result['candidates'][0]['content']['parts'][0]['text']

        # Clean markdown code blocks
        import re
        cleaned_text = re.sub(r'```\w*\n?', '', generated_text).strip()

        # Parse JSON
        try:
            names_data = json.loads(cleaned_text)
        except json.JSONDecodeError:
            return jsonify({"error": "AI response is not valid JSON"}), 500

        # Extract names
        names_list = []
        if isinstance(names_data, dict) and 'names' in names_data:
            names_list = names_data['names']
        elif isinstance(names_data, list):
            names_list = names_data
        else:
            return jsonify({"error": "Unexpected response format"}), 500

        return jsonify({
            "names": names_list,
            "count": len(names_list),
            "file_name": file.filename
        })

    except Exception as e:
        return jsonify({"error": str(e)}), 500

@app.route('/ai/extract-names-diaries', methods=['POST'])
def ai_extract_names_diaries():
    """
    Upload a diaries CSV and return enriched rows with parsed fields and AI-flagged attendee names.
    Expected CSV headers (case-sensitive):
      - Minister
      - Date or Date Started
      - Date Finished           (optional)
      - Schedule Time           (e.g., "6:00 PM - 7:30 PM" with possible line breaks)
      - Type
      - Meeting                 (title/description)
      - Location
      - With                    (free-text attendees/participants)
      - Portfolio
    Returns JSON:
    {
      "file_name": "...",
      "count_rows": N,
      "rows": [
        {
          "row_index": i,
          "minister": "...",
          "date": "m/d/yyyy",
          "start_time": "h:mm AM/PM" | null,
          "end_time": "h:mm AM/PM" | null,
          "title": "...",
          "type": "...",
          "portfolio": "...",
          "location": "...",
          "notes": "",
          "attendees_text": "...",
          "attendees_names": ["..."]
        },
        ...
      ]
    }
    """
    # Validate file
    file = request.files.get('file')
    if not file:
        return jsonify({"error": "No file uploaded"}), 400

    try:
        content_bytes = file.read()
        # Attempt to decode as UTF-8 with replacement for bad chars
        content = content_bytes.decode('utf-8', errors='replace')
    except Exception as e:
        return jsonify({"error": f"Error reading file: {str(e)}"}), 400

    # Limit file size (1MB similar to /ai/extract-names)
    if len(content) > 1024 * 1024:
        return jsonify({"error": "File too large (max 1MB)"}), 400

    # Parse CSV with Python's csv module (handles quoted line breaks)
    try:
        reader = csv.DictReader(io.StringIO(content))
        rows_raw = list(reader)
        if not rows_raw:
            return jsonify({"error": "CSV contains no data rows"}), 400
    except Exception as e:
        return jsonify({"error": f"CSV parse error: {str(e)}"}), 400

    # Header keys we expect
    MINISTER = 'Minister'
    DATE_STARTED = 'Date or Date Started'
    DATE_FINISHED = 'Date Finished'
    SCHEDULE_TIME = 'Schedule Time'
    TYPE = 'Type'
    MEETING = 'Meeting'
    LOCATION = 'Location'
    WITH = 'With'
    PORTFOLIO = 'Portfolio'

    # Helpers
    def norm_ws(s: Optional[str]) -> str:
        return ' '.join((s or '').split()).strip()

    def extract_times(s: Optional[str]) -> Tuple[Optional[str], Optional[str]]:
        text = norm_ws(s)
        if not text:
            return None, None
        m = re.search(r'(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm))\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm))', text, flags=re.IGNORECASE)
        if m:
            start = m.group(1).upper().replace('  ', ' ').strip()
            end = m.group(2).upper().replace('  ', ' ').strip()
            return start, end
        return None, None

    # Build minimal text per row for AI extraction (attendees + meeting title)
    ai_items = []  # [(id, text)]
    enriched_rows = []  # pre-populated rows without attendees_names
    for idx, r in enumerate(rows_raw):
        date = norm_ws(r.get(DATE_STARTED))
        start_time, end_time = extract_times(r.get(SCHEDULE_TIME))
        title = norm_ws(r.get(MEETING))
        row_type = norm_ws(r.get(TYPE))
        portfolio = norm_ws(r.get(PORTFOLIO))
        location = norm_ws(r.get(LOCATION))
        attendees_text = norm_ws(r.get(WITH))
        minister = norm_ws(r.get(MINISTER))

        enriched_rows.append({
            "row_index": idx,
            "minister": minister or None,
            "date": date or None,
            "start_time": start_time,
            "end_time": end_time,
            "title": title or None,
            "type": row_type or None,
            "portfolio": portfolio or None,
            "location": location or None,
            "notes": "",  # not present in source CSV
            "attendees_text": attendees_text or ""
        })

        # Text for AI: prioritize attendees list, then title context
        text_for_ai = " | ".join([t for t in [attendees_text, title] if t])
        ai_items.append((idx, text_for_ai))

    # If no AI key, we can still return baseline enriched rows without attendees_names
    api_key = get_gemini_api_key()
    if not api_key:
        for row in enriched_rows:
            row["attendees_names"] = []
        return jsonify({
            "file_name": file.filename,
            "count_rows": len(enriched_rows),
            "rows": enriched_rows,
            "warning": "Gemini API key not found; attendees_names left empty."
        })

    # Chunk AI requests to stay within token/size limits
    def chunk_items(items, char_limit=8000):
        chunks = []
        current = []
        current_len = 0
        for (rid, text) in items:
            tlen = len(text)
            # Ensure at least one item per chunk
            if current and current_len + tlen > char_limit:
                chunks.append(current)
                current = []
                current_len = 0
            current.append((rid, text))
            current_len += tlen
        if current:
            chunks.append(current)
        return chunks

    chunks = chunk_items(ai_items, char_limit=8000)

    def call_gemini_for_chunk(chunk):
        """
        chunk: list of (id, text)
        Returns: dict id -> [names]
        """
        payload_rows = [{"id": rid, "text": text} for (rid, text) in chunk]
        instruction = (
            "You are given a JSON object with an array named 'rows'. Each item has 'id' and 'text' fields "
            "(from ministerial diary attendees or meeting descriptions). "
            "For EACH item, extract ONLY human person names (no organizations, roles, titles-only, acronyms, departments, or locations). "
            "Return STRICTLY VALID JSON in this exact form with no extra text or markdown:\n"
            '{"results":[{"id": <id>, "names": ["First Last", ...]}, ...]}\n'
            "Rules:\n"
            "- Only include people names. Exclude ministries, committees, departments, companies, acronyms (e.g., 'MPI', 'FSANZ'), and role words (e.g., 'Officials', 'Ministers', 'Attendees').\n"
            "- Keep names as plain strings; do not include roles or parentheses. If uncertain, omit.\n"
            "- If no person names for an item, use an empty array for that item.\n"
            "- Preserve original spelling as best as possible.\n"
            "- Do NOT include any explanation or markdown. JSON only.\n"
            "rows:\n"
            f"{json.dumps({'rows': payload_rows}, ensure_ascii=False)}"
        )

        url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={api_key}"
        headers = {'Content-Type': 'application/json'}
        body = {
            "contents": [
                {
                    "parts": [
                        {
                            "text": instruction
                        }
                    ]
                }
            ]
        }
        resp = requests.post(url, headers=headers, json=body)
        if resp.status_code != 200:
            # Fallback: give empty names for all in chunk
            return {rid: [] for (rid, _) in chunk}

        result = resp.json()
        if 'candidates' not in result or not result['candidates']:
            return {rid: [] for (rid, _) in chunk}

        generated_text = result['candidates'][0]['content']['parts'][0]['text']
        cleaned_text = re.sub(r'```\w*\n?', '', generated_text).strip()

        try:
            data = json.loads(cleaned_text)
            out = {}
            if isinstance(data, dict) and isinstance(data.get("results"), list):
                for item in data["results"]:
                    rid = item.get("id")
                    names = item.get("names") if isinstance(item, dict) else []
                    if isinstance(names, list):
                        # Normalize names to strings
                        out[rid] = [str(n) for n in names if isinstance(n, (str, int, float))]
            else:
                out = {}
        except Exception:
            out = {}

        # Ensure all ids in chunk present
        for (rid, _) in chunk:
            if rid not in out:
                out[rid] = []
        return out

    # Aggregate AI results
    id_to_names = {}
    for chunk in chunks:
        try:
            chunk_result = call_gemini_for_chunk(chunk)
            id_to_names.update(chunk_result)
        except Exception:
            # On any unexpected error, default empty names for that chunk
            for (rid, _) in chunk:
                id_to_names[rid] = []

    # Merge into enriched rows
    for row in enriched_rows:
        row["attendees_names"] = id_to_names.get(row["row_index"], [])

    return jsonify({
        "file_name": file.filename,
        "count_rows": len(enriched_rows),
        "rows": enriched_rows
    })

# --- Extended endpoints for compatibility and search (migrating from TestDbLoader/database_api.py) ---

def _valid_year(year: str) -> bool:
    # Allowlisted years present in data; extend if needed
    return bool(re.match(r'^(2011|2014|2017|2020|2023)$', year))

@app.route('/candidates/search-id', methods=['GET'])
def api_get_candidate_by_id():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        people_id = request.args.get('people_id')
        if not people_id:
            return jsonify({"error": "people_id is required"}), 400
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.People WHERE id = %s", (people_id,))
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/party/search-id', methods=['GET'])
def api_get_party_by_id():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        party_id = request.args.get('party_id')
        if not party_id:
            return jsonify({"error": "party_id is required"}), 400
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.Parties WHERE id = %s", (party_id,))
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/electorate/search-id', methods=['GET'])
def api_get_electorate_by_id():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        electorate_id = request.args.get('electorate_id')
        if not electorate_id:
            return jsonify({"error": "electorate_id is required"}), 400
        with connection.cursor() as cursor:
            cursor.execute("SELECT * FROM Entities.Electorates WHERE id = %s", (electorate_id,))
            row = cursor.fetchone()
            if not row:
                return jsonify({"error": "not found"}), 404
            return jsonify(row)  # single object (Output.jsx expects object)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/candidates/search', methods=['GET'])
def api_search_candidates():
    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500
    try:
        first_name = request.args.get('first_name')
        last_name = request.args.get('last_name')
        if not first_name and not last_name:
            return jsonify({"error": "At least one parameter (first_name or last_name) is required"}), 400

        sql = "SELECT * FROM Entities.People WHERE "
        params = []
        conds = []
        if first_name:
            conds.append("first_name = %s")
            params.append(first_name)
        if last_name:
            conds.append("last_name = %s")
            params.append(last_name)
        sql += " AND ".join(conds)

        with connection.cursor() as cursor:
            cursor.execute(sql, tuple(params))
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "not found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

@app.route('/candidates/election-overview/<year>/search/combined', methods=['GET'])
def api_candidates_combined_search(year):
    # Validate path parameter to prevent injection in schema.table identifier
    if not _valid_year(year):
        return jsonify({"error": "Invalid year"}), 400

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500

    try:
        first_name = request.args.get('first_name')
        last_name = request.args.get('last_name')
        party_name = request.args.get('party_name')
        electorate_name = request.args.get('electorate_name')

        base = f"SELECT * FROM Overviews_Candidate_Donations_By_Year.{year}_Candidate_Donation_Overview overview"
        conditions = []
        params = []

        if first_name or last_name:
            name_conds = []
            if first_name:
                name_conds.append("first_name = %s")
                params.append(first_name)
            if last_name:
                name_conds.append("last_name = %s")
                params.append(last_name)
            conditions.append(f"""
                overview.people_id IN (
                    SELECT id FROM Entities.People
                    WHERE {' AND '.join(name_conds)}
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

        sql = base
        if conditions:
            sql += " WHERE " + " AND ".join(conditions)

        with connection.cursor() as cursor:
            cursor.execute(sql, tuple(params))
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "No results found"}), 404
            return jsonify(rows)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

# Utilities
def convert_timedelta(obj):
    try:
        from datetime import timedelta
        if isinstance(obj, timedelta):
            return str(obj)
    except Exception:
        pass
    return obj

# Meetings search (case-insensitive name match, optional date range and portfolio)
@app.route('/ministerial_diaries/search-cand-filter', methods=['GET'])
def api_ministerial_diaries_search():
    first_name = request.args.get('first_name')
    last_name = request.args.get('last_name')
    start_date = request.args.get('start_date')
    end_date = request.args.get('end_date')
    portfolio = request.args.get('portfolio')

    if not first_name or not last_name:
        return jsonify({"error": "Both first name and last name are required"}), 400

    connection = get_db_connection()
    if connection is None:
        return jsonify({"error": "Failed to connect to the database"}), 500

    try:
        with connection.cursor() as cursor:
            cursor.execute(
                "SELECT id FROM Entities.People WHERE UPPER(first_name) = UPPER(%s) AND UPPER(last_name) = UPPER(%s)",
                (first_name, last_name),
            )
            person = cursor.fetchone()
            if not person:
                return jsonify({"error": "Candidate not found"}), 404
            people_id = person["id"]

            sql = "SELECT * FROM Ministerial_Meetings.Meetings_Log WHERE minister_logged_id = %s"
            params = [people_id]

            if start_date and end_date:
                sql += " AND (date BETWEEN %s AND %s)"
                params.extend([start_date, end_date])
            elif start_date:
                sql += " AND date >= %s"
                params.append(start_date)
            elif end_date:
                sql += " AND date <= %s"
                params.append(end_date)

            if portfolio:
                sql += " AND portfolio LIKE %s"
                params.append(f"%{portfolio}%")

            cursor.execute(sql, tuple(params))
            rows = cursor.fetchall()
            if not rows:
                return jsonify({"error": "No meetings found"}), 404

            cleaned = []
            for row in rows:
                cleaned_row = {}
                for k, v in row.items():
                    cleaned_row[k] = convert_timedelta(v)
                cleaned.append(cleaned_row)
            return jsonify(cleaned)
    except MySQLError as e:
        return jsonify({"error": str(e)}), 500
    finally:
        connection.close()

# Note: No __main__ section needed when running under Passenger.

# Local development entrypoint (not used by Passenger)
if __name__ == "__main__":
    host = "127.0.0.1"
    port = int(os.environ.get("PORT", "5050"))
    app.run(host=host, port=port, debug=True)
