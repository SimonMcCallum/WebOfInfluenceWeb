<?php
// Copy to config.php only if you want to override the env-var-driven defaults
// in deploy/php-api/index.php. On Docker/Ubuntu, leave config.php returning [].
return [
  // Database
  // 'DB_HOST' => 'woi-db',
  // 'DB_USER' => 'woi',
  // 'DB_PASS' => 'set-via-env',
  // 'DB_NAME' => 'webofinfluence',

  // Security — shared secret for admin endpoints (if API_PROTECT_ALL true, also for reads)
  // 'API_TOKEN' => 'changeme-strong-secret',
  // 'API_PROTECT_ALL' => false,

  // AI — preferred to set via env var GEMINI_API_KEY
  // 'GEMINI_API_KEY' => 'changeme-gemini-key',
];
