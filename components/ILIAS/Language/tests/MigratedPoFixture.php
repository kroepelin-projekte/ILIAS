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

use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;

/**
 * Shared fixture helpers for the tests of modules migrated to PO/MO. Builds shipped/overlay files
 * with the component's gettext adapter (ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog
 * and TranslationEntry) - the same classes production uses - so the fixtures never touch the
 * gettext/gettext library directly.
 */
final class MigratedPoFixture
{
    /**
     * @param array<string, string|array{value: string, context?: string, original?: string,
     *        local_change?: string, fuzzy?: bool}> $entries identifier => value or details.
     *        'context' defaults to $module, 'original' seeds a LocalChangeComments "original",
     *        'local_change' a raw "local_change: <value>" comment, 'fuzzy' the fuzzy flag.
     */
    public static function catalog(string $module, array $entries): TranslationCatalog
    {
        $catalog = new TranslationCatalog();
        $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        foreach ($entries as $identifier => $details) {
            $details = is_array($details) ? $details : ['value' => $details];
            $entry = new TranslationEntry(array_key_exists('context', $details) ? $details['context'] : $module, (string) $identifier);
            $entry->translate($details['value']);
            if (isset($details['original'])) {
                LocalChangeComments::setOriginal($entry, $details['original']);
            }
            if (isset($details['local_change'])) {
                $entry->addTranslatorComment('local_change: ' . $details['local_change']);
            }
            if (!empty($details['fuzzy'])) {
                $entry->addFlag('fuzzy');
            }
            $catalog->add($entry);
        }

        return $catalog;
    }

    public static function entry(?string $context, string $id, string $value): TranslationEntry
    {
        $entry = new TranslationEntry($context, $id);
        $entry->translate($value);

        return $entry;
    }

    public static function writePo(string $file, TranslationCatalog $catalog): void
    {
        self::ensureDirectory(dirname($file));
        file_put_contents($file, $catalog->toPoString());
    }

    public static function writeMo(string $file, TranslationCatalog $catalog): void
    {
        self::ensureDirectory(dirname($file));
        file_put_contents($file, $catalog->toMoString());
    }

    /**
     * Writes "<base>.po" and "<base>.mo" with the same content.
     */
    public static function writePair(string $base_path, TranslationCatalog $catalog): void
    {
        self::writePo($base_path . '.po', $catalog);
        self::writeMo($base_path . '.mo', $catalog);
    }

    public static function readPo(string $file): TranslationCatalog
    {
        return TranslationCatalog::fromPoFile($file);
    }

    /**
     * @return array<string, string> identifier => translation, exactly what ilLanguage serves
     */
    public static function readMo(string $file): array
    {
        return TranslationCatalog::readMoTranslations($file);
    }

    public static function directory(string $prefix, string $path, bool $local = false): LanguageFileDirectory
    {
        return new class ($prefix, $path, $local) implements LanguageFileDirectory {
            public function __construct(
                private readonly string $prefix,
                private readonly string $path,
                private readonly bool $local
            ) {
            }

            public function getPrefix(): string
            {
                return $this->prefix;
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getSuffix(): string
            {
                return $this->local ? '.local' : '';
            }

            public function isLocal(): bool
            {
                return $this->local;
            }
        };
    }

    /**
     * Writes $catalog as the SHIPPED `.po` of $module/$lang_key below ILIAS_ABSOLUTE_PATH at
     * $relative_path (a LanguageFileDirectory path): a module only counts as migrated for a language
     * while its shipped `.po` exists, so an overlay fixture needs one as well. Remove it again with
     * removeShippedDirectory().
     */
    public static function writeShippedPo(
        string $relative_path,
        string $module,
        string $lang_key,
        TranslationCatalog $catalog
    ): void {
        self::writePo(self::shippedDirectory($relative_path) . '/' . $module . '_' . $lang_key . '.po', $catalog);
    }

    public static function removeShippedDirectory(string $relative_path): void
    {
        self::removeDirectory(self::shippedDirectory($relative_path));
    }

    private static function shippedDirectory(string $relative_path): string
    {
        $directory = rtrim((string) ILIAS_ABSOLUTE_PATH, '/') . '/' . trim($relative_path, '/');
        self::ensureDirectory($directory);

        return $directory;
    }

    /**
     * Guards every fixture that writes under CLIENT_DATA_DIR: a full-suite run can have that constant
     * already defined - and pointing at the real client data directory (e.g. /var/iliasdata, see
     * Filesystem/tests/ilServicesFileSystemTest.php and Test/tests/ilTestBaseTestCaseTrait.php) - long
     * before one of these fixture classes ever runs. A PHP constant cannot be redefined, so a foreign,
     * non-temp CLIENT_DATA_DIR must never be written into: this skips the test instead, exactly the
     * pattern UninstallRemovesMigratedMoFilesTest::ensureClientDataDirDefined() established first, now
     * centralised for every other migrated-PO fixture that needs the same guard. Only ever defines the
     * constant itself when nobody else has - never assumes exclusive ownership of it.
     */
    public static function ensureClientDataDirDefinedOrSkip(\PHPUnit\Framework\TestCase $test): void
    {
        if (defined('CLIENT_DATA_DIR') && !str_starts_with(CLIENT_DATA_DIR, sys_get_temp_dir() . '/')) {
            $test->markTestSkipped(
                'CLIENT_DATA_DIR ("' . CLIENT_DATA_DIR . '") is not a test-owned temp directory - '
                . 'refusing to write fixture files there.'
            );
        }
        if (!defined('CLIENT_DATA_DIR')) {
            define('CLIENT_DATA_DIR', sys_get_temp_dir() . '/ilias_lang_test_client_data_dir');
        }
    }

    public static function removeDirectory(string $directory): void
    {
        if (is_link($directory)) {
            unlink($directory);
            return;
        }
        if (!is_dir($directory)) {
            return;
        }
        @chmod($directory, 0775);
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                self::removeDirectory($path);
            } else {
                @chmod($path, 0664);
                unlink($path);
            }
        }
        rmdir($directory);
    }

    private static function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
