<?php
declare(strict_types=1);

/**
 * Web Of Influence — PHP API (PDO, no framework)
 * - Targets single MySQL schema: woi
 * - Mirrors endpoints from the previous Flask API
 * - Token-protected Admin actions, optional global protection
 *
 * Deployment:
 * 1) Copy deploy/php-api to your server directory (e.g. /home/USER/public_html/webofinfluence/api)
 * 2) Create deploy/php-api/config.php from config.example.php and set DB creds + API_TOKEN
 * 3) Ensure .htaccess in this directory routes all requests to index.php
 * 4) Import Documentation/sql/woi_schema.sql (creates woi schema and compatibility views)
 */

header('Content-Type: application/json; charset=utf-8');

$CONFIG = [
  'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
  'DB_USER' => getenv('DB_USER') ?: null,
  'DB_PASS' => getenv('DB_PASSWORD') ?: null,
  'DB_NAME' => getenv('DB_NAME') ?: null,
  'API_TOKEN' => getenv('API_TOKEN') ?: null,
  'API_PROTECT_ALL' => (getenv('API_PROTECT_ALL') === '1'),
];

// Load config.php if present (overrides env/defaults)
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
  /** @noinspection PhpIncludeInspection */
  $loaded = include $configFile;
  if (is_array($loaded)) {
    $CONFIG = array_merge($CONFIG, $loaded);
  }
}

if (php_sapi_name() !== 'cli') {
  // Basic CORS (safe defaults; same-origin works without this)
  header('Access-Control-Allow-Origin: *');
  header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}

// Helpers
function json_response($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function fail_config_missing(): void {
  $msg = [
    'error' => 'Configuration missing',
    'detail' => 'Create deploy/php-api/config.php (copy from config.example.php) or set DB_* env vars.',
  ];
  json_response($msg, 500);
}

/** @return PDO */
function pdo() {
  static $pdo = null;
  global $CONFIG;
  if ($pdo !== null) return $pdo;
  $host = $CONFIG['DB_HOST'];
  $db   = $CONFIG['DB_NAME'];
  $user = $CONFIG['DB_USER'];
  $pass = $CONFIG['DB_PASS'];
  if (!$host || !$db || !$user) {
    fail_config_missing();
  }
  $dsn = "mysql:host={$host};dbname={$db};charset=utf8mb4";
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];
  try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    return $pdo;
  } catch (PDOException $e) {
    json_response(['error' => 'DB connection failed', 'detail' => $e->getMessage()], 500);
  }
}

/** Token utilities */
function extract_token(): ?string {
  // Authorization: Bearer <token>
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (stripos($auth, 'Bearer ') === 0) {
    return trim(substr($auth, 7));
  }
  // form/query fallback
  $token = $_POST['token'] ?? ($_GET['token'] ?? null);
  return $token ? trim($token) : null;
}

function require_token_if_protected(): void {
  global $CONFIG, $ROUTE, $METHOD;
  // Always allow health and admin GET page to render login form
  $open = ($ROUTE === '/' && $METHOD === 'GET') || ($ROUTE === '/admin' && $METHOD === 'GET');
  if ($open) return;
  if ($CONFIG['API_PROTECT_ALL']) {
    $token = extract_token();
    if (!$CONFIG['API_TOKEN']) json_response(['error' => 'API_TOKEN not configured on server'], 500);
    if (!$token || $token !== $CONFIG['API_TOKEN']) json_response(['error' => 'Unauthorized'], 401);
  }
}

function require_token_admin(): void {
  global $CONFIG;
  if (!$CONFIG['API_TOKEN']) json_response(['error' => 'API_TOKEN not configured on server'], 500);
  $token = extract_token();
  if (!$token || $token !== $CONFIG['API_TOKEN']) {
    // Render admin HTML with error if accept text/html, else JSON
    if (accepts_html()) {
      render_admin(['error' => 'Unauthorized']);
      exit;
    }
    json_response(['error' => 'Unauthorized'], 401);
  }
}

