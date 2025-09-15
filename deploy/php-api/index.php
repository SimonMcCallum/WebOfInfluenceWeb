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
  'DB_USER' => getenv('DB_USER') ?: 'ludog319_kng',
  'DB_PASS' => getenv('DB_PASSWORD') ?: 'WFoSE!',
  'DB_NAME' => getenv('DB_NAME') ?: 'ludog319_webofinfluence',
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
  // Open access: no token enforcement
  return;
}

function require_token_admin(): void {
  // Open access: no token required
  return;
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

// Check for query-style routing first, before path processing
if (isset($_GET['route'])) {
  $gp = $_GET['route'];
  if (!is_string($gp)) $gp = '';
  if ($gp === '' || $gp[0] !== '/') $gp = '/' . $gp;
  $path = $gp;
}

$ROUTE = rtrim($path, '/') === '' ? '/' : rtrim($path, '/');
// Fallback: accept /index.php and /index.php/* by rewriting to canonical routes
if ($ROUTE === '/index.php') {
  $ROUTE = '/';
} elseif (strpos($ROUTE, '/index.php/') === 0) {
  $ROUTE = substr($ROUTE, strlen('/index.php'));
  if ($ROUTE === '' || $ROUTE === false) $ROUTE = '/';
}

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

/** Temporary directory for admin uploads/mapping (../tmp relative to /api) */
function tmp_dir(): string {
  $candidate = __DIR__ . '/../tmp';
  if (!is_dir($candidate)) {
    @mkdir($candidate, 0775, true);
  }
  $dir = realpath($candidate);
  if ($dir === false) {
    // Fallback to system temp directory
    $systemTmp = sys_get_temp_dir();
    $woiTmp = $systemTmp . '/woi_uploads';
    if (!is_dir($woiTmp)) {
      @mkdir($woiTmp, 0775, true);
    }
    return $woiTmp;
  }
  return $dir;
}

/** Data directory for server-side CSVs (../data relative to /api) */
function data_dir(): string {
  // /home/USER/public_html/webofinfluence/api -> ../data
  $dir = realpath(__DIR__ . '/../data');
  if ($dir === false) {
    // Fallback to a safe non-existing path; UI will show none found
    return __DIR__ . '/../data';
  }
  return $dir;
}

/** List tables in the current DB with row counts */
function list_tables_with_counts(): array {
  $tables = [];
  $dbName = null;
  $stmt = pdo()->query('SELECT DATABASE() AS db');
  $row = $stmt->fetch();
  if ($row && isset($row['db'])) $dbName = $row['db'];
  if (!$dbName) return $tables;

  $q = "SELECT TABLE_NAME FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME";
  $stmt = pdo()->prepare($q);
  $stmt->execute([$dbName]);
  $names = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
  foreach ($names as $t) {
    if (!$t) continue;
    try {
      $c = pdo()->query("SELECT COUNT(*) AS c FROM `" . str_replace('`', '``', $t) . "`")->fetch();
      $tables[] = ['name' => $t, 'rows' => (int)($c['c'] ?? 0)];
    } catch (Throwable $e) {
      $tables[] = ['name' => $t, 'rows' => 0];
    }
  }
  return $tables;
}

/** Recursively find CSV files under data_dir, return relative paths */
function find_server_csvs(): array {
  $base = data_dir();
  $files = [];
  if (!is_dir($base)) return $files;

  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );
  foreach ($it as $item) {
    if ($item->isFile()) {
      $name = $item->getFilename();
      if (preg_match('/\.csv$/i', $name)) {
        $abs = $item->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($base))), '/');
        $files[] = $rel;
      }
    }
  }
  sort($files);
  return $files;
}

/** Identifier sanitization */
function sanitize_table_name(string $raw): string {
  $s = preg_replace('/[^A-Za-z0-9_]/', '_', $raw);
  $s = preg_replace('/_+/', '_', $s);
  $s = trim($s, '_');
  if ($s === '') $s = 'imported';
  return $s;
}

/** Derive a table name from CSV relative path */
function table_name_from_csv_rel(string $rel): string {
  // Use path with slashes converted to underscores (without extension)
  $rel = preg_replace('/\.csv$/i', '', $rel);
  $rel = str_replace('/', '_', $rel);
  return sanitize_table_name($rel);
}

/** Ensure table exists with given columns (TEXT columns); if exists, only insert matching cols */
function ensure_table_exists_with_columns(string $table, array $columns): void {
  // Create if not exists
  $colsDef = [];
  foreach ($columns as $c) {
    $safe = sanitize_table_name($c);
    $colsDef[] = "`$safe` TEXT NULL";
  }
  $sql = "CREATE TABLE IF NOT EXISTS `" . str_replace('`', '``', $table) . "` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    " . implode(",\n    ", $colsDef) . "
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
  pdo()->exec($sql);
}

