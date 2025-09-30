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
 * - Allow calling /php-api/index.php or /api/index.php/route
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

/** Check if a column exists on the current DB table */
function column_exists(string $table, string $column): bool {
  try {
    $sql = "SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
    $stmt = pdo()->prepare($sql);
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/** Check if a table exists in current DB */
function table_exists(string $table): bool {
  try {
    $stmt = pdo()->prepare("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

/** Foreign key helpers for safe TRUNCATE (cascade children then parent) */
function find_referencing_tables(string $table): array {
  $sql = "SELECT TABLE_NAME
          FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME = ?
          GROUP BY TABLE_NAME";
  $stmt = pdo()->prepare($sql);
  $stmt->execute([$table]);
  $rows = $stmt->fetchAll();
  $out = [];
  foreach ($rows as $r) {
    $t = $r['TABLE_NAME'] ?? null;
    if ($t) $out[] = $t;
  }
  return $out;
}

/** Internal recursive truncation in FK dependency order (children first) */
function truncate_cascade_internal(string $table, array &$visited, array &$order): void {
  if (isset($visited[$table])) return;
  $visited[$table] = true;

  // Recurse into child tables that reference this table
  $children = find_referencing_tables($table);
  foreach ($children as $child) {
    truncate_cascade_internal($child, $visited, $order);
  }

  // Then add this table to the truncation order only (actual truncation done later)
  $order[] = $table;
}

/**
 * Public API: Truncate a table along with any tables that reference it via FKs.
 * Returns [array $truncatedOrder, string $warning]
 */
function truncate_table_cascade(string $table): array {
  $visited = [];
  $order = [];
  truncate_cascade_internal($table, $visited, $order);

  // Clear tables in dependency order.
  // Use TRUNCATE only for tables that are not referenced by any FKs.
  // For tables that ARE referenced by any FK (even if children are empty), MySQL forbids TRUNCATE (error 1701),
  // so we fallback to DELETE + AUTO_INCREMENT reset.
  foreach ($order as $t) {
    $isReferenced = !empty(find_referencing_tables($t));
    $qt = "`" . str_replace('`', '``', $t) . "`";
    if ($isReferenced) {
      pdo()->exec("DELETE FROM {$qt}");
      try {
        pdo()->exec("ALTER TABLE {$qt} AUTO_INCREMENT = 1");
      } catch (Throwable $e) {
        // Ignore if table has no AUTO_INCREMENT or ALTER not applicable
      }
    } else {
      pdo()->exec("TRUNCATE TABLE {$qt}");
    }
  }

  return [$order, ''];
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
  $post_count = isset($ctx['post_count']) ? (int)$ctx['post_count'] : null;

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
      .info-box { background: #111827; color: #ffffff; border: 1px solid #111827; border-left: 4px solid #3b82f6; padding: .75rem .9rem; border-radius: 6px; margin: .75rem 0; }
      .info-box .info-title { font-weight: 700; margin-bottom: .25rem; color: #ffffff; letter-spacing: .2px; }
      .info-box .info-content { color: #e5e7eb; font-size: .92rem; }
      .info-box .info-subtitle { color: #93c5fd; font-size: .9rem; margin: .15rem 0 .35rem; }
      .info-box ul { margin: .4rem 0 .2rem 1.1rem; padding: 0; }
      .info-box li { margin: .2rem 0; }

      /* Form-like bubble used for Mapping Instructions under Read-only Query */
      .bubble { background: #ffffff; color: #111827; border: 1px solid #e0e0e0; border-radius: 6px; padding: .75rem .9rem; margin: .75rem 0; }
      .bubble .info-title { font-weight: 700; margin-bottom: .25rem; color: #111827; letter-spacing: .2px; }
      .bubble .info-content { color: #333333; font-size: .92rem; }
      .bubble .info-subtitle { color: #374151; font-size: .9rem; margin: .15rem 0 .35rem; }
      .bubble ul { margin: .4rem 0 .2rem 1.1rem; padding: 0; }
      .bubble li { margin: .2rem 0; }
    </style>
  </head>
  <body>
    <h1>Web Of Influence — Admin</h1>
    <p class="note">Admin is currently OPEN (no authentication). Use with caution. We will add Firebase auth later.</p>

    <div class="row">
      <div>
        <h2>Read-only Query</h2>
        <form action="?route=/admin/query" method="post">
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

        <div id="mapping-instructions" class="bubble" aria-live="polite" style="order:3">
          <h2 class="info-title">Mapping Instructions</h2>
          <div class="info-subtitle" id="mapping-instructions-subtitle">
            Mapping instructions for <b><?= htmlspecialchars((string)($ctx['map_table'] ?? 'none selected'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></b>
          </div>

          <div class="info-content" id="mapping-instructions-content">
            <?php
              $mapTableLower = strtolower((string)($ctx['map_table'] ?? ''));
              if ($mapTableLower && strpos($mapTableLower, 'donation') !== false): ?>
                <p><b>Importing into donations (and auto-creating donors)</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Donors are auto-created/linked in the <code>donors</code> table.</li>
                  <li>Candidates are auto-linked via <code>people_id</code> or <code>original_id</code>.</li>
                  <li>You just need to map the correct CSV columns.</li>
                </ul>

                <p><b>Case: (year)_donor_information_for_candidate.csv</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>donations</b></li>
                      <li>Upload file: <b>(year)_donor_information_for_candidate.csv</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen — map columns as follows:</li>
                </ol>

                <p><b>Required / Recommended</b></p>
                <ul>
                  <li><code>_(year)CandidateDonations_Id</code> → <b>original_id</b><br>
                    <small>Links each donation to the correct <code>candidate_overview</code> row for (year)</small>
                  </li>
                  <li><code>DateReceived</code> → <b>date</b></li>
                  <li><code>DonationAmount</code> → <b>amount</b> <small>(auto‑strips $ and commas)</small></li>
                  <li><code>MoneyOrGoodsServices</code> → <b>money_or_goods_services</b></li>
                  <li><code>OtherDetail</code> → <b>notes</b></li>
                </ul>

                <p><b>Donor (auto‑created/linked in donors table)</b></p>
                <ul>
                  <li><code>DonorName_First</code> → <b>donor_first_name</b></li>
                  <li><code>DonorName_Last</code> → <b>donor_last_name</b></li>
                  <li><code>CompanyOrOrganisation</code> → <b>donor_org_name</b></li>
                </ul>

                <p><b>Address (builds “location” automatically)</b></p>
                <ul>
                  <li><code>Address_Line1</code> → <b>address_line1</b></li>
                  <li><code>Address_Line2</code> → <b>address_line2</b></li>
                  <li><code>Address_City</code> → <b>address_city</b></li>
                  <li><code>Address_PostalCode</code> → <b>address_postalcode</b></li>
                  <li><code>Address_Country</code> → <b>address_country</b></li>
                </ul>

                <p><b>Ignore (leave unmapped)</b></p>
                <ul>
                  <li><code>PartADonationEntry_Id</code></li>
                  <li><code>DateRangeFinishDate</code></li>
                  <li><code>AdditionalDateReceived</code>, <code>AdditionalDateReceived2..6</code></li>
                  <li><code>Contributions</code> <small>(optional: map to notes if you want the text)</small></li>
                  <li><code>DonorName_Prefix</code></li>
                  <li>Any system fields (<code>donor_id</code>, <code>candidate_person_id</code>, <code>candidate_overview_id</code>, <code>created_at</code>)</li>
                  <li><code>CandidateDonations2023Test_Id</code> <small>(not used for 2011–2020)</small></li>
                </ul>

                <p><b>Click “Import with Mapping”.</b> Optional: tick <b>Truncate table before insert</b> for a clean reload.</p>

                <p><b>What Happens Automatically</b></p>
                <ul>
                  <li><b>Donors:</b> Auto‑created/linked using donor_first_name/donor_last_name or donor_org_name. A <code>normalized_name</code> prevents duplicate donors. If a donor is a person, a matching record is also ensured in <code>people</code>.</li>
                  <li><b>Candidate linking priority:</b>
                    <ol>
                      <li><code>people_id</code> / <code>candidate_person_id</code> (not in your CSV)</li>
                      <li>Candidate first/last name (if present)</li>
                      <li><code>original_id</code> + <code>year</code> (preferred)</li>
                    </ol>
                  </li>
                  <li><b>Year:</b> Taken from <code>DateReceived</code> where possible; otherwise inferred from filename/header (2011).</li>
                  <li><b>Location:</b> Built automatically from the mapped address fields if no single location column is provided.</li>
                  <li><b>Insert:</b> Creates rows in <code>donations</code> with year, date, amount, money_or_goods_services, notes, location, donor_id, candidate_person_id, candidate_overview_id.</li>
                  <li><b>Re‑imports:</b> Uses INSERT (not upsert). If you re‑run, use <b>Truncate</b> first for a clean reload.</li>
                </ul>

                <p><b>Tips</b></p>
                <ul>
                  <li>Never map <code>_(year)CandidateDonations_Id</code> to <code>people_id</code>. Always map it to <code>original_id</code>.</li>
                  <li>If a row’s <code>original_id</code> doesn’t match any <code>candidate_overview</code> for (year):
                    <ul>
                      <li>The donation and donor still insert.</li>
                      <li><code>candidate_overview_id</code> remains NULL.</li>
                    </ul>
                  </li>
                </ul>

                <p><b>Optional Verification Queries</b></p>
                <p>Run in <b>Admin → Read‑only Query</b>:</p>
<pre>-- Check newly created donors
SELECT id, first_name, last_name, org_name 
FROM donors 
ORDER BY id DESC LIMIT 10;

-- Donations linked to a person
SELECT COUNT(*) 
FROM donations 
WHERE year = 2011 
  AND candidate_person_id IS NOT NULL;

-- Donations linked to candidate_overview
SELECT COUNT(*) 
FROM donations 
WHERE year = 2011 
  AND candidate_overview_id IS NOT NULL;</pre>
            <?php elseif ($mapTableLower && strpos($mapTableLower, 'candidate_overview') !== false): ?>
                <p><b>Importing candidate_overview CSVs</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Never do manual column mapping.</li>
                  <li>Always leave every dropdown as <b>Ignore</b>.</li>
                  <li>Click <b>Import with Mapping</b> — the enhanced importer automatically handles mappings.</li>
                </ul>

                <p><b>Case 1: Cleaned 2023 Candidates File</b><br>File: Electorate-Candidates-2023-GE-9-October-cleaned.csv</p>
                <ol>
                  <li>Set destination table to <b>candidate_overview</b> and select the file; click <b>Continue to Mapping</b>.</li>
                  <li>On Mapping Screen:
                    <ul>
                      <li>Info box confirms enhanced importer is active.</li>
                      <li><b>Important:</b> leave all columns as <b>Ignore</b>.</li>
                      <li>Optional: tick <b>Truncate table before insert</b> to clear the table.</li>
                      <li>Click <b>Import with Mapping</b>.</li>
                    </ul>
                  </li>
                </ol>
                <p><b>Automatic Import Behaviour</b></p>
                <ul>
                  <li>First Name → people.first_name</li>
                  <li>Last Name → people.last_name</li>
                  <li>Electorate → resolves/creates electorates.name → electorate_id</li>
                  <li>Party → resolves/creates parties.name → party_id</li>
                  <li>year = 2023 inferred from filename</li>
                  <li>Inserts into candidate_overview with people_id, party_id, electorate_id, year; totals stay NULL.</li>
                </ul>
                <p><b>Notes</b></p>
                <ul>
                  <li>Header normalization happens automatically (e.g., "First Name" → first_name).</li>
                  <li>Keep names consistent to avoid duplicate parties/electorates.</li>
                  <li><code>original_id</code> not populated for this file (fine).</li>
                </ul>

                <p><b>Case 2: 2011–2024 Candidate Donations CSV</b><br>File: (year)_candidate_donations.csv (year = 2011, 2014, 2017, 2020, 2023)</p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>candidate_overview</b></li>
                      <li>Upload file: <b>(year)_candidate_donations.csv</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen:
                    <ul>
                      <li><b>Do not map anything</b>; the manual grid is ignored.</li>
                      <li>Optional: tick <b>Truncate</b> to clear the table before insert.</li>
                      <li>Click <b>Import with Mapping</b>.</li>
                    </ul>
                  </li>
                </ol>
                <p><b>Automatic Import Behaviour</b></p>
                <ul>
                  <li>Reads headers: <code>CandidateName_First</code>, <code>CandidateName_Last</code>, <code>Electorate</code>, <code>Party</code>.</li>
                  <li>Creates/links people by candidate name → <code>people_id</code>.</li>
                  <li>Creates/links party and electorate by name.</li>
                  <li>Year detected from <code>_2011CandidateDonations_Id</code> (or year in filename).</li>
                  <li><code>_2011CandidateDonations_Id</code> is stored in <code>candidate_overview.original_id</code>.</li>
                  <li>Totals auto‑parsed (<code>TotalDonationsACD</code>, <code>PartA/B/C/D</code>, <code>Expenses</code>).</li>
                  <li>De‑dupe: <code>UNIQUE(year, people_id)</code> + <code>INSERT IGNORE</code>.</li>
                </ul>
                <p><b>Important Correction</b></p>
                <ul>
                  <li><b>Never</b> map <code>_2011CandidateDonations_Id</code> to <code>people_id</code>. It is stored in <code>candidate_overview.original_id</code>.</li>
                </ul>
<?php elseif ($mapTableLower === 'stg_overview_2023'): ?>
                <p><b>stg_overview_2023 — What this is</b></p>
                <ul>
                  <li>Temporary <b>staging table</b> used to load the 2023 candidate donations CSV before running the Maintenance backfill.</li>
                  <li>The <b>Maintenance → “Backfill 2023 original_id”</b> action copies <code>candidatedonations2023test_id</code> from this staging table into <code>candidate_overview.original_id</code> (year 2023) so <b>donations</b> can link correctly.</li>
                </ul>

                <p><b>When and why to use it</b></p>
                <ol>
                  <li>Use when preparing the site to link 2023 donations → candidates.</li>
                  <li>Load <b>candidate_csv/2023_candidate_donations.csv</b> into <b>stg_overview_2023</b>.</li>
                  <li>Run <b>Maintenance → Backfill 2023 original_id</b> to safely fill <code>candidate_overview.original_id</code> for 2023.</li>
                </ol>

                <p><b>Expected/Helpful columns in stg_overview_2023</b></p>
                <ul>
                  <li><code>candidatedonations2023test_id</code> <small>(REQUIRED to copy into candidate_overview.original_id)</small></li>
                  <li><code>candidatename_first</code>, <code>candidatename_last</code></li>
                  <li><code>party</code>, <code>electorate</code></li>
                </ul>

                <p><b>How to load the CSV</b></p>
                <ol>
                  <li>Recommended (no manual mapping): <b>Import CSVs from Server</b>
                    <ul>
                      <li>Place the file under <code>data/candidate_csv/2023_candidate_donations.csv</code> on the server.</li>
                      <li>Choose the file and set Target Table to <b>stg_overview_2023</b> (create if missing).</li>
                    </ul>
                  </li>
                  <li>Alternative: <b>CSV Upload → Table</b>
                    <ul>
                      <li>Destination table: <b>stg_overview_2023</b></li>
                      <li>Ensure the columns listed above are present; you may <b>Create column (TEXT)</b> if needed.</li>
                      <li>It’s fine to leave unrelated columns as <b>Ignore</b>.</li>
                    </ul>
                  </li>
                </ol>

                <p><b>After loading</b></p>
                <ol>
                  <li>Go to <b>Maintenance</b> and click <b>Backfill 2023 original_id</b>.</li>
                  <li>Check the result message: it shows before/after counts and updated rows per matching step.</li>
                  <li>Optional: once backfill finishes, you can <b>Truncate</b> <code>stg_overview_2023</code> to keep things tidy.</li>
                </ol>

                <p><b>Notes & Troubleshooting</b></p>
                <ul>
                  <li>The backfill is <b>idempotent</b>: it only fills <code>NULL</code> values and can be re-run safely.</li>
                  <li>If no rows are updated, confirm staging columns exist (case-insensitive): <code>candidatename_first</code>, <code>candidatename_last</code>, <code>party</code>, <code>electorate</code>, and <code>candidatedonations2023test_id</code>.</li>
                  <li>You can re-run after improving the staging file; only NULL <code>original_id</code> values will be filled.</li>
                </ul>
<?php elseif ($mapTableLower && strpos($mapTableLower, 'meetings') !== false): ?>
                <p><b>Importing Ministerial Diaries into meetings Table</b></p>
                <p><b>General Rule</b></p>
                <ul>
                  <li>Use the <b>AI Name Finder</b> first to enrich your CSV.</li>
                  <li>Then import into <b>meetings</b>.</li>
                  <li>The importer auto‑creates/links people for <b>minister</b> and <b>attendees</b>.</li>
                </ul>

                <p><b>Step 1 — Enrich CSV with AI Name Finder</b></p>
                <ul>
                  <li><b>Tool:</b> AI Name Finder</li>
                  <li><b>Mode:</b> Ministerial Diaries CSV → enrich + flag attendees</li>
                </ul>
                <p><b>Input CSV headers (expected)</b></p>
                <ul>
                  <li>Minister</li>
                  <li>Date</li>
                  <li>Schedule Time</li>
                  <li>Title</li>
                  <li>Type</li>
                  <li>Portfolio</li>
                  <li>Location</li>
                  <li>Notes</li>
                  <li>With/Attendees</li>
                </ul>
                <p><b>Output (enriched CSV includes extra columns)</b></p>
                <ul>
                  <li><code>Attendees_Text</code>: normalized text of attendees</li>
                  <li><code>Attendees_Names</code>: AI‑flagged person names, semicolon‑separated<br>
                    <small>Example: "John Smith; Jane Doe"</small>
                  </li>
                </ul>

                <p><b>Step 2 — Import Enriched CSV into meetings</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>meetings</b></li>
                    </ul>
                  </li>
                  <li>On Mapping Screen — map columns as follows:</li>
                </ol>
                <p><b>Required mappings</b></p>
                <ul>
                  <li><code>Date</code> → <b>date</b></li>
                  <li><code>Title</code> → <b>title</b></li>
                  <li><code>Type</code> → <b>type</b></li>
                  <li><code>Portfolio</code> → <b>portfolio</b></li>
                  <li><code>Location</code> → <b>location</b></li>
                  <li><code>Notes</code> → <b>notes</b></li>
                  <li><code>Attendees_Text</code> → <b>with_text</b></li>
                </ul>
                <p><b>Leave as Ignore</b></p>
                <ul>
                  <li><code>Minister</code> → <b>Ignore</b> (importer derives <code>minister_person_id</code> automatically)</li>
                  <li><code>Attendees_Names</code> → <b>Ignore</b> (importer reads and upserts people automatically)</li>
                </ul>
                <p><b>Time handling</b></p>
                <ul>
                  <li>If you only have <code>Schedule Time</code> (e.g., "9:30 AM - 10:00 AM"): <b>leave unmapped</b> — importer parses into <code>start_time</code> / <code>end_time</code>.</li>
                  <li>If you already have <code>Start_Time</code> and <code>End_Time</code> columns:
                    <ul>
                      <li><code>Start_Time</code> → <b>start_time</b></li>
                      <li><code>End_Time</code> → <b>end_time</b></li>
                    </ul>
                    <small>(Both approaches work)</small>
                  </li>
                </ul>

                <p><b>What Happens Automatically</b></p>
                <ul>
                  <li><b>Minister linking</b>
                    <ul>
                      <li>Importer resolves <code>minister_person_id</code> from the <code>Minister</code> column (or AI‑enriched ai_first_name / ai_last_name).</li>
                      <li>If the minister doesn’t exist in <code>people</code>, it creates them.</li>
                    </ul>
                  </li>
                  <li><b>Attendees linking</b>
                    <ul>
                      <li>Importer reads <code>Attendees_Names</code>.</li>
                      <li>Each name is split into first/last (best‑effort) and upserted into <code>people</code>, preventing duplicates.</li>
                      <li>Attendees are then linked to the meeting.</li>
                    </ul>
                  </li>
                </ul>
<?php elseif ($mapTableLower && strpos($mapTableLower, 'people') !== false): ?>
                <p><b>Importing into the people table</b></p>
                <p><b>General rule</b></p>
                <ul>
                  <li>Use destination table: <b>people</b></li>
                  <li>Map only the name fields.</li>
                  <li>Leave other columns as <b>Ignore</b>.</li>
                </ul>
                <p><b>Steps</b></p>
                <ol>
                  <li>In <b>Admin → CSV Upload → Table</b>:
                    <ul>
                      <li>Destination table: <b>people</b></li>
                      <li>Upload your CSV</li>
                    </ul>
                  </li>
                  <li>On Mapping Screen (recommended): 
                    <ul>
                      <li><code>First_Name</code> (or <code>First Name</code>) → <b>first_name</b></li>
                      <li><code>Last_Name</code> (or <code>Last Name</code>) → <b>last_name</b></li>
                      <li><code>Prefix</code> / <code>CandidateName_Prefix</code> (if present) → <b>prefix</b> (or choose “Create column 'prefix' (TEXT)” if the column doesn’t exist)</li>
                      <li><code>Electorate</code> → <b>Ignore</b></li>
                      <li><code>Party</code> → <b>Ignore</b></li>
                    </ul>
                  </li>
                  <li>Click <b>Import with Mapping</b>.</li>
                </ol>
                <p><b>What happens</b></p>
                <ul>
                  <li>Each row inserts one person with an auto-generated <code>people.id</code>.</li>
                  <li>These records can be linked later by the <code>candidate_overview</code> importer.</li>
                  <li>Matching is done case-insensitively on <code>first_name</code> + <code>last_name</code>.</li>
                  <li>If a person is missing, <code>candidate_overview</code> will create them automatically.</li>
                </ul>
                <p><b>Avoiding duplicates</b></p>
                <ul>
                  <li>The generic importer uses <code>INSERT IGNORE</code>; without a <code>UNIQUE</code> constraint, exact duplicates may slip through.</li>
                </ul>
                <p>Optional checks (Admin → Read-only Query):</p>
<pre>-- Find potential duplicates (case-insensitive)
SELECT 
  UPPER(first_name) AS fn, 
  UPPER(last_name) AS ln, 
  COUNT(*) AS c
FROM people
GROUP BY UPPER(first_name), UPPER(last_name)
HAVING COUNT(*) > 1
ORDER BY c DESC;</pre>
                <p>Optional uniqueness enforcement (after cleaning duplicates):</p>
<pre>ALTER TABLE people 
ADD UNIQUE idx_people_name (first_name, last_name);</pre>
                <p><b>Notes</b></p>
                <ul>
                  <li><b>Electorate</b> and <b>Party</b> are not part of the <code>people</code> table.
                    <ul>
                      <li>To load them, import into their own tables:
                        <ul>
                          <li><code>parties</code>: map <b>Party</b> → <b>name</b></li>
                          <li><code>electorates</code>: map <b>Electorate</b> → <b>name</b></li>
                        </ul>
                      </li>
                    </ul>
                  </li>
                  <li>You do not need to prefill <code>people</code> for <code>candidate_overview</code> imports—missing people are created automatically. Prefill only if you want specific capitalization or to control prefixes/titles.</li>
                </ul>
<?php else: ?>
                <p>Pick a destination table then upload a CSV. The next step lets you map CSV columns to database columns. Use “Ignore” for columns you do not want imported.</p>
                <ul>
                  <li>Avoid mapping id/primary key columns.</li>
                  <li>Map only columns that exist in the destination table (or choose “Create column” to add a new TEXT column).</li>
                  <li>Use “Truncate” if you want to replace existing rows.</li>
                </ul>
            <?php endif; ?>
          </div>
        </div>

      <div style="order:2">
        <h2>CSV Upload → Table</h2>
        <form action="?route=/admin/upload-start" method="post" enctype="multipart/form-data">
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
            <div class="ok">
              Inserted <?= (int)$inserted ?> rows into <?= $table_shown ?>.
              <?php if ($post_count !== null): ?> Now has <?= (int)$post_count ?> rows.<?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($ctx['upload_mapping'])): ?>
    <section class="panel">
      <h2>CSV Column Mapping</h2>
      <p class="note">CSV: <?= htmlspecialchars((string)($ctx['tmp_label'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> → Table: <?= htmlspecialchars((string)($ctx['map_table'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <form action="?route=/admin/upload-commit" method="post">
        <input type="hidden" name="tmp_file" value="<?= htmlspecialchars((string)($ctx['tmp_file'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="table" value="<?= htmlspecialchars((string)($ctx['map_table'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php if (!empty($ctx['orig_name'])): ?>
          <input type="hidden" name="orig_name" value="<?= htmlspecialchars((string)($ctx['orig_name']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endif; ?>
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
                      <?php
                        // Expose helper tokens for donations importer, even if not real DB columns
                        $isDonationsTarget = isset($ctx['map_table']) && stripos((string)$ctx['map_table'], 'donation') !== false;
                        if ($isDonationsTarget):
                          $helperTokens = [
                            // Address pieces to build "location"
                            'address_line1',
                            'address_line2',
                            'address_city',
                            'address_state',
                            'address_postalcode',
                            'address_country',
                            'address_countrycode',
                            // Donor info
                            'donor_first_name',
                            'donor_last_name',
                            'donor_org_name',
                            // Candidate linking
                            // Map CandidateDonations2023Test_Id (or similar) -> original_id to link to candidate_overview.original_id
                            'original_id',
                          ];
                      ?>
                        <optgroup label="Helper tokens (donations importer)">
                          <?php foreach ($helperTokens as $tok): ?>
                            <option value="<?= htmlspecialchars($tok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($tok, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <?php endforeach; ?>
                        </optgroup>
                      <?php endif; ?>
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
                  <form action="?route=/admin/table-action" method="post" style="display:inline;margin-right:.5rem">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="truncate">
                    <button type="submit" onclick="return confirm('Truncate table <?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>?')">Truncate</button>
                  </form>
                  <form action="?route=/admin/table-action" method="post" style="display:inline">
                    <input type="hidden" name="table" value="<?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="drop">
                    <button type="submit" onclick="return confirm('DROP table <?= htmlspecialchars($t['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>? This cannot be undone!')">Drop</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div>
        <h2>Import CSVs from Server</h2>
        <p class="note">CSV files discovered under: <?= htmlspecialchars(data_dir(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

        <form action="?route=/admin/import-server" method="post">
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

        <form action="?route=/admin/import-server-batch" method="post" style="margin-top:1rem">
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

    <div class="row">
      <div>
        <h2>Maintenance</h2>
        <form action="?route=/admin/backfill-2023-original" method="post" style="margin-bottom: .75rem;">
          <button type="submit">Backfill 2023 original_id (link donations → candidates)</button>
          <div class="note">
            Purpose: populate candidate_overview.original_id for 2023 so donations files can be linked to the correct candidate rows.
            <ul>
              <li>Prerequisite: import candidate_csv/2023_candidate_donations.csv into a staging table named <b>stg_overview_2023</b> using “Import CSVs from Server”.</li>
              <li>What it does: copies <code>candidatedonations2023test_id</code> from the staging table into <code>candidate_overview.original_id</code> when a match is found.</li>
              <li>Matching order: Names + Party + Electorate, then Names + Electorate, then Names + Party, and finally Names only.</li>
              <li>Idempotent: only fills NULL values and can be re-run safely; the message below shows before/after counts.</li>
            </ul>
          </div>
        </form>
        <?php if (!empty($ctx['table_action_msg'])): ?>
          <div class="<?= !empty($ctx['table_action_err']) ? 'err' : 'ok' ?>"><?= htmlspecialchars($ctx['table_action_msg'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
    </div>

    <p class="note">Security: Query tool only permits SELECT and blocks semicolons. Upload validates identifiers and uses prepared statements.</p>
    <script>
    (function(){
      // Initial table from server context (when returning to page with mapping section)
      var initialTable = <?php echo json_encode((string)($ctx['map_table'] ?? '')); ?>;
      var sel = document.querySelector('select[name="dest_table_select"]');
      var custom = document.querySelector('input[name="table"]');
      var subtitleEl = document.getElementById('mapping-instructions-subtitle');
      var contentEl = document.getElementById('mapping-instructions-content');

      // Mapping Instructions bubble appears under the Read-only Query form
      var mapEl = document.getElementById('mapping-instructions');
      var queryForm = document.querySelector('form[action*="/admin/query"]');
      if (mapEl && queryForm && mapEl.previousElementSibling !== queryForm) {
        queryForm.insertAdjacentElement('afterend', mapEl);
      } else if (mapEl && !queryForm) {
        // Fallback: place after the query textarea if form selector fails
        var qta = document.querySelector('textarea[name="query"]');
        if (qta && qta.parentElement) {
          qta.parentElement.insertAdjacentElement('afterend', mapEl);
        }
      }

      function htmlDefault(){
        return '<p>Pick a destination table then upload a CSV. The next step lets you map CSV columns to database columns. Use "Ignore" for columns you do not want imported.</p>'
          + '<ul>'
          + '<li>Do not map id columns.</li>'
          + '<li>Only map columns that exist in the destination table (or choose "Create column" to add a new TEXT column).</li>'
          + '<li>Use "Truncate" if you want to replace existing rows.</li>'
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
        return '<p>Candidate Overview uses an enhanced importer and ignores the manual mapping grid.</p>'
          + '<ul>'
          + '<li>Ensure your CSV has candidate <b>first</b> and <b>last</b> name, <b>party</b>, and <b>electorate</b> headers (common variants are detected automatically).</li>'
          + '<li><b>year</b> is inferred from the file or headers; include it if available.</li>'
          + '<li><b>original_id</b> (e.g. 2011candidatedonations_id or candidatedonations2023test_id) helps link to donations later.</li>'
          + '<li>Other totals (part_a, total_donations, total_expenses, etc.) are optional.</li>'
          + '</ul>'
          + '<p>Why ignore? Manual mapping is not used here—the importer reads known headers directly.</p>';
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
          + '<li><code>Attendees_Names</code>: AI‑flagged person names, semicolon‑separated</li>'
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
          +     '<li><code>Prefix</code> / <code>CandidateName_Prefix</code> (if present) → <b>prefix</b> (or choose “Create column \'prefix\' (TEXT)” if needed)</li>'
          +     '<li><code>Electorate</code> → <b>Ignore</b></li>'
          +     '<li><code>Party</code> → <b>Ignore</b></li>'
          +   '</ul>'
          + '</li>'
          + '<li>Click <b>Import with Mapping</b>.</li>'
          + '</ol>'
          + '<p><b>What happens</b></p>'
          + '<ul>'
          + '<li>Each row inserts one person with an auto-generated <code>people.id</code>.</li>'
          + '<li>These records can be linked later by <code>candidate_overview</code>.</li>'
          + '<li>Matching is case-insensitive on <code>first_name</code> + <code>last_name</code>.</li>'
          + '<li>If a person is missing, <code>candidate_overview</code> will create them automatically.</li>'
          + '</ul>'
          + '<p><b>Avoiding duplicates</b></p>'
          + '<ul>'
          + '<li>Generic importer uses <code>INSERT IGNORE</code>; without a <code>UNIQUE</code> constraint, exact duplicates may slip through.</li>'
          + '</ul>'
          + '<p>Optional checks (Admin → Read-only Query):</p>'
          + '<pre>-- Find potential duplicates (case-insensitive)\nSELECT \n  UPPER(first_name) AS fn, \n  UPPER(last_name) AS ln, \n  COUNT(*) AS c\nFROM people\nGROUP BY UPPER(first_name), UPPER(last_name)\nHAVING COUNT(*) > 1\nORDER BY c DESC;</pre>'
          + '<p>Optional uniqueness enforcement (after cleaning duplicates):</p>'
          + '<pre>ALTER TABLE people \nADD UNIQUE idx_people_name (first_name, last_name);</pre>'
          + '<p><b>Notes</b></p>'
          + '<ul>'
          + '<li><b>Electorate</b> and <b>Party</b> are not part of <code>people</code>.'
          +   '<ul>'
          +     '<li>Import them separately:'
          +       '<ul>'
          +         '<li><code>parties</code>: map <b>Party</b> → <b>name</b></li>'
          +         '<li><code>electorates</code>: map <b>Electorate</b> → <b>name</b></li>'
          +       '</ul>'
          +     '</li>'
          +   '</ul>'
          + '</li>'
          + '<li>Prefill <code>people</code> only if you want specific capitalization or to control prefixes/titles—the <code>candidate_overview</code> importer creates missing people automatically.</li>'
          + '</ul>';
      }

      function htmlStgOverview2023(){
        return '<p><b>stg_overview_2023 — What this is</b></p>'
          + '<ul>'
          + '<li>Temporary <b>staging table</b> used to load the 2023 candidate donations CSV before running the Maintenance backfill.</li>'
          + '<li>The <b>Maintenance → Backfill 2023 original_id</b> action copies <code>candidatedonations2023test_id</code> into <code>candidate_overview.original_id</code> (year 2023) so donations can link.</li>'
          + '</ul>'
          + '<p><b>When and why to use it</b></p>'
          + '<ol>'
          + '<li>Use when preparing the site to link 2023 donations → candidates.</li>'
          + '<li>Load <b>candidate_csv/2023_candidate_donations.csv</b> into <b>stg_overview_2023</b>.</li>'
          + '<li>Then run <b>Maintenance → Backfill 2023 original_id</b> to safely fill <code>candidate_overview.original_id</code> for 2023.</li>'
          + '</ol>'
          + '<p><b>Expected/Helpful columns in stg_overview_2023</b></p>'
          + '<ul>'
          + '<li><code>candidatedonations2023test_id</code> <small>(required to copy into candidate_overview.original_id)</small></li>'
          + '<li><code>candidatename_first</code>, <code>candidatename_last</code></li>'
          + '<li><code>party</code>, <code>electorate</code></li>'
          + '</ul>'
          + '<p><b>How to load the CSV</b></p>'
          + '<ol>'
          + '<li><b>Import CSVs from Server</b> (recommended)'
          +   '<ul>'
          +     '<li>Place the file under <code>data/candidate_csv/2023_candidate_donations.csv</code> on the server.</li>'
          +     '<li>Choose the file and set Target Table to <b>stg_overview_2023</b> (create if missing).</li>'
          +   '</ul>'
          + '</li>'
          + '<li><b>CSV Upload → Table</b> (alternative)'
          +   '<ul>'
          +     '<li>Destination table: <b>stg_overview_2023</b></li>'
          +     '<li>Ensure the columns listed above are present; you may <b>Create column (TEXT)</b> if needed.</li>'
          +     '<li>It is fine to leave unrelated columns as <b>Ignore</b>.</li>'
          +   '</ul>'
          + '</li>'
          + '</ol>'
          + '<p><b>After loading</b></p>'
          + '<ol>'
          + '<li>Go to <b>Maintenance</b> and click <b>Backfill 2023 original_id</b>.</li>'
          + '<li>Review the result message: it shows before/after counts and updated rows per matching step.</li>'
          + '<li>Optional: once backfill finishes, you can <b>Truncate</b> <code>stg_overview_2023</code> to keep things tidy.</li>'
          + '</ol>'
          + '<p><b>Notes & troubleshooting</b></p>'
          + '<ul>'
          + '<li>The backfill is <b>idempotent</b>: it only fills <code>NULL</code> values and can be re-run safely.</li>'
          + '<li>If no rows are updated, confirm staging columns exist (case-insensitive): <code>candidatename_first</code>, <code>candidatename_last</code>, <code>party</code>, <code>electorate</code>, and <code>candidatedonations2023test_id</code>.</li>'
          + '<li>You can re-run after improving the staging file; only <code>NULL</code> <code>original_id</code> values will be filled.</li>'
          + '</ul>';
      }
      function htmlGeneric(name){
        return '<p>Importing into <b>' + name + '</b>.</p>'
          + '<ul>'
          + '<li>Map only columns that exist in the table or use "Create column" to add new TEXT columns.</li>'
          + '<li>Avoid mapping primary key/id columns.</li>'
          + '<li>Use "Ignore" for columns that are informational only.</li>'
          + '</ul>';
      }

      function getChosen(){
        // Re-query each time in case DOM nodes change
        var s = document.querySelector('select[name="dest_table_select"]');
        var c = document.querySelector('input[name="table"]');
        var v = '';

        // 1) custom text input takes precedence
        if (c && typeof c.value === 'string' && c.value.trim() !== '') {
          v = c.value.trim();
        } else if (s) {
          // 2) select value, else fallback to selected option's text (strip " (rowcount)" etc.)
          var raw = (typeof s.value === 'string' && s.value.trim() !== '') ? s.value.trim() : '';
          if (!raw) {
            var opt = s.options && s.selectedIndex >= 0 ? s.options[s.selectedIndex] : null;
            var txt = opt && opt.textContent ? opt.textContent.trim() : '';
            var m = txt.match(/^[A-Za-z0-9_.]+/);
            if (m) raw = m[0];
          }
          v = raw;
        }

        // 3) server-provided initial table (when returning from mapping step)
        if (!v && initialTable) v = initialTable;

        return (v || '').toLowerCase();
      }

      function render(){
        if (!contentEl) return;
        var chosen = getChosen();
        if (subtitleEl) {
          subtitleEl.innerHTML = '';
          subtitleEl.append('Mapping instructions for ', (function(){
            var b = document.createElement('b');
            b.textContent = chosen || 'none selected';
            return b;
          })());
        }
        if (!chosen) { contentEl.innerHTML = htmlDefault(); return; }
        if (chosen.indexOf('donation') !== -1) { contentEl.innerHTML = htmlDonations(); return; }
        if (chosen.indexOf('meetings') !== -1) { contentEl.innerHTML = htmlMeetings(); return; }
        if (chosen.indexOf('people') !== -1) { contentEl.innerHTML = htmlPeople(); return; }
        if (chosen.indexOf('candidate_overview') !== -1) { contentEl.innerHTML = htmlCandidateOverview(); return; }
        if (chosen.indexOf('stg_overview_2023') !== -1) { contentEl.innerHTML = htmlStgOverview2023(); return; }
        contentEl.innerHTML = htmlGeneric(chosen);
      }

      if (sel) { 
        sel.addEventListener('change', render); 
        sel.addEventListener('input', render); 
        sel.addEventListener('click', render);
      }
      if (custom) custom.addEventListener('input', render);

      // Defensive: also listen at document level in case events are missed/rebound
      document.addEventListener('change', function(e){
        var t = e.target;
        if (t && t.matches && (t.matches('select[name="dest_table_select"]') || t.matches('input[name="table"]'))) {
          render();
        }
      }, true);
      document.addEventListener('input', function(e){
        var t = e.target;
        if (t && t.matches && (t.matches('select[name="dest_table_select"]') || t.matches('input[name="table"]'))) {
          render();
        }
      }, true);

      // Fallback: poll for selection changes (handles browser/UI quirks)
      var __lastChosen = getChosen();
      setInterval(function(){
        var now = getChosen();
        if (now !== __lastChosen) {
          __lastChosen = now;
          render();
        }
      }, 300);

      // Kick once after DOM settles
      setTimeout(render, 0);
    })();
    </script>
  </body>
  </html>
  <?php
  exit;
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
      <li><a href="./index.php">/php-api/index.php (health)</a></li>
      <li><a href="./index.php/admin">/php-api/index.php/admin (admin)</a></li>
      <li><a href="./index.php?route=/admin">/php-api/index.php?route=/admin (admin ?route form)</a></li>
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

  // Case-insensitive partial match to be more forgiving (e.g., "Mcgruddy" vs "McGruddy")
  $conds = [];
  $params = [];
  if ($first) { $conds[] = 'UPPER(first_name) LIKE UPPER(?)'; $params[] = '%' . $first . '%'; }
  if ($last)  { $conds[] = 'UPPER(last_name)  LIKE UPPER(?)';  $params[] = '%' . $last  . '%'; }

  $sql = 'SELECT id, first_name, last_name FROM people';
  if (!empty($conds)) {
    $sql .= ' WHERE ' . implode(' AND ', $conds);
  }
  $sql .= ' ORDER BY last_name, first_name LIMIT 20';

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

  // Return joined names alongside IDs so the frontend doesn't need N+1 lookups
  $sql = "SELECT 
            co.id,
            co.total_donations,
            co.total_expenses,
            co.people_id,
            co.party_id,
            co.electorate_id,
            co.part_a,
            co.part_b,
            co.part_c,
            co.part_d,
            co.part_f,
            co.part_g,
            co.part_h,
            co.year,
            co.year AS election_year,
            co.original_id,
            p.first_name,
            p.last_name,
            pr.name AS party_name,
            el.name AS electorate_name
          FROM candidate_overview co
          LEFT JOIN people p      ON p.id = co.people_id
          LEFT JOIN parties pr    ON pr.id = co.party_id
          LEFT JOIN electorates el ON el.id = co.electorate_id
          WHERE co.year = ?";
  $params = [$year];

  if ($first) { $sql .= ' AND UPPER(p.first_name) = UPPER(?)'; $params[] = $first; }
  if ($last)  { $sql .= ' AND UPPER(p.last_name)  = UPPER(?)'; $params[] = $last; }
  if ($party) { $sql .= ' AND UPPER(pr.name)      = UPPER(?)'; $params[] = $party; }
  if ($elect) { $sql .= ' AND UPPER(el.name)      = UPPER(?)'; $params[] = $elect; }

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

  if (!$first && !$last) json_response(['error' => 'Provide at least one of first_name or last_name'], 400);

  // Resolve person (partial, case-insensitive)
  $conds = [];
  $params = [];
  if ($first) { $conds[] = 'UPPER(first_name) LIKE UPPER(?)'; $params[] = '%' . $first . '%'; }
  if ($last)  { $conds[] = 'UPPER(last_name)  LIKE UPPER(?)';  $params[] = '%' . $last  . '%'; }
  $sqlP = 'SELECT id FROM people WHERE ' . implode(' AND ', $conds) . ' ORDER BY last_name, first_name LIMIT 1';
  $stmt = pdo()->prepare($sqlP);
  $stmt->execute($params);
  $person = $stmt->fetch();
  if (!$person) json_response([]);
  $people_id = (int)$person['id'];

  $sql = 'SELECT m.id, m.date, m.start_time, m.end_time, m.location, m.notes, m.type, m.portfolio, m.title, m.minister_person_id, m.with_text, p.first_name AS minister_first_name, p.last_name AS minister_last_name
          FROM meetings m
          LEFT JOIN people p ON p.id = m.minister_person_id
          WHERE m.minister_person_id = ?';
  $params = [$people_id];

  if ($start && $end) {
    $sql .= ' AND (m.date BETWEEN ? AND ?)';
    $params[] = $start; $params[] = $end;
  } elseif ($start) {
    $sql .= ' AND m.date >= ?';
    $params[] = $start;
  } elseif ($end) {
    $sql .= ' AND m.date <= ?';
    $params[] = $end;
  }

  if ($portfolio) {
    $sql .= ' AND m.portfolio LIKE ?';
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
  // Hide noisy/legacy columns from the mapping dropdown for certain tables
  $tLower = strtolower($table);
  if ($tLower === 'meetings') {
    // Only show the columns we actually support importing into meetings
    $allowed = [
      'date',
      'start_time',
      'end_time',
      'location',
      'title',
      'notes',
      'type',
      'portfolio',
      'with_text',
      'minister_person_id',
    ];
    $dbCols = array_values(array_intersect($dbCols, $allowed));
  }

  $ctx = [
    'tables' => list_tables_with_counts(),
    'server_csvs' => find_server_csvs(),
    'upload_mapping' => 1,
    'tmp_file' => $tmpName,
    'tmp_label' => $tmpName,
    'map_table' => $table,
    'csv_columns' => $csvColumns,
    'db_columns' => array_values(array_filter($dbCols, static fn($c) => strtolower($c) !== 'id')),
    'orig_name' => basename($_FILES['file']['name'] ?? '')
  ];
  render_admin($ctx);
}

/**
 * Map candidate names to people IDs (simple exact match; create if missing).
 * Avoids assuming optional columns like electorate_name exist.
 */
function resolve_candidate_person_id(string $firstName, string $lastName, string $electorate = ''): ?int {
  // Exact match on first/last
  $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?) LIMIT 1');
  $stmt->execute([$firstName, $lastName]);
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) {
    return (int)$row['id'];
  }
  // Insert minimal person record
  $ins = pdo()->prepare('INSERT INTO people (first_name, last_name) VALUES (?, ?)');
  $ins->execute([$firstName, $lastName]);
  return (int)pdo()->lastInsertId();
}

/**
 * Get candidate suggestions using Gemini AI for name matching
 */
function get_candidate_suggestions_from_gemini(string $firstName, string $lastName, string $electorate, string $api_key): array {
  // Get existing people from database for comparison (no electorate column assumed)
  $stmt = pdo()->query('SELECT id, first_name, last_name FROM people LIMIT 1000');
  $existing_people = $stmt->fetchAll();

  $people_list = [];
  foreach ($existing_people as $person) {
    $people_list[] = sprintf(
      'ID:%d - %s %s',
      $person['id'],
      $person['first_name'],
      $person['last_name']
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
  // Prefer environment variable if set (e.g., via hosting control panel)
  $env = getenv('GEMINI_API_KEY');
  if ($env !== false && trim($env) !== '') {
    return trim($env);
  }

  // Next, allow config.php to hold the key so it persists across deployments
  if (isset($GLOBALS['CONFIG']) && is_array($GLOBALS['CONFIG'])) {
    $cfgKey = $GLOBALS['CONFIG']['GEMINI_API_KEY'] ?? null;
    if (is_string($cfgKey) && trim($cfgKey) !== '') {
      return trim($cfgKey);
    }
  }

  // Fallback to a file in the PHP API directory or sibling php-api directory
  $api_key_file = __DIR__ . '/gemini_api_key.txt';
  if (!file_exists($api_key_file)) {
    // Try the Python API directory sibling (in case the key is stored once for both)
    $api_key_file = __DIR__ . '/../api/gemini_api_key.txt';
  }
  if (!file_exists($api_key_file)) {
    // Also support deployment that places the key under /webofinfluence/php-api
    $api_key_file = __DIR__ . '/../php-api/gemini_api_key.txt';
  }
  if (file_exists($api_key_file)) {
    return trim(file_get_contents($api_key_file));
  }
  return null;
}

/** Deterministic Gemini payload (temperature=0) */
function gemini_make_body(string $instruction, int $maxTokens = 2048): string {
  return json_encode([
    'generationConfig' => [
      'temperature' => 0,
      'topP' => 0,
      'topK' => 1,
      'candidateCount' => 1,
      'maxOutputTokens' => $maxTokens
    ],
    'contents' => [
      [
        'parts' => [
          ['text' => $instruction]
        ]
      ]
    ]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** AI key debug utilities */
function get_gemini_key_status(): array {
  $env = getenv('GEMINI_API_KEY');
  $hasEnv = ($env !== false && trim((string)$env) !== '');

  $filePath1 = __DIR__ . '/gemini_api_key.txt';
  $filePath2 = __DIR__ . '/../php-api/gemini_api_key.txt';
  $exists1 = is_file($filePath1);
  $exists2 = is_file($filePath2);

  $source = null;
  $path = null;
  if ($hasEnv) {
    $source = 'env';
  } elseif ($exists1) {
    $source = 'file';
    $path = realpath($filePath1) ?: $filePath1;
  } elseif ($exists2) {
    $source = 'file';
    $path = realpath($filePath2) ?: $filePath2;
  }
  return [
    'present' => (bool)($hasEnv || $exists1 || $exists2),
    'source' => $source,
    'file_path' => $path
  ];
}

/** GET /ai/key-status — return whether GEMINI_API_KEY is visible to the PHP API */
function handle_ai_key_status(): void {
  json_response(get_gemini_key_status());
}

/**
 * Enhanced function to handle donations import with proper foreign key resolution
 * Supports:
 *  - Using people_id directly (preferred) or resolving candidate_person_id from names or original_id
 *  - Creating donors (people and/or organisations) and linking donor_id
 *  - Populating woi.donations compatible columns: year, date, amount, money_or_goods_services, notes, location, donor_id, candidate_person_id, candidate_overview_id
 * Notes:
 *  - Mapping keys are sanitized header names (via sanitize_table_name)
 *  - Mapping values can be either actual donations table columns OR helper tokens like:
 *      people_id, candidate_person_id, donorname_first, donorname_last, companyororganisation,
 *      datereceived, donationamount, moneyorgoodsservices, otherdetail, address_line1/2, address_city, address_country,
 *      partadonationentry_id, candidatedonations2023test_id, _YYYYcandidatedonations_id (auto-detected), original_id (if present)
 */
function handle_donations_import_with_candidate_mapping(string $tmpPath, string $table, array $mapping, bool $truncate, ?int $defaultYearHint = null, ?string $origFilename = null): array {
  // Helper: sanitize consistently with admin mapper
  $sanitize = fn(string $s) => sanitize_table_name($s);

  if ($truncate) {
    try {
      pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
    } catch (Throwable $e) {
      // Fallback: DELETE + reset AUTO_INCREMENT
      pdo()->exec("DELETE FROM `" . str_replace('`', '``', $table) . "`");
      try { pdo()->exec("ALTER TABLE `" . str_replace('`', '``', $table) . "` AUTO_INCREMENT = 1"); } catch (Throwable $ignore) {}
    }
  }

  $fh = fopen($tmpPath, 'r');
  if ($fh === false) {
    return ['inserted' => 0, 'errors' => ['Failed to open CSV file']];
  }

  $rawHeader = fgetcsv($fh);
  if (!$rawHeader) {
    fclose($fh);
    return ['inserted' => 0, 'errors' => ['CSV must have header row']];
  }

  // Build header index with both sanitized and normalized keys
  $header_index = [];
  foreach ($rawHeader as $i => $col) {
    $raw  = (string)$col;
    $san  = $sanitize($raw);               // preserves case, trims non-word chars
    $norm = normalize_csv_header($raw);    // lowercase + underscores
    $header_index[$san]  = $i;
    $header_index[$norm] = $i;
  }

  // Reverse mapping: DB column -> CSV sanitized column
  $mapDbToCsv = [];
  foreach ($mapping as $csvSan => $dbSan) {
    $csvSan = $sanitize((string)$csvSan);
    $dbSan  = $sanitize((string)$dbSan);
    $mapDbToCsv[$dbSan] = $csvSan;
  }

  // Helpers to read a value from row
  $getValByCsv = function(array $row, string $csvSan) use ($header_index) {
    // Try direct, then normalized fallback (handles headers like _2011CandidateDonations_Id)
    $i = $header_index[$csvSan]
      ?? $header_index[normalize_csv_header($csvSan)]
      ?? null;
    if ($i === null) return null;
    return isset($row[$i]) ? trim((string)$row[$i]) : null;
  };
  $getValByDb = function(array $row, string $dbSan) use ($mapDbToCsv, $header_index, $getValByCsv) {
    $csvSan = $mapDbToCsv[$dbSan] ?? null;
    return $csvSan ? $getValByCsv($row, $csvSan) : null;
  };

  // Convenience: resolve/insert People and Donors
  $get_or_create_person = function(string $first, string $last): int {
    $first = trim($first);
    $last  = trim($last);
    if ($first === '' || $last === '') return 0;

    $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?) LIMIT 1');
    $stmt->execute([$first, $last]);
    $row = $stmt->fetch();
    if ($row && isset($row['id'])) return (int)$row['id'];

    try {
      $ins = pdo()->prepare('INSERT INTO people (first_name, last_name) VALUES (?, ?)');
      $ins->execute([$first, $last]);
      return (int)pdo()->lastInsertId();
    } catch (Throwable $e) {
      // Unique constraint race; re-select
      $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?) LIMIT 1');
      $stmt->execute([$first, $last]);
      $row = $stmt->fetch();
      return $row && isset($row['id']) ? (int)$row['id'] : 0;
    }
  };

  $get_or_create_donor = function(?string $first, ?string $last, ?string $org) {
    $first = trim((string)$first);
    $last  = trim((string)$last);
    $org   = trim((string)$org);

    // Determine normalized donor identity
    $normalized = '';
    if ($org !== '') {
      $normalized = mb_strtoupper($org, 'UTF-8');
    } elseif ($first !== '' || $last !== '') {
      $normalized = mb_strtoupper(trim($first . ' ' . $last), 'UTF-8');
    }

    // Check if donors table has organization_id column
    static $donorsHasOrg = null;
    if ($donorsHasOrg === null) {
      $donorsHasOrg = column_exists('donors', 'organization_id');
    }

    // If an org is provided, resolve/create organization row
    $orgId = null;
    if ($org !== '') {
      try {
        $orgId = get_or_create_organization_id($org);
      } catch (Throwable $e) {
        $orgId = null;
      }
    }

    // Try find donor by normalized_name first
    if ($normalized !== '') {
      $stmt = pdo()->prepare('SELECT id, organization_id FROM donors WHERE normalized_name = ? LIMIT 1');
      $stmt->execute([$normalized]);
      $row = $stmt->fetch();
      if ($row && isset($row['id'])) {
        // If existing donor lacks organization_id but we have one, update it
        if ($donorsHasOrg && $orgId && empty($row['organization_id'])) {
          try {
            $upd = pdo()->prepare('UPDATE donors SET organization_id = ? WHERE id = ?');
            $upd->execute([$orgId, (int)$row['id']]);
          } catch (Throwable $e) { /* ignore */ }
        }
        return (int)$row['id'];
      }
    } else {
      // Fallback search on exact fields
      $sql = 'SELECT id, organization_id FROM donors WHERE COALESCE(first_name,"") = ? AND COALESCE(last_name,"") = ? AND COALESCE(org_name,"") = ? LIMIT 1';
      $stmt = pdo()->prepare($sql);
      $stmt->execute([$first, $last, $org]);
      $row = $stmt->fetch();
      if ($row && isset($row['id'])) {
        if ($donorsHasOrg && $orgId && empty($row['organization_id'])) {
          try {
            $upd = pdo()->prepare('UPDATE donors SET organization_id = ? WHERE id = ?');
            $upd->execute([$orgId, (int)$row['id']]);
          } catch (Throwable $e) { /* ignore */ }
        }
        return (int)$row['id'];
      }
    }

    // Insert new donor (prefer including organization_id when supported)
    try {
      if ($donorsHasOrg) {
        $ins = pdo()->prepare('INSERT INTO donors (first_name, last_name, org_name, normalized_name, organization_id) VALUES (?, ?, ?, ?, ?)');
        $ins->execute([
          $first !== '' ? $first : null,
          $last  !== '' ? $last  : null,
          $org   !== '' ? $org   : null,
          $normalized !== '' ? $normalized : null,
          $orgId
        ]);
      } else {
        $ins = pdo()->prepare('INSERT INTO donors (first_name, last_name, org_name, normalized_name) VALUES (?, ?, ?, ?)');
        $ins->execute([
          $first !== '' ? $first : null,
          $last  !== '' ? $last  : null,
          $org   !== '' ? $org   : null,
          $normalized !== '' ? $normalized : null
        ]);
      }
      return (int)pdo()->lastInsertId();
    } catch (Throwable $e) {
      // On unique normalized_name conflict, re-select
      if ($normalized !== '') {
        $stmt = pdo()->prepare('SELECT id FROM donors WHERE normalized_name = ? LIMIT 1');
        $stmt->execute([$normalized]);
        $row = $stmt->fetch();
        if ($row && isset($row['id'])) return (int)$row['id'];
      }
      return 0;
    }
  };

  $parse_amount = function($v): float {
    if ($v === null || $v === '') return 0.0;
    $clean = preg_replace('/[$,]/', '', (string)$v);
    return is_numeric($clean) ? (float)$clean : 0.0;
  };

  $parse_date = function($v): ?string {
    if (!$v) return null;
    $candidates = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
    foreach ($candidates as $fmt) {
      $dt = DateTime::createFromFormat($fmt, $v);
      if ($dt !== false) return $dt->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
  };

  $find_candidate_overview = function(?string $originalId, ?string $yearHint, ?int $peopleIdHint = null): array {
    if (!$originalId && !$peopleIdHint) return [null, null, null];
    // 1) Try by original_id with year hint (robust for placeholder dates in CSV)
    if ($originalId && $yearHint && preg_match('/^\d{4}$/', $yearHint)) {
      $stmt = pdo()->prepare('SELECT id, people_id, year FROM candidate_overview WHERE original_id = ? AND year = ? LIMIT 1');
      $stmt->execute([$originalId, $yearHint]);
      $row = $stmt->fetch();
      if ($row) return [(int)$row['id'], (int)$row['people_id'], (int)$row['year']];
    }
    // 2) Try by original_id without year (in case of mismatched placeholder dates)
    if ($originalId) {
      $stmt = pdo()->prepare('SELECT id, people_id, year FROM candidate_overview WHERE original_id = ? LIMIT 1');
      $stmt->execute([$originalId]);
      $row = $stmt->fetch();
      if ($row) return [(int)$row['id'], (int)$row['people_id'], (int)$row['year']];
    }
    // 3) Fallback by (people_id, year)
    if ($peopleIdHint && $yearHint && preg_match('/^\d{4}$/', $yearHint)) {
      $stmt = pdo()->prepare('SELECT id, people_id, year FROM candidate_overview WHERE people_id = ? AND year = ? LIMIT 1');
      $stmt->execute([$peopleIdHint, $yearHint]);
      $row = $stmt->fetch();
      if ($row) return [(int)$row['id'], (int)$row['people_id'], (int)$row['year']];
    }
    return [null, $peopleIdHint, null];
  };

  $inserted = 0;
  $errors = [];

  try {
    pdo()->beginTransaction();

    // Deduce a reliable election year hint for this CSV (filename or header key)
    $csvYearHint = null;
    if ($defaultYearHint) $csvYearHint = (string)$defaultYearHint;

    // 1) From original filename if present (e.g., ...2023...)
    if (!$csvYearHint && is_string($origFilename) && preg_match('/\b(2011|2014|2017|2020|2023)\b/', $origFilename, $mfn)) {
      $csvYearHint = $mfn[1];
    }

    // 2) From header keys like "2011candidatedonations_id" (appears in some year-specific CSVs)
    if (!$csvYearHint) {
      foreach ($header_index as $hSan => $_idx) {
        if (preg_match('/^(\d{4})candidatedonations_id$/', $hSan, $mh)) {
          $csvYearHint = $mh[1];
          break;
        }
      }
    }

    // 3) From donor CSV header variants that embed the year e.g. "CandidateDonations2023Test_Id",
    //    or normalized "candidatedonations2023test_id"
    if (!$csvYearHint) {
      foreach (array_keys($header_index) as $key) {
        // generic: "candidate...<year>...donations"
        if (preg_match('/candidate.*(2011|2014|2017|2020|2023).*donations/i', $key, $m2)) {
          $csvYearHint = $m2[1];
          break;
        }
        // specific: "candidatedonations<year>(test)?_id"
        if (preg_match('/candidatedonations(2011|2014|2017|2020|2023)(?:test)?_id/i', $key, $m3)) {
          $csvYearHint = $m3[1];
          break;
        }
      }
    }

    while (($row = fgetcsv($fh)) !== false) {
      try {
        // Resolve core fields via mapping or sensible defaults
        $dateStr = $getValByDb($row, 'date') ?? $getValByDb($row, 'datereceived') ?? $getValByCsv($row, 'date') ?? $getValByCsv($row, 'datereceived');
        $date    = $parse_date($dateStr);
        $year    = $getValByDb($row, 'year') ?? $getValByCsv($row, 'year');
        if (!$year && $date) $year = date('Y', strtotime($date));
        // If parsed year is not one of our known election years, override with csvYearHint
        if (!$year || !preg_match('/^(2011|2014|2017|2020|2023)$/', (string)$year)) {
          $year = $csvYearHint ?: null;
        }

        $amount  = $getValByDb($row, 'amount') ?? $getValByDb($row, 'donationamount') ?? $getValByCsv($row, 'donationamount') ?? $getValByCsv($row, 'amount');
        $amountF = $parse_amount($amount);

        $mos     = $getValByDb($row, 'money_or_goods_services') ?? $getValByDb($row, 'moneyorgoodsservices') ?? $getValByCsv($row, 'money_or_goods_services') ?? $getValByCsv($row, 'moneyorgoodsservices');
        $notes   = $getValByDb($row, 'notes') ?? $getValByDb($row, 'otherdetail') ?? $getValByCsv($row, 'notes') ?? $getValByCsv($row, 'otherdetail');

        // Build location if not provided
        $location = $getValByDb($row, 'location') ?? $getValByCsv($row, 'location');
        if ($location === null) {
          $addr1 = $getValByDb($row, 'address_line1') ?? $getValByCsv($row, 'address_line1');
          $addr2 = $getValByDb($row, 'address_line2') ?? $getValByCsv($row, 'address_line2');
          $city  = $getValByDb($row, 'address_city')  ?? $getValByCsv($row, 'address_city');
          $cntry = $getValByDb($row, 'address_country') ?? $getValByCsv($row, 'address_country');
          $parts = array_values(array_filter([$addr1, $addr2, $city, $cntry], fn($x) => ($x !== null && $x !== '')));
          $location = empty($parts) ? null : implode(', ', $parts);
        }

        // Donor info (person or org)
        // Accept mapping names or raw CSV headers (sanitized) if user didn't explicitly map
        $donorFirst = $getValByDb($row, 'donor_first_name') ?? $getValByDb($row, 'donorname_first') ?? $getValByCsv($row, 'donorname_first');
        $donorLast  = $getValByDb($row, 'donor_last_name')  ?? $getValByDb($row, 'donorname_last')  ?? $getValByCsv($row, 'donorname_last');
        $donorOrg   = $getValByDb($row, 'donor_org_name')   ?? $getValByDb($row, 'companyororganisation') ?? $getValByCsv($row, 'companyororganisation');

        // Create donor in donors and, if personal name present, also add as person
        $donorId = $get_or_create_donor($donorFirst, $donorLast, $donorOrg);
        if ($donorFirst || $donorLast) {
          $get_or_create_person($donorFirst ?: '', $donorLast ?: '');
        }

        // Candidate resolution (priority order):
        // 1) Explicit people_id mapping (user-provided)
        // 2) Explicit candidate_person_id mapping
        // 3) Candidate names mapping
        // 4) candidate_overview.original_id via CSV columns like _YYYYCandidateDonations_Id or CandidateDonations2023Test_Id
        $peopleId = null;

        // (1) people_id provided
        $peopleIdVal = $getValByDb($row, 'people_id') ?? $getValByCsv($row, 'people_id');
        if ($peopleIdVal && is_numeric($peopleIdVal)) {
          $peopleId = (int)$peopleIdVal;
        }

        // (2) candidate_person_id provided
        if ($peopleId === null) {
          $candIdVal = $getValByDb($row, 'candidate_person_id');
          if ($candIdVal && is_numeric($candIdVal)) {
            $peopleId = (int)$candIdVal;
          }
        }

        // (3) Resolve from candidate names
        if ($peopleId === null) {
          $candFirst = $getValByDb($row, 'candidatename_first')
                    ?? $getValByDb($row, 'first_name')
                    ?? $getValByDb($row, 'first_name_s')
                    ?? $getValByDb($row, 'first')
                    ?? $getValByDb($row, 'first_names')
                    ?? $getValByDb($row, 'firstname')
                    ?? $getValByDb($row, 'given_name')
                    ?? $getValByCsv($row, 'candidatename_first')
                    ?? $getValByCsv($row, 'candidate_name_first')
                    ?? $getValByCsv($row, 'first_name')
                    ?? $getValByCsv($row, 'first_name_s')
                    ?? $getValByCsv($row, 'first')
                    ?? $getValByCsv($row, 'first_names')
                    ?? $getValByCsv($row, 'firstname')
                    ?? $getValByCsv($row, 'given_name');
          $candLast  = $getValByDb($row, 'candidatename_last')
                    ?? $getValByDb($row, 'last_name')
                    ?? $getValByDb($row, 'surname')
                    ?? $getValByDb($row, 'last')
                    ?? $getValByDb($row, 'lastname')
                    ?? $getValByDb($row, 'family_name')
                    ?? $getValByCsv($row, 'candidatename_last')
                    ?? $getValByCsv($row, 'candidate_name_last')
                    ?? $getValByCsv($row, 'last_name')
                    ?? $getValByCsv($row, 'surname')
                    ?? $getValByCsv($row, 'last')
                    ?? $getValByCsv($row, 'lastname')
                    ?? $getValByCsv($row, 'family_name');
          $electName = $getValByDb($row, 'electorate')          ?? $getValByDb($row, 'electorate_name') ?? $getValByCsv($row, 'electorate') ?? $getValByCsv($row, 'electorate_name');
          if ($candFirst && $candLast) {
            $peopleId = $get_or_create_person($candFirst, $candLast); // exact-create; avoids AI for admin imports
          }
        }

        // (4) Resolve via candidate_overview original_id if present in row
        // Detect common original_id columns in donations CSVs
        $originalId = $getValByDb($row, 'original_id');
        if ($originalId === null) {
          // Try known 2023 header directly (with mapping OR without)
          $c23 = $getValByDb($row, 'candidatedonations2023test_id')
             ?? $getValByCsv($row, 'candidatedonations2023test_id')
             ?? $getValByCsv($row, 'candidate_donations_2023_test_id');

          // Not used for linking but kept here for completeness/provenance if needed in future
          $pA  = $getValByDb($row, 'partadonationentry_id') ?? $getValByCsv($row, 'partadonationentry_id');

          // Pattern: _2011CandidateDonations_Id -> sanitized likely "2011candidatedonations_id"
          $autoOrigKey = null;
          foreach ($header_index as $hSan => $idx) {
            if (preg_match('/^\d{4}candidatedonations_id$/', $hSan)) {
              $autoOrigKey = $hSan;
              break;
            }
          }
          $origAuto = $autoOrigKey ? $getValByCsv($row, $autoOrigKey) : null;

          // As a final fallback, scan for any header that looks like a "candidate donations ... id"
          if ($c23 === null && $origAuto === null) {
            foreach (array_keys($header_index) as $hSan) {
              if (preg_match('/^candidate(_)?donations.*(test)?_id$/', $hSan)) {
                $origAuto = $getValByCsv($row, $hSan);
                if ($origAuto !== null && $origAuto !== '') break;
              }
            }
          }

          $originalId = $c23 ?: $origAuto ?: null;
        }

        // Attempt to find candidate_overview id and/or derive people_id from it
        [$candidateOverviewId, $coPeopleId, $coYear] = $find_candidate_overview($originalId, $csvYearHint ?: $year, $peopleId);
        if ($peopleId === null && $coPeopleId) $peopleId = (int)$coPeopleId;
        // Ensure year is populated: prefer parsed year; else coYear; else csvYearHint, otherwise skip row
        if (!$year || !preg_match('/^(2011|2014|2017|2020|2023)$/', (string)$year)) {
          if (!empty($coYear)) {
            $year = (string)$coYear;
          } elseif (!empty($csvYearHint)) {
            $year = (string)$csvYearHint;
          } else {
            $errors[] = "Row " . ($inserted + 1) . ": Missing year and unable to infer from filename or candidate_overview.";
            continue;
          }
        }

        if ($peopleId === null || $peopleId === 0) {
          $errors[] = "Row " . ($inserted + 1) . ": Unable to resolve candidate (people_id) from provided mapping.";
          continue;
        }

        // Final insert payload
        $cols = ['year','date','amount','money_or_goods_services','location','notes','donor_id','candidate_person_id','candidate_overview_id'];
        $vals = [
          $year,
          $date,
          $amountF,
          ($mos !== null && $mos !== '') ? $mos : null,
          $location,
          $notes,
          $donorId ?: null,
          $peopleId,
          $candidateOverviewId ?: null
        ];

        $colList = implode(',', array_map(fn($c) => "`$c`", $cols));
        $ph      = implode(',', array_fill(0, count($cols), '?'));
        $sql     = "INSERT INTO `" . str_replace('`', '``', $table) . "` ($colList) VALUES ($ph)";
        $stmt    = pdo()->prepare($sql);
        $stmt->execute($vals);
        $inserted++;
      } catch (Throwable $e) {
        $errors[] = "Row " . ($inserted + 1) . ": " . $e->getMessage();
        if (count($errors) >= 50) {
          // prevent flooding
          break;
        }
      }
    }

    pdo()->commit();
  } catch (Throwable $e) {
    pdo()->rollBack();
    $errors[] = "Transaction failed: " . $e->getMessage();
  } finally {
    fclose($fh);
  }

  return [
    'inserted' => $inserted,
    'errors' => $errors
  ];
}

/** Helper: get or create party by name (case-insensitive) */
function get_or_create_party_id(string $name): ?int {
  $name = trim($name);
  if ($name === '') return null;
  $stmt = pdo()->prepare('SELECT id FROM parties WHERE UPPER(name) = UPPER(?) LIMIT 1');
  $stmt->execute([$name]);
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) return (int)$row['id'];
  $ins = pdo()->prepare('INSERT INTO parties (name) VALUES (?)');
  $ins->execute([$name]);
  return (int)pdo()->lastInsertId();
}

/** Helper: get or create electorate by name (case-insensitive) */
function get_or_create_electorate_id(string $name): ?int {
  $name = trim($name);
  if ($name === '') return null;
  $stmt = pdo()->prepare('SELECT id FROM electorates WHERE UPPER(name) = UPPER(?) LIMIT 1');
  $stmt->execute([$name]);
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) return (int)$row['id'];
  $ins = pdo()->prepare('INSERT INTO electorates (name) VALUES (?)');
  $ins->execute([$name]);
  return (int)pdo()->lastInsertId();
}

/** Helper: get or create organization by name (case-insensitive) */
function get_or_create_organization_id(string $name): ?int {
  $name = trim($name);
  if ($name === '') return null;
  // Prefer matching on normalized_name when available
  $normalized = mb_strtoupper($name, 'UTF-8');
  // Try by normalized_name first if column exists
  $hasNorm = column_exists('organizations', 'normalized_name');
  if ($hasNorm) {
    $stmt = pdo()->prepare('SELECT id FROM organizations WHERE normalized_name = ? LIMIT 1');
    $stmt->execute([$normalized]);
    $row = $stmt->fetch();
    if ($row && isset($row['id'])) return (int)$row['id'];
  }
  // Fallback to name exact (case-insensitive)
  $stmt = pdo()->prepare('SELECT id FROM organizations WHERE UPPER(name) = UPPER(?) LIMIT 1');
  $stmt->execute([$name]);
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) return (int)$row['id'];

  // Insert
  try {
    if ($hasNorm) {
      $ins = pdo()->prepare('INSERT INTO organizations (name, normalized_name) VALUES (?, ?)');
      $ins->execute([$name, $normalized]);
    } else {
      $ins = pdo()->prepare('INSERT INTO organizations (name) VALUES (?)');
      $ins->execute([$name]);
    }
    return (int)pdo()->lastInsertId();
  } catch (Throwable $e) {
    // On unique violation, re-select by name
    $stmt = pdo()->prepare('SELECT id FROM organizations WHERE UPPER(name) = UPPER(?) LIMIT 1');
    $stmt->execute([$name]);
    $row = $stmt->fetch();
    return ($row && isset($row['id'])) ? (int)$row['id'] : null;
  }
}

/** Normalize CSV header keys: "First Name" -> "first_name", "CandidateName_First" -> "candidatename_first" */
function normalize_csv_header(string $s): string {
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/', '_', $s);
  $s = preg_replace('/_+/', '_', $s);
  return trim($s, '_');
}

/**
 * Candidate Overview import:
 * - CSV is expected to have columns for first/last name and optionally electorate and party.
 * - Resolves/creates people, resolves/creates parties/electorates by name.
 * - Inserts rows into candidate_overview with (people_id, party_id, electorate_id, year).
 */
function handle_candidate_overview_import(string $tmpPath, string $table, bool $truncate, int $defaultYear = 2023): array {
  // Open CSV
  $fh = @fopen($tmpPath, 'r');
  if ($fh === false) {
    return ['inserted' => 0, 'errors' => ['Failed to open CSV file']];
  }
  $header = fgetcsv($fh);
  if (!$header) {
    fclose($fh);
    return ['inserted' => 0, 'errors' => ['CSV must include a header row']];
  }

  // Build normalized header index
  $normIndex = [];
  foreach ($header as $i => $col) {
    $normIndex[normalize_csv_header((string)$col)] = $i;
  }
  $getVal = function(array $row, ?int $idx): string {
    if ($idx === null) return '';
    return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
  };
  $parseMoney = function(string $s): float {
    $s = trim($s);
    if ($s === '') return 0.0;
    $clean = preg_replace('/[$,]/', '', $s);
    return is_numeric($clean) ? (float)$clean : 0.0;
  };

  // Detect year from original_id header like "2011candidatedonations_id"
  $detectedYear = null;
  foreach (array_keys($normIndex) as $k) {
    if (preg_match('/^(\d{4})candidatedonations_id$/', $k, $m)) {
      $detectedYear = $m[1];
      break;
    }
  }

  // Resolve candidate-relevant indices with broad alias support (handles e.g. SURNAME, FIRST NAME(S))
  $resolveIndex = function(array $aliases) use ($normIndex) {
    foreach ($aliases as $a) {
      if (array_key_exists($a, $normIndex)) return $normIndex[$a];
    }
    return null;
  };
  $idxFirst = $resolveIndex(['candidatename_first','first_name','first','first_names','first_name_s','firstname','given_name','given_names']);
  $idxLast  = $resolveIndex(['candidatename_last','last_name','surname','last','last_names','lastname','family_name']);
  $idxElect = $resolveIndex(['electorate','electorate_name']);
  $idxParty = $resolveIndex(['party','party_name']);
  $idxYear  = $normIndex['year']                ?? null; // optional override per row

  // Totals indices (support multiple header variants e.g. "TOTAL DONATIONS", "TOTAL EXPENSES")
  $idxTotalDon = $normIndex['totaldonationsacd']                 ?? ($normIndex['total_donations'] ?? ($normIndex['totaldonations'] ?? null));
  $idxPartA    = $normIndex['totalparta']                        ?? null;
  $idxPartB    = $normIndex['totalpartb']                        ?? null;
  $idxPartC    = $normIndex['totalpartc']                        ?? null;
  $idxPartD    = $normIndex['totalpartd']                        ?? ($normIndex['totalpartdcalculated'] ?? ($normIndex['totalpartdcalculated2'] ?? null));
  $idxTotalExp = $normIndex['totalcandidateexpensespartsabcd']   ?? ($normIndex['totalexpensesfg'] ?? ($normIndex['totalexpenses'] ?? ($normIndex['total_expenses'] ?? null)));

  // original_id index (2011/2014/2017/2023 variants)
  $idxOriginalId = null;
  foreach (['candidatedonations2023test_id'] as $candKey) {
    if ($idxOriginalId === null && array_key_exists($candKey, $normIndex)) {
      $idxOriginalId = $normIndex[$candKey];
    }
  }
  if ($idxOriginalId === null) {
    foreach (array_keys($normIndex) as $k) {
      if (preg_match('/^\d{4}candidatedonations_id$/', $k)) {
        $idxOriginalId = $normIndex[$k];
        break;
      }
    }
  }

  if ($idxFirst === null || $idxLast === null || $idxElect === null || $idxParty === null) {
    fclose($fh);
    return ['inserted' => 0, 'errors' => ['CSV must include Candidate First/Last, Electorate, and Party columns']];
  }

  $inserted = 0;
  $errors = [];

  try {
    if ($truncate) {
      try {
        pdo()->exec("TRUNCATE TABLE `" . str_replace('`', '``', $table) . "`");
      } catch (Throwable $e) {
        // Fallback if TRUNCATE is not permitted due to FKs
        pdo()->exec("DELETE FROM `" . str_replace('`', '``', $table) . "`");
        try { pdo()->exec("ALTER TABLE `" . str_replace('`', '``', $table) . "` AUTO_INCREMENT = 1"); } catch (Throwable $ignore) {}
      }
    }

    pdo()->beginTransaction();

    $sql = "INSERT IGNORE INTO `" . str_replace('`', '``', $table) . "` 
      (people_id, party_id, electorate_id, part_a, part_b, part_c, part_d, total_donations, total_expenses, year, original_id)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = pdo()->prepare($sql);

    // Carry-forward context for rows that omit candidate details
    $carryFirst = '';
    $carryLast  = '';
    $carryParty = '';
    $carryElect = '';

    // Optional Gemini fallback to extract names from row text
    $geminiKey = get_gemini_api_key();
    $aiCalls = 0;
    $aiLimit = 50; // protect against overuse
    $aiExtractFirstLast = function(string $text) use ($geminiKey, &$aiCalls, $aiLimit): array {
      if (!$geminiKey || $aiCalls >= $aiLimit) return [null, null];
      $prompt = "Extract a candidate person's first and last name from the following CSV row. "
              . "Return exactly this JSON: {\"first_name\":\"\",\"last_name\":\"\"}. "
              . "If you cannot find a clear person name, return both fields empty. "
              . "Do not return organisation or address strings.\n\nRow: " . $text;

      try {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$geminiKey}";
    $payload = gemini_make_body($prompt);
        $payload = gemini_make_body($prompt);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $aiCalls++;
        if ($code === 200 && $resp) {
          $res = json_decode($resp, true);
          $textOut = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
          $textOut = preg_replace('/```\w*\n?/', '', $textOut);
          $data = json_decode(trim($textOut), true);
          if (is_array($data)) {
            $fn = isset($data['first_name']) ? trim((string)$data['first_name']) : '';
            $ln = isset($data['last_name']) ? trim((string)$data['last_name']) : '';
            return [$fn !== '' ? $fn : null, $ln !== '' ? $ln : null];
          }
        }
      } catch (Throwable $e) {
        // ignore
      }
      return [null, null];
    };

    while (($row = fgetcsv($fh)) !== false) {
      try {
        $first = $getVal($row, $idxFirst);
        $last  = $getVal($row, $idxLast);
        $electName = $getVal($row, $idxElect);
        $partyName = $getVal($row, $idxParty);
        $yearVal   = $getVal($row, $idxYear);
        $year = (is_numeric($yearVal) && strlen((string)$yearVal) === 4)
          ? (string)$yearVal
          : ($detectedYear ?: (string)$defaultYear);

        // Carry-forward values from previous candidate row (common pattern in these CSVs)
        if ($first === '' && $carryFirst !== '') $first = $carryFirst;
        if ($last  === '' && $carryLast  !== '') $last  = $carryLast;
        if ($partyName === '' && $carryParty !== '') $partyName = $carryParty;
        if ($electName === '' && $carryElect !== '') $electName = $carryElect;

        // If still missing first/last, try AI fallback to extract from the row text
        if ($first === '' || $last === '') {
          $rowText = implode(', ', array_map(static fn($c) => (string)$c, $row));
          [$aiFirst, $aiLast] = $aiExtractFirstLast($rowText);
          if ($first === '' && $aiFirst) $first = $aiFirst;
          if ($last  === '' && $aiLast)  $last  = $aiLast;
        }

        if ($first === '' || $last === '') {
          $errors[] = "Missing first/last name on row " . ($inserted + count($errors) + 1);
          continue;
        }

        // Resolve or create people
        $peopleId = resolve_candidate_person_id($first, $last, $electName);

        // Resolve or create party/electorate
        $partyId = $partyName !== '' ? get_or_create_party_id($partyName) : null;
        $electId = $electName !== '' ? get_or_create_electorate_id($electName) : null;

        // Totals
        $partA = $parseMoney($getVal($row, $idxPartA));
        $partB = $parseMoney($getVal($row, $idxPartB));
        $partC = $parseMoney($getVal($row, $idxPartC));
        $partD = $parseMoney($getVal($row, $idxPartD));
        $totDon = $parseMoney($getVal($row, $idxTotalDon));
        $totExp = $parseMoney($getVal($row, $idxTotalExp));

        // original_id
        $originalId = $idxOriginalId !== null ? $getVal($row, $idxOriginalId) : null;

        $stmt->execute([
          $peopleId, $partyId, $electId,
          $partA ?: null, $partB ?: null, $partC ?: null, $partD ?: null,
          $totDon, $totExp,
          $year,
          ($originalId !== '') ? $originalId : null
        ]);
        $inserted++;

        // Update carry-forward context using the most recent resolved candidate
        $carryFirst = $first;
        $carryLast  = $last;
        if ($partyName !== '') $carryParty = $partyName;
        if ($electName !== '') $carryElect = $electName;
      } catch (Throwable $e) {
        $errors[] = "Row " . ($inserted + count($errors) + 1) . ": " . $e->getMessage();
        if (count($errors) >= 50) {
          // Avoid flooding with errors
          break;
        }
      }
    }

    pdo()->commit();
  } catch (Throwable $e) {
    pdo()->rollBack();
    $errors[] = "Transaction failed: " . $e->getMessage();
  } finally {
    fclose($fh);
  }

  return ['inserted' => $inserted, 'errors' => $errors];
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

  // Special handling for candidate_overview import using name-to-ID resolution
  $isCandidateOverview = (stripos($table, 'candidate_overview') !== false);
  if ($isCandidateOverview) {
    // Infer default year from original filename if possible (e.g., "...2011...csv")
    $defaultYear = 2023;
    $origName = $_POST['orig_name'] ?? '';
    if (is_string($origName) && preg_match('/\b(2011|2014|2017|2020|2023)\b/', $origName, $m)) {
      $defaultYear = (int)$m[1];
    }
    $result = handle_candidate_overview_import($abs, $table, $truncate, $defaultYear);
    // Cleanup tmp
    @unlink($abs);

    $inserted = (int)($result['inserted'] ?? 0);
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

    render_admin([
      'upload_result' => true,
      'inserted' => $inserted,
      'table_shown' => $table,
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
    // Infer default year from filename, if present (helps link original_id when dates use placeholder years)
    $defaultYear = null;
    $origName = $_POST['orig_name'] ?? '';
    if (is_string($origName) && preg_match('/\b(2011|2014|2017|2020|2023)\b/', $origName, $m)) {
      $defaultYear = (int)$m[1];
    }
    $result = handle_donations_import_with_candidate_mapping($abs, $table, $finalMap, $truncate, $defaultYear, is_string($origName) ? $origName : null);
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
    // Map header index (sanitized and normalized lowercase)
    $idx = [];
    $idxNorm = [];
    foreach ($header as $i => $name) {
      $san = sanitize_table_name((string)$name);
      $idx[$san] = $i;
      $idxNorm[normalize_csv_header((string)$name)] = $i;
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
    // Special-case: importing into meetings requires minister_person_id (FK NOT NULL).
    // If user didn't map minister_person_id, compute it from CSV (ai_first_name/import_first_name or Minister)
    $isMeetings = (strtolower($table) === 'meetings');
    if ($isMeetings) {
      if (!in_array('minister_person_id', $dbCols, true)) {
        $dbCols[] = 'minister_person_id';
      }
      // Ensure time columns exist; values will be derived from Schedule Time if not explicitly mapped
      if (!in_array('start_time', $dbCols, true)) {
        $dbCols[] = 'start_time';
      }
      if (!in_array('end_time', $dbCols, true)) {
        $dbCols[] = 'end_time';
      }
      // Ensure attendees text is captured even if not explicitly mapped
      if (!in_array('with_text', $dbCols, true)) {
        $dbCols[] = 'with_text';
      }

      // Defensive: ensure required columns actually exist on the meetings table
      try {
        $qt = "`" . str_replace('`', '``', $table) . "`";
        pdo()->exec("ALTER TABLE {$qt} ADD COLUMN `with_text` TEXT NULL");
      } catch (Throwable $e) {
        // ignore if exists or permission denied
      }
      try {
        $qt = "`" . str_replace('`', '``', $table) . "`";
        pdo()->exec("ALTER TABLE {$qt} ADD COLUMN `start_time` TIME NULL");
      } catch (Throwable $e) {}
      try {
        $qt = "`" . str_replace('`', '``', $table) . "`";
        pdo()->exec("ALTER TABLE {$qt} ADD COLUMN `end_time` TIME NULL");
      } catch (Throwable $e) {}
    }

    // Prepare final SQL
    $placeholders = implode(', ', array_fill(0, count($dbCols), '?'));
    $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $dbCols));
    $sql = "INSERT IGNORE INTO `" . str_replace('`', '``', $table) . "` ($colList) VALUES ($placeholders)";
    $stmt = pdo()->prepare($sql);

    // Helpers for meetings import
    $normDate = function($v) {
      $v = trim((string)$v);
      if ($v === '') return null;
      $ts = strtotime($v);
      return $ts ? date('Y-m-d', $ts) : null;
    };
    $splitName = function($full) {
      $full = trim(preg_replace('/\s+/', ' ', (string)$full));
      if ($full === '') return [null, null];
      $parts = explode(' ', $full);
      if (count($parts) === 1) return [$parts[0], ''];
      return [array_shift($parts), implode(' ', $parts)];
    };

    $inserted = 0;
    while (($row = fgetcsv($fh)) !== false) {
      // Build value list aligned to $dbCols
      $vals = [];
      // Pre-read commonly used CSV values for meetings helpers
      $csv_ai_first = null;
      $csv_ai_last  = null;
      $csv_minister = null;
      $csv_date     = null;
      $derivedStart = null;
      $derivedEnd = null;

      if ($isMeetings) {
        // Case-insensitive and normalized header lookup
        $getHdr = function($keys) use ($idx, $idxNorm, $row) {
          foreach ((array)$keys as $k) {
            if (array_key_exists($k, $idx)) {
              $i = $idx[$k];
              return ($i !== null && array_key_exists($i, $row)) ? trim((string)$row[$i]) : null;
            }
            $kn = normalize_csv_header((string)$k);
            if (array_key_exists($kn, $idxNorm)) {
              $i = $idxNorm[$kn];
              return ($i !== null && array_key_exists($i, $row)) ? trim((string)$row[$i]) : null;
            }
          }
          return null;
        };
        $csv_ai_first = $getHdr(['ai_first_name','import_first_name']);
        $csv_ai_last  = $getHdr(['ai_last_name','import_last_name']);
        $csv_minister = $getHdr('Minister') ?? $getHdr('minister');
        // Attendees text (CSV header variants)
        // Some diaries use "With", others "Attendees" or similar
        // Also support AI-prepared files that use 'attendees_text'
        $csv_with = $getHdr(['attendees_text','With','with','Attendees','attendees','Attendee','attendee','Who','who']);
        // Optional AI names column produced by AI Name Finder "diaries" or Mapping Prep
        $csv_ai_attendees = $getHdr(['ai_person_names','attendees_names','ai_names']);

        // Helper to strip titles like "Rt Hon", "Hon", "Dr", "Sir", "Dame", "MP" from names
        $cleanName = function($name) {
          $name = preg_replace('/\b(Rt\.?\s*Hon\.?|Hon\.?|Dr|Sir|Dame|MP)\b/i', '', (string)$name);
          $name = preg_replace('/[(),]/', ' ', $name);
          return trim(preg_replace('/\s+/', ' ', $name));
        };

        // Parse "Schedule Time" into derived start/end times (e.g., "9:30 AM - 10:00 AM")
        $csv_schedule = $getHdr(['Schedule_Time','schedule_time','schedule time','time']);
        if ($csv_schedule) {
          // Normalize separators and whitespace (collapse newlines/tabs etc. inside the field)
          $csv_schedule = preg_replace('/\s+/u', ' ', (string)$csv_schedule);
          $rangeParts = preg_split('/\s*(?:-|–|—|to)\s*/i', trim($csv_schedule));
          $normalizeTime = function($t) {
            $t = trim((string)$t);
            if ($t === '') return null;
            // Accept formats like "9:30", "9.30", "9:30 AM"
            $t = preg_replace('/\./', ':', $t);
            if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(AM|PM|am|pm)?$/', $t, $m)) {
              return null;
            }
            $h = (int)($m[1] ?? 0);
            $min = (int)($m[2] ?? 0);
            $ampm = isset($m[3]) ? strtolower($m[3]) : null;
            if ($ampm === 'pm' && $h < 12) $h += 12;
            if ($ampm === 'am' && $h === 12) $h = 0;
            if ($h < 0 || $h > 23 || $min < 0 || $min > 59) return null;
            return sprintf('%02d:%02d:00', $h, $min);
          };
          if (count($rangeParts) >= 1) $derivedStart = $normalizeTime($rangeParts[0]);
          if (count($rangeParts) >= 2) $derivedEnd   = $normalizeTime($rangeParts[1]);
        }
      }
      $vals = [];
      foreach ($dbCols as $dbCol) {
        // Find csv col that maps to this dbCol (sanitized header name)
        $csvCol = array_search($dbCol, $finalMap, true);
        $i = ($csvCol !== false) ? ($idx[$csvCol] ?? null) : null;
        $rawVal = ($i !== null && array_key_exists($i, $row)) ? $row[$i] : null;

        // Special handling for meetings table
        if ($isMeetings) {
          if ($dbCol === 'date') {
            // Normalize date like 5/29/2024 to YYYY-MM-DD
            $vals[] = $normDate($rawVal);
            continue;
          }
          if ($dbCol === 'minister_person_id') {
            // Derive minister id from AI name columns or from the "Minister" field (case-insensitive header)
            $first = $csv_ai_first;
            $last  = $csv_ai_last;
            if ((!$first || !$last) && $csv_minister) {
              $mn = isset($cleanName) ? $cleanName($csv_minister) : $csv_minister;
              [$first, $last] = $splitName($mn);
            }
            if ($first && $last) {
              // Resolve or create person id
              $vals[] = resolve_candidate_person_id($first, $last, '');
            } else {
              // No minister available; keep NULL so INSERT IGNORE skips, avoiding FK violation
              $vals[] = null;
            }
            continue;
          }
          if ($dbCol === 'start_time') {
            // Prefer time parsed from "Schedule Time"
            $vals[] = isset($derivedStart) ? $derivedStart : null;
            continue;
          }
          if ($dbCol === 'end_time') {
            $vals[] = isset($derivedEnd) ? $derivedEnd : null;
            continue;
          }
          if ($dbCol === 'with_text') {
            // Populate attendees text from "With" column if present
            $vals[] = ($csv_with !== null && $csv_with !== '') ? $csv_with : null;
            // If AI attendees provided, opportunistically upsert people records for each name
            if ($csv_ai_attendees) {
              $names = preg_split('/[;,&]/', (string)$csv_ai_attendees);
              foreach ($names as $nm) {
                $nm = trim($nm);
                if ($nm === '' || preg_match('/^(attendees?|officials|event attendees)$/i', $nm)) continue;
                // Split "First Last" (fallback: first token as first, remainder as last)
                $parts = preg_split('/\s+/', $nm);
                if (!$parts || count($parts) === 0) continue;
                $first = array_shift($parts);
                $last  = implode(' ', $parts);
                if ($first !== '' && $last !== '') {
                  // Create if missing
                  try { resolve_candidate_person_id($first, $last, ''); } catch (Throwable $e) {}
                }
              }
            }
            continue;
          }
        }

        // Default behavior for other columns
        $vals[] = ($rawVal === '' ? null : $rawVal);
      }
      try {
        $stmt->execute($vals);
        // Count actual affected rows to avoid over-reporting when INSERT IGNORE skips duplicates
        $inserted += (int)$stmt->rowCount();
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

  // Compute post-insert row count to show accurate table size
  $postCount = null;
  try {
    $cntStmt = pdo()->query("SELECT COUNT(*) AS c FROM `" . str_replace('`', '``', $table) . "`");
    $postCount = (int)($cntStmt->fetch()['c'] ?? 0);
  } catch (Throwable $e) {
    $postCount = null;
  }

  render_admin([
    'upload_result' => true,
    'inserted' => $inserted,
    'post_count' => $postCount,
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
      // Truncate in FK-safe order: children first, then requested table
      [$truncated, $warn] = truncate_table_cascade($table);
      $msg = "Truncated tables: " . implode(', ', $truncated) . ".";
      if (!empty($warn)) {
        $msg .= " " . $warn;
      }
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

function handle_admin_backfill_2023(): void {
  require_token_admin();
  try {
    // Ensure staging table exists
    $exists = false;
    try {
      $stmt = pdo()->query("SHOW TABLES LIKE 'stg_overview_2023'");
      $exists = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
      // ignore
    }
    if (!$exists) {
      render_admin([
        'tables' => list_tables_with_counts(),
        'server_csvs' => find_server_csvs(),
        'table_action_msg' => "stg_overview_2023 not found. Import candidate_csv/2023_candidate_donations.csv into table 'stg_overview_2023' first (via Import CSVs from Server).",
        'table_action_err' => 1,
      ]);
      return;
    }

    $qCount = "SELECT COUNT(*) AS total, SUM(original_id IS NOT NULL) AS with_orig FROM candidate_overview WHERE year = 2023";
    $before = pdo()->query($qCount)->fetch();
    $bTotal = (int)($before['total'] ?? 0);
    $bWith  = (int)($before['with_orig'] ?? 0);

    $notes = [];

    // 1) Name + Party + Electorate (using sanitized staging column names)
    $sql1 = "UPDATE candidate_overview co
      JOIN people p      ON p.id = co.people_id
      JOIN parties pr    ON pr.id = co.party_id
      JOIN electorates el ON el.id = co.electorate_id
      JOIN stg_overview_2023 s
        ON UPPER(p.first_name) = UPPER(s.candidatename_first)
       AND UPPER(p.last_name)  = UPPER(s.candidatename_last)
       AND UPPER(pr.name)      = UPPER(s.party)
       AND UPPER(el.name)      = UPPER(s.electorate)
      SET co.original_id = s.candidatedonations2023test_id
      WHERE co.year = 2023
        AND co.original_id IS NULL
        AND s.candidatedonations2023test_id IS NOT NULL";
    $a1 = 0;
    try { $a1 = (int)pdo()->exec($sql1); } catch (Throwable $e) { $notes[] = "Step1: ".$e->getMessage(); }

    // 2) Names + Electorate
    $sql2 = "UPDATE candidate_overview co
      JOIN people p      ON p.id = co.people_id
      JOIN electorates el ON el.id = co.electorate_id
      JOIN stg_overview_2023 s
        ON UPPER(p.first_name) = UPPER(s.candidatename_first)
       AND UPPER(p.last_name)  = UPPER(s.candidatename_last)
       AND UPPER(el.name)      = UPPER(s.electorate)
      SET co.original_id = s.candidatedonations2023test_id
      WHERE co.year = 2023
        AND co.original_id IS NULL
        AND s.candidatedonations2023test_id IS NOT NULL";
    $a2 = 0;
    try { $a2 = (int)pdo()->exec($sql2); } catch (Throwable $e) { $notes[] = "Step2: ".$e->getMessage(); }

    // 3) Names + Party
    $sql3 = "UPDATE candidate_overview co
      JOIN people p   ON p.id = co.people_id
      JOIN parties pr ON pr.id = co.party_id
      JOIN stg_overview_2023 s
        ON UPPER(p.first_name) = UPPER(s.candidatename_first)
       AND UPPER(p.last_name)  = UPPER(s.candidatename_last)
       AND UPPER(pr.name)      = UPPER(s.party)
      SET co.original_id = s.candidatedonations2023test_id
      WHERE co.year = 2023
        AND co.original_id IS NULL
        AND s.candidatedonations2023test_id IS NOT NULL";
    $a3 = 0;
    try { $a3 = (int)pdo()->exec($sql3); } catch (Throwable $e) { $notes[] = "Step3: ".$e->getMessage(); }

    // 4) Names only (fallback)
    $sql4 = "UPDATE candidate_overview co
      JOIN people p ON p.id = co.people_id
      JOIN stg_overview_2023 s
        ON UPPER(p.first_name) = UPPER(s.candidatename_first)
       AND UPPER(p.last_name)  = UPPER(s.candidatename_last)
      SET co.original_id = s.candidatedonations2023test_id
      WHERE co.year = 2023
        AND co.original_id IS NULL
        AND s.candidatedonations2023test_id IS NOT NULL";
    $a4 = 0;
    try { $a4 = (int)pdo()->exec($sql4); } catch (Throwable $e) { $notes[] = "Step4: ".$e->getMessage(); }

    $after = pdo()->query($qCount)->fetch();
    $aWith = (int)($after['with_orig'] ?? 0);

    $msg = "Backfill 2023 original_id complete. Before with_orig={$bWith}/{$bTotal}. "
         . "Steps updated rows: [{$a1}, {$a2}, {$a3}, {$a4}]. "
         . "After with_orig={$aWith}/{$bTotal}.";
    if (!empty($notes)) {
      $msg .= " Notes: " . implode(' | ', $notes);
    }

    render_admin([
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
      'table_action_msg' => $msg,
      'table_action_err' => 0,
    ]);
  } catch (Throwable $e) {
    render_admin([
      'tables' => list_tables_with_counts(),
      'server_csvs' => find_server_csvs(),
      'table_action_msg' => "Backfill failed: " . $e->getMessage(),
      'table_action_err' => 1,
    ]);
  }
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
    'organizations' => ['id', 'name', 'normalized_name', 'created_at', 'updated_at'],
    'donors' => ['id', 'first_name', 'last_name', 'org_name', 'normalized_name', 'organization_id', 'created_at', 'updated_at'],
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

  // Helpers
  $norm_ws = function($s) {
    $s = (string)($s ?? '');
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  };

  // Remove titles, parentheses, and stray punctuation; keep hyphens and dots in names
  $cleanName = function(string $name) use ($norm_ws) {
    $n = $norm_ws($name);
    if ($n === '') return null;

    // Strip quotes and bracketed info
    $n = trim($n, "\"'");
    $n = preg_replace('/\([^)]*\)/', ' ', $n);

    // Titles and honorifics commonly present in diaries
    $n = preg_replace('/\b(Rt\.?\s*Hon\.?|Rt\s*Hon|Hon\.?|Dr|Sir|Dame|Councillor|Minister|MP)\b\.?/i', '', $n);

    // Replace commas/parentheses with spaces, normalize hyphen spacing
    $n = preg_replace('/[(),]/', ' ', $n);
    $n = preg_replace('/\s*-\s*/', '-', $n);
    $n = $norm_ws($n);

    // Filter obvious non-person tokens (roles, groups, acronyms, org words)
    $bad = [
      'attendees','attendee','event attendees',
      'officials','official',
      'representatives','representative',
      'delegation','members','committee','committee members',
      'chair','co-chair','chairperson',
      'ce','ceo','cfo','cto','gm','director','secretary','advisor','advisors',
      'department','ministry','council','association','university','board','professor','prof','press','media','news',
      'minister','ministers','mp','mlc',
      'ministers & officials','ministers and officials'
    ];
    if (in_array(mb_strtolower($n, 'UTF-8'), $bad, true)) return null;

    // Expect at least two tokens
    $parts = explode(' ', $n);
    if (count($parts) < 2) return null;

    // Validate tokens (letters, apostrophes, dots, hyphens)
    foreach ($parts as $p) {
      if (!preg_match("/^[A-Za-z][A-Za-z'.-]*$/", $p)) return null;
    }

    return $n;
  };

  $splitPossibleNames = function(string $text) use ($norm_ws, $cleanName) {
    $t = $norm_ws($text);
    if ($t === '') return [];

    // Normalize common separators to semicolon
    $t = preg_replace('/\s+(?:&|and)\s+/i', ';', $t);
    $pieces = preg_split('/[;|]/', $t);
    $out = [];

    foreach ($pieces as $piece) {
      $piece = $norm_ws($piece);
      if ($piece === '') continue;

      // If there are many commas, split further on commas
      $subs = [ $piece ];
      if (substr_count($piece, ',') >= 2) {
        $subs = array_filter(array_map($norm_ws, explode(',', $piece)));
      }

      foreach ($subs as $sp) {
        $nm = $cleanName($sp);
        if ($nm) $out[] = $nm;
      }
    }

    return $out;
  };

  // Unique set helper (case-insensitive)
  $namesSet = [];
  $addName = function($n) use (&$namesSet) {
    $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($n)), 'UTF-8');
    if ($key !== '') $namesSet[$key] = trim($n);
  };

  // Detect CSV quickly
  $filename = $_FILES['file']['name'] ?? '';
  $looksCsv = (is_string($filename) && preg_match('/\.csv$/i', $filename)) ||
              (strpos($content, ',') !== false && preg_match('/\r?\n/', $content));

  // Optional: force Gemini-only extraction via ?gemini_only=1 or form field
  $forceGemini = false;
  $rq = array_merge($_GET ?? [], $_POST ?? []);
  if (isset($rq['gemini_only'])) {
    $v = strtolower(trim((string)$rq['gemini_only']));
    $forceGemini = ($v === '1' || $v === 'true' || $v === 'yes' || $v === 'on');
  }

  if ($forceGemini) {
    // Build text for AI (prefer likely name-bearing columns for CSVs)
    $aiText = $content;

    // Chunk and call Gemini
    $apiKey = get_gemini_api_key();
    if (!$apiKey) {
      json_response(['error' => 'Gemini API key not found'], 500);
    }

    $chunks = [];
    $start = 0;
    $limit = 8000;
    $len = strlen($aiText);
    while ($start < $len) {
      $end = min($len, $start + $limit);
      $chunks[] = substr($aiText, $start, $end - $start);
      $start = $end;
    }

    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    foreach ($chunks as $chunk) {
      $instruction = 'Extract ONLY human person names from the following text. '
        . 'Return STRICTLY VALID JSON as: {"names": ["First Last", "..."]} with no markdown or explanation. '
        . "Text:\n" . $chunk;

      $payload = gemini_make_body($instruction);

      $ch = curl_init($endpoint);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
      curl_setopt($ch, CURLOPT_TIMEOUT, 20);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($code === 200 && $resp) {
        $res = json_decode($resp, true);
        $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = preg_replace('/```\w*\n?/', '', (string)$text);
        $data = json_decode(trim((string)$text), true);
        $aiNames = [];
        if (is_array($data) && isset($data['names']) && is_array($data['names'])) {
          $aiNames = $data['names'];
        } elseif (is_array($data)) {
          $aiNames = $data;
        }
        foreach ($aiNames as $nm) {
          if (!is_string($nm) && !is_numeric($nm)) continue;
          $cn = $cleanName((string)$nm);
          if ($cn) $addName($cn);
        }
      }
    }

    // Final unique, case-insensitive ordered list
    ksort($namesSet, SORT_NATURAL | SORT_FLAG_CASE);
    $final = array_values($namesSet);

    json_response([
      'names' => $final,
      'count' => count($final),
      'file_name' => $filename
    ]);
  }

  if ($looksCsv) {
    // Parse CSV with quoted newline support using php://memory
    $mem = fopen('php://memory', 'r+');
    fwrite($mem, $content);
    rewind($mem);

    $header = fgetcsv($mem);
    if ($header && is_array($header)) {
      // Build header maps (raw and normalized)
      $norm = fn($s) => normalize_csv_header((string)$s);
      $rawToIdx = [];
      $normToIdx = [];
      foreach ($header as $i => $h) {
        $raw = trim((string)$h);
        $rawToIdx[$raw] = $i;
        $normToIdx[$norm($raw)] = $i;
      }

      // Candidate columns likely to contain names
      $candidateNorms = [
        'attendees_names','attendeesnames','names',
        'attendees_text','attendeestext','attendees','with','who','attendee','minister'
      ];
      $candidateIdx = [];
      foreach ($candidateNorms as $ck) {
        if (array_key_exists($ck, $normToIdx)) {
          $candidateIdx[] = $normToIdx[$ck];
        }
      }
      // Always include last column if it's likely to be "Attendees Names"
      if (!empty($header)) {
        $lastRaw = trim(end($header));
        $lastNorm = $norm($lastRaw);
        if (in_array($lastNorm, ['attendees_names','attendeesnames']) && !in_array(array_key_last($header), $candidateIdx, true)) {
          $candidateIdx[] = array_key_last($header);
        }
      }
      $candidateIdx = array_values(array_unique(array_filter($candidateIdx, fn($v) => $v !== null)));

      // Read rows and extract names from candidate columns
      while (($row = fgetcsv($mem)) !== false) {
        foreach ($candidateIdx as $ci) {
          $val = isset($row[$ci]) ? $row[$ci] : null;
          if ($val === null || $val === '') continue;
          foreach ($splitPossibleNames((string)$val) as $nm) {
            $addName($nm);
          }
        }
      }
      fclose($mem);

      // If very few names found, broaden: scan all cells
      if (count($namesSet) < 5) {
        $mem2 = fopen('php://memory', 'r+');
        fwrite($mem2, $content);
        rewind($mem2);
        // skip header
        fgetcsv($mem2);
        while (($row = fgetcsv($mem2)) !== false) {
          foreach ($row as $cell) {
            foreach ($splitPossibleNames((string)$cell) as $nm) {
              $addName($nm);
            }
          }
        }
        fclose($mem2);
      }
    } else {
      fclose($mem);
    }
  } else {
    // Not a CSV: try splitting raw text heuristically
    foreach ($splitPossibleNames($content) as $nm) {
      $addName($nm);
    }
  }

  // Always perform AI extraction when a Gemini key is available (deterministic), so both modes align
  $aiSet = [];
  $apiKey = get_gemini_api_key();
  if ($apiKey) {
    $aiText = $content; // use full original text for consistency across modes

    // Chunk text to ~8k chars per request
    $chunks = [];
    $start = 0;
    $limit = 8000;
    $len = strlen($aiText);
    while ($start < $len) {
      $end = min($len, $start + $limit);
      $chunks[] = substr($aiText, $start, $end - $start);
      $start = $end;
    }

    $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";
    foreach ($chunks as $chunk) {
      $instruction = 'Extract ONLY human person names from the following text. '
        . 'Return STRICTLY VALID JSON as: {"names": ["First Last", "..."]} with no markdown or explanation. '
        . "Text:\n" . $chunk;

      $payload = gemini_make_body($instruction);

      $ch = curl_init($endpoint);
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
      curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
      curl_setopt($ch, CURLOPT_TIMEOUT, 20);
      $resp = curl_exec($ch);
      $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);

      if ($code === 200 && $resp) {
        $res = json_decode($resp, true);
        $text = $res['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $text = preg_replace('/```\w*\n?/', '', (string)$text);
        $data = json_decode(trim((string)$text), true);
        $aiNames = [];
        if (is_array($data) && isset($data['names']) && is_array($data['names'])) {
          $aiNames = $data['names'];
        } elseif (is_array($data)) {
          $aiNames = $data;
        }
        foreach ($aiNames as $nm) {
          if (!is_string($nm) && !is_numeric($nm)) continue;
          $cn = $cleanName((string)$nm);
          if ($cn) {
            $key = mb_strtolower(preg_replace('/\s+/', ' ', trim($cn)), 'UTF-8');
            if ($key !== '') $aiSet[$key] = $cn;
          }
        }
      }
    }
  }

  // Final unique, case-insensitive ordered list
  // Prefer AI-derived names when available to align counts with "Gemini-only" mode
  $baseSet = !empty($aiSet) ? $aiSet : $namesSet;
  ksort($baseSet, SORT_NATURAL | SORT_FLAG_CASE);
  $final = array_values($baseSet);

  json_response([
    'names' => $final,
    'count' => count($final),
    'file_name' => $filename
  ]);
}

/** Add a new person and return their generated ID.
 * POST /people
 * Body (JSON or form-encoded):
 *   - first_name (required)
 *   - last_name  (required)
 *   - electorate_name (optional)
 * Behavior:
 *   - If an exact match (case-insensitive) exists, returns that id with created=false
 *   - Otherwise inserts a new row and returns the new id with created=true
 */
/** AI Name Extraction for Ministerial Diaries CSV
 * POST /ai/extract-names-diaries
 * Upload a diaries CSV with headers:
 *   Minister, Date or Date Started, Date Finished, Schedule Time, Type, Meeting, Location, With, Portfolio
 * Returns JSON with enriched rows and attendees_names flagged by AI.
 */
function handle_ai_extract_names_diaries(): void {
  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_response(['error' => 'No file uploaded'], 400);
  }

  $content = file_get_contents($_FILES['file']['tmp_name']);
  if ($content === false) {
    json_response(['error' => 'Error reading file'], 400);
  }

  if (strlen($content) > 1024 * 1024) {
    json_response(['error' => 'File too large (max 1MB)'], 400);
  }

  // Parse CSV with quoted newlines support using php://memory
  $mem = fopen('php://memory', 'r+');
  fwrite($mem, $content);
  rewind($mem);

  $header = fgetcsv($mem);
  if (!$header) {
    fclose($mem);
    json_response(['error' => 'CSV must include a header row'], 400);
  }

  // Build header index (exact match to expected headers)
  $idx = [];
  foreach ($header as $i => $h) {
    $idx[trim((string)$h)] = $i;
  }

  $H_MINISTER = 'Minister';
  $H_DATE = 'Date or Date Started';
  $H_DATE_FIN = 'Date Finished';
  $H_TIME = 'Schedule Time';
  $H_TYPE = 'Type';
  $H_TITLE = 'Meeting';
  $H_LOCATION = 'Location';
  $H_WITH = 'With';
  $H_PORTFOLIO = 'Portfolio';

  $get = function(array $row, string $key) use ($idx) {
    if (!array_key_exists($key, $idx)) return null;
    $i = $idx[$key];
    return isset($row[$i]) ? (string)$row[$i] : null;
  };

  $norm_ws = function($s) {
    $s = (string)($s ?? '');
    // Collapse any whitespace (including newlines) to single spaces
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
  };

  $extract_times = function($s) use ($norm_ws) {
    $t = $norm_ws($s);
    if ($t === '') return [null, null];
    if (preg_match('/(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm))\s*-\s*(\d{1,2}:\d{2}\s*(?:AM|PM|am|pm))/', $t, $m)) {
      $a = strtoupper(trim(preg_replace('/\s+/', ' ', $m[1])));
      $b = strtoupper(trim(preg_replace('/\s+/', ' ', $m[2])));
      return [$a, $b];
    }
    return [null, null];
  };

  $rows_raw = [];
  while (($row = fgetcsv($mem)) !== false) {
    $rows_raw[] = $row;
  }
  fclose($mem);

  if (empty($rows_raw)) {
    json_response(['error' => 'CSV contains no data rows'], 400);
  }

  $enriched = [];
  $ai_items = []; // [ [id, text], ... ]
  foreach ($rows_raw as $i => $r) {
    $date = $norm_ws($get($r, $H_DATE));
    $title = $norm_ws($get($r, $H_TITLE));
    $type = $norm_ws($get($r, $H_TYPE));
    $portfolio = $norm_ws($get($r, $H_PORTFOLIO));
    $location = $norm_ws($get($r, $H_LOCATION));
    $with = $norm_ws($get($r, $H_WITH));
    $minister = $norm_ws($get($r, $H_MINISTER));
    [$start, $end] = $extract_times($get($r, $H_TIME));

    $enriched[] = [
      'row_index' => $i,
      'minister' => $minister !== '' ? $minister : null,
      'date' => $date !== '' ? $date : null,
      'start_time' => $start,
      'end_time' => $end,
      'title' => $title !== '' ? $title : null,
      'type' => $type !== '' ? $type : null,
      'portfolio' => $portfolio !== '' ? $portfolio : null,
      'location' => $location !== '' ? $location : null,
      'notes' => '',
      'attendees_text' => $with !== '' ? $with : ''
    ];

    $textForAi = trim(implode(' | ', array_filter([$with, $title], fn($x) => $x !== '')));
    $ai_items[] = [$i, $textForAi];
  }

  $apiKey = get_gemini_api_key();
  if (!$apiKey) {
    // Return without attendees_names if no API key
    foreach ($enriched as &$row) {
      $row['attendees_names'] = [];
    }
    unset($row);
    json_response([
      'file_name' => $_FILES['file']['name'],
      'count_rows' => count($enriched),
      'rows' => $enriched,
      'warning' => 'Gemini API key not found; attendees_names left empty.'
    ]);
  }

  // Chunk AI requests to keep prompt sizes reasonable
  $chunks = [];
  $current = [];
  $curLen = 0;
  foreach ($ai_items as [$rid, $text]) {
    $tlen = strlen($text);
    if (!empty($current) && ($curLen + $tlen) > 8000) {
      $chunks[] = $current;
      $current = [];
      $curLen = 0;
    }
    $current[] = [$rid, $text];
    $curLen += $tlen;
  }
  if (!empty($current)) $chunks[] = $current;

  $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

  $call_chunk = function(array $chunk) use ($endpoint) {
    $payloadRows = [];
    foreach ($chunk as [$rid, $text]) {
      $payloadRows[] = ['id' => $rid, 'text' => $text];
    }

    $instruction = "You are given a JSON object with an array named 'rows'. Each item has 'id' and 'text' fields "
      . "(from ministerial diary attendees or meeting descriptions). "
      . "For EACH item, extract ONLY human person names (no organizations, roles, titles-only, acronyms, departments, or locations). "
      . "Return STRICTLY VALID JSON in this exact form with no extra text or markdown:\n"
      . "{\"results\":[{\"id\": <id>, \"names\": [\"First Last\", ...]}, ...]}\n"
      . "Rules:\n"
      . "- Only include people names. Exclude ministries, committees, departments, companies, acronyms (e.g., 'MPI', 'FSANZ'), and role words (e.g., 'Officials', 'Ministers', 'Attendees').\n"
      . "- Keep names as plain strings; do not include roles or parentheses. If uncertain, omit.\n"
      . "- If no person names for an item, use an empty array for that item.\n"
      . "- Preserve original spelling as best as possible.\n"
      . "- Do NOT include any explanation or markdown. JSON only.\n"
      . "rows:\n"
      . json_encode(['rows' => $payloadRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $body = gemini_make_body($instruction);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $default = [];
    foreach ($chunk as [$rid, $_]) $default[$rid] = [];

    if ($code !== 200 || !$resp) return $default;

    $result = json_decode($resp, true);
    if (!is_array($result) || empty($result['candidates'][0]['content']['parts'][0]['text'])) {
      return $default;
    }

    $text = $result['candidates'][0]['content']['parts'][0]['text'];
    // Clean markdown code blocks
    $text = preg_replace('/```\w*\n?/', '', $text);
    $text = trim((string)$text);

    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
      return $default;
    }

    $out = [];
    foreach ($data['results'] as $item) {
      if (!is_array($item)) continue;
      $rid = $item['id'] ?? null;
      $names = $item['names'] ?? [];
      if ($rid === null || !is_array($names)) continue;
      $norm = [];
      foreach ($names as $n) {
        if (is_string($n) || is_numeric($n)) {
          $norm[] = (string)$n;
        }
      }
      $out[$rid] = $norm;
    }

    foreach ($chunk as [$rid, $_]) {
      if (!array_key_exists($rid, $out)) $out[$rid] = [];
    }
    return $out;
  };

  $idToNames = [];
  foreach ($chunks as $ch) {
    try {
      $res = $call_chunk($ch);
      foreach ($res as $rid => $names) {
        $idToNames[$rid] = $names;
      }
    } catch (Throwable $e) {
      foreach ($ch as [$rid, $_]) $idToNames[$rid] = [];
    }
  }

  foreach ($enriched as &$row) {
    $rid = $row['row_index'];
    $row['attendees_names'] = $idToNames[$rid] ?? [];
  }
  unset($row);

  json_response([
    'file_name' => $_FILES['file']['name'],
    'count_rows' => count($enriched),
    'rows' => $enriched
  ]);
}

/** AI Mapping Prep for arbitrary "unclean" CSVs
 * POST /ai/prepare-mapping-csv
 * Upload any CSV; AI will flag likely person names and organizations for easy mapping.
 * Output JSON contains headers_out (original headers + AI columns) and rows_out (array-of-arrays),
 * so the frontend can generate a prepared CSV to upload into Admin → Add Data and map columns.
 *
 * Appended columns:
 *   - ai_first_name
 *   - ai_last_name
 *   - ai_org_name
 *   - ai_person_names   (semicolon-separated list)
 *   - ai_orgs           (semicolon-separated list)
 */
function handle_ai_prepare_mapping_csv(): void {
  if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    json_response(['error' => 'No file uploaded'], 400);
  }

  $content = file_get_contents($_FILES['file']['tmp_name']);
  if ($content === false) {
    json_response(['error' => 'Error reading file'], 400);
  }

  if (strlen($content) > 1024 * 1024) {
    json_response(['error' => 'File too large (max 1MB)'], 400);
  }

  // Parse CSV to header + rows
  $mem = fopen('php://memory', 'r+');
  fwrite($mem, $content);
  rewind($mem);

  $header = fgetcsv($mem);
  if (!$header) {
    fclose($mem);
    json_response(['error' => 'CSV must include a header row'], 400);
  }

  $rows_raw = [];
  while (($row = fgetcsv($mem)) !== false) {
    $rows_raw[] = $row;
  }
  fclose($mem);

  $appendCols = ['ai_first_name', 'ai_last_name', 'ai_org_name', 'ai_person_names', 'ai_orgs'];
  $headers_out = array_merge($header, $appendCols);

  // If no AI key, return passthrough with empty AI columns
  $apiKey = get_gemini_api_key();
  if (!$apiKey) {
    $rows_out = [];
    foreach ($rows_raw as $r) {
      $rows_out[] = array_merge($r, ['', '', '', '', '']);
    }
    json_response([
      'file_name' => $_FILES['file']['name'],
      'count_rows' => count($rows_out),
      'headers_out' => $headers_out,
      'rows_out' => $rows_out,
      'warning' => 'Gemini API key not found; AI columns left empty.'
    ]);
  }

  // Build AI items (id + concatenated row text)
  $ai_items = []; // [ [id, text], ... ]
  foreach ($rows_raw as $i => $r) {
    // Join all field values; collapse whitespace
    $joined = trim(preg_replace('/\s+/u', ' ', implode(', ', array_map(static fn($v) => (string)$v, $r))));
    $ai_items[] = [$i, $joined];
  }

  // Chunk rows to keep prompt size reasonable
  $chunks = [];
  $current = [];
  $curLen = 0;
  foreach ($ai_items as [$rid, $text]) {
    $tlen = strlen($text);
    if (!empty($current) && ($curLen + $tlen) > 8000) {
      $chunks[] = $current;
      $current = [];
      $curLen = 0;
    }
    $current[] = [$rid, $text];
    $curLen += $tlen;
  }
  if (!empty($current)) $chunks[] = $current;

  $endpoint = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$apiKey}";

  $call_chunk = function(array $chunk) use ($endpoint) {
    // Prepare payload rows
    $payloadRows = [];
    foreach ($chunk as [$rid, $text]) {
      $payloadRows[] = ['id' => $rid, 'text' => $text];
    }

    $instruction = "You are given a JSON object with an array named 'rows'. Each item has 'id' and 'text' fields "
      . "(from an arbitrary CSV row concatenated into one string). "
      . "For EACH item, extract the following fields and return STRICTLY VALID JSON with NO extra text/markdown:\n"
      . "{\"results\":[{\"id\": <id>, \"top_person_first\":\"\", \"top_person_last\":\"\", \"org\":\"\", \"names\": [\"First Last\", ...], \"orgs\": [\"...\"]}, ...]}\n"
      . "Rules:\n"
      . "- names: ONLY human person names (exclude ministries, departments, committees, companies, acronyms, roles like 'Officials', 'Attendees').\n"
      . "- top_person_first/last: best single person from the row, split into first and last name. Leave empty strings if none.\n"
      . "- org/orgs: Companies/organizations mentioned (no people). Leave empty string / empty array if none.\n"
      . "- Keep original spelling as best as possible.\n"
      . "rows:\n"
      . json_encode(['rows' => $payloadRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $body = gemini_make_body($instruction);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $default = [];
    foreach ($chunk as [$rid, $_]) {
      $default[$rid] = [
        'top_person_first' => '',
        'top_person_last' => '',
        'org' => '',
        'names' => [],
        'orgs' => []
      ];
    }

    if ($code !== 200 || !$resp) return $default;

    $result = json_decode($resp, true);
    if (!is_array($result) || empty($result['candidates'][0]['content']['parts'][0]['text'])) {
      return $default;
    }

    $text = $result['candidates'][0]['content']['parts'][0]['text'];
    // Clean markdown code blocks
    $text = preg_replace('/```\w*\n?/', '', (string)$text);
    $text = trim((string)$text);

    $data = json_decode($text, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
      return $default;
    }

    $out = [];
    foreach ($data['results'] as $item) {
      if (!is_array($item)) continue;
      $rid = $item['id'] ?? null;
      if ($rid === null) continue;
      $tp_first = isset($item['top_person_first']) && is_string($item['top_person_first']) ? $item['top_person_first'] : '';
      $tp_last  = isset($item['top_person_last']) && is_string($item['top_person_last']) ? $item['top_person_last'] : '';
      $org      = isset($item['org']) && is_string($item['org']) ? $item['org'] : '';
      $names    = isset($item['names']) && is_array($item['names']) ? array_values(array_filter(array_map('strval', $item['names']), static fn($s) => $s !== '')) : [];
      $orgs     = isset($item['orgs'])  && is_array($item['orgs'])  ? array_values(array_filter(array_map('strval', $item['orgs']), static fn($s) => $s !== '')) : [];
      $out[$rid] = [
        'top_person_first' => $tp_first,
        'top_person_last' => $tp_last,
        'org' => $org,
        'names' => $names,
        'orgs' => $orgs
      ];
    }

    // Ensure all ids appear
    foreach ($chunk as [$rid, $_]) {
      if (!array_key_exists($rid, $out)) {
        $out[$rid] = $default[$rid];
      }
    }
    return $out;
  };

  $ai_results = [];
  foreach ($chunks as $ch) {
    try {
      $res = $call_chunk($ch);
      foreach ($res as $rid => $vals) {
        $ai_results[$rid] = $vals;
      }
    } catch (Throwable $e) {
      // Default empty on error
      foreach ($ch as [$rid, $_]) {
        $ai_results[$rid] = [
          'top_person_first' => '',
          'top_person_last' => '',
          'org' => '',
          'names' => [],
          'orgs' => []
        ];
      }
    }
  }

  // Build rows_out (array-of-arrays in header order)
  $rows_out = [];
  foreach ($rows_raw as $i => $r) {
    $ai = $ai_results[$i] ?? [
      'top_person_first' => '',
      'top_person_last' => '',
      'org' => '',
      'names' => [],
      'orgs' => []
    ];
    $ai_first = (string)($ai['top_person_first'] ?? '');
    $ai_last  = (string)($ai['top_person_last'] ?? '');
    $ai_org   = (string)($ai['org'] ?? '');
    $namesStr = implode('; ', (array)($ai['names'] ?? []));
    $orgsStr  = implode('; ', (array)($ai['orgs'] ?? []));
    $rows_out[] = array_merge($r, [$ai_first, $ai_last, $ai_org, $namesStr, $orgsStr]);
  }

  json_response([
    'file_name' => $_FILES['file']['name'],
    'count_rows' => count($rows_out),
    'headers_out' => $headers_out,
    'rows_out' => $rows_out
  ]);
}

function handle_add_person(): void {
  // Support JSON or form POST
  $input = null;
  $ct = $_SERVER['CONTENT_TYPE'] ?? '';
  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: 'null', true);
  }
  $first = trim((string)($input['first_name'] ?? ($_POST['first_name'] ?? '')));
  $last  = trim((string)($input['last_name'] ?? ($_POST['last_name'] ?? '')));
  $elect = trim((string)($input['electorate_name'] ?? ($_POST['electorate_name'] ?? '')));

  if ($first === '' || $last === '') {
    json_response(['error' => 'first_name and last_name are required'], 400);
  }

  // Check for existing exact match (case-insensitive)
  $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?) LIMIT 1');
  $stmt->execute([$first, $last]);
  $row = $stmt->fetch();
  if ($row && isset($row['id'])) {
    json_response([
      'id' => (int)$row['id'],
      'created' => false
    ], 200);
  }

  // Insert new record; assumes people.id is AUTO_INCREMENT
  $stmt = pdo()->prepare('INSERT INTO people (first_name, last_name) VALUES (?, ?)');
  $stmt->execute([$first, $last]);
  $newId = (int)pdo()->lastInsertId();

  json_response([
    'id' => $newId,
    'created' => true
  ], 201);
}

/** Donations by person (with donors) */
function handle_donations_by_person(): void {
  $first = $_GET['first_name'] ?? null;
  $last  = $_GET['last_name'] ?? null;
  $peopleIdParam = $_GET['people_id'] ?? null;
  $yearsParam = $_GET['years'] ?? null; // comma-separated e.g. 2011,2014,2017,2020,2023

  if (!$peopleIdParam && (!$first && !$last)) {
    json_response(['error' => 'Provide either people_id or at least one of first_name or last_name'], 400);
  }

  // Resolve person id(s)
  $peopleIds = [];
  if ($peopleIdParam && is_numeric($peopleIdParam)) {
    $peopleIds = [(int)$peopleIdParam];
  } else {
    // Try exact match first
    $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?) LIMIT 1');
    $stmt->execute([$first, $last]);
    $row = $stmt->fetch();
    if ($row && isset($row['id'])) {
      $peopleIds = [(int)$row['id']];
    } else {
      // Fallback to partial case-insensitive match (more forgiving)
      $stmt = pdo()->prepare('SELECT id FROM people WHERE UPPER(first_name) LIKE UPPER(?) AND UPPER(last_name) LIKE UPPER(?) LIMIT 50');
      $stmt->execute(['%' . $first . '%', '%' . $last . '%']);
      $rows = $stmt->fetchAll();
      foreach ($rows as $r) {
        if (isset($r['id'])) $peopleIds[] = (int)$r['id'];
      }
      if (empty($peopleIds)) {
        json_response([]); // no person candidates -> no donations
      }
    }
  }

  // Parse and validate years filter if provided
  $years = null;
  if (is_string($yearsParam) && trim($yearsParam) !== '') {
    $parts = array_filter(array_map('trim', explode(',', $yearsParam)), static fn($y) => $y !== '');
    $valid = [];
    foreach ($parts as $y) {
      if (preg_match('/^(2011|2014|2017|2020|2023)$/', $y)) {
        $valid[] = $y;
      }
    }
    if (!empty($valid)) {
      $years = $valid;
    }
  }

  // Build SQL (support 1 or many candidate_person_id values)
  $params = [];
  $sql = "SELECT 
            d.id,
            d.year,
            d.date,
            d.amount,
            d.money_or_goods_services,
            d.notes,
            d.location,
            d.donor_id,
            dr.first_name AS donor_first_name,
            dr.last_name  AS donor_last_name,
            dr.org_name   AS donor_org_name
          FROM donations d
          LEFT JOIN donors dr ON dr.id = d.donor_id
          WHERE ";

  if (count($peopleIds) === 1) {
    $sql .= "d.candidate_person_id = ?";
    $params[] = $peopleIds[0];
  } else {
    $in = implode(',', array_fill(0, count($peopleIds), '?'));
    $sql .= "d.candidate_person_id IN ($in)";
    foreach ($peopleIds as $pid) $params[] = $pid;
  }

  if ($years && count($years) > 0) {
    $inYears = implode(',', array_fill(0, count($years), '?'));
    $sql .= " AND d.year IN ($inYears)";
    foreach ($years as $y) $params[] = $y;
  }

  $sql .= " ORDER BY COALESCE(d.date, CONCAT(d.year, '-01-01')) ASC, d.id ASC";

  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]);
  json_response($rows);
}

/** Donations by donor -> other connected people */
function handle_donations_by_donor(): void {
  $donorId = $_GET['donor_id'] ?? null;
  if (!$donorId || !is_numeric($donorId)) {
    json_response(['error' => 'donor_id is required'], 400);
  }
  $exclude = $_GET['exclude_people_id'] ?? null;

  $sql = "SELECT DISTINCT p.id AS people_id, p.first_name, p.last_name
          FROM donations d
          JOIN people p ON p.id = d.candidate_person_id
          WHERE d.donor_id = ?";
  $params = [(int)$donorId];

  if ($exclude && is_numeric($exclude)) {
    $sql .= " AND p.id <> ?";
    $params[] = (int)$exclude;
  }

  $sql .= " ORDER BY p.last_name, p.first_name";

  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]);
  json_response($rows);
}

/** Donors/org search */
function handle_donors_search(): void {
  $org   = $_GET['org_name']   ?? null;
  $first = $_GET['first_name'] ?? null;
  $last  = $_GET['last_name']  ?? null;

  if (!$org && !$first && !$last) {
    json_response(['error' => 'Provide org_name or first_name/last_name'], 400);
  }

  $conds = [];
  $params = [];

  $hasOrgCol = column_exists('donors', 'organization_id');

  if ($org) {
    if ($hasOrgCol) {
      $conds[] = '(UPPER(COALESCE(d.org_name, "")) LIKE UPPER(?) OR UPPER(COALESCE(o.name, "")) LIKE UPPER(?))';
      $params[] = '%' . $org . '%';
      $params[] = '%' . $org . '%';
    } else {
      $conds[] = 'UPPER(COALESCE(d.org_name, "")) LIKE UPPER(?)';
      $params[] = '%' . $org . '%';
    }
  }
  if ($first) {
    $conds[] = 'UPPER(COALESCE(d.first_name, "")) LIKE UPPER(?)';
    $params[] = '%' . $first . '%';
  }
  if ($last) {
    $conds[] = 'UPPER(COALESCE(d.last_name, "")) LIKE UPPER(?)';
    $params[] = '%' . $last . '%';
  }

  $select = 'SELECT d.id, d.first_name, d.last_name, d.org_name';
  if ($hasOrgCol) {
    $select .= ', d.organization_id, o.name AS organization_name';
  } else {
    $select .= ", NULL AS organization_id, NULL AS organization_name";
  }

  $sql = $select . ' FROM donors d ' . ($hasOrgCol ? 'LEFT JOIN organizations o ON o.id = d.organization_id ' : '');

  if (!empty($conds)) {
    $sql .= ' WHERE ' . implode(' AND ', $conds);
  }

  $coalesceName = $hasOrgCol ? 'COALESCE(o.name, d.org_name)' : 'd.org_name';
  $sql .= ' ORDER BY CASE WHEN (COALESCE(d.org_name, "") <> ""' . ($hasOrgCol ? ' OR o.id IS NOT NULL' : '') . ') THEN 0 ELSE 1 END, ' . $coalesceName . ', d.last_name, d.first_name LIMIT 50';

  $stmt = pdo()->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]);
  json_response($rows);
}

/** Organizations: search by name (partial, case-insensitive) */
function handle_organizations_search(): void {
  $name = $_GET['name'] ?? null;
  if (!$name) {
    json_response(['error' => 'Provide name'], 400);
  }
  $stmt = pdo()->prepare('SELECT id, name FROM organizations WHERE UPPER(name) LIKE UPPER(?) ORDER BY name LIMIT 50');
  $stmt->execute(['%' . $name . '%']);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]);
  json_response($rows);
}

/** Organization by id */
function handle_organization_by_id(): void {
  $orgId = $_GET['organization_id'] ?? null;
  if (!$orgId || !is_numeric($orgId)) {
    json_response(['error' => 'organization_id is required'], 400);
  }
  $stmt = pdo()->prepare('SELECT id, name FROM organizations WHERE id = ?');
  $stmt->execute([(int)$orgId]);
  $row = $stmt->fetch();
  if (!$row) json_response((object)[]);
  json_response($row);
}

/** Donations by organization (via donors.organization_id) */
function handle_donations_by_organization(): void {
  $orgId = $_GET['organization_id'] ?? null;
  if (!$orgId || !is_numeric($orgId)) {
    json_response(['error' => 'organization_id is required'], 400);
  }
  if (!column_exists('donors', 'organization_id')) {
    json_response(['error' => 'donors.organization_id column not found. Apply DB migration first.'], 400);
  }

  $sql = "SELECT 
            dnt.id,
            dnt.year,
            dnt.date,
            dnt.amount,
            dnt.money_or_goods_services,
            dnt.notes,
            dnt.location,
            dnt.donor_id,
            p.first_name AS candidate_first_name,
            p.last_name  AS candidate_last_name
          FROM donations dnt
          JOIN donors dr ON dr.id = dnt.donor_id
          LEFT JOIN people p ON p.id = dnt.candidate_person_id
          WHERE dr.organization_id = ?
          ORDER BY COALESCE(dnt.date, CONCAT(dnt.year, '-01-01')) ASC, dnt.id ASC";
  $stmt = pdo()->prepare($sql);
  $stmt->execute([(int)$orgId]);
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]);
  json_response($rows);
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
  if ($METHOD === 'GET' && $ROUTE === '/donations/by-person') handle_donations_by_person();
  if ($METHOD === 'GET' && $ROUTE === '/donations/by-donor') handle_donations_by_donor();
  if ($METHOD === 'GET' && $ROUTE === '/donors/search') handle_donors_search();
  if ($METHOD === 'GET' && $ROUTE === '/organizations/search') handle_organizations_search();
  if ($METHOD === 'GET' && $ROUTE === '/organization/search-id') handle_organization_by_id();
  if ($METHOD === 'GET' && $ROUTE === '/donations/by-organization') handle_donations_by_organization();

  // AI
  if ($METHOD === 'POST' && $ROUTE === '/ai/extract-names') handle_ai_extract_names();
  if ($METHOD === 'POST' && $ROUTE === '/ai/extract-names-diaries') handle_ai_extract_names_diaries();
  if ($METHOD === 'POST' && $ROUTE === '/ai/prepare-mapping-csv') handle_ai_prepare_mapping_csv();
  if ($METHOD === 'GET'  && $ROUTE === '/ai/key-status') handle_ai_key_status();

  // People management
  if ($METHOD === 'POST' && $ROUTE === '/people') handle_add_person();

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
  if ($METHOD === 'POST' && $ROUTE === '/admin/backfill-2023-original') handle_admin_backfill_2023();

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
