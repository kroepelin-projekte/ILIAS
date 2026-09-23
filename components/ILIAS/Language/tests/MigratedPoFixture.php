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

use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\Gettext\Entry;
use ILIAS\Language\ComponentTranslation\Gettext\MoReader;
use ILIAS\Language\ComponentTranslation\Gettext\MoWriter;
use ILIAS\Language\ComponentTranslation\Gettext\PoParser;
use ILIAS\Language\ComponentTranslation\Gettext\PoWriter;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;

/**
 * Shared fixture helpers for the tests of modules migrated to PO/MO. Builds shipped/overlay files
 * with the component's own gettext implementation (ILIAS\Language\ComponentTranslation\Gettext) -
 * the same classes production uses - so the fixtures never depend on the (only transitively
 * installed) gettext/gettext package.
 */
final class MigratedPoFixture
{
    /**
     * @param array<string, string|array{value: string, context?: string, original?: string,
     *        local_change?: string, fuzzy?: bool}> $entries identifier => value or details.
     *        'context' defaults to $module, 'original' seeds a LocalChangeComments "original",
     *        'local_change' a raw "local_change: <value>" comment, 'fuzzy' the fuzzy flag.
     */
    public static function catalog(string $module, array $entries): Catalog
    {
        $catalog = new Catalog();
        $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        foreach ($entries as $identifier => $details) {
            $details = is_array($details) ? $details : ['value' => $details];
            $entry = new Entry(array_key_exists('context', $details) ? $details['context'] : $module, (string) $identifier);
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

    public static function entry(?string $context, string $id, string $value): Entry
    {
        $entry = new Entry($context, $id);
        $entry->translate($value);

        return $entry;
    }

    public static function writePo(string $file, Catalog $catalog): void
    {
        self::ensureDirectory(dirname($file));
        file_put_contents($file, PoWriter::toString($catalog));
    }

    public static function writeMo(string $file, Catalog $catalog): void
    {
        self::ensureDirectory(dirname($file));
        file_put_contents($file, MoWriter::toString($catalog));
    }

    /**
     * Writes "<base>.po" and "<base>.mo" with the same content.
     */
    public static function writePair(string $base_path, Catalog $catalog): void
    {
        self::writePo($base_path . '.po', $catalog);
        self::writeMo($base_path . '.mo', $catalog);
    }

    public static function readPo(string $file): Catalog
    {
        return PoParser::parseFile($file);
    }

    /**
     * @return array<string, string> identifier => translation, exactly what ilLanguage serves
     */
    public static function readMo(string $file): array
    {
        return MoReader::readTranslations($file);
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