/** Return existing column names for a table */
function existing_columns(string $table): array {
  try {
    $stmt = pdo()->query("SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "`");
    $cols = [];
    foreach ($stmt->fetchAll() as $r) {
      $cols[] = $r['Field'];
    }
    return $cols;
  } catch (Throwable $e) {
    return [];
  }
}

/** Import a CSV file on server into a table. Returns ['inserted' => n, 'table' => t, 'error' => ''] */
function import_server_csv_to_table(string $relPath, ?string $targetTable, bool $truncateFirst): array {
  $base = data_dir();
  $abs = realpath($base . '/' . $relPath);
  if ($abs === false || strpos($abs, $base) !== 0 || !is_file($abs)) {
    return ['inserted' => 0, 'table' => (string)$targetTable, 'error' => 'CSV not found or invalid path'];
  }

  // Open and read header
  $fh = fopen($abs, 'r');
  if ($fh === false) return ['inserted' => 0, 'table' => (string)$targetTable, 'error' => 'Failed to open CSV'];
  $header = fgetcsv($fh);
  if (!$header) {
    fclose($fh);
    return ['inserted' => 0, 'table' => (string)$targetTable, 'error' => 'CSV must include a header row'];
  }
  $columns = array_map(fn($c) => sanitize_table_name((string)$c), $header);

  // Determine table name
  $table = $targetTable ?: table_name_from_csv_rel($relPath);

  // Ensure table exists
  ensure_table_exists_with_columns($table, $columns);

  // Determine insertable columns = intersection of header columns and existing table columns (excluding id)
  $existing = array_filter(existing_columns($table), fn($c) => strtolower($c) !== 'id');
  $insertCols = array_values(array_intersect($existing, $columns));
  if (empty($insertCols)) {
    fclose($fh);
    return ['inserted' => 0, 'table' => $table, 'error' => 'No matching columns to insert'];
  }

  if ($truncateFirst) {
    pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
  }

  $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
  $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $insertCols));
  $sql = "INSERT IGNORE INTO `" . str_replace('`', '``', $table) . "` ($colList) VALUES ($placeholders)";
  $stmt = pdo()->prepare($sql);

  $inserted = 0;
  // Map header name -> index
  $index = [];
  foreach ($columns as $i => $name) $index[$name] = $i;

  while (($row = fgetcsv($fh)) !== false) {
    $vals = [];
    foreach ($insertCols as $c) {
      $i = $index[$c] ?? null;
      $val = ($i !== null && array_key_exists($i, $row)) ? $row[$i] : null;
      $vals[] = ($val === '') ? null : $val;
    }
    try {
      $stmt->execute($vals);
      $inserted++;
    } catch (Throwable $e) {
      fclose($fh);
      return ['inserted' => $inserted, 'table' => $table, 'error' => 'Insert failed: ' . $e->getMessage()];
    }
  }
  fclose($fh);

  return ['inserted' => $inserted, 'table' => $table, 'error' => ''];
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
    <p class="note">Admin is currently OPEN (no authentication). Use with caution. We will add Firebase auth later.</p>

    <div class="row">
      <div>
        <h2>Read-only Query</h2>
        <form action="index.php?route=/admin/query" method="post">
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
        <form action="index.php?route=/admin/upload-start" method="post" enctype="multipart/form-data">
          <label>Destination Table (pick existing)
            <select name="dest_table_select">
              <option value="">-- choose an existing table --</option>
              <?php foreach (($ctx['tables'] ?? []) as $t): ?>
                <option value="<?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= (int)($t['rows'] ?? 0) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Or custom table name (letters, digits, underscore only)
            <input type="text" name="table" placeholder="e.g., people_import_2023">
          </label>
          <p class="note">If both are provided, the custom name takes precedence. Use schema_table form (e.g., woi_people) if namespaced; underscore converts to dot.</p>
          <label>CSV File (header row recommended for mapping)
            <input type="file" name="file" accept=".csv" required>
          </label>
          <button type="submit">Continue to Mapping</button>
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

    <?php if (!empty($ctx['upload_mapping'])): ?>
    <section class="panel">
      <h2>CSV Column Mapping</h2>
      <p class="note">CSV: <?= htmlspecialchars((string)($ctx['tmp_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> → Table: <?= htmlspecialchars((string)($ctx['map_table'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <form action="index.php?route=/admin/upload-commit" method="post">
        <input type="hidden" name="tmp_file" value="<?= htmlspecialchars((string)($ctx['tmp_file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="table" value="<?= htmlspecialchars((string)($ctx['map_table'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="row">
          <div class="col-12">
            <table>
              <thead><tr><th>CSV Column</th><th>Map to DB Column</th></tr></thead>
              <tbody>
              <?php foreach (($ctx['csv_columns'] ?? []) as $c): ?>
                <tr>
                  <td><?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td>
                    <select name="map[<?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>]">
                      <option value="">Ignore</option>
                      <?php foreach (($ctx['db_columns'] ?? []) as $dbc): ?>
                        <option value="<?= htmlspecialchars($dbc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($dbc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                      <option value="__CREATE__:<?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Create column '<?= htmlspecialchars($c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>' (TEXT)</option>
                    </select>
                  </td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="col-3">
            <label><input type="checkbox" name="truncate" value="1"> Truncate table before insert</label>
          </div>
          <div class="col-3">
            <button type="submit" class="btn primary">Import with Mapping</button>
          </div>
        </div>
      </form>
    </section>
    <?php endif; ?>

    <div class="row">
      <div>
        <h2>Database Tables</h2>
        <p class="note">Current DB tables and row counts. Truncate removes all rows but keeps the table.</p>
        <table>
          <thead>
            <tr><th>Table</th><th>Rows</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach (($ctx['tables'] ?? []) as $t): ?>
              <tr>
                <td><?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= (int)($t['rows'] ?? 0) ?></td>
                <td>
                  <form action="index.php?route=/admin/table-action" method="post" style="display:inline;margin-right:.5rem">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="truncate">
                    <button type="submit" onclick="return confirm('Truncate table <?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?')">Truncate</button>
                  </form>
                  <form action="index.php?route=/admin/table-action" method="post" style="display:inline">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="drop">
                    <button type="submit" onclick="return confirm('DROP table <?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>? This cannot be undone!')">Drop</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if (!empty($ctx['table_action_msg'])): ?>
          <div class="<?= !empty($ctx['table_action_err']) ? 'err' : 'ok' ?>"><?= htmlspecialchars($ctx['table_action_msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>

      <div>
        <h2>Import CSVs from Server</h2>
        <p class="note">CSV files discovered under: <?= htmlspecialchars(data_dir(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

        <form action="index.php?route=/admin/import-server" method="post">
          <label>Select CSV file found on server
            <select name="csv_rel" required>
              <option value="">-- choose a CSV --</option>
              <?php foreach (($ctx['server_csvs'] ?? []) as $rel): ?>
                <option value="<?= htmlspecialchars($rel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($rel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <label>Target Table (optional; default is derived from path)
            <input type="text" name="table" placeholder="leave empty to auto-derive">
          </label>
          <label><input type="checkbox" name="truncate" value="1"> Truncate table before import</label>
          <button type="submit">Import Selected CSV</button>
        </form>

        <form action="index.php?route=/admin/import-server-batch" method="post" style="margin-top:1rem">
          <label>Optional Subdirectory (relative to data/)
            <input type="text" name="subdir" placeholder="e.g., candidate_csv">
          </label>
          <label><input type="checkbox" name="truncate_each" value="1"> Truncate tables before each import</label>
          <button type="submit">Import ALL CSVs (recursive)</button>
        </form>

        <?php if (!empty($ctx['import_server_msg'])): ?>
          <div class="<?= !empty($ctx['import_server_err']) ? 'err' : 'ok' ?>"><?= htmlspecialchars($ctx['import_server_msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
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

/** Debug route: GET /__debug — show routing + config (masked) + DB check */
function handle_debug(): void {
  header('Content-Type: text/html; charset=utf-8');

  // Gather routing info
  $method   = $_SERVER['REQUEST_METHOD'] ?? '';
  $uriPath  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  $script   = $_SERVER['SCRIPT_NAME'] ?? '';
  $scriptDir= rtrim(str_replace('\\', '/', dirname($script)), '/');
  $server   = $_SERVER['SERVER_NAME'] ?? '';
  $addr     = $_SERVER['REMOTE_ADDR'] ?? '';

  // Config snapshot (masked)
  $cfg = $GLOBALS['CONFIG'] ?? [];
  $cfgMasked = $cfg;
  if (isset($cfgMasked['DB_PASS'])) $cfgMasked['DB_PASS'] = str_repeat('*', max(4, strlen((string)$cfgMasked['DB_PASS'])));

  // Config file presence
  $configPath = __DIR__ . '/config.php';
  $configExists = is_file($configPath) ? 'yes' : 'no';
  $configReal   = is_file($configPath) ? (realpath($configPath) ?: $configPath) : '(missing)';
  $configMd5    = is_file($configPath) ? md5_file($configPath) : '(n/a)';

  // DB check
  $dbOk = false;
  $dbErr = '';
  $dbName = '';
  $tables = [];
  try {
    $pdo = pdo();
    $dbName = $pdo->query('SELECT DATABASE() AS db')->fetch()['db'] ?? '';
    $stmt = $pdo->prepare("SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE table_schema = ? ORDER BY TABLE_NAME LIMIT 20");
    $stmt->execute([$dbName]);
    $rows = $stmt->fetchAll();
    foreach ($rows as $r) $tables[] = $r['table_name'] ?? '';
    $dbOk = true;
  } catch (Throwable $e) {
    $dbErr = $e->getMessage();
  }

  ?><!doctype html>
  <html lang="en">
  <head>
    <meta charset="utf-8">
    <title>API Debug</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
      body { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; margin: 1rem; }
      h1, h2 { margin: .25rem 0; }
      pre { background: #0b1220; color: #e5e7eb; padding: .75rem; border-radius: 8px; overflow:auto; }
      .ok { color: #16a34a; }
      .err { color: #dc2626; white-space: pre-wrap; }
      table { border-collapse: collapse; width: 100%; }
      th, td { border: 1px solid #e5e7eb; padding: .4rem .5rem; text-align: left; }
    </style>
  </head>
  <body>
    <h1>Web Of Influence — API Debug</h1>

    <h2>Routing</h2>
    <pre><?php echo htmlspecialchars(json_encode([
      'METHOD' => $method,
      'SERVER_NAME' => $server,
      'REMOTE_ADDR' => $addr,
      'SCRIPT_NAME' => $script,
      'scriptDir' => $scriptDir,
      'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
      'uriPath' => $uriPath,
      'ROUTE' => $GLOBALS['ROUTE'] ?? '(unset)',
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>Config</h2>
    <pre><?php echo htmlspecialchars(json_encode([
      'config_exists' => $configExists,
      'config_realpath' => $configReal,
      'config_md5' => $configMd5,
      'CONFIG' => $cfgMasked,
    ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>

    <h2>Database</h2>
    <?php if ($dbOk): ?>
      <div class="ok">Connected. DATABASE() = <?php echo htmlspecialchars($dbName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
      <p>First 20 tables:</p>
      <pre><?php echo htmlspecialchars(json_encode($tables, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></pre>
    <?php else: ?>
      <div class="err">DB error: <?php echo htmlspecialchars($dbErr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></div>
    <?php endif; ?>

    <h2>Useful Links</h2>
    <ul>
      <li><a href="./index.php">/api/index.php (health)</a></li>
      <li><a href="./index.php/admin">/api/index.php/admin (admin)</a></li>
      <li><a href="./index.php?route=/admin">/api/index.php?route=/admin (admin ?route form)</a></li>
    </ul>
  </body>
  </html><?php
  exit;
}

function handle_get_candidates(): void {
  $stmt = pdo()->query('SELECT id, first_name, last_name FROM people');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // return empty for no data
  json_response($rows);
}

function handle_get_parties(): void {
  // Include both "name" and legacy alias "party_name"
  $stmt = pdo()->query('SELECT id, name, name AS party_name FROM parties');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // return empty for no data
  json_response($rows);
}

function handle_get_electorates(): void {
  // Include both "name" and legacy alias "electorate_name"
  $stmt = pdo()->query('SELECT id, name, name AS electorate_name FROM electorates');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // return empty for no data
  json_response($rows);
}

function handle_candidate_by_id(): void {
  $people_id = $_GET['people_id'] ?? null;
  if (!$people_id) json_response(['error' => 'people_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, first_name, last_name FROM people WHERE id = ?');
  $stmt->execute([$people_id]);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // empty list when not found
  json_response($rows);
}

function handle_party_by_id(): void {
  $party_id = $_GET['party_id'] ?? null;
  if (!$party_id) json_response(['error' => 'party_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, name, name AS party_name FROM parties WHERE id = ?');
  $stmt->execute([$party_id]);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // empty list when not found
  json_response($rows);
}

function handle_electorate_by_id(): void {
  $electorate_id = $_GET['electorate_id'] ?? null;
  if (!$electorate_id) json_response(['error' => 'electorate_id is required'], 400);
  $stmt = pdo()->prepare('SELECT id, name, name AS electorate_name FROM electorates WHERE id = ?');
  $stmt->execute([$electorate_id]);
  $row = $stmt->fetch();
  if (!$row) json_response((object)[]); // empty object when not found
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
  if (!$rows) json_response([]); // return empty for no data
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
                 co.part_a, co.part_b, co.part_c, co.part_d, co.part_f, co.part_g, co.part_h, co.year, co.year AS election_year, co.original_id
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
  if (!$rows) json_response([]); // return empty for no data
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
  if (!$rows) json_response([]); // return empty for no data

  // Times are strings under PDO; return as-is
  json_response($rows);
}

/** Admin: GET /admin */
function handle_admin_get(): void {
  // Populate tables and server CSV list
  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
  ];
  render_admin($ctx);
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
  $sql = "INSERT IGNORE INTO {$bt} ({$colList}) VALUES ({$placeholders})";
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

/** Admin: POST /admin/upload-start (prepare mapping UI) */
function handle_admin_upload_start(): void {
  // Open access
  $rawTable = $_POST['table'] ?? '';
  $selTable = $_POST['dest_table_select'] ?? '';
  $table = $rawTable ?: $selTable;
  if (!$table) {
    render_admin([
      'error' => 'Destination table is required (select existing or provide custom).',
      'upload_result' => false,
      'inserted' => 0,
      'table_shown' => '',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }
  if (!is_safe_identifier(str_replace('.', '_', $table))) {
    render_admin([
      'error' => 'Invalid table name',
      'upload_result' => false,
      'inserted' => 0,
      'table_shown' => $table,
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    render_admin([
      'error' => 'CSV file is required',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  $tmpBase = tmp_dir();
  $tmpName = 'upload_' . uniqid('', true) . '.csv';
  $dest = rtrim($tmpBase, '/\\') . DIRECTORY_SEPARATOR . $tmpName;
  if (!@move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
    // fallback: read and write
    $content = @file_get_contents($_FILES['file']['tmp_name']);
    if ($content === false || @file_put_contents($dest, $content) === false) {
      render_admin([
        'error' => 'Failed to save uploaded file to tmp',
        'tables' => list_tables_with_counts(),
        'server_csvs' => find_server_csvs(),
      ]);
      return;
    }
  }

  // Read header
  $fh = @fopen($dest, 'r');
  if ($fh === false) {
    render_admin([
      'error' => 'Failed to open tmp CSV',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }
  $header = fgetcsv($fh) ?: [];
  fclose($fh);
  $csvColumns = array_values(array_filter(array_map(static fn($c) => sanitize_table_name((string)$c), $header), static fn($c) => $c !== ''));

  // Existing DB columns for mapping
  $dbCols = existing_columns($table);

  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
    'upload_mapping' => 1,
    'tmp_file' => $tmpName,
    'tmp_label' => $tmpName,
    'map_table' => $table,
    'csv_columns' => $csvColumns,
    'db_columns' => array_values(array_filter($dbCols, static fn($c) => strtolower($c) !== 'id')),
  ];
  render_admin($ctx);
}

/** 
 * Map candidate names to people IDs using fuzzy matching with Gemini AI
 * This function helps resolve foreign key relationships for donations import
 */
function resolve_candidate_person_id(string $firstName, string $lastName, string $electorate = ''): ?int {
  // First try exact match
  $stmt = pdo()->prepare(
    'SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?)'
  );
  $stmt->execute([$firstName, $lastName]);
  $exact = $stmt->fetch();
  if ($exact) {
    return (int)$exact['id'];
  }

  // Try fuzzy matching using Gemini AI
  $api_key = get_gemini_api_key();
  if ($api_key) {
    $candidates = get_candidate_suggestions_from_gemini($firstName, $lastName, $electorate, $api_key);
    if ($candidates && count($candidates) > 0) {
      return $candidates[0]['id']; // Return best match
    }
  }

  // No match found - create new person entry
  $stmt = pdo()->prepare(
    'INSERT INTO people (first_name, last_name, electorate_name) VALUES (?, ?, ?)'
  );
  $stmt->execute([$firstName, $lastName, $electorate ?: null]);
  return (int)pdo()->lastInsertId();
}

/**
 * Get candidate suggestions using Gemini AI for name matching
 */
function get_candidate_suggestions_from_gemini(string $firstName, string $lastName, string $electorate, string $api_key): array {
  // Get existing people from database for comparison
  $stmt = pdo()->query('SELECT id, first_name, last_name, electorate_name FROM people LIMIT 1000');
  $existing_people = $stmt->fetchAll();
  
  $people_list = [];
  foreach ($existing_people as $person) {
    $people_list[] = sprintf(
      'ID:%d - %s %s (%s)', 
      $person['id'], 
      $person['first_name'], 
      $person['last_name'],
      $person['electorate_name'] ?: 'Unknown'
    );
  }
  
  $people_text = implode("\n", array_slice($people_list, 0, 100)); // Limit for API
  
  $prompt = sprintf(
    'Find the best match for candidate "%s %s" from electorate "%s" in this list of existing people. ' .
    'Return JSON format: {"matches": [{"id": number, "confidence": 0-100, "reason": "explanation"}]} ' .
    'Only return matches with confidence > 70. If no good match, return empty matches array.\n\n' .
    'Existing people:\n%s',
    $firstName,
    $lastName,
    $electorate,
    $people_text
  );

  try {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$api_key}";
    $payload = json_encode([
      'contents' => [['parts' => [['text' => $prompt]]]]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
      $result = json_decode($response, true);
      if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $text = $result['candidates'][0]['content']['parts'][0]['text'];
        $text = preg_replace('/```\w*\n?/', '', $text); // Clean markdown
        $text = trim($text);
        
        $data = json_decode($text, true);
        if ($data && isset($data['matches']) && is_array($data['matches'])) {
          return $data['matches'];
        }
      }
    }
  } catch (Exception $e) {
    error_log("Gemini API error: " . $e->getMessage());
  }

  return [];
}