function accepts_html(): bool {
  $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
  return stripos($accept, 'text/html') !== false;
}

/** Routing */
$METHOD = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uriPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/'); // e.g. /webofinfluence/api
$path = substr($uriPath, strlen($scriptDir));
$path = $path === false ? '/' : $path;
$path = $path === '' ? '/' : $path;

/**
 * Normalize request path to avoid needing .htaccess:
 * - Allow calling /api/index.php or /api/index.php/route
 * - Also support query-style routing: /api/index.php?route=/path
 */
// Normalize /index.php to /, and strip /index.php prefix if present
if ($path === '/index.php') {
  $path = '/';
} elseif (strpos($path, '/index.php') === 0) {
  $path = substr($path, strlen('/index.php'));
  if ($path === '' || $path === false) $path = '/';
}

if ($path === '/' && isset($_GET['route'])) {
  $gp = $_GET['route'];
  if (!is_string($gp)) $gp = '';
  if ($gp === '' || $gp[0] !== '/') $gp = '/' . $gp;
  $path = $gp;
}

$ROUTE = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');

// Protect if needed
require_token_if_protected();

/** Utilities for Admin CSV upload */
function is_safe_identifier(string $name): bool {
  return (bool)preg_match('/^[A-Za-z0-9_]+$/', $name);
}

/**
 * Accepts either "TableName" or "Schema_Table" and converts first underscore to dot:
 *   Entities_People -> Entities.People
 * If no underscore, returns name as-is.
 */
function normalize_schema_table(string $raw): ?string {
  if (!is_safe_identifier($raw)) return null;
  if (strpos($raw, '_') !== false) {
    [$schema, $table] = explode('_', $raw, 2);
    if (!is_safe_identifier($schema) || !is_safe_identifier($table)) return null;
    return $schema . '.' . $table;
  }
  return $raw;
}

/** Backtick-quote a possibly qualified identifier schema.table */
function backtick_qualified_table(string $qname): string {
  $parts = explode('.', $qname, 2);
  if (count($parts) === 2) {
    return '`' . $parts[0] . '`.`' . $parts[1] . '`';
  }
  return '`' . $qname . '`';
}

function ensure_select_query_is_safe(string $q): array {
  if (strpos($q, ';') !== false) return [false, 'Semicolons are not allowed.'];
  if (!preg_match('/^\s*SELECT\b/i', $q)) return [false, 'Only SELECT queries are permitted.'];
  return [true, ''];
}

function maybe_append_limit(string $q, int $default = 200): string {
  return preg_match('/\blimit\b/i', $q) ? $q : ($q . ' LIMIT ' . $default);
}

