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

# The app is deployed under https://www.ludogogy.co.nz/webofinfluence/
# Normalize incoming PATH_INFO by stripping this prefix so routing/static works.
APP_BASE_PREFIX = os.environ.get("APP_BASE_PREFIX", "/webofinfluence").rstrip("/")

# Serve built static assets from dist/ if it exists; otherwise fallback to repo root
DIST_DIR = os.path.join(BASE_DIR, "dist")
STATIC_ROOT = DIST_DIR if os.path.isdir(DIST_DIR) else BASE_DIR

# Lazy import of Flask app (avoid breaking static if backend deps missing)
flask_app = None


def _read_file_bytes(path: str) -> bytes:
    with open(path, "rb") as f:
        return f.read()


def _normalize_path(path: str) -> str:
    """
    Ensure leading slash and strip APP_BASE_PREFIX (e.g., '/webofinfluence')
    so that '/webofinfluence/assets/...' resolves to '/assets/...'.
    """
    if not path:
        path = "/"
    if not path.startswith("/"):
        path = "/" + path
    if APP_BASE_PREFIX and APP_BASE_PREFIX != "/" and (
        path == APP_BASE_PREFIX or path.startswith(APP_BASE_PREFIX + "/")
    ):
        stripped = path[len(APP_BASE_PREFIX):]
        path = stripped if stripped else "/"
    return path


def _serve_static(environ, start_response):
    """
    Minimal static file server for STATIC_ROOT (dist/ in production).
    If the requested path doesn't exist, serve index.html (SPA behavior).
    """
    path = _normalize_path(environ.get("PATH_INFO", "/"))
    # Prevent directory traversal
    rel = path.lstrip("/")
    if rel == "" or rel == "/":
        rel = "index.html"
    if ".." in rel:
        rel = "index.html"

    abs_path = os.path.join(STATIC_ROOT, rel)

    if os.path.isdir(abs_path):
        abs_path = os.path.join(abs_path, "index.html")

    if not os.path.isfile(abs_path):
        # Fallback to SPA entry
        abs_path = os.path.join(STATIC_ROOT, "index.html")

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
    - /api/* and /admin/* -> Flask app (after stripping base prefix)
    - everything else -> serve static files from STATIC_ROOT (dist/), fallback to index.html
    """
    raw_path = environ.get("PATH_INFO", "/") or "/"
    path = _normalize_path(raw_path)

    if path.startswith("/api") or path.startswith("/admin"):
        # Serve static files if they exist under this repo for requested path (e.g., /api/docs)
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
            # Preserve SCRIPT_NAME including base prefix so url_for builds correct absolute URLs
            prefix = APP_BASE_PREFIX if APP_BASE_PREFIX else ""
            env["SCRIPT_NAME"] = f"{prefix}/api" if prefix else "/api"
            return flask_app(env, start_response)
        return flask_app(environ, start_response)

    return _serve_static(environ, start_response)