/**
 * Get Gemini API key from file
 */
function get_gemini_api_key(): ?string {
  $api_key_file = __DIR__ . '/gemini_api_key.txt';
  if (!file_exists($api_key_file)) {
    // Try the api directory
    $api_key_file = __DIR__ . '/../api/gemini_api_key.txt';
  }
  if (file_exists($api_key_file)) {
    return trim(file_get_contents($api_key_file));
  }
  return null;
}

/**
 * Enhanced function to handle donations import with proper foreign key resolution
 */
function handle_donations_import_with_candidate_mapping(string $tmpPath, string $table, array $mapping, bool $truncate): array {
  if ($truncate) {
    pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
  }

  $fh = fopen($tmpPath, 'r');
  if ($fh === false) {
    return ['inserted' => 0, 'errors' => ['Failed to open CSV file']];
  }

  $header = fgetcsv($fh);
  if (!$header) {
    fclose($fh);
    return ['inserted' => 0, 'errors' => ['CSV must have header row']];
  }

  // Create header index mapping
  $header_index = [];
  foreach ($header as $i => $col) {
    $header_index[trim($col)] = $i;
  }

  $inserted = 0;
  $errors = [];
  $candidate_cache = []; // Cache resolved candidates

  try {
    pdo()->beginTransaction();

    while (($row = fgetcsv($fh)) !== false) {
      try {
        $donation_data = [];
        $candidate_person_id = null;

        // Process each mapped field
        foreach ($mapping as $csv_col => $db_col) {
          if (!$db_col || $db_col === 'ignore') continue;

          $csv_index = $header_index[$csv_col] ?? null;
          $value = ($csv_index !== null && isset($row[$csv_index])) ? trim($row[$csv_index]) : null;

          // Handle special cases for donations
          if ($db_col === 'candidate_person_id') {
            // Extract candidate name from CSV - this might be in separate first/last name columns
            // or we might need to parse from a full name column
            $first_name = '';
            $last_name = '';
            $electorate = '';

            // Try to get first name, last name, and electorate from various possible columns
            if (isset($header_index['CandidateName_First'])) {
              $first_name = trim($row[$header_index['CandidateName_First']] ?? '');
            }
            if (isset($header_index['CandidateName_Last'])) {
              $last_name = trim($row[$header_index['CandidateName_Last']] ?? '');
            }
            if (isset($header_index['Electorate'])) {
              $electorate = trim($row[$header_index['Electorate']] ?? '');
            }

            // Alternative column names that might exist
            if (!$first_name && isset($header_index['first_name'])) {
              $first_name = trim($row[$header_index['first_name']] ?? '');
            }
            if (!$last_name && isset($header_index['last_name'])) {
              $last_name = trim($row[$header_index['last_name']] ?? '');
            }

            if ($first_name && $last_name) {
              $cache_key = strtolower($first_name . '|' . $last_name . '|' . $electorate);
              
              if (isset($candidate_cache[$cache_key])) {
                $candidate_person_id = $candidate_cache[$cache_key];
              } else {
                $candidate_person_id = resolve_candidate_person_id($first_name, $last_name, $electorate);
                $candidate_cache[$cache_key] = $candidate_person_id;
              }
              
              $donation_data[$db_col] = $candidate_person_id;
            }
          } elseif ($db_col === 'amount') {
            // Clean monetary amounts - remove $ and commas
            if ($value) {
              $clean_amount = preg_replace('/[$,]/', '', $value);
              $donation_data[$db_col] = is_numeric($clean_amount) ? (float)$clean_amount : 0;
            } else {
              $donation_data[$db_col] = 0;
            }
          } elseif ($db_col === 'date') {
            // Handle date parsing - try multiple formats
            if ($value) {
              $parsed_date = null;
              $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
              
              foreach ($formats as $format) {
                $date_obj = DateTime::createFromFormat($format, $value);
                if ($date_obj !== false) {
                  $parsed_date = $date_obj->format('Y-m-d');
                  break;
                }
              }
              
              $donation_data[$db_col] = $parsed_date;
            } else {
              $donation_data[$db_col] = null;
            }
          } elseif ($db_col === 'year') {
            // Extract year from various sources
            if ($value) {
              if (is_numeric($value) && strlen($value) === 4) {
                $donation_data[$db_col] = $value;
              } else {
                // Try to extract year from date
                $year = date('Y', strtotime($value));
                $donation_data[$db_col] = $year ?: null;
              }
            } else {
              $donation_data[$db_col] = null;
            }
          } else {
            // Handle other fields normally
            $donation_data[$db_col] = ($value === '') ? null : $value;
          }
        }

        // Ensure required fields are present
        if (!isset($donation_data['candidate_person_id']) || !$donation_data['candidate_person_id']) {
          $errors[] = "Row " . ($inserted + 1) . ": Missing or invalid candidate information";
          continue;
        }

        if (!isset($donation_data['amount'])) {
          $donation_data['amount'] = 0;
        }

        if (!isset($donation_data['year']) && isset($donation_data['date'])) {
          // Extract year from date if not provided
          $donation_data['year'] = date('Y', strtotime($donation_data['date']));
        }

        // Build and execute insert query
        $columns = array_keys($donation_data);
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $column_list = implode(',', array_map(fn($c) => "`$c`", $columns));
        
        $sql = "INSERT INTO `" . str_replace('`', '``', $table) . "` ($column_list) VALUES ($placeholders)";
        $stmt = pdo()->prepare($sql);
        $stmt->execute(array_values($donation_data));
        
        $inserted++;

      } catch (Exception $e) {
        $errors[] = "Row " . ($inserted + 1) . ": " . $e->getMessage();
      }
    }

    pdo()->commit();

  } catch (Exception $e) {
    pdo()->rollBack();
    $errors[] = "Transaction failed: " . $e->getMessage();
  }

  fclose($fh);
  
  return [
    'inserted' => $inserted,
    'errors' => $errors
  ];
}

