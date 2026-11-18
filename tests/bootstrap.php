<?php

/**
 * Test bootstrap. The tests need no Joomla installation and no ZKBioTime server: only classes
 * without Joomla dependencies are tested, and ZKBioTime's database is replaced by CatalogueQuery
 * (or, with BIOTIME_TEST_PG set, a real PostgreSQL loaded with tests/Support/sql/zkbiotime-pgsql.sql).
 *
 * @license GNU General Public License version 2 or later
 */

\define('_JEXEC', 1);

require __DIR__ . '/../vendor/autoload.php';

// Several small fakes share one file.
require __DIR__ . '/Support/Fakes.php';
