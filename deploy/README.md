Web Of Influence — API Deployment on cPanel (PHP API + optional Python WSGI)

Overview
- What’s deployed under https://ludogogy.co.nz/webofinfluence:
  - PHP API mounted at /webofinfluence/api (single database, PDO, no framework)
    - Admin web UI at /webofinfluence/api/admin (read-only SQL query + CSV upload)
  - Optional (legacy): Flask API via Passenger (Python WSGI) still present at /webofinfluence (root), but /api subdirectory is handled by PHP and is isolated from Passenger.
  - Static files (index.html placeholder + app-config.js) at /webofinfluence
- Hosting: cPanel (Apache). The /webofinfluence/api subdirectory explicitly disables Passenger so PHP handles requests there.
- Deployed URL base: https://ludogogy.co.nz/webofinfluence

Single Database Design (for PHP)
- All application tables live in a single MySQL database (recommended: ludog319_webofinfluence).
- Schema options:
  1) Single-DB schema (recommended for PHP):
     - Import Documentation/sql/woi_schema_singledb.sql into your target database (no CREATE DATABASE/USE; creates tables in the selected DB).
     - Tables created: people, parties, electorates, candidate_overview, donations, meetings.
  2) Namespaced schema (original multi-schema version):
     - Import Documentation/sql/woi_schema.sql (creates a database woi plus compatibility views).
     - If you use this, set DB_NAME to woi in the PHP API config.

Key Files
- .cpanel.yml — automated deployment tasks for cPanel “Deploy HEAD”
  - Builds frontend (if npm is available), syncs deploy/ to /webofinfluence
  - Syncs PHP API to /webofinfluence/api and disables Passenger in that subdir
  - Vendors Python deps for legacy Flask API and restarts Passenger
- deploy/.htaccess — Passenger + rewrite rules for Python at the root (legacy)
- deploy/passenger_wsgi.py — WSGI entry for legacy Flask app (still serves root if needed)
- deploy/api/app.py — Legacy Flask API (kept for reference; not used when hitting /webofinfluence/api)
- deploy/php-api/index.php — New PHP API (PDO) implementing the endpoints
- deploy/php-api/.htaccess — Disables Passenger in /api and routes to index.php
- deploy/php-api/config.example.php — Copy to config.php and set DB credentials + token
- deploy/index.html — Placeholder page (frontend SPA is intentionally not built/served by default)
- deploy/app-config.js — Sets window.__APP_CONFIG__.API_BASE = "/webofinfluence/api"

Database (Simon deploy)
- Recommended Single DB name: ludog319_webofinfluence
- DB user: ludog319_kng
- DB password: WFoSE!
- Host: localhost
- Ensure MySQL user privileges are correctly set for the database.

PHP API Configuration
- Path: /home/ludog319/public_html/webofinfluence/api
- Copy deploy/php-api/config.example.php to deploy/php-api/config.php (not committed) and set:
  return [
    'DB_HOST' => 'localhost',
    'DB_USER' => 'ludog319_kng',
    'DB_PASS' => 'WFoSE!',
    'DB_NAME' => 'ludog319_webofinfluence',  // IMPORTANT: single database name
    'API_TOKEN' => 'changeme-strong-secret',  // choose a strong token
    'API_PROTECT_ALL' => false,               // true to require token for all endpoints (except GET /)
  ];
- Alternatively, environment variables (DB_HOST, DB_USER, DB_PASSWORD, DB_NAME, API_TOKEN, API_PROTECT_ALL) can be used, but for PHP on cPanel, config.php is preferred.

Endpoints (PHP)
- Health:
  - GET /webofinfluence/api/
  - Response: "API is running!"