/** Admin: POST /admin/upload-commit (execute mapping import) */
function handle_admin_upload_commit(): void {
  $tmpName = $_POST['tmp_file'] ?? '';
  $table = $_POST['table'] ?? '';
  $truncate = isset($_POST['truncate']) && $_POST['truncate'] === '1';
  $map = $_POST['map'] ?? [];

  if (!$tmpName || !$table || !is_array($map)) {
    render_admin([
      'error' => 'Missing mapping inputs',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  $base = tmp_dir();
  $tmpPath = $base . DIRECTORY_SEPARATOR . $tmpName;
  
  // Try to find the file with different approaches
  $abs = false;
  if (is_file($tmpPath)) {
    $abs = realpath($tmpPath);
  }
  
  // If realpath fails but file exists, use the direct path
  if ($abs === false && is_file($tmpPath)) {
    $abs = $tmpPath;
  }
  
  if ($abs === false || !is_file($abs)) {
    render_admin([
      'error' => 'Invalid tmp file reference - file not found: ' . $tmpName,
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }
  
  // Security check: ensure the file is within our tmp directory
  $realBase = realpath($base);
  $realAbs = realpath($abs);
  if ($realBase !== false && $realAbs !== false && strpos($realAbs, $realBase) !== 0) {
    render_admin([
      'error' => 'Invalid tmp file reference - security violation',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  // Sanitize table and mapping
  if (!is_safe_identifier(str_replace('.', '_', $table))) {
    render_admin([
      'error' => 'Invalid destination table',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  // Build final column mapping: csv_col -> db_col (skip empty)
  $finalMap = [];
  $createCols = [];
  foreach ($map as $csvCol => $dbSel) {
    $csvCol = sanitize_table_name((string)$csvCol);
    if (!$dbSel) continue; // ignore
    if (strpos($dbSel, '__CREATE__:') === 0) {
      $toCreate = substr($dbSel, strlen('__CREATE__:'));
      $toCreate = sanitize_table_name($toCreate);
      if ($toCreate) {
        $createCols[$toCreate] = true;
        $finalMap[$csvCol] = $toCreate;
      }
    } else {
      $finalMap[$csvCol] = sanitize_table_name((string)$dbSel);
    }
  }
  if (empty($finalMap)) {
    render_admin([
      'error' => 'No columns selected for import',
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
    ]);
    return;
  }

  // Create missing columns if needed
  if (!empty($createCols)) {
    foreach (array_keys($createCols) as $col) {
      try {
        $sql = "ALTER TABLE `" . str_replace('`', '``', $table) . "` ADD COLUMN `" . str_replace('`', '``', $col) . "` TEXT NULL";
        pdo()->exec($sql);
      } catch (Throwable $e) {
        // if already exists, ignore
      }
    }
  }

  // Check if this is a donations table that needs special handling
  $isDonationsTable = (strpos(strtolower($table), 'donation') !== false);
  
  if ($isDonationsTable) {
    // Use the enhanced donations import function
    $result = handle_donations_import_with_candidate_mapping($abs, $table, $finalMap, $truncate);
    $inserted = $result['inserted'];
    $errors = $result['errors'] ?? [];
    
    if (!empty($errors)) {
      $errorMsg = "Import completed with errors:\n" . implode("\n", array_slice($errors, 0, 10));
      if (count($errors) > 10) {
        $errorMsg .= "\n... and " . (count($errors) - 10) . " more errors.";
      }
      render_admin([
        'error' => $errorMsg,
        'upload_result' => true,
        'inserted' => $inserted,
        'table_shown' => $table,
        'tables' => list_tables_with_counts(),
        'server_csvs' => find_server_csvs(),
      ]);
      return;
    }
  } else {
    // Use standard import for non-donation tables
    $fh = @fopen($abs, 'r');
    if ($fh === false) {
      render_admin([
        'error' => 'Failed to re-open tmp CSV',
        'tables' => list_tables_with_counts(),
        'server_csvs' => find_server_csvs(),
      ]);
      return;
    }
    $header = fgetcsv($fh) ?: [];
    // Map header index
    $idx = [];
    foreach ($header as $i => $name) {
      $name = sanitize_table_name((string)$name);
      $idx[$name] = $i;
    }

    if ($truncate) {
      pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
    }

    $dbCols = array_values(array_unique(array_values($finalMap)));
    if (empty($dbCols)) {
      fclose($fh);
      render_admin([
        'error' => 'No destination columns after mapping',
        'tables' => list_tables_with_counts(),
        'server_csvs' => find_server_csvs(),
      ]);
      return;
    }
    $placeholders = implode(', ', array_fill(0, count($dbCols), '?'));
    $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $dbCols));
    $sql = "INSERT IGNORE INTO `" . str_replace('`', '``', $table) . "` ($colList) VALUES ($placeholders)";
    $stmt = pdo()->prepare($sql);

    $inserted = 0;
    while (($row = fgetcsv($fh)) !== false) {
      $vals = [];
      foreach ($dbCols as $dbCol) {
        // find csv col that maps to this dbCol
        $csvCol = array_search($dbCol, $finalMap, true);
        if ($csvCol === false) { $vals[] = null; continue; }
        $i = $idx[$csvCol] ?? null;
        $val = ($i !== null && array_key_exists($i, $row)) ? $row[$i] : null;
        $vals[] = ($val === '') ? null : $val;
      }
      try {
        $stmt->execute($vals);
        $inserted++;
      } catch (Throwable $e) {
        fclose($fh);
        render_admin([
          'error' => 'Insert failed at row ' . ($inserted + 1) . ': ' . $e->getMessage(),
          'tables' => list_tables_with_counts(),
          'server_csvs' => find_server_csvs(),
        ]);
        return;
      }
    }
    fclose($fh);
  }

  // Cleanup tmp file
  @unlink($abs);

  render_admin([
    'upload_result' => true,
    'inserted' => $inserted,
    'table_shown' => $table,
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
  ]);
}

/** Admin: POST /admin/table-action */
function handle_admin_table_action(): void {
  require_token_admin();
  $table = $_POST['table'] ?? '';
  $action = $_POST['action'] ?? '';
  $err = '';
  $msg = '';
  if (!is_safe_identifier($table)) {
    $err = 'Invalid table name';
  } elseif ($action === 'truncate') {
    try {
      pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
      $msg = "Truncated table {$table}.";
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  } elseif ($action === 'drop') {
    try {
      pdo()->exec("DROP TABLE `" . str_replace('`', '``', $table) . "`");
      $msg = "Dropped table {$table}.";
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  } else {
    $err = 'Unsupported action';
  }
  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
    'table_action_msg' => $msg ?: ($err ?: ''),
    'table_action_err' => $err ? 1 : 0,
  ];
  render_admin($ctx);
}

/** Admin: POST /admin/import-server (single CSV) */
function handle_admin_import_server(): void {
  require_token_admin();
  $rel = $_POST['csv_rel'] ?? '';
  $table = $_POST['table'] ?? null;
  $truncate = isset($_POST['truncate']) && $_POST['truncate'] === '1';

  $res = import_server_csv_to_table((string)$rel, $table ? sanitize_table_name($table) : null, $truncate);
  $err = $res['error'] ?? '';
  $msg = $err ? '' : ("Imported {$res['inserted']} rows into {$res['table']} from {$rel}.");

  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
    'import_server_msg' => $msg ?: ($err ?: ''),
    'import_server_err' => $err ? 1 : 0,
  ];
  render_admin($ctx);
}

/** Admin: POST /admin/import-server-batch (all CSVs under optional subdir) */
function handle_admin_import_server_batch(): void {
  require_token_admin();
  $subdir = trim((string)($_POST['subdir'] ?? ''));
  $truncateEach = isset($_POST['truncate_each']) && $_POST['truncate_each'] === '1';

  $base = data_dir();
  $dir = $base;
  if ($subdir !== '') {
    $dir = realpath($base . '/' . $subdir) ?: $dir;
  }
  $csvs = [];
  if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
      if ($item->isFile() && preg_match('/\.csv$/i', $item->getFilename())) {
        $abs = $item->getPathname();
        $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($base))), '/');
        $csvs[] = $rel;
      }
    }
  }
  sort($csvs);

  $totalInserted = 0;
  $errors = [];
  foreach ($csvs as $rel) {
    $res = import_server_csv_to_table($rel, null, $truncateEach);
    if (!empty($res['error'])) {
      $errors[] = $rel . ': ' . $res['error'];
    } else {
      $totalInserted += (int)$res['inserted'];
    }
  }

  $msg = "Imported " . count($csvs) . " CSV file(s), total rows inserted: {$totalInserted}.";
  if (!empty($errors)) {
    $msg .= " Errors:\n" . implode("\n", $errors);
  }

  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
    'import_server_msg' => $msg,
    'import_server_err' => empty($errors) ? 0 : 1,
  ];
  render_admin($ctx);
}

/** CSV Import API Functions (for frontend) */
function handle_csv_upload_api(): void {
  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_response(['error' => 'No file uploaded'], 400);
  }

  $uploadDir = __DIR__ . '/../uploads/';
  if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0755, true);
  }

  $filename = uniqid() . '_' . basename($_FILES['file']['name']);
  $filepath = $uploadDir . $filename;

  if (!move_uploaded_file($_FILES['file']['tmp_name'], $filepath)) {
    json_response(['error' => 'Failed to upload file'], 500);
  }

  json_response([
    'success' => true,
    'filename' => $filename,
    'filepath' => $filepath,
    'size' => filesize($filepath)
  ]);
}

