import os
import sys
import mimetypes

# Ensure this directory and vendor deps are on the path
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
if BASE_DIR not in sys.path:
    sys.path.insert(0, BASE_DIR)
VENDOR_DIR = os.path.join(BASE_DIR, "vendor")
if os.path.isdir(VENDOR_DIR) and VENDOR_DIR not in sys.path:
    sys.path.insert(0, VENDOR_DIR)

# Default environment for production - can be overridden in cPanel "Set Environment Variables"
os.environ.setdefault("FLASK_ENV", "production")

# Database configuration (override in cPanel if desired or via .env copied during deploy)
os.environ.setdefault("DB_HOST", "localhost")
os.environ.setdefault("DB_USER", "ludog319_kng")
os.environ.setdefault("DB_PASSWORD", "WFoSE!")
os.environ.setdefault("DB_NAME", "ludog319_webofinfluence")
# Provide defaults so admin works out-of-the-box (can be overridden via .env or cPanel env)
os.environ.setdefault("API_TOKEN", "changeme-strong-secret")
os.environ.setdefault("API_PROTECT_ALL", "0")

# Lazy import of Flask app (avoid breaking static if backend deps missing)
flask_app = None

def _read_file_bytes(path: str) -> bytes:
    with open(path, "rb") as f:
        return f.read()

def _serve_static(environ, start_response):
    """
    Minimal static file server for this directory to avoid relying on rewrite rules.
    If the requested path doesn't exist, serve index.html (SPA-like behavior).
    """
    path = environ.get("PATH_INFO", "/") or "/"
    # Prevent directory traversal
    rel = path.lstrip("/")
    if rel == "" or rel == "/":
        rel = "index.html"
    if ".." in rel:
        rel = "index.html"

    abs_path = os.path.join(BASE_DIR, rel)

    if os.path.isdir(abs_path):
        abs_path = os.path.join(abs_path, "index.html")

    if not os.path.isfile(abs_path):
        # Fallback to root index.html
        abs_path = os.path.join(BASE_DIR, "index.html")

    try:
        data = _read_file_bytes(abs_path)
        ctype = mimetypes.guess_type(abs_path)[0] or "application/octet-stream"
        start_response("200 OK", [("Content-Type", ctype), ("Content-Length", str(len(data)))])
        return [data]
    except Exception as e:
        msg = f"Static file error: {e}".encode("utf-8", errors="replace")
        start_response("500 Internal Server Error", [("Content-Type", "text/plain; charset=utf-8"),
                                                     ("Content-Length", str(len(msg)))])
        return [msg]

def application(environ, start_response):
    """
    Simple router:
    - /api/* and /admin/* -> Flask app
    - everything else -> serve static files from this directory, fallback to index.html
    """
    path = environ.get("PATH_INFO", "/") or "/"
    if path.startswith("/api") or path.startswith("/admin"):
        # Serve static files if they exist under this directory (e.g., /api/index.html)
        rel = path.lstrip("/")
        abs_path = os.path.join(BASE_DIR, rel)
        if os.path.isfile(abs_path) or os.path.isdir(abs_path):
            return _serve_static(environ, start_response)
        # Lazy-load the Flask app on first API/Admin request
        global flask_app
        if flask_app is None:
            try:
                from api.app import app as loaded_app  # noqa: E402
                flask_app = loaded_app
            except Exception as e:
                msg = f"Backend import error: {e}".encode("utf-8", errors="replace")
                start_response("500 Internal Server Error", [("Content-Type", "text/plain; charset=utf-8"),
                                                             ("Content-Length", str(len(msg)))])
                return [msg]
        # If under /api, strip the "/api" prefix so Flask routes like "/" and "/candidates" match.
        if path.startswith("/api"):
            env = environ.copy()
            new_path = path[4:] or "/"
            if not new_path.startswith("/"):
                new_path = "/" + new_path
            env["PATH_INFO"] = new_path
            env["SCRIPT_NAME"] = "/api"
            return flask_app(env, start_response)
        return flask_app(environ, start_response)
    return _serve_static(environ, start_response)
