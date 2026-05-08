<?php

declare(strict_types=1);

// SPDX-License-Identifier: Apache-2.0
//
// PHPUnit bootstrap. Loads composer's autoloader so dragonmantank/cron-expression
// is available, and defines a no-op gettext shim so production code that wraps
// strings in _() works under tests without the FreePBX framework loaded.

require_once __DIR__ . '/../vendor/autoload.php';

if (!function_exists('_')) {
    function _($s)
    {
        return $s;
    }
}