/** Rendering for Admin HTML */
function render_admin(array $ctx = []): void {
  header('Content-Type: text/html; charset=utf-8');
  $error = $ctx['error'] ?? '';
  $query_result = $ctx['query_result'] ?? [];
  $columns = $ctx['columns'] ?? [];
  $rows_count = $ctx['rows_count'] ?? 0;
  $upload_result = $ctx['upload_result'] ?? false;
  $inserted = (int)($ctx['inserted'] ?? 0);
  $table_shown = htmlspecialchars((string)($ctx['table_shown'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

  ?><!doctype html>
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
    <p class="note">All admin actions require a valid API token. Configure API_TOKEN in config.php or environment.</p>

    <div class="row">
      <div>
        <h2>Read-only Query</h2>
        <form action="index.php?route=/admin/query" method="post">
          <label>API Token
            <input type="password" name="token" placeholder="Enter API token" required>
          </label>
          <label>SELECT Query (LIMIT enforced if missing)
            <textarea name="query" rows="5" placeholder="SELECT * FROM woi.people LIMIT 50" required></textarea>
          </label>
          <button type="submit">Run Query</button>
        </form>
        <?php if (!empty($query_result) || !empty($error)) : ?>
          <?php if (!empty($error)) : ?>
            <div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php else: ?>
            <div class="ok">Returned <?= (int)$rows_count ?> rows.</div>
            <table>
              <thead>
                <tr>
                  <?php foreach ($columns as $col): ?><th><?= htmlspecialchars((string)$col, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th><?php endforeach; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($query_result as $row): ?>
                <tr>
                  <?php foreach ($columns as $col): $val = $row[$col] ?? null; ?>
                    <td><?= htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        <?php endif; ?>
      </div>

      <div>
        <h2>CSV Upload → Table</h2>
        <form action="index.php?route=/admin/upload" method="post" enctype="multipart/form-data">
          <label>API Token
            <input type="password" name="token" placeholder="Enter API token" required>
          </label>
          <label>Target Table (letters, digits, underscore only)
            <input type="text" name="table" placeholder="e.g., woi_people or woi_People" required>
          </label>
          <p class="note">Use schema_table form (e.g., woi_people) if table is namespaced; underscore converts to dot (woi.people).</p>
          <label>CSV File (header row must match table column names)
            <input type="file" name="file" accept=".csv" required>
          </label>
          <button type="submit">Upload & Insert</button>
        </form>
        <?php if ($upload_result): ?>
          <?php if (!empty($error)) : ?>
            <div class="err"><?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php else: ?>
            <div class="ok">Inserted <?= (int)$inserted ?> rows into <?= $table_shown ?>.</div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <p class="note">Security: Query tool only permits SELECT and blocks semicolons. Upload validates identifiers and uses prepared statements.</p>
  </body>
  </html>
  <?php
}

/** Route handlers */
function handle_health(): void {
  header('Content-Type: text/plain; charset=utf-8');
  echo 'API is running!';
  exit;
}

function handle_get_candidates(): void {
  $stmt = pdo()->query('SELECT id, first_name, last_name FROM people');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function handle_get_parties(): void {
  // Include both "name" and legacy alias "party_name"
  $stmt = pdo()->query('SELECT id, name, name AS party_name FROM parties');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function handle_get_electorates(): void {
  // Include both "name" and legacy alias "electorate_name"
  $stmt = pdo()->query('SELECT id, name, name AS electorate_name FROM electorates');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function handle_candidate_by_id(): void {
  $people_id = $_GET['people_id'] ?? null;
  if (!$people_id) json_response(['error' => 'people_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, first_name, last_name FROM people WHERE id = ?');
  $stmt->execute([$people_id]);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function handle_party_by_id(): void {
  $party_id = $_GET['party_id'] ?? null;
  if (!$party_id) json_response(['error' => 'party_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, name, name AS party_name FROM parties WHERE id = ?');
  $stmt->execute([$party_id]);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function handle_electorate_by_id(): void {
  $electorate_id = $_GET['electorate_id'] ?? null;
  if (!$electorate_id) json_response(['error' => 'electorate_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, name, name AS electorate_name FROM electorates WHERE id = ?');
  $stmt->execute([$electorate_id]);
  $row = $stmt->fetch();
  if (!$row) json_response(['error' => 'not found'], 404);
  json_response($row);
}

function handle_search_candidates(): void {
  $first = $_GET['first_name'] ?? null;
  $last = $_GET['last_name'] ?? null;
  if (!$first && !$last) json_response(['error' => 'At least one parameter (first_name or last_name) is required'], 400);

  $conds = [];
  $params = [];
  if ($first) { $conds[] = 'UPPER(first_name) = UPPER(?)'; $params[] = $first; }
  if ($last) { $conds[] = 'UPPER(last_name)  = UPPER(?)';  $params[] = $last; }
  $sql = 'SELECT id, first_name, last_name FROM people WHERE ' . implode(' AND ', $conds);
  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'not found'], 404);
  json_response($rows);
}

function is_valid_year(string $year): bool {
  return (bool)preg_match('/^(2011|2014|2017|2020|2023)$/', $year);
}

function handle_candidates_combined_search(string $year): void {
  if (!is_valid_year($year)) json_response(['error' => 'Invalid year'], 400);

  $first = $_GET['first_name'] ?? null;
  $last = $_GET['last_name'] ?? null;
  $party = $_GET['party_name'] ?? null;
  $elect = $_GET['electorate_name'] ?? null;

  $sql = "SELECT co.id, co.total_donations, co.total_expenses, co.people_id, co.party_id, co.electorate_id,
                 co.part_a, co.part_b, co.part_c, co.part_d, co.part_f, co.part_g, co.part_h, co.year, co.original_id
          FROM candidate_overview co
          WHERE co.year = ?";
  $params = [$year];

  if ($first || $last) {
    $subConds = [];
    $subParams = [];
    if ($first) { $subConds[] = 'UPPER(first_name) = UPPER(?)'; $subParams[] = $first; }
    if ($last)  { $subConds[] = 'UPPER(last_name)  = UPPER(?)'; $subParams[] = $last; }
    $sql .= ' AND co.people_id IN (SELECT id FROM people WHERE ' . implode(' AND ', $subConds) . ')';
    $params = array_merge($params, $subParams);
  }

  if ($party) {
    $sql .= ' AND co.party_id IN (SELECT id FROM parties WHERE name = ?)';
    $params[] = $party;
  }

  if ($elect) {
    $sql .= ' AND co.electorate_id IN (SELECT id FROM electorates WHERE name = ?)';
    $params[] = $elect;
  }

  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'No results found'], 404);
  json_response($rows);
}

function handle_ministerial_diaries_search(): void {
  $first = $_GET['first_name'] ?? null;
  $last  = $_GET['last_name'] ?? null;
  $start = $_GET['start_date'] ?? null;
  $end   = $_GET['end_date'] ?? null;
  $portfolio = $_GET['portfolio'] ?? null;

  if (!$first || !$last) json_response(['error' => 'Both first name and last name are required'], 400);

  // Resolve person
  $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?)');
  $stmt->execute([$first, $last]);
  $person = $stmt->fetch();
  if (!$person) json_response(['error' => 'Candidate not found'], 404);
  $people_id = (int)$person['id'];

  $sql = 'SELECT id, date, start_time, end_time, location, notes, type, portfolio, title, minister_person_id, with_text
          FROM meetings WHERE minister_person_id = ?';
  $params = [$people_id];

  if ($start && $end) {
    $sql .= ' AND (date BETWEEN ? AND ?)';
    $params[] = $start; $params[] = $end;
  } elseif ($start) {
    $sql .= ' AND date >= ?';
    $params[] = $start;
  } elseif ($end) {
    $sql .= ' AND date <= ?';
    $params[] = $end;
  }

  if ($portfolio) {
    $sql .= ' AND portfolio LIKE ?';
    $params[] = '%' . $portfolio . '%';
  }

  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response(['error' => 'No meetings found'], 404);

  // Times are strings under PDO; return as-is
  json_response($rows);
}

/** Admin: GET /admin */
function handle_admin_get(): void {
  render_admin();
}

/** Admin: POST /admin/query (SELECT only) */
function handle_admin_query(): void {
  require_token_admin();
  $q = $_POST['query'] ?? '';
  [$ok, $msg] = ensure_select_query_is_safe($q);
  if (!$ok) {
    render_admin(['error' => $msg, 'query_result' => [], 'columns' => [], 'rows_count' => 0]);
    return;
  }
  $qExec = maybe_append_limit($q);
  try {
    $stmt = pdo()->query($qExec);
    $rows = $stmt->fetchAll();
    $cols = $rows ? array_keys($rows[0]) : [];
    render_admin([
      'query_result' => $rows,
      'columns' => $cols,
      'rows_count' => count($rows),
    ]);
  } catch (Throwable $e) {
    render_admin(['error' => $e->getMessage(), 'query_result' => [], 'columns' => [], 'rows_count' => 0]);
  }
}

/** Admin: POST /admin/upload (CSV → table) */
function handle_admin_upload(): void {
  require_token_admin();

  $rawTable = $_POST['table'] ?? '';
  $table = normalize_schema_table($rawTable);
  if (!$table) {
    render_admin(['error' => 'Invalid table name', 'upload_result' => true, 'inserted' => 0, 'table_shown' => $rawTable]);
    return;
  }

  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    render_admin(['error' => 'CSV file is required', 'upload_result' => true, 'inserted' => 0, 'table_shown' => $table]);
    return;
  }

  $csv = file_get_contents($_FILES['file']['tmp_name']);
  if ($csv === false) {
    render_admin(['error' => 'Failed to read uploaded file', 'upload_result' => true, 'inserted' => 0, 'table_shown' => $table]);
    return;
  }

  $fp = fopen('php://memory', 'r+');
  fwrite($fp, $csv);
  rewind($fp);
  $header = fgetcsv($fp);
  if (!$header) {
    render_admin(['error' => 'CSV must include a header row', 'upload_result' => true, 'inserted' => 0, 'table_shown' => $table]);
    return;
  }

  $columns = array_map('trim', $header);
  foreach ($columns as $col) {
    if (!is_safe_identifier($col)) {
      render_admin(['error' => "Invalid column name: {$col}", 'upload_result' => true, 'inserted' => 0, 'table_shown' => $table]);
      return;
    }
  }

  $bt = backtick_qualified_table($table);
  $placeholders = implode(', ', array_fill(0, count($columns), '?'));
  $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $columns));
  $sql = "INSERT INTO {$bt} ({$colList}) VALUES ({$placeholders})";
  $stmt = pdo()->prepare($sql);

  $inserted = 0;
  while (($row = fgetcsv($fp)) !== false) {
    // Map row values to columns; treat empty string as NULL
    $vals = [];
    foreach ($columns as $i => $c) {
      $val = $row[$i] ?? null;
      $vals[] = ($val === '') ? null : $val;
    }
    try {
      $stmt->execute($vals);
      $inserted++;
    } catch (Throwable $e) {
      render_admin(['error' => 'Insert failed at row ' . ($inserted + 1) . ': ' . $e->getMessage(), 'upload_result' => true, 'inserted' => $inserted, 'table_shown' => $table]);
      return;
    }
  }
  fclose($fp);

  render_admin(['upload_result' => true, 'inserted' => $inserted, 'table_shown' => $table]);
}

/** Dispatch */
try {
  if ($METHOD === 'GET' && $ROUTE === '/') handle_health();

  if ($METHOD === 'GET' && $ROUTE === '/candidates') handle_get_candidates();
  if ($METHOD === 'GET' && $ROUTE === '/party') handle_get_parties();
  if ($METHOD === 'GET' && $ROUTE === '/electorate') handle_get_electorates();

  if ($METHOD === 'GET' && $ROUTE === '/candidates/search-id') handle_candidate_by_id();
  if ($METHOD === 'GET' && $ROUTE === '/party/search-id') handle_party_by_id();
  if ($METHOD === 'GET' && $ROUTE === '/electorate/search-id') handle_electorate_by_id();

  if ($METHOD === 'GET' && $ROUTE === '/candidates/search') handle_search_candidates();

  if ($METHOD === 'GET' && preg_match('#^/candidates/election-overview/(\d{4})/search/combined$#', $ROUTE, $m)) {
    handle_candidates_combined_search($m[1]);
  }

  if ($METHOD === 'GET' && $ROUTE === '/ministerial_diaries/search-cand-filter') handle_ministerial_diaries_search();

  // Admin
  if ($METHOD === 'GET' && $ROUTE === '/admin') handle_admin_get();
  if ($METHOD === 'POST' && $ROUTE === '/admin/query') handle_admin_query();
  if ($METHOD === 'POST' && $ROUTE === '/admin/upload') handle_admin_upload();

  // Not found
  json_response(['error' => 'Not Found', 'route' => $ROUTE], 404);
} catch (Throwable $e) {
  json_response(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