function handle_csv_preview_api(): void {
  $input = json_decode(file_get_contents('php://input'), true);
  $filename = $input['filename'] ?? '';

  if (empty($filename)) {
    json_response(['error' => 'Filename required'], 400);
  }

  $filepath = __DIR__ . '/../uploads/' . $filename;
  if (!file_exists($filepath)) {
    json_response(['error' => 'File not found'], 404);
  }

  $handle = fopen($filepath, 'r');
  $headers = fgetcsv($handle);
  $preview = [];

  // Get first 10 rows for preview
  for ($i = 0; $i < 10; $i++) {
    $row = fgetcsv($handle);
    if ($row === false) break;
    $preview[] = $row;
  }

  fclose($handle);

  json_response([
    'success' => true,
    'headers' => $headers,
    'preview' => $preview,
    'filename' => $filename
  ]);
}

function handle_csv_mapping_api(): void {
  // Return available database tables and their columns for mapping
  $tables = [
    'people' => ['id', 'first_name', 'last_name', 'created_at', 'updated_at'],
    'parties' => ['id', 'name', 'created_at', 'updated_at'],
    'electorates' => ['id', 'name', 'created_at', 'updated_at'],
    'donors' => ['id', 'first_name', 'last_name', 'org_name', 'normalized_name', 'created_at', 'updated_at'],
    'candidate_overview' => [
      'id', 'original_id', 'year', 'people_id', 'party_id', 'electorate_id',
      'total_donations', 'total_expenses', 'part_a', 'part_b', 'part_c', 'part_d',
      'part_f', 'part_g', 'part_h', 'created_at', 'updated_at'
    ],
    'donations' => [
      'id', 'year', 'date', 'amount', 'money_or_goods_services', 'notes',
      'donor_id', 'candidate_person_id', 'candidate_overview_id', 'created_at', 'updated_at'
    ],
    'meetings' => [
      'id', 'date', 'start_time', 'end_time', 'location', 'title', 'notes',
      'type', 'portfolio', 'with_text', 'minister_person_id', 'created_at', 'updated_at'
    ]
  ];

  $input = json_decode(file_get_contents('php://input'), true);

  json_response([
    'success' => true,
    'tables' => $tables,
    'filename' => $input['filename'] ?? ''
  ]);
}

