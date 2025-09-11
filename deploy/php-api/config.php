<?php
// Configuration file for Web Of Influence PHP API
// Database connection and security settings

return [
  // Database connection
  // IMPORTANT: Set DB_NAME to the single database that contains the tables:
  // people, parties, electorates, candidate_overview, donations, meetings.
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