- Public data (token required only if API_PROTECT_ALL=true):
  - GET /webofinfluence/api/candidates
  - GET /webofinfluence/api/party
  - GET /webofinfluence/api/electorate
  - GET /webofinfluence/api/candidates/search-id?people_id=...
  - GET /webofinfluence/api/party/search-id?party_id=...
  - GET /webofinfluence/api/electorate/search-id?electorate_id=...
  - GET /webofinfluence/api/candidates/search?first_name=&last_name=
  - GET /webofinfluence/api/candidates/election-overview/{year}/search/combined?first_name=&last_name=&party_name=&electorate_name=
    - years allowed: 2011|2014|2017|2020|2023
  - GET /webofinfluence/api/ministerial_diaries/search-cand-filter?first_name=&last_name=&start_date=&end_date=&portfolio=
- Admin HTML (always token-protected):
  - GET /webofinfluence/api/admin (renders form)
  - POST /webofinfluence/api/admin/query (SELECT-only; LIMIT enforced)
  - POST /webofinfluence/api/admin/upload (CSV to table; prepared insert)

Field name compatibility
- parties: returns id, name, and party_name (alias of name)
- electorates: returns id, name, and electorate_name (alias of name)
- people: returns id, first_name, last_name

Token Usage and Examples
- Authorization header form:
  - Authorization: Bearer YOUR_TOKEN_HERE
- cURL examples:
  1) Health (no token required unless API_PROTECT_ALL=true):
     curl -i https://ludogogy.co.nz/webofinfluence/api/
  2) Get candidates:
     curl -H "Authorization: Bearer YOUR_TOKEN" \
       https://ludogogy.co.nz/webofinfluence/api/candidates
  3) Admin UI (browser; requires token in form inputs for actions):
     https://ludogogy.co.nz/webofinfluence/api/admin

How the Deployment Works (via cPanel “Deploy HEAD”)
.cpanel.yml performs:
1) Ensure target directory exists: /home/ludog319/public_html/webofinfluence
2) Build frontend (if npm available) with VITE_BASE=/webofinfluence/ and sync to /webofinfluence
3) Sync server files from deploy/ to /webofinfluence
4) Deploy PHP API into /webofinfluence/api and disable Passenger in that subdirectory (via its .htaccess)
5) Vendor Python packages (legacy Flask) into vendor/ and restart Passenger for the root app

After Deployment — Verification
- Check files in /home/ludog319/public_html/webofinfluence:
  - .htaccess, passenger_wsgi.py, requirements.txt, vendor/, app-config.js, index.html
  - api/ (PHP API: index.php, .htaccess, config.php if created)
- PHP API health:
  - https://ludogogy.co.nz/webofinfluence/api/
  - Expected: “API is running!”
- Admin UI:
  - https://ludogogy.co.nz/webofinfluence/api/admin
  - Enter the API token to run queries or upload CSVs.

Schema Setup Steps (Single Database)
1) In cPanel MySQL, select the database (e.g., ludog319_webofinfluence).
2) Import Documentation/sql/woi_schema_singledb.sql.
3) In deploy/php-api/config.php set DB_NAME to that same database (e.g., ludog319_webofinfluence).
4) Optionally load data using your loaders or CSV upload via Admin UI.

Troubleshooting
- PHP 500 errors:
  - Check /home/ludog319/public_html/webofinfluence/api/error_log and Apache logs for details.
  - Ensure deploy/php-api/config.php exists with correct DB credentials and API token.
  - Make sure the database tables exist (import schema).
- 500 errors (legacy Python):
  - cPanel Metrics → Errors; ensure environment variables are set (deploy/.env) and DB credentials are valid.
- Authorization failures:
  - For admin: set API_TOKEN in config.php and use it in the form.
  - For API when API_PROTECT_ALL=true: pass Authorization: Bearer YOUR_TOKEN.
- Route missing/404:
  - /webofinfluence/api/* is handled by PHP (independent from Passenger). Ensure deploy/php-api/.htaccess is present and index.php exists.

Security Notes
- Keep API_TOKEN secret. Do not commit deploy/php-api/config.php to the repository.
- Set API_PROTECT_ALL=true if you want to restrict all routes to authorized users.
- The admin query tool is read-only; the upload tool validates identifiers and uses prepared statements.
