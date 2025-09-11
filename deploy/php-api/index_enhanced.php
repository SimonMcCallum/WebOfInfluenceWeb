<?php
declare(strict_types=1);

/**
 * Web Of Influence — Enhanced PHP API with Advanced CSV Import
 * - Enhanced duplicate detection with fuzzy matching
 * - Comprehensive error tracking and logging
 * - Interactive duplicate resolution
 * - Better person identification with address fields
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

/** Enhanced CSV Import API Functions */

/** Normalize name for fuzzy matching */
function normalize_name_for_matching(string $firstName, string $lastName): string {
  $full = trim($firstName . ' ' . $lastName);
  // Remove common prefixes/suffixes, normalize case, remove extra spaces
  $normalized = preg_replace('/\b(mr|mrs|ms|dr|prof|sir|dame)\b\.?\s*/i', '', $full);
  $normalized = preg_replace('/\s+/', ' ', $normalized);
  return strtolower(trim($normalized));
}

/** Calculate Levenshtein similarity percentage */
function calculate_name_similarity(string $name1, string $name2): float {
  $distance = levenshtein(strtolower($name1), strtolower($name2));
  $maxLen = max(strlen($name1), strlen($name2));
  if ($maxLen === 0) return 100.0;
  return (1 - ($distance / $maxLen)) * 100;
}

/** Find potential duplicate people using fuzzy matching */
function find_potential_duplicates(string $firstName, string $lastName, ?string $electorate = null, ?string $address = null): array {
  $pdo = pdo();
  $normalizedName = normalize_name_for_matching($firstName, $lastName);
  
  // First check for exact matches
  $exactQuery = "SELECT id, first_name, last_name, electorate_name, address, normalized_name 
                FROM people 
                WHERE UPPER(first_name) = UPPER(?) AND UPPER(last_name) = UPPER(?)";
  $exactStmt = $pdo->prepare($exactQuery);
  $exactStmt->execute([$firstName, $lastName]);
  $exactMatches = $exactStmt->fetchAll();
  
  if (!empty($exactMatches)) {
    foreach ($exactMatches as &$match) {
      $match['match_score'] = 100.0;
      $match['match_type'] = 'exact';
      $match['match_reasons'] = 'Exact name match';
    }
    return $exactMatches;
  }
  
  // Then check for fuzzy matches
  $fuzzyQuery = "SELECT id, first_name, last_name, electorate_name, address, normalized_name 
                FROM people 
                WHERE normalized_name IS NOT NULL";
  $fuzzyStmt = $pdo->query($fuzzyQuery);
  $allPeople = $fuzzyStmt->fetchAll();
  
  $potentialMatches = [];
  foreach ($allPeople as $person) {
    $similarity = calculate_name_similarity($normalizedName, $person['normalized_name']);
    
    if ($similarity >= 80.0) { // 80% similarity threshold
      $reasons = ["Name similarity: {$similarity}%"];
      $totalScore = $similarity;
      
      // Boost score for matching electorate
      if ($electorate && $person['electorate_name'] && 
          strtolower($electorate) === strtolower($person['electorate_name'])) {
        $totalScore += 10.0;
        $reasons[] = 'Same electorate';
      }
      
      // Boost score for similar address
      if ($address && $person['address']) {
        $addressSimilarity = calculate_name_similarity($address, $person['address']);
        if ($addressSimilarity >= 70.0) {
          $totalScore += 5.0;
          $reasons[] = "Similar address: {$addressSimilarity}%";
        }
      }
      
      $person['match_score'] = min($totalScore, 100.0);
      $person['match_type'] = 'fuzzy';
      $person['match_reasons'] = implode(', ', $reasons);
      
      if ($totalScore >= 85.0) { // Only return high-confidence matches
        $potentialMatches[] = $person;
      }
    }
  }
  
  // Sort by match score descending
  usort($potentialMatches, function($a, $b) {
    return $b['match_score'] <=> $a['match_score'];
  });
  
  return array_slice($potentialMatches, 0, 5); // Return top 5 matches
}

