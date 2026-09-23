<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);
require_once 'vendor/composer/vendor/autoload.php';

// ILIAS autoloads via a generated Composer classmap only. Classes added to
// components/ILIAS/Language/src/ after the last `composer dump-autoload` are
// not in that map yet; resolve them PSR-4 style (ILIAS\\Language\\X\\Y ->
// src/X/Y.php) so the tests do not depend on a regenerated classmap.
spl_autoload_register(static function (string $class): void {
    $prefix = 'ILIAS\\Language\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

require_once 'ilLanguageBaseTestCase.php';
require_once __DIR__ . '/MigratedPoFixture.php';
