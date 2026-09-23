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

namespace ILIAS\Language\ComponentTranslation\Gettext;

use RuntimeException;

/**
 * Writes a file so that concurrent readers only ever see the complete old or the complete new
 * content: the data goes into a temporary file in the SAME directory (rename() is only atomic
 * within one filesystem) which then replaces the target via rename().
 */
final class AtomicFileWriter
{
    public static function write(string $file, string $content): void
    {
        $directory = dirname($file);
        $temporary = @tempnam($directory, '.' . basename($file) . '.');
        // tempnam() returns a resolved path - compare resolved directories, so symlinks, "//" or
        // relative paths in $file do not count as a fallback to the system temp dir
        $resolved_directory = realpath($directory);
        if (
            $temporary === false
            || $resolved_directory === false
            || realpath(dirname($temporary)) !== $resolved_directory
        ) {
            // tempnam() silently falls back to the system temp dir if $directory is not writable -
            // a rename() from there would no longer be atomic (or even possible)
            if ($temporary !== false) {
                @unlink($temporary);
            }
            throw new RuntimeException(sprintf('Could not create a temporary file next to "%s".', $file));
        }

        try {
            if (file_put_contents($temporary, $content) !== strlen($content)) {
                throw new RuntimeException(sprintf('Could not write "%s".', $temporary));
            }
            // tempnam() creates the file with mode 0600 - use what a plain file_put_contents() would
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $file)) {
                throw new RuntimeException(sprintf('Could not replace "%s".', $file));
            }
        } catch (\Throwable $t) {
            @unlink($temporary);
            throw $t;
        }
    }
}