/** Create import log entry */
function create_import_log(string $filename, string $tableName): int {
  $pdo = pdo();
  $stmt = $pdo->prepare("INSERT INTO import_logs (filename, table_name, status) VALUES (?, ?, 'processing')");
  $stmt->execute([$filename, $tableName]);
  return (int)$pdo->lastInsertId();
}

/** Log import error */
function log_import_error(int $importLogId, int $rowNumber, string $errorType, string $errorMessage, array $rowData, ?string $suggestedAction = null): void {
  $pdo = pdo();
  $stmt = $pdo->prepare("INSERT INTO import_errors (import_log_id, row_number, error_type, error_message, row_data, suggested_action) VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->execute([$importLogId, $rowNumber, $errorType, $errorMessage, json_encode($rowData), $suggestedAction]);
}

/** Log potential duplicate */
function log_potential_duplicate(int $importLogId, int $rowNumber, array $newPersonData, ?int $matchedPersonId, float $matchScore, string $matchReasons): int {
  $pdo = pdo();
  $stmt = $pdo->prepare("INSERT INTO duplicate_candidates (import_log_id, row_number, new_person_data, matched_person_id, match_score, match_reasons) VALUES (?, ?, ?, ?, ?, ?)");
  $stmt->execute([$importLogId, $rowNumber, json_encode($newPersonData), $matchedPersonId, $matchScore, $matchReasons]);
  return (int)$pdo->lastInsertId();
}

/** Update import log status */
function update_import_log(int $importLogId, int $totalRows, int $successfulRows, int $failedRows, int $duplicateRows, string $status, ?string $errorSummary = null): void {
  $pdo = pdo();
  $stmt = $pdo->prepare("UPDATE import_logs SET total_rows = ?, successful_rows = ?, failed_rows = ?, duplicate_rows = ?, status = ?, error_summary = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?");
  $stmt->execute([$totalRows, $successfulRows, $failedRows, $duplicateRows, $status, $errorSummary, $importLogId]);
}

/** Enhanced CSV Import API Functions */
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
    'people' => [
      'id', 'first_name', 'last_name', 'address', 'suburb', 'city', 'postal_code', 
      'electorate_name', 'party_affiliation', 'comments', 'created_at', 'updated_at'
    ],
    'parties' => ['id', 'name', 'short_name', 'created_at', 'updated_at'],
    'electorates' => ['id', 'name', 'region', 'created_at', 'updated_at'],
    'donors' => [
      'id', 'first_name', 'last_name', 'org_name', 'address', 
      'created_at', 'updated_at'
    ],
    'candidate_overview' => [
      'id', 'original_id', 'year', 'people_id', 'party_id', 'electorate_id',
      'total_donations', 'total_expenses', 'part_a', 'part_b', 'part_c', 'part_d',
      'part_f', 'part_g', 'part_h', 'created_at', 'updated_at'
    ],
    'donations' => [
      'id', 'year', 'date', 'amount', 'money_or_goods_services', 'location', 'notes',
      'donor_id', 'candidate_person_id', 'candidate_overview_id', 'created_at', 'updated_at'
    ],
    'meetings' => [
      'id', 'date', 'start_time', 'end_time', 'location', 'title', 'notes',
      'type', 'portfolio', 'with_text', 'minister_person_id', 'created_at', 'updated_at'
    ]
  ];

  $input = json_decode(file_get_contents('php://input'), true);
  $filename = $input['filename'] ?? '';

  // Get CSV sample data
  $csvSampleData = [];
  if ($filename) {
    $filepath = __DIR__ . '/../uploads/' . $filename;
    if (file_exists($filepath)) {
      $handle = fopen($filepath, 'r');
      $headers = fgetcsv($handle);
      $firstDataRow = fgetcsv($handle);
      fclose($handle);
      
      if ($headers && $firstDataRow) {
        foreach ($headers as $index => $header) {
          $csvSampleData[$header] = $firstDataRow[$index] ?? '';
        }
      }
    }
  }

  // Get database sample data for each table
  $databaseSamples = [];
  try {
    $pdo = pdo();
    foreach ($tables as $tableName => $columns) {
      try {
        // Get the most recent record from each table
        $stmt = $pdo->query("SELECT * FROM `{$tableName}` ORDER BY created_at DESC LIMIT 1");
        $sample = $stmt->fetch();
        if ($sample) {
          // Remove sensitive or irrelevant fields
          unset($sample['id'], $sample['created_at'], $sample['updated_at']);
          $databaseSamples[$tableName] = $sample;
        } else {
          $databaseSamples[$tableName] = [];
        }
      } catch (Exception $e) {
        // Table might not exist or have data, skip
        $databaseSamples[$tableName] = [];
      }
    }
  } catch (Exception $e) {
    // Database connection issue, skip samples
    $databaseSamples = [];
  }

  json_response([
    'success' => true,
    'tables' => $tables,
    'filename' => $filename,
    'csv_sample' => $csvSampleData,
    'database_samples' => $databaseSamples
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
    $importLogId = create_import_log($filename, $table);
    
    $handle = fopen($filepath, 'r');
    $headers = fgetcsv($handle); // Get header row
    
    $totalRows = 0;
    $insertedCount = 0;
    $errorCount = 0;
    $duplicateCount = 0;
    $errors = [];
    $duplicates = [];

    // Prepare columns and placeholders for the insert statement
    $columns = array_values($mapping);
    $placeholders = array_fill(0, count($columns), '?');

    $sql = "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";
    $stmt = $pdo->prepare($sql);

    while (($row = fgetcsv($handle)) !== false) {
      $totalRows++;
      $rowNumber = $totalRows + 1; // +1 for header row
      
      try {
        // Map CSV columns to database columns
        $values = [];
        $rowData = [];
        $hasRequiredData = true;
        foreach ($mapping as $csvColumn => $dbColumn) {
          $columnIndex = array_search($csvColumn, $headers);
          $value = $columnIndex !== false ? trim($row[$columnIndex] ?? '') : null;
          $value = ($value === '') ? null : $value;
          
          // Check if this is a critical field that shouldn't be null
          if ($value === null && in_array($dbColumn, ['first_name', 'last_name', 'name'])) {
            $hasRequiredData = false;
          }
          
          $values[] = $value;
          $rowData[$dbColumn] = $value;
        }

        // Skip rows with missing critical data before attempting insert
        if (!$hasRequiredData) {
          $duplicateCount++;
          log_import_error($importLogId, $rowNumber, 'validation', 'Required fields (first_name, last_name, or name) are missing or empty', $rowData, 'Row skipped due to missing required data');
          $duplicates[] = "Row {$rowNumber}: Skipped due to missing required data (first_name, last_name, or name)";
          continue;
        }

        // Special handling for people table with duplicate checking
        if ($table === 'people') {
          $firstName = $rowData['first_name'] ?? null;
          $lastName = $rowData['last_name'] ?? null;
          
          // Validate required fields
          if (empty($firstName) || empty($lastName)) {
            $errorCount++;
            log_import_error($importLogId, $rowNumber, 'validation', 
              'First name and last name are required', $rowData, 
              'Provide valid first and last names');
            $errors[] = "Row {$rowNumber}: First name and last name are required";
            continue;
          }

          $electorate = $rowData['electorate_name'] ?? null;
          $address = $rowData['address'] ?? null;
          
          // Check for potential duplicates
          $potentialDuplicates = find_potential_duplicates($firstName, $lastName, $electorate, $address);
          
          if (!empty($potentialDuplicates)) {
            $bestMatch = $potentialDuplicates[0];
            
            // If high confidence match (95%+) with same electorate, skip
            if ($bestMatch['match_score'] >= 95.0 && 
                $electorate && $bestMatch['electorate_name'] && 
                strtolower($electorate) === strtolower($bestMatch['electorate_name'])) {
              
              $duplicateCount++;
              log_potential_duplicate($importLogId, $rowNumber, $rowData, 
                (int)$bestMatch['id'], $bestMatch['match_score'], $bestMatch['match_reasons']);
              $duplicates[] = "Row {$rowNumber}: High confidence duplicate of {$bestMatch['first_name']} {$bestMatch['last_name']} (ID: {$bestMatch['id']}) - {$bestMatch['match_reasons']}";
              continue;
            }
            
            // For medium confidence matches, still log but continue with insert for now
            if ($bestMatch['match_score'] >= 85.0) {
              log_potential_duplicate($importLogId, $rowNumber, $rowData, 
                (int)$bestMatch['id'], $bestMatch['match_score'], $bestMatch['match_reasons']);
              $duplicates[] = "Row {$rowNumber}: Possible duplicate of {$bestMatch['first_name']} {$bestMatch['last_name']} (ID: {$bestMatch['id']}) - {$bestMatch['match_reasons']} - INSERTED ANYWAY";
            }
          }
          
          // Add normalized name for future fuzzy matching
          $normalizedName = normalize_name_for_matching($firstName, $lastName);
          if (in_array('normalized_name', $columns)) {
            $normalizedIndex = array_search('normalized_name', $columns);
            $values[$normalizedIndex] = $normalizedName;
          }
        }

        $stmt->execute($values);
        $insertedCount++;

      } catch (Exception $rowError) {
        // Handle duplicate entries specially - just log them and continue
        if (strpos($rowError->getMessage(), 'Duplicate entry') !== false) {
          $duplicateCount++;
          log_import_error($importLogId, $rowNumber, 'duplicate', $rowError->getMessage(), $rowData, 'Duplicate record skipped');
          $duplicates[] = "Row {$rowNumber}: Duplicate entry skipped - {$rowError->getMessage()}";
          continue;
        }
        
        $errorCount++;
        
        $errorType = 'other';
        $suggestedAction = null;
        
        // Categorize other error types
        if (strpos($rowError->getMessage(), 'cannot be null') !== false) {
          $duplicateCount++; // Treat null required fields as skipped entries
          log_import_error($importLogId, $rowNumber, 'validation', $rowError->getMessage(), $rowData, 'Required field is null - record skipped');
          $duplicates[] = "Row {$rowNumber}: Required field is null, record skipped - {$rowError->getMessage()}";
          continue;
        } elseif (strpos($rowError->getMessage(), 'foreign key') !== false) {
          $errorType = 'constraint';
          $suggestedAction = 'Ensure referenced records exist';
        }
        
        log_import_error($importLogId, $rowNumber, $errorType, $rowError->getMessage(), $rowData, $suggestedAction);
        $errors[] = "Row {$rowNumber}: {$rowError->getMessage()}";

        // Limit error reporting in response to first 20 errors
        if (count($errors) >= 20) {
          $errors[] = "... and more errors (check import log for full details)";
          break;
        }
      }
    }

    fclose($handle);
    
    // Update import log
    $status = ($errorCount === 0 && $duplicateCount === 0) ? 'completed' : 
              ($insertedCount > 0 ? 'partial' : 'failed');
    $errorSummary = null;
    if (!empty($errors) || !empty($duplicates)) {
      $errorSummary = "Errors: " . count($errors) . ", Duplicates: " . count($duplicates);
    }
    
    update_import_log($importLogId, $totalRows, $insertedCount, $errorCount, $duplicateCount, $status, $errorSummary);

    // Clean up uploaded file
    unlink($filepath);

    json_response([
      'success' => true,
      'import_log_id' => $importLogId,
      'total_rows' => $totalRows,
      'inserted' => $insertedCount,
      'errors' => $errorCount,
      'duplicates' => $duplicateCount,
      'error_details' => array_slice($errors, 0, 10), // Limit to first 10 in response
      'duplicate_details' => array_slice($duplicates, 0, 10),
      'status' => $status,
      'message' => "Import completed: {$insertedCount} inserted, {$errorCount} errors, {$duplicateCount} duplicates"
    ]);

  } catch (Exception $e) {
    if (isset($importLogId)) {
      update_import_log($importLogId, $totalRows ?? 0, $insertedCount ?? 0, 
                       $errorCount ?? 0, $duplicateCount ?? 0, 'failed', $e->getMessage());
    }
    json_response(['error' => 'Import failed', 'message' => $e->getMessage()], 500);
  }
}

