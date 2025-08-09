Web Of Influence — API-Only cPanel Deployment (with Admin UI and Token Auth)

Overview
- What’s deployed:
  - Flask API mounted at /api
  - Admin web UI mounted at /admin (read-only SQL query + CSV upload)
  - Static files (index.html placeholder + app-config.js) at /webofinfluence
- Hosting: cPanel (Phusion Passenger for Python WSGI)
- Deployed URL base: https://ludogology.co.nz/webofinfluence

Key Files
- .cpanel.yml — automated deployment tasks for cPanel “Deploy HEAD”
- deploy/.htaccess — Passenger + rewrite rules (routes /api and /admin to backend)
- deploy/passenger_wsgi.py — WSGI entry; adds vendor/ to sys.path; mounts /api and /admin
- deploy/api/app.py — Flask API with:
  - Public data endpoints (candidates, party, electorate)
  - Admin UI at /admin with:
    - Token-protected read-only SQL query tool (SELECT only; LIMIT enforced)
    - Token-protected CSV upload into a given table using prepared statements
  - Token enforcement via API_TOKEN and API_PROTECT_ALL
- deploy/requirements.txt — Python dependencies (versions compatible with server)
- deploy/index.html — Placeholder page (frontend SPA is intentionally not built/served)
- deploy/.env.example — Template for environment variables
- demo-cand/* — React SPA (not built or deployed in API-only mode)

Database (Simon deploy)
- DB name: ludog319_webofinfluence
- DB user: ludog319_kng
- DB password: WFoSE!
- Host: localhost
- Ensure MySQL user privileges are correctly set for the database.

Environment Variables (configure via deploy/.env)
Copy deploy/.env.example to deploy/.env and set:
- FLASK_ENV=production
- DB_HOST=localhost
- DB_USER=ludog319_kng
- DB_PASSWORD=WFoSE!
- DB_NAME=ludog319_webofinfluence
- API_TOKEN=changeme-strong-secret
  - A strong shared secret used to authorize admin actions (and optionally all API routes).
  - Clients pass it via Authorization: Bearer <token> header, or in form fields for admin UI.
- API_PROTECT_ALL=0
  - When set to 1: ALL endpoints require the token (except GET / health check).
  - When 0: only the /admin endpoints require the token.

Token Usage and Examples
- Authorization header form:
  - Authorization: Bearer YOUR_TOKEN_HERE

- cURL examples:
  1) Health (no token required):
     curl -i https://ludogology.co.nz/webofinfluence/api/
  2) Get candidates (requires token only if API_PROTECT_ALL=1):
     curl -H "Authorization: Bearer YOUR_TOKEN" \
       https://ludogology.co.nz/webofinfluence/api/candidates
  3) Admin UI (browser; requires token in form inputs for actions):
     https://ludogology.co.nz/webofinfluence/admin

Admin UI Details (/admin)
- Read-only Query:
  - Accepts only SELECT statements.
  - Disallows semicolons to prevent chaining.
  - Automatically adds LIMIT 200 if not present.
  - Requires API token in the form.
- CSV Upload:
  - Requires API token in the form.
  - Field “Target Table”:
    - Accepts “Schema_Table” form (e.g., Entities_People) and maps to “Entities.People”.
    - The server validates identifiers.
  - CSV must include a header row with column names matching the target table.
  - Uses prepared statements and bulk insert.

How the Deployment Works (via cPanel “Deploy HEAD”)
.cpanel.yml performs:
1) Ensure target directory exists: /home/ludog319/public_html/webofinfluence
2) Sync server files:
   - rsync deploy/ → /home/ludog319/public_html/webofinfluence
3) Vendor Python packages (no virtualenv):
   - pip install -r /home/ludog319/public_html/webofinfluence/requirements.txt \
     --target /home/ludog319/public_html/webofinfluence/vendor
4) Restart Passenger:
   - touch /home/ludog319/public_html/webofinfluence/tmp/restart.txt

Notes:
- Node/npm are not required now; the frontend build is skipped intentionally.
- If deploy/.env exists in the repo, .cpanel.yml will copy it to the deployed directory.

After Deployment — Verification
- Check files in /home/ludog319/public_html/webofinfluence:
  - .htaccess, passenger_wsgi.py, requirements.txt, api/, vendor/, app-config.js, index.html
- API health:
  - https://ludogology.co.nz/webofinfluence/api/
  - Expected: “API is running!”
- Admin UI:
  - https://ludogology.co.nz/webofinfluence/admin
  - Enter the API token to run queries or upload CSVs.

Dependencies (Pinned for server compatibility)
deploy/requirements.txt:
- Flask==2.0.3
- flask-cors==3.0.10
- PyMySQL==1.0.2
- python-dotenv==0.21.0

Troubleshooting
- Pip install failure:
  - The pipeline vendors dependencies into vendor/. If server pip cannot fetch, consider manually vendoring libs locally and committing vendor/ (no-network deploy).
- 500 errors:
  - Check cPanel Metrics → Errors.
  - Ensure environment variables are set (deploy/.env) and DB credentials are valid.
- Authorization failures:
  - Make sure API_TOKEN is set in deploy/.env and the Authorization header is present:
    Authorization: Bearer YOUR_TOKEN
  - For admin forms, the token is entered in the “API Token” field.
- Route missing/404:
  - Ensure .htaccess rewrite rules are deployed; they pass /api and /admin to Passenger.

Security Notes
- Keep API_TOKEN secret. Do not commit deploy/.env to the repository.
- Set API_PROTECT_ALL=1 if you want to restrict all routes to authorized users.
- The admin query tool is read-only; the upload tool validates identifiers and uses prepared statements.