function handle_csv_execute_api(): void {
  $input = json_decode(file_get_contents('php://input'), true);
  $filename = $input['filename'] ?? '';
  $table = $input['table'] ?? '';
  $mapping = $input['mapping'] ?? [];

  if (empty($filename) || empty($table) || empty($mapping)) {
    json_response(['error' => 'Missing required parameters'], 400);
  }

  $filepath = __DIR__ . '/../uploads/' . $filename;
  if (!file_exists($filepath)) {
    json_response(['error' => 'File not found'], 404);
  }

  try {
    $pdo = pdo();
    $pdo->beginTransaction();

    $handle = fopen($filepath, 'r');
    $headers = fgetcsv($handle); // Skip header row

    $insertedCount = 0;
    $errorCount = 0;
    $errors = [];

    // Prepare columns and placeholders for the insert statement
    $columns = array_values($mapping);
    $placeholders = array_fill(0, count($columns), '?');

    $sql = "INSERT IGNORE INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);

    while (($row = fgetcsv($handle)) !== false) {
      try {
        // Map CSV columns to database columns
        $values = [];
        foreach ($mapping as $csvColumn => $dbColumn) {
          $columnIndex = array_search($csvColumn, $headers);
          $values[] = $columnIndex !== false ? $row[$columnIndex] : null;
        }

        $stmt->execute($values);
        $insertedCount++;

      } catch (Exception $rowError) {
        $errorCount++;
        $errors[] = "Row " . ($insertedCount + $errorCount) . ": " . $rowError->getMessage();

        // Limit error reporting to first 10 errors
        if (count($errors) >= 10) {
          break;
        }
      }
    }

    fclose($handle);
    $pdo->commit();

    // Clean up uploaded file
    unlink($filepath);

    json_response([
      'success' => true,
      'inserted' => $insertedCount,
      'errors' => $errorCount,
      'error_details' => $errors
    ]);

  } catch (Exception $e) {
    if (isset($pdo)) {
      $pdo->rollback();
    }
    json_response(['error' => 'Import failed', 'message' => $e->getMessage()], 500);
  }
}

