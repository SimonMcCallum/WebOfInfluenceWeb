import os
import sys

# Ensure this directory is on the path so we can import app.py
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)

# Default environment for production - can be overridden via .htaccess SetEnv
os.environ.setdefault("FLASK_ENV", "production")
os.environ.setdefault("DB_HOST", "localhost")
os.environ.setdefault("DB_USER", "ludog319_kng")
os.environ.setdefault("DB_PASSWORD", "WFoSE!")
os.environ.setdefault("DB_NAME", "ludog319_webofinfluence")

# Import the Flask app (WSGI callable must be named "application")
from app import app as application
