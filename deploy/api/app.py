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
    open_paths = {"/", "/admin"}
    if request.path in open_paths and request.method == "GET":
        return None
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
        columns: List[str] = reader.fieldnames
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

# Note: No __main__ section needed when running under Passenger.