/** AI Name Extraction */
function handle_ai_extract_names(): void {
  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_response(['error' => 'No file uploaded'], 400);
  }

  // Read file content
  $content = file_get_contents($_FILES['file']['tmp_name']);
  if ($content === false) {
    json_response(['error' => 'Error reading file'], 400);
  }

  // Limit file size (1MB)
  if (strlen($content) > 1024 * 1024) {
    json_response(['error' => 'File too large (max 1MB)'], 400);
  }

  // Get Gemini API key
  $apiKeyFile = __DIR__ . '/../api/gemini_api_key.txt';
  $apiKey = file_exists($apiKeyFile) ? trim(file_get_contents($apiKeyFile)) : null;
  if (!$apiKey) {
    json_response(['error' => 'Gemini API key not found'], 500);
  }

  // Create prompt for name extraction
  $prompt = 'Analyze the following text and extract all person names you can find. Return the results as a JSON object with this exact format: {"names": ["First Last", "First Last", ...]}

Only include actual person names, not organizations, places, or other entities. If no names are found, return {"names": []}.

Text to analyze:
' . substr($content, 0, 10000); // Limit content to avoid token limits

  // Call Gemini API
  $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
  $payload = json_encode([
    'contents' => [
      [
        'parts' => [
          [
            'text' => $prompt
          ]
        ]
      ]
    ]
  ]);

  $ch = curl_init($url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json'
  ]);

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($httpCode !== 200) {
    json_response(['error' => "AI API error: {$httpCode}"], 500);
  }

  $result = json_decode($response, true);
  if (!$result || !isset($result['candidates']) || empty($result['candidates'])) {
    json_response(['error' => 'No response generated by AI'], 500);
  }

  $generatedText = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';

  // Clean markdown code blocks
  $cleanedText = preg_replace('/```\w*\n?/', '', $generatedText);
  $cleanedText = trim($cleanedText);

  // Parse JSON
  $namesData = json_decode($cleanedText, true);
  if ($namesData === null) {
    json_response(['error' => 'AI response is not valid JSON'], 500);
  }

  // Extract names
  $namesList = [];
  if (is_array($namesData) && isset($namesData['names'])) {
    $namesList = $namesData['names'];
  } elseif (is_array($namesData)) {
    $namesList = $namesData;
  } else {
    json_response(['error' => 'Unexpected response format'], 500);
  }

  json_response([
    'names' => $namesList,
    'count' => count($namesList),
    'file_name' => $_FILES['file']['name']
  ]);
}

/** Dispatch */
try {
  if ($METHOD === 'GET' && $ROUTE === '/') handle_health();
  if ($METHOD === 'GET' && $ROUTE === '/__debug') handle_debug();

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

  // AI
  if ($METHOD === 'POST' && $ROUTE === '/ai/extract-names') handle_ai_extract_names();

  // Admin
  if ($METHOD === 'GET' && $ROUTE === '/admin') handle_admin_get();
  if ($METHOD === 'GET' && $ROUTE === '/adddata') handle_admin_get(); // Data import interface
  if ($METHOD === 'POST' && $ROUTE === '/admin/query') handle_admin_query();
  if ($METHOD === 'POST' && $ROUTE === '/admin/upload') handle_admin_upload();
  if ($METHOD === 'POST' && $ROUTE === '/admin/upload-start') handle_admin_upload_start();
  if ($METHOD === 'POST' && $ROUTE === '/admin/upload-commit') handle_admin_upload_commit();
  if ($METHOD === 'POST' && $ROUTE === '/admin/table-action') handle_admin_table_action();
  if ($METHOD === 'POST' && $ROUTE === '/admin/import-server') handle_admin_import_server();
  if ($METHOD === 'POST' && $ROUTE === '/admin/import-server-batch') handle_admin_import_server_batch();

  // CSV Import API endpoints (for frontend integration)
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/upload') handle_csv_upload_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/preview') handle_csv_preview_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/mapping') handle_csv_mapping_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/execute') handle_csv_execute_api();

  // Not found - add debug info
  json_response([
    'error' => 'Not Found',
    'route' => $ROUTE,
    'debug' => [
      'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
      'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '',
      'PATH_INFO' => $_SERVER['PATH_INFO'] ?? '',
      'uriPath' => $uriPath,
      'scriptDir' => $scriptDir,
      'path' => $path,
      'METHOD' => $METHOD,
      'GET_route' => $_GET['route'] ?? null
    ]
  ], 404);
} catch (Throwable $e) {
  json_response(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}
