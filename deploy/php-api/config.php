<?php
// Runtime overrides for the PHP API.
//
// On the Ubuntu/Docker deployment, ALL credentials come from environment
// variables injected by docker-compose. Return an empty array so index.php
// falls back to its getenv()-driven defaults.
//
// To override locally (e.g. for `php -S` testing), copy this file and add:
//   'DB_HOST' => '127.0.0.1', 'DB_USER' => 'woi', ... etc.

return [];
