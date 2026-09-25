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

namespace ILIAS\Language\ComponentTranslation;

/**
 * The single place that knows where the files of a module migrated to PO/MO live:
 *
 * - the shipped, git-tracked `.po`: <ILIAS root>/<directory path><module>_<lang>.po
 * - the per-installation overlay `.po`/`.mo`:
 *   <client data dir>/lang/<directory path><module>_<lang>.po|.mo
 *
 * and how the client data directory itself is determined. Setup, the runtime read path
 * (ilLanguage), the administration write paths (ilObjLanguage/ilObjLanguageExt/ilPluginLanguage)
 * and LanguageInstallationManager all use this class instead of assembling these paths themselves.
 */
final class MigratedLanguageFilePaths
{
    /**
     * The format of an ILIAS language key, as used for the language files (`ilias_<key>.lang`).
     */
    private const string LANGUAGE_KEY_FORMAT = '/^[a-z]{2}\z/';

    /**
     * A module name as it may become a file name of the compiled shipped state.
     */
    private const string MODULE_FORMAT = '/^[A-Za-z0-9_-]+\z/';

    private const string SHIPPED_ARTIFACT_DIRECTORY = 'artifacts/language';

    /**
     * The client data directory (CLIENT_DATA_DIR) of this installation, or `null` if it cannot be
     * determined or does not exist (yet).
     *
     * - In a fully initialised request the CLIENT_DATA_DIR constant is authoritative.
     * - Everywhere else (CLI Setup, objectives constructed without a Setup environment) it is read
     *   from ilias.ini.php exactly the way ilInitialisation composes CLIENT_DATA_DIR:
     *   `[clients] datadir` . '/' . `[clients] default`. It is NOT guessed from the contents of the
     *   data directory - that directory regularly holds further entries (e.g. "logs") next to the
     *   client directory.
     *
     * A client directory that does not exist yet (a fresh installation before the filesystem
     * objectives ran) yields `null`: the overlay is then created by the next `setup update`
     * instead of pre-creating a client directory the installer might still want to create itself.
     */
    public static function resolveClientDataDir(?string $ilias_absolute_path = null): ?string
    {
        if (defined('CLIENT_DATA_DIR')) {
            return (string) CLIENT_DATA_DIR;
        }

        $ilias_absolute_path ??= dirname(__DIR__, 5);
        $ini_file = rtrim($ilias_absolute_path, '/') . '/ilias.ini.php';
        if (!is_file($ini_file) || !is_readable($ini_file)) {
            return null;
        }
        $ini = @parse_ini_file($ini_file, true);
        if (!is_array($ini)) {
            return null;
        }

        $data_dir = $ini['clients']['datadir'] ?? null;
        if (is_string($data_dir) && $data_dir !== '' && !str_starts_with($data_dir, '/')) {
            $data_dir = rtrim($ilias_absolute_path, '/') . '/' . $data_dir;
        }

        return self::fromDataDirAndClientId(
            is_string($data_dir) ? $data_dir : null,
            is_string($ini['clients']['default'] ?? null) ? $ini['clients']['default'] : null
        );
    }

    /**
     * Composes the client data directory from its two parts, e.g. from the Setup environment's
     * ilias.ini resource and client id. `null` if a part is missing, the client id is not a plain
     * directory name, or the directory does not exist.
     */
    public static function fromDataDirAndClientId(?string $data_dir, ?string $client_id): ?string
    {
        if ($data_dir === null || $data_dir === '' || $client_id === null || $client_id === '') {
            return null;
        }
        if ($client_id === '.' || $client_id === '..' || strpbrk($client_id, "/\\\0") !== false) {
            return null;
        }

        $client_data_dir = rtrim($data_dir, '/') . '/' . $client_id;

        return is_dir($client_data_dir) ? $client_data_dir : null;
    }

    /**
     * Without extension - append ".po" (the shipped file never has a compiled ".mo" next to it).
     * Named by shippedFileNamePattern().
     *
     * @throws \InvalidArgumentException for a $lang_key that is not an ILIAS language key or an
     *         invalid file name pattern
     */
    public static function shippedBasePath(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key
    ): string {
        self::assertValidLanguageKey($lang_key);

        return self::shippedDirectoryPath($ilias_absolute_path, $directory)
            . sprintf(self::shippedFileNamePattern($directory), $lang_key);
    }

