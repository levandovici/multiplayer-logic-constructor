<?php

// Test bootstrap — loads pure helpers only; no session, no DB connection.
// Unit/Contract suites must not require MySQL. Integration tests bootstrap
// their own PDO from TEST_DB_* env vars.

require_once dirname(__DIR__) . '/src/helpers.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';
