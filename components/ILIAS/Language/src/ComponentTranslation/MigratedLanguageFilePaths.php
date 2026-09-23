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
    private const string LANGUAGE_KEY_FORMAT = '/^[a-z]{2}$/';

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
     *
     * @throws \InvalidArgumentException see relativeBasePath()
     */
    public static function shippedBasePath(
        string $ilias_absolute_path,
        LanguageFileDirectory $directory,
        string $lang_key
    ): string {
        return rtrim($ilias_absolute_path, '/') . '/' . self::relativeBasePath($directory, $lang_key);
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
        if (preg_match(self::LANGUAGE_KEY_FORMAT, $lang_key) !== 1) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid language key.', $lang_key));
        }

        return ltrim($directory->getPath(), '/') . $directory->getPrefix() . '_' . $lang_key;
    }
}
