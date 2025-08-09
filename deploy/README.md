Web Of Influence — cPanel Deployment Guide

Overview
- Frontend: Vite + React app in demo-cand
- Backend API: Flask app in deploy/api/app.py (mounted at /api by Passenger)
- Hosting: cPanel (Phusion Passenger for Python WSGI)
- Deployed URL: https://ludogology.co.nz/webofinfluence

Key Files
- .cpanel.yml — automated deployment tasks for cPanel “Deploy HEAD”
- deploy/.htaccess — Passenger + SPA rewrite rules
- deploy/passenger_wsgi.py — WSGI entry that mounts the API at /api
- deploy/api/app.py — Flask API (uses MySQL)
- deploy/requirements.txt — Python dependencies installed on deploy
- demo-cand/vite.config.js — honors VITE_BASE env for subpath builds
- demo-cand/public/app-config.js — runtime frontend config (API_BASE)
- demo-cand/src/apiConfig.js — reads window.__APP_CONFIG__.API_BASE if present

Database (simon deploy)
- DB name: ludog319_webofinfluence
- DB user: ludog319_kng
- DB password: WFoSE!
- Host: localhost (default; changeable via env)

How Deployment Works (via cPanel “Deploy HEAD”)
.cpanel.yml performs these steps:
1) Build frontend with correct base path
   - cd demo-cand && npm ci (or npm install) && VITE_BASE=/webofinfluence/ npm run build
2) Sync server and config
   - rsync deploy/ → /home/ludog319/public_html/webofinfluence
3) Sync built frontend
   - rsync demo-cand/dist/ → /home/ludog319/public_html/webofinfluence
   - copy runtime app-config.js into the deployed folder
4) Python setup and restart Passenger
   - create venv, pip install -r requirements.txt, touch tmp/restart.txt

After success, the site is available at:
- Frontend: https://ludogology.co.nz/webofinfluence
- API health: https://ludogology.co.nz/webofinfluence/api/

Runtime Configuration (no rebuilds needed)
A. Frontend API base URL
- Default: computed to /webofinfluence/api by demo-cand/public/app-config.js
- Override at deploy time by providing deploy/app-config.override.js (copied to web root as app-config.js):
  Example deploy/app-config.override.js:
    window.__APP_CONFIG__ = {
      API_BASE: '/webofinfluence/api'
      // Or: 'https://some-other-host/api'
    };

B. Backend (Flask) environment variables
- The WSGI entry sets defaults in deploy/api/passenger_wsgi.py or deploy/passenger_wsgi.py:
  - FLASK_ENV = production
  - DB_HOST = localhost
  - DB_USER = ludog319_kng
  - DB_PASSWORD = WFoSE!
  - DB_NAME = ludog319_webofinfluence
- To change these on the server, either:
  1) Edit the passenger_wsgi.py file in the repo with your desired defaults and redeploy, or
  2) Use cPanel’s Application/Passenger environment variable UI (if available), or
  3) Add SetEnv directives to .htaccess (advanced; requires appropriate Apache configs)

Notes for Subpath Hosting (/webofinfluence)
- Vite base is set at build-time via VITE_BASE=/webofinfluence/ to produce correct asset URLs.
- Router basename is derived from import.meta.env.BASE_URL in demo-cand/src/main.jsx.
- SPA rewrites in deploy/.htaccess ensure deep-links work:
  - Requests to /webofinfluence/api/... go to the Flask API
  - Other paths serve index.html unless an actual file/dir exists

MySQL Permissions
Ensure user ludog319_kng has privileges on database ludog319_webofinfluence.

Manual Verification Checklist
- Frontend loads at https://ludogology.co.nz/webofinfluence without 404s on assets.
- GET https://ludogology.co.nz/webofinfluence/api/ returns “API is running!”.
- Example API calls:
  - /webofinfluence/api/candidates
  - /webofinfluence/api/party
  - /webofinfluence/api/electorate
  - etc. (same routes as in deploy/api/app.py)
- Frontend fetches use window.__APP_CONFIG__.API_BASE, so they should target /webofinfluence/api.

Troubleshooting
- 500 or DB errors: verify DB credentials and host in passenger_wsgi.py or server env vars.
- Missing Python packages: .cpanel.yml installs from deploy/requirements.txt on each deploy.
- Asset 404s: rebuild with correct VITE_BASE (/webofinfluence/), ensure dist/ was synced.
- Logs: cPanel Errors and Passenger app logs (depending on host configuration).
