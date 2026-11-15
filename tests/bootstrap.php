<?php

/**
 * Test bootstrap. The tests need no Joomla installation and no ZKBioTime server: only classes
 * without Joomla dependencies are tested, and ZKBioTime is replaced by FakeTransport.
 *
 * @license GNU General Public License version 2 or later
 */

\define('_JEXEC', 1);

require __DIR__ . '/../vendor/autoload.php';
