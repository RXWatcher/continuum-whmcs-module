<?php

declare(strict_types=1);

// Composer autoload pulls in the module's own autoload.php (registered via
// the "files" autoload entry) plus the PSR-4 mapping for test support
// classes. The WHMCS runtime is not present in CI, so its globals are
// shimmed explicitly afterwards.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Support/whmcs_shims.php';
