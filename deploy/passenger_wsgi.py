import os
import sys
from werkzeug.middleware.dispatcher import DispatcherMiddleware

# Ensure this directory and vendor deps are on the path
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)
VENDOR_DIR = os.path.join(BASE_DIR, "vendor")
if os.path.isdir(VENDOR_DIR) and VENDOR_DIR not in sys.path:
    sys.path.insert(0, VENDOR_DIR)

# Default environment for production - can be overridden in cPanel "Set Environment Variables"
os.environ.setdefault("FLASK_ENV", "production")

# Database configuration (override in cPanel if desired)
os.environ.setdefault("DB_HOST", "localhost")
os.environ.setdefault("DB_USER", "ludog319_kng")
os.environ.setdefault("DB_PASSWORD", "WFoSE!")
os.environ.setdefault("DB_NAME", "ludog319_webofinfluence")

# Import the Flask app
from api.app import app as flask_app

def not_found_app(environ, start_response):
    start_response("404 Not Found", [("Content-Type", "text/plain; charset=utf-8")])
    return [b"Not Found"]

# Mount Flask API under /api and admin UI under /admin, leaving everything else to static handling
application = DispatcherMiddleware(not_found_app, {
    "/api": flask_app,
    "/admin": flask_app
})