/** API endpoint to get import log details */
function handle_import_log_api(): void {
  $importLogId = $_GET['import_log_id'] ?? null;
  if (!$importLogId) {
    json_response(['error' => 'import_log_id is required'], 400);
  }

  try {
    $pdo = pdo();
    
    // Get import log
    $logStmt = $pdo->prepare("SELECT * FROM import_logs WHERE id = ?");
    $logStmt->execute([$importLogId]);
    $log = $logStmt->fetch();
    
    if (!$log) {
      json_response(['error' => 'Import log not found'], 404);
    }
    
    // Get errors
    $errorStmt = $pdo->prepare("SELECT * FROM import_errors WHERE import_log_id = ? ORDER BY row_number");
    $errorStmt->execute([$importLogId]);
    $errors = $errorStmt->fetchAll();
    
    // Get duplicates
    $duplicateStmt = $pdo->prepare("SELECT dc.*, p.first_name, p.last_name FROM duplicate_candidates dc LEFT JOIN people p ON dc.matched_person_id = p.id WHERE dc.import_log_id = ? ORDER BY dc.match_score DESC");
    $duplicateStmt->execute([$importLogId]);
    $duplicates = $duplicateStmt->fetchAll();
    
    json_response([
      'success' => true,
      'import_log' => $log,
      'errors' => $errors,
      'duplicates' => $duplicates
    ]);
    
  } catch (Exception $e) {
    json_response(['error' => 'Failed to retrieve import log', 'message' => $e->getMessage()], 500);
  }
}