    /**
     * The shipped `.po` files of $directory, found by its file name pattern (see
     * shippedFileNamePattern()), keyed by language key. A file whose name does not yield a language
     * key (e.g. "tos_pt_BR.po") is not listed.
     *
     * @return array<string, string> language key => absolute path, sorted by language key
     * @throws \InvalidArgumentException for a directory with an invalid file name pattern
     */
    public static function findShippedPoFiles(string $ilias_absolute_path, LanguageFileDirectory $directory): array
    {
        $pattern = self::shippedFileNamePattern($directory);
        $base = self::shippedDirectoryPath($ilias_absolute_path, $directory);
        [$before, $after] = explode('%s', $pattern, 2);
        $name_format = '/\A' . preg_quote($before, '/') . '([a-z]{2})' . preg_quote($after, '/') . '\.po\z/';

        $files = [];
        foreach (glob($base . sprintf($pattern, '*') . '.po') ?: [] as $file) {
            if (is_file($file) && preg_match($name_format, basename($file), $matches) === 1) {
                $files[$matches[1]] = $file;
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * $directory's path below $ilias_absolute_path, where its shipped `.po` files live.
     */
    private static function shippedDirectoryPath(string $ilias_absolute_path, LanguageFileDirectory $directory): string
    {
        return rtrim($ilias_absolute_path, '/') . '/' . ltrim($directory->getPath(), '/');
    }

    /**
     * The name of $directory's shipped `.po` of a language, without ".po" and with "%s" for the
     * language key: the directory's own pattern (NamesShippedLanguageFiles), otherwise
     * "<prefix>_%s". Only the shipped file is named by it - the overlay and the build artifact are
     * named by the module (the prefix).
     *
     * @throws \InvalidArgumentException see assertValidShippedFileNamePattern()
     */
    public static function shippedFileNamePattern(LanguageFileDirectory $directory): string
    {
        $pattern = $directory instanceof NamesShippedLanguageFiles
            ? $directory->getShippedFileNamePattern()
            : $directory->getPrefix() . '_%s';
        self::assertValidShippedFileNamePattern($pattern);

        return $pattern;
    }

    /**
     * @throws \InvalidArgumentException unless $pattern contains exactly one "%s" and otherwise only
     *         letters, digits, "_", "-" and "." - no "/", no "..", no other "%"
     */
    public static function assertValidShippedFileNamePattern(string $pattern): void
    {
        if (
            substr_count($pattern, '%s') !== 1
            || str_contains($pattern, '..')
            || preg_match('/\A[A-Za-z0-9_.\-]*%s[A-Za-z0-9_.\-]*\z/', $pattern) !== 1
        ) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid file name pattern for shipped language files.', $pattern));
        }
    }

    /**
     * The directory Setup's build compiles every shipped `.po` into (see ShippedTranslations),
     * below the `artifacts/` directory of the installation (not tracked by git).
     */
    public static function shippedArtifactDirectory(string $ilias_absolute_path): string
    {
        return rtrim($ilias_absolute_path, '/') . '/' . self::SHIPPED_ARTIFACT_DIRECTORY;
    }

    /**
     * The compiled shipped state of $directory's module for $lang_key:
     * `artifacts/language/<lang_key>/<module>.mo`.
     *
     * @throws \InvalidArgumentException for a $lang_key that is not an ILIAS language key, or a
     *         module (the directory's prefix) that is not a plain file name
     */
    public static function shippedArtifactFile(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key
    ): string {
        self::assertValidLanguageKey($lang_key);
        $module = $directory->getPrefix();
        if (preg_match(self::MODULE_FORMAT, $module) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid module name.', $module));
        }

        return self::shippedArtifactDirectory($ilias_absolute_path) . '/' . $lang_key . '/' . $module . '.mo';
    }

    /**
     * Without extension - append ".po" or ".mo".
     *
     * @throws \InvalidArgumentException see relativeBasePath()
     */
    public static function overlayBasePath(
        string $client_data_dir,
        LanguageFileDirectory $directory,
        string $lang_key
    ): string {
        return rtrim($client_data_dir, '/') . '/lang/' . self::relativeBasePath($directory, $lang_key);
    }

    /**
     * @throws \InvalidArgumentException for a $lang_key that is not an ILIAS language key (two
     *         lowercase letters) - it becomes part of a file path, and some callers pass it through
     *         from public APIs (e.g. ilLanguage::_lookupEntry())
     */
    private static function relativeBasePath(LanguageFileDirectory $directory, string $lang_key): string
    {
        self::assertValidLanguageKey($lang_key);

        return ltrim($directory->getPath(), '/') . $directory->getPrefix() . '_' . $lang_key;
    }

    private static function assertValidLanguageKey(string $lang_key): void
    {
        if (preg_match(self::LANGUAGE_KEY_FORMAT, $lang_key) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid language key.', $lang_key));
        }
    }
}
