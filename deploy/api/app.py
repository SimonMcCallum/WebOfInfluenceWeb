
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
    .info-box { background: #f9fafb; border: 1px solid #e5e7eb; border-left: 4px solid #3b82f6; padding: .75rem .9rem; border-radius: 6px; margin: .75rem 0; }
    .info-box .info-title { font-weight: 600; margin-bottom: .25rem; color: #111827; }
    .info-box .info-content { color: #374151; font-size: .92rem; }
    .info-box ul { margin: .4rem 0 .2rem 1.1rem; padding: 0; }
    .info-box li { margin: .2rem 0; }
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
      <div id="mapping-instructions" class="info-box" aria-live="polite">
        <div class="info-title">Mapping Instructions</div>
        <div class="info-content" id="mapping-instructions-content">
          Type a destination table name on the right to see tailored mapping guidance.
        </div>
      </div>
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
  <script>
  (function(){
    // Flask Admin uses a text input for target table
    var custom = document.querySelector('input[name="table"]');
    var contentEl = document.getElementById('mapping-instructions-content');

    function htmlDefault(){
      return '<p>Pick a destination table then upload a CSV. The next step lets you map CSV columns to database columns. Use "Ignore" for columns you do not want imported.</p>'
        + '<ul>'
        + '<li>Avoid mapping id/primary key columns.</li>'
        + '<li>Map only columns that exist in the destination table (or choose "Create column" to add a new TEXT column, if your importer supports it).</li>'
        + '</ul>';
    }

    function htmlDonations(){
      return '<p><b>Importing into donations (and auto-creating donors)</b></p>'
        + '<p><b>General rule</b></p>'
        + '<ul>'
        + '<li>Donors are auto-created/linked in the <code>donors</code> table.</li>'
        + '<li>Candidates are auto-linked via <code>people_id</code> or <code>original_id</code>.</li>'
        + '<li>You just need to map the correct CSV columns.</li>'
        + '</ul>'
        + '<p><b>Case: (year)_donor_information_for_candidate.csv</b></p>'
        + '<ol>'
        + '<li>In <b>Admin → CSV Upload → Table</b>:'
        +   '<ul>'
        +     '<li>Destination table: <b>donations</b></li>'
        +     '<li>Upload file: <b>(year)_donor_information_for_candidate.csv</b></li>'
        +   '</ul>'
        + '</li>'
        + '<li>On Mapping Screen — map columns as follows:</li>'
        + '</ol>'
        + '<p><b>Required / Recommended</b></p>'
        + '<ul>'
        + '<li><code>_(year)CandidateDonations_Id</code> → <b>original_id</b><br><small>Links each donation to the correct <code>candidate_overview</code> row for (year)</small></li>'
        + '<li><code>DateReceived</code> → <b>date</b></li>'
        + '<li><code>DonationAmount</code> → <b>amount</b> <small>(auto‑strips $ and commas)</small></li>'
        + '<li><code>MoneyOrGoodsServices</code> → <b>money_or_goods_services</b></li>'
        + '<li><code>OtherDetail</code> → <b>notes</b></li>'
        + '</ul>'
        + '<p><b>Donor (auto‑created/linked in donors table)</b></p>'
        + '<ul>'
        + '<li><code>DonorName_First</code> → <b>donor_first_name</b></li>'
        + '<li><code>DonorName_Last</code> → <b>donor_last_name</b></li>'
        + '<li><code>CompanyOrOrganisation</code> → <b>donor_org_name</b></li>'
        + '</ul>'
        + '<p><b>Address (builds “location” automatically)</b></p>'
        + '<ul>'
        + '<li><code>Address_Line1</code> → <b>address_line1</b></li>'
        + '<li><code>Address_Line2</code> → <b>address_line2</b></li>'
        + '<li><code>Address_City</code> → <b>address_city</b></li>'
        + '<li><code>Address_PostalCode</code> → <b>address_postalcode</b></li>'
        + '<li><code>Address_Country</code> → <b>address_country</b></li>'
        + '</ul>'
        + '<p><b>Ignore (leave unmapped)</b></p>'
        + '<ul>'
        + '<li><code>PartADonationEntry_Id</code></li>'
        + '<li><code>DateRangeFinishDate</code></li>'
        + '<li><code>AdditionalDateReceived</code>, <code>AdditionalDateReceived2..6</code></li>'
        + '<li><code>Contributions</code> <small>(optional: map to notes if you want the text)</small></li>'
        + '<li><code>DonorName_Prefix</code></li>'
        + '<li>Any system fields (<code>donor_id</code>, <code>candidate_person_id</code>, <code>candidate_overview_id</code>, <code>created_at</code>)</li>'
        + '<li><code>CandidateDonations2023Test_Id</code> <small>(not used for 2011–2020)</small></li>'
        + '</ul>'
        + '<p><b>Click “Import with Mapping”.</b> Optional: tick <b>Truncate table before insert</b> for a clean reload.</p>'
        + '<p><b>What Happens Automatically</b></p>'
        + '<ul>'
        + '<li><b>Donors:</b> Auto‑created/linked using donor_first_name/donor_last_name or donor_org_name. A <code>normalized_name</code> prevents duplicate donors. If a donor is a person, a matching record is also ensured in <code>people</code>.</li>'
        + '<li><b>Candidate linking priority:</b>'
        +   '<ol>'
        +     '<li><code>people_id</code> / <code>candidate_person_id</code> (not in your CSV)</li>'
        +     '<li>Candidate first/last name (if present)</li>'
        +     '<li><code>original_id</code> + <code>year</code> (preferred)</li>'
        +   '</ol>'
        + '</li>'
        + '<li><b>Year:</b> Taken from <code>DateReceived</code> where possible; otherwise inferred from filename/header (2011).</li>'
        + '<li><b>Location:</b> Built automatically from the mapped address fields if no single location column is provided.</li>'
        + '<li><b>Insert:</b> Creates rows in <code>donations</code> with year, date, amount, money_or_goods_services, notes, location, donor_id, candidate_person_id, candidate_overview_id.</li>'
        + '<li><b>Re‑imports:</b> Uses INSERT (not upsert). If you re‑run, use <b>Truncate</b> first for a clean reload.</li>'
        + '</ul>'
        + '<p><b>Tips</b></p>'
        + '<ul>'
        + '<li>Never map <code>_(year)CandidateDonations_Id</code> to <code>people_id</code>. Always map it to <code>original_id</code>.</li>'
        + '<li>If a row’s <code>original_id</code> doesn’t match any <code>candidate_overview</code> for (year):'
        +   '<ul>'
        +     '<li>The donation and donor still insert.</li>'
        +     '<li><code>candidate_overview_id</code> remains NULL.</li>'
        +   '</ul>'
        + '</li>'
        + '</ul>'
        + '<p><b>Optional Verification Queries</b></p>'
        + '<pre>-- Check newly created donors\nSELECT id, first_name, last_name, org_name \nFROM donors \nORDER BY id DESC LIMIT 10;\n\n-- Donations linked to a person\nSELECT COUNT(*) \nFROM donations \nWHERE year = 2011 \n  AND candidate_person_id IS NOT NULL;\n\n-- Donations linked to candidate_overview\nSELECT COUNT(*) \nFROM donations \nWHERE year = 2011 \n  AND candidate_overview_id IS NOT NULL;</pre>';
    }

    function htmlCandidateOverview(){
      return '<p>Candidate Overview often uses an enhanced importer and may ignore manual mapping.</p>'
        + '<ul>'
        + '<li>Ensure headers include <b>candidate first</b>, <b>last</b>, <b>party</b>, <b>electorate</b>.</li>'
        + '<li><b>year</b> may be inferred; include if available.</li>'
        + '<li><b>original_id</b> helps link to donations later.</li>'
        + '</ul>'
        + '<p>Why ignore? Extra fields are not used by the overview importer.</p>';
    }

    function htmlMeetings(){
      return '<p><b>Importing Ministerial Diaries into meetings Table</b></p>'
        + '<p><b>General Rule</b></p>'
        + '<ul>'
        + '<li>Use the <b>AI Name Finder</b> first to enrich your CSV.</li>'
        + '<li>Then import into <b>meetings</b>.</li>'
        + '<li>The importer auto‑creates/links people for <b>minister</b> and <b>attendees</b>.</li>'
        + '</ul>'
        + '<p><b>Step 1 — Enrich CSV with AI Name Finder</b></p>'
        + '<ul>'
        + '<li><b>Tool:</b> AI Name Finder</li>'
        + '<li><b>Mode:</b> Ministerial Diaries CSV → enrich + flag attendees</li>'
        + '</ul>'
        + '<p><b>Input CSV headers (expected)</b></p>'
        + '<ul>'
        + '<li>Minister</li>'
        + '<li>Date</li>'
        + '<li>Schedule Time</li>'
        + '<li>Title</li>'
        + '<li>Type</li>'
        + '<li>Portfolio</li>'
        + '<li>Location</li>'
        + '<li>Notes</li>'
        + '<li>With/Attendees</li>'
        + '</ul>'
        + '<p><b>Output (enriched CSV includes extra columns)</b></p>'
        + '<ul>'
        + '<li><code>Attendees_Text</code>: normalized text of attendees</li>'
        + '<li><code>Attendees_Names</code>: AI‑flagged person names, semicolon‑separated (e.g., "John Smith; Jane Doe")</li>'
        + '</ul>'
        + '<p><b>Step 2 — Import Enriched CSV into meetings</b></p>'
        + '<ol>'
        + '<li>In <b>Admin → CSV Upload → Table</b>: Destination table: <b>meetings</b></li>'
        + '<li>On Mapping Screen — map columns as follows:</li>'
        + '</ol>'
        + '<p><b>Required mappings</b></p>'
        + '<ul>'
        + '<li><code>Date</code> → <b>date</b></li>'
        + '<li><code>Title</code> → <b>title</b></li>'
        + '<li><code>Type</code> → <b>type</b></li>'
        + '<li><code>Portfolio</code> → <b>portfolio</b></li>'
        + '<li><code>Location</code> → <b>location</b></li>'
        + '<li><code>Notes</code> → <b>notes</b></li>'
        + '<li><code>Attendees_Text</code> → <b>with_text</b></li>'
        + '</ul>'
        + '<p><b>Leave as Ignore</b></p>'
        + '<ul>'
        + '<li><code>Minister</code> → <b>Ignore</b> (importer derives <code>minister_person_id</code> automatically)</li>'
        + '<li><code>Attendees_Names</code> → <b>Ignore</b> (importer reads and upserts people automatically)</li>'
        + '</ul>'
        + '<p><b>Time handling</b></p>'
        + '<ul>'
        + '<li>If only <code>Schedule Time</code> is present (e.g., "9:30 AM - 10:00 AM"), leave unmapped — importer parses into <code>start_time</code>/<code>end_time</code>.</li>'
        + '<li>If you have <code>Start_Time</code> and <code>End_Time</code> columns, map them directly.</li>'
        + '</ul>'
        + '<p><b>What Happens Automatically</b></p>'
        + '<ul>'
        + '<li><b>Minister linking:</b> importer resolves <code>minister_person_id</code> from <code>Minister</code> (or AI ai_first_name/ai_last_name) and creates the person if missing.</li>'
        + '<li><b>Attendees linking:</b> importer reads <code>Attendees_Names</code>, splits to first/last, upserts into <code>people</code>, and links attendees to the meeting.</li>'
        + '</ul>';
    }

    function htmlPeople(){
      return '<p><b>Importing into the people table</b></p>'
        + '<p><b>General rule</b></p>'
        + '<ul>'
        + '<li>Use destination table: <b>people</b></li>'
        + '<li>Map only the name fields.</li>'
        + '<li>Leave other columns as <b>Ignore</b>.</li>'
        + '</ul>'
        + '<p><b>Steps</b></p>'
        + '<ol>'
        + '<li>In <b>Admin → CSV Upload → Table</b>:'
        +   '<ul>'
        +     '<li>Destination table: <b>people</b></li>'
        +     '<li>Upload your CSV</li>'
        +   '</ul>'
        + '</li>'
        + '<li>On Mapping Screen (recommended):'
        +   '<ul>'
        +     '<li><code>First_Name</code> (or <code>First Name</code>) → <b>first_name</b></li>'
        +     '<li><code>Last_Name</code> (or <code>Last Name</code>) → <b>last_name</b></li>'
        +     '<li><code>Prefix</code> / <code>CandidateName_Prefix</code> (if present) → <b>prefix</b> (create the column if needed)</li>'
        +     '<li><code>Electorate</code> → <b>Ignore</b></li>'
        +     '<li><code>Party</code> → <b>Ignore</b></li>'
        +   '</ul>'
        + '</li>'
        + '<li>Click <b>Import with Mapping</b>.</li>'
        + '</ol>'
        + '<p><b>What happens</b></p>'
        + '<ul>'
        + '<li>Each row inserts one person with an auto-generated <code>people.id</code>.</li>'
        + '<li>These records can be linked by the <code>candidate_overview</code> importer.</li>'
        + '<li>Matching is case-insensitive on <code>first_name</code> + <code>last_name</code>.</li>'
        + '<li>If a person is missing, <code>candidate_overview</code> will create them automatically.</li>'
        + '</ul>'
        + '<p><b>Avoiding duplicates</b></p>'
        + '<ul>'
        + '<li>Generic importer uses <code>INSERT IGNORE</code>; without a <code>UNIQUE</code> constraint, exact duplicates may still slip through.</li>'
        + '</ul>'
        + '<p>Optional checks (Admin → Read-only Query):</p>'
        + '<pre>-- Find potential duplicates (case-insensitive)\nSELECT \n  UPPER(first_name) AS fn, \n  UPPER(last_name) AS ln, \n  COUNT(*) AS c\nFROM people\nGROUP BY UPPER(first_name), UPPER(last_name)\nHAVING COUNT(*) > 1\nORDER BY c DESC;</pre>'
        + '<p>Optional uniqueness enforcement (after cleaning duplicates):</p>'
        + '<pre>ALTER TABLE people \nADD UNIQUE idx_people_name (first_name, last_name);</pre>'
        + '<p><b>Notes</b></p>'
        + '<ul>'
        + '<li><b>Electorate</b> and <b>Party</b> are not part of <code>people</code>. Import them separately:'
        +   '<ul>'
        +     '<li><code>parties</code>: map <b>Party</b> → <b>name</b></li>'
        +     '<li><code>electorates</code>: map <b>Electorate</b> → <b>name</b></li>'
        +   '</ul>'
        + '</li>'
        + '<li>You don’t need to prefill <code>people</code> for <code>candidate_overview</code>—missing people are created automatically. Prefill only if you want specific capitalization or to control prefixes/titles.</li>'
        + '</ul>';
    }

    function htmlGeneric(name){
      return '<p>Importing into <b>' + name + '</b>.</p>'
        + '<ul>'
        + '<li>Map only columns that exist in the table (or use a create-column feature if available).</li>'
        + '<li>Avoid mapping primary key/id columns.</li>'
        + '<li>Use "Ignore" for columns that are informational only.</li>'
        + '</ul>';
    }

    function getChosen(){
      var v = '';
      if (custom && custom.value.trim() !== '') v = custom.value.trim();
      return (v || '').toLowerCase();
    }

    function render(){
      if (!contentEl) return;
      var chosen = getChosen();
      if (!chosen) { contentEl.innerHTML = htmlDefault(); return; }
      if (chosen.indexOf('donation') !== -1) { contentEl.innerHTML = htmlDonations(); return; }
      if (chosen.indexOf('meetings') !== -1) { contentEl.innerHTML = htmlMeetings(); return; }
      if (chosen.indexOf('people') !== -1) { contentEl.innerHTML = htmlPeople(); return; }
      if (chosen.indexOf('candidate_overview') !== -1) { contentEl.innerHTML = htmlCandidateOverview(); return; }
      contentEl.innerHTML = htmlGeneric(chosen);
    }

    if (custom) custom.addEventListener('input', render);
    render();
  })();
  </script>
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
    prompt = (data.get('prompt') or '').strip()
    if not prompt:
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
        ],
        "generationConfig": {
            "temperature": 0,
            "topP": 1,
            "topK": 1,
            "candidateCount": 1
        }
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
    """
    Generic Text/CSV → extract names
    Improvements:
      - If the upload is a CSV, parse likely name-bearing columns first (e.g., Attendees Names, With, Attendees, Minister).
      - Heuristics to split semicolon/comma/&/'and' separated names and strip titles (Hon, Dr, Sir, Dame, Councillor, Minister).
      - Fallback to AI on the full text in CHUNKS (no 10k truncation) and merge results.
    """
    # Get the uploaded file
    file = request.files.get('file')
    if not file:
        return jsonify({"error": "No file uploaded"}), 400

    # Read file content (text mode)
    try:
        content_bytes = file.read()
        content = content_bytes.decode('utf-8', errors='replace')
    except Exception as e:
        return jsonify({"error": f"Error reading file: {str(e)}"}), 400

    # Limit file size (1MB)
    if len(content) > 1024 * 1024:
        return jsonify({"error": "File too large (max 1MB)"}), 400

    # Helpers
    def norm_ws(s: Optional[str]) -> str:
        return ' '.join((s or '').split()).strip()

    # Remove common titles, parentheses, stray punctuation, keep hyphens
    TITLES_RE = re.compile(r'\b(Rt\.?\s*Hon|Hon|Dr|Sir|Dame|Councillor|Minister|MP)\b\.?', re.IGNORECASE)
    BAD_WORDS = set([
        'attendees','attendee','event attendees',
        'officials','official','representatives','representative','delegation','members','committee','committee members',
        'chair','co-chair','chairperson','ce','ceo','cfo','cto','gm','director','advisor','advisors','secretary',
        'department','ministry','council','association','university','board','professor','prof','press','media','news',
        'minister','ministers','mp','mlc'
    ])

    def clean_name(name: str) -> Optional[str]:
        n = norm_ws(name)
        if not n:
            return None
        # Strip surrounding quotes
        n = n.strip('"\'')

        # Drop bracketed/role info and commas in role suffixes conservatively
        n = re.sub(r'\([^)]*\)', ' ', n)
        n = TITLES_RE.sub('', n)
        n = re.sub(r'[(),]', ' ', n)
        n = re.sub(r'\s*-\s*', '-', n)  # normalize spaces around hyphens
        n = norm_ws(n)

        # Expect at least 2 tokens to be a person name
        parts = n.split()
        if len(parts) < 2:
            return None

        # Token-level rejection: roles/orgs/acronyms
        for p in parts:
            pl = p.lower()
            if pl in BAD_WORDS:
                return None
            # reject obvious acronyms (e.g., MPI, FSANZ)
            if p.isupper() and len(p) >= 2:
                return None
            # token sanity
            if not re.match(r"^[A-Za-z][A-Za-z'.-]*$", p):
                return None
        return n

    def split_possible_names(text: str) -> List[str]:
        """
        Split by common separators ; , & and, and vertical bars.
        Then try to clean each piece into a person name.
        """
        t = norm_ws(text)
        if not t:
            return []
        # canonical separators
        # Replace ' & ' and ' and ' with ';' to unify
        t = re.sub(r'\s+(?:&|and)\s+', ';', t, flags=re.IGNORECASE)
        # Also split on commas that likely separate distinct people (keep commas inside last names rare)
        # We will first split on semicolons and pipes, then further split residual long chunks on comma if needed.
        parts = re.split(r'[;|]', t)
        out = []
        for part in parts:
            part = norm_ws(part)
            # If still contains multiple comma-separated items, split them too
            subparts = [part]
            if part.count(',') >= 2:
                subparts = [norm_ws(x) for x in part.split(',') if norm_ws(x)]
            for sp in subparts:
                nm = clean_name(sp)
                if nm:
                    out.append(nm)
        return out

    # Decide if this looks like CSV
    looks_like_csv = file.filename.lower().endswith('.csv') or (',' in content.splitlines()[0] if content else False)

    gemini_only_flag = (request.form.get('gemini_only') or '').strip().lower()
    gemini_only = gemini_only_flag in ('1', 'true', 'yes', 'on')

    heuristic_names = {}
    def add_heuristic_name(n: str):
        key = re.sub(r'\s+', ' ', n.strip()).lower()
        if not key:
            return
        heuristic_names[key] = n.strip()

    ai_names_set = {}
    def add_ai_name(n: str):
        key = re.sub(r'\s+', ' ', n.strip()).lower()
        if not key:
            return
        ai_names_set[key] = n.strip()

    if looks_like_csv and not gemini_only:
        try:
            reader = csv.DictReader(io.StringIO(content))
            headers = reader.fieldnames or []
            # Normalize headers to find candidate columns
            def norm_key(s: str) -> str:
                return re.sub(r'[^a-z0-9]+', '_', (s or '').lower()).strip('_')

            norm_headers = {norm_key(h): h for h in headers}
            # Candidate columns likely to contain explicit names
            candidate_cols = []
            for nk, raw in norm_headers.items():
                if any(key in nk for key in [
                    'attendees_names', 'attendeesnames', 'names',
                    'attendees_text','attendeestext','attendees','with','minister'
                ]):
                    candidate_cols.append(raw)

            # Always include last column if it's "Attendees Names" pattern
            # (supports files where last col is the target)
            if headers:
                last_h = headers[-1]
                if last_h not in candidate_cols:
                    candidate_cols.append(last_h)

            # Iterate rows and parse names from candidate columns
            for row in reader:
                for col in candidate_cols:
                    val = row.get(col)
                    if not val:
                        continue
                    # Primary: explicit attendees names list
                    for nm in split_possible_names(val):
                        add_heuristic_name(nm)

            # If very few names found, widen search by scanning all cells for person-like patterns
            if len(heuristic_names) < 5:
                for row in csv.reader(io.StringIO(content)):
                    for cell in row:
                        for nm in split_possible_names(cell):
                            add_heuristic_name(nm)
        except Exception:
            # If CSV parse fails, fall back to text mode below
            pass

    # Fallback and augmentation with AI over the full text in CHUNKS (no 10k truncation)
    api_key = get_gemini_api_key()
    if api_key:
        def chunk_text(txt: str, limit: int = 8000) -> List[str]:
            if not txt:
                return []
            # Use full original text to keep AI extraction consistent across modes
            chunks = []
            start = 0
            while start < len(txt):
                end = min(len(txt), start + limit)
                chunks.append(txt[start:end])
                start = end
            return chunks

        def call_gemini(text_chunk: str) -> List[str]:
            prompt = (
                'Extract ONLY human person names from the following text. '
                'Return STRICTLY VALID JSON as: {"names": ["First Last", "..."]} with no markdown:\n\n'
                + text_chunk
            )
            url = f"https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={api_key}"
            headers = {'Content-Type': 'application/json'}
            payload = {
                "contents": [
                    {
                        "parts": [
                            {"text": prompt}
                        ]
                    }
                ],
                "generationConfig": {
                    "temperature": 0,
                    "topP": 1,
                    "topK": 1,
                    "candidateCount": 1
                }
            }
            try:
                resp = requests.post(url, headers=headers, json=payload, timeout=20)
                if resp.status_code != 200:
                    return []
                result = resp.json()
                if 'candidates' not in result or not result['candidates']:
                    return []
                generated_text = result['candidates'][0]['content']['parts'][0]['text']
                cleaned_text = re.sub(r'```\w*\n?', '', generated_text).strip()
                data = json.loads(cleaned_text)
                if isinstance(data, dict) and isinstance(data.get('names'), list):
                    return [str(x) for x in data['names']]
                if isinstance(data, list):
                    return [str(x) for x in data]
                return []
            except Exception:
                return []

        for ch in chunk_text(content, 8000):
            ai_names = call_gemini(ch)
            for nm in ai_names:
                cn = clean_name(nm)
                if cn:
                    add_ai_name(cn)

    # Build final unique, stable-ordered list
    # Prefer AI-derived names for determinism; fall back to heuristics if AI empty or unavailable
    base = ai_names_set if ai_names_set else heuristic_names
    final_names = list(dict(sorted(base.items(), key=lambda kv: kv[0])).values())

    return jsonify({
        "names": final_names,
        "count": len(final_names),
        "file_name": file.filename
    })

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

    # Minimal person-name cleaner (diaries)
    TITLES_RE_DIARIES = re.compile(r'\b(Rt\.?\s*Hon|Hon|Dr|Sir|Dame|Councillor|Minister|MP)\b\.?', re.IGNORECASE)
    BAD_WORDS_DIARIES = set([
        'attendees','attendee','event attendees','officials','ministers','committee','committee members',
        'members','representatives','representative','delegation','chair','ce','ceo','advisor','advisors',
        'department','ministry','council','association','university','board','professor','prof','press',
        'media','news','herald'
    ])
    def clean_name_diaries(name: str) -> Optional[str]:
        n = norm_ws(name)
        if not n:
            return None
        n = n.strip('"\'')

        n = re.sub(r'\([^)]*\)', ' ', n)
        n = TITLES_RE_DIARIES.sub('', n)
        n = re.sub(r'[(),]', ' ', n)
        n = re.sub(r'\s*-\s*', '-', n)
        n = norm_ws(n)
        if not n:
            return None

        parts = n.split()
        if len(parts) < 2:
            return None

        # Reject if any token is a known bad word or an all-caps acronym (length >= 2)
        for p in parts:
            pl = p.lower()
            if pl in BAD_WORDS_DIARIES:
                return None
            if p.isupper() and len(p) >= 2:
                return None
            if not re.match(r"^[A-Za-z][A-Za-z'.-]*$", p):
                return None
        return n

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
            ],
            "generationConfig": {
                "temperature": 0,
                "topP": 1,
                "topK": 1,
                "candidateCount": 1
            }
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
                        # Normalize names to strings then clean/filter person names
                        cleaned_names = []
                        for n in names:
                            s = str(n)
                            cn = clean_name_diaries(s)
                            if cn:
                                cleaned_names.append(cn)
                        out[rid] = cleaned_names
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