/** Basic API endpoints (simplified for demo) */
function handle_health(): void {
  header('Content-Type: text/plain; charset=utf-8');
  echo 'Enhanced API is running!';
  exit;
}

function handle_get_candidates(): void {
  $stmt = pdo()->query('SELECT id, first_name, last_name FROM people');
  $rows = $stmt->fetchAll();
  if (!$rows) json_response([]); // return empty for no data
  json_response($rows);
}

/** Dispatch */
try {
  if ($METHOD === 'GET' && $ROUTE === '/') handle_health();

  if ($METHOD === 'GET' && $ROUTE === '/candidates') handle_get_candidates();

  // Enhanced CSV Import API endpoints
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/upload') handle_csv_upload_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/preview') handle_csv_preview_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/mapping') handle_csv_mapping_api();
  if ($METHOD === 'POST' && $ROUTE === '/api/import/csv/execute') handle_csv_execute_api();
  if ($METHOD === 'GET' && $ROUTE === '/api/import/log') handle_import_log_api();

  // Not found
  json_response([
    'error' => 'Not Found', 
    'route' => $ROUTE,
    'debug' => [
      'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '',
      'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? '',
      'PATH_INFO' => $_SERVER['PATH_INFO'] ?? '',
      'METHOD' => $METHOD,
      'GET_route' => $_GET['route'] ?? null
    ]
  ], 404);
} catch (Throwable $e) {
  json_response(['error' => 'Server error', 'detail' => $e->getMessage()], 500);
}