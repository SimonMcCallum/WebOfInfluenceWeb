<?php
// Copy this file to config.php and set your credentials.
// On cPanel you can also set environment variables instead of editing this file.

return [
  // Database connection
  // IMPORTANT: Set DB_NAME to the single database that contains the tables:
  // people, parties, electorates, candidate_overview, donations, meetings.
  // If you import Documentation/sql/woi_schema.sql as-is, it will create a database named "woi".
  // In that case set DB_NAME to "woi".
  'DB_HOST' => 'localhost',
  'DB_USER' => 'ludog319_kng',
  'DB_PASS' => 'WFoSE!',
  'DB_NAME' => 'ludog319_webofinfluence',

  // Security
  // Shared secret for admin actions (and optionally all endpoints)
  'API_TOKEN' => 'changeme-strong-secret',
  // When set to true, ALL endpoints require the token (except GET / health).
  // When false, only /admin actions require the token.
  'API_PROTECT_ALL' => false,
];