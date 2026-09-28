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

namespace ILIAS\Language\Setup;

use ILIAS\Language\ComponentTranslation\AtomicFileWriter;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
use ILIAS\Language\ComponentTranslation\Catalog\TranslationEntry;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use ILIAS\Language\ComponentTranslation\PlainLogText;
use ILIAS\Language\ComponentTranslation\PluralFormKey;
use ILIAS\Language\ComponentTranslation\PluralForms;
use ILIAS\Language\ComponentTranslation\ShippedTranslations;
use ILIAS\Language\ComponentTranslation\TranslationMarkupPolicy;
use ILIAS\Setup;

/**
 * Setup's build step (`php cli/setup.php build`, run by `composer install`/`composer dump-autoload`)
 * for the migrated modules: compiles every shipped `.po` of every contributed LanguageFileDirectory
 * into `artifacts/language/<lang>/<module>.mo` (see ShippedTranslations).
 *
 * Incremental: an index (`artifacts/language/index.json`) records the hash of every compiled `.po`
 * and the size and hash of its artifact; only new or changed ones - or those whose artifact is
 * missing or not intact - are compiled again, and artifacts whose `.po` no longer exists are
 * removed. The index also carries a fingerprint of the code that determines the compiled output
 * (compile(), the markup policy, the gettext adapter) - changing that code rebuilds everything.
 *
 * Values with markup TranslationMarkupPolicy does not allow are compiled cleaned, and every cleaned
 * value is reported as a warning (file, module, key, what was removed) - on every build, not only
 * the one that compiled it, until the `.po` is fixed. Neither that nor a `.po` that cannot be
 * compiled aborts the build: the latter is reported, and its artifact removed, so ilLanguage falls
 * back to compiling the `.po` itself (and on failure to the database).
 *
 * Artifacts are written atomically (temporary file + rename) but without fsync(): they can be
 * rebuilt any time, and an fsync() per file would dominate the build time.
 *
 * @phpstan-type IndexEntry array{source: string, hash: string, artifact_size: int, artifact_hash: string, warnings: list<string>}
 */
final class ShippedLanguageFilesCompiledObjective implements Setup\Objective
{
    private const string INDEX_FILE = 'index.json';

    /**
     * Raise to rebuild every artifact when the compiled output changes for a reason the fingerprint
     * of the involved classes (see fingerprint()) does not capture, e.g. an update of the gettext
     * library. 3: plural messages (every form cleaned, exactly as many forms as "Plural-Forms"
     * declares, see TranslationCatalog::toMoString()).
     */
    private const int FORMAT_VERSION = 3;

    public function __construct(
        private readonly LanguageFileDirectoryManager $language_file_directory_manager,
        private readonly string $ilias_absolute_path,
        private readonly ShippedTranslations $shipped_translations = new ShippedTranslations()
    ) {
    }

    public function getHash(): string
    {
        $directories = [];
        foreach ($this->language_file_directory_manager->getDirectories() as $directory) {
            $directories[] = $directory->getPath() . '|' . $directory->getPrefix();
        }

        return hash('sha256', self::class . '|' . $this->ilias_absolute_path . '|' . implode(',', $directories));
    }

    public function getLabel(): string
    {
        return 'Compile the shipped language files (.po) of the migrated modules to artifacts/language';
    }

    public function isNotable(): bool
    {
        return true;
    }

    public function getPreconditions(Setup\Environment $environment): array
    {
        return [];
    }

    public function isApplicable(Setup\Environment $environment): bool
    {
        return true;
    }

    public function achieve(Setup\Environment $environment): Setup\Environment
    {
        $io = $environment->getResource(Setup\Environment::RESOURCE_ADMIN_INTERACTION);
        $inform = static function (string $message) use ($io): void {
            if ($io instanceof Setup\AdminInteraction) {
                $io->inform(PlainLogText::of($message));
            }
        };

        $target_directory = MigratedLanguageFilePaths::shippedArtifactDirectory($this->ilias_absolute_path);
        $this->ensureDirectory($target_directory);
        $this->removeLeftoverTemporaryFiles($target_directory);

        $fingerprint = $this->fingerprint();
        $previous = $this->readIndex($target_directory, $fingerprint);
        $index = [];
        $compiled = 0;
        $unchanged = 0;

        foreach ($this->findShippedFiles($inform) as $artifact_name => [$directory, $shipped_po]) {
            $artifact = $target_directory . '/' . $artifact_name;
            $source = $this->relativePath($shipped_po);
            try {
                $hash = @hash_file('sha256', $shipped_po);
                if ($hash === false) {
                    throw new \RuntimeException('it cannot be read');
                }

                $known = $previous[$artifact_name] ?? null;
                if (
                    $known !== null
                    && $known['source'] === $source
                    && $known['hash'] === $hash
                    && $this->isIntact($target_directory, $artifact, $known)
                    && $this->isNotOlderThan($artifact, $shipped_po)
                ) {
                    $index[$artifact_name] = $known;
                    $unchanged++;
                } else {
                    $index[$artifact_name] = $this->compile($target_directory, $artifact, $directory, $shipped_po, $source, $hash);
                    $compiled++;
                }
            } catch (\Throwable $t) {
                // one broken file must not abort the build - without its artifact ilLanguage
                // compiles the .po itself (and falls back to the database if that fails as well)
                $inform(sprintf('WARNING: Could not compile "%s": %s', $source, $t->getMessage()));
                $this->remove($target_directory, $artifact);
                continue;
            }

            foreach ($index[$artifact_name]['warnings'] as $warning) {
                $inform('WARNING: Markup not allowed in a language value, ' . $warning);
            }
        }

        $removed = $this->removeObsoleteArtifacts($target_directory, $index);

        AtomicFileWriter::write(
            $target_directory . '/' . self::INDEX_FILE,
            json_encode(
                ['fingerprint' => $fingerprint, 'files' => $index],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            ) . "\n",
            $target_directory,
            false
        );

        $inform(sprintf(
            'Shipped language files: %d compiled, %d unchanged, %d removed.',
            $compiled,
            $unchanged,
            $removed
        ));

        return $environment;
    }

    /**
     * Compiles $shipped_po to $artifact.
     *
     * @return IndexEntry
     * @throws \Throwable if it cannot be compiled or written
     */
    private function compile(
        string $target_directory,
        string $artifact,
        LanguageFileDirectory $directory,
        string $shipped_po,
        string $source,
        string $hash
    ): array {
        $warnings = [];
        $mo = $this->shipped_translations->compile(
            $shipped_po,
            $directory->getPrefix(),
            static function (string $identifier, array $violations) use (&$warnings, $source, $directory): void {
                $warnings[] = sprintf(
                    '%s (module "%s", key "%s"): removed %s',
                    $source,
                    $directory->getPrefix(),
                    $identifier,
                    implode(', ', $violations)
                );
            },
            static function (string $message) use (&$warnings, $source, $directory): void {
                $warnings[] = sprintf('%s (module "%s"): %s', $source, $directory->getPrefix(), $message);
            }
        );
        $this->ensureDirectory(dirname($artifact));
        AtomicFileWriter::write($artifact, $mo, $target_directory, false);

        return [
            'source' => $source,
            'hash' => $hash,
            'artifact_size' => strlen($mo),
            'artifact_hash' => hash('sha256', $mo),
            'warnings' => $warnings,
        ];
    }

    /**
     * Whether $artifact is exactly what was compiled for $known - without fsync(), a crash can leave
     * an empty or truncated file behind.
     *
     * @param IndexEntry $known
     */
    private function isIntact(string $target_directory, string $artifact, array $known): bool
    {
        clearstatcache(true, $artifact);

        return $this->isBelow($target_directory, $artifact)
            && !is_link($artifact)
            && is_file($artifact)
            && @filesize($artifact) === $known['artifact_size']
            && @hash_file('sha256', $artifact) === $known['artifact_hash'];
    }

    /**
     * Whether $artifact is not older than $shipped_po - otherwise ilLanguage would compile the .po
     * on every request. An artifact of the same content (e.g. the .po was checked out again) only
     * gets its time updated; if that is not possible (e.g. another owner), it is written again.
     */
    private function isNotOlderThan(string $artifact, string $shipped_po): bool
    {
        $po_modified = @filemtime($shipped_po);
        $artifact_modified = @filemtime($artifact);
        if ($po_modified === false || $artifact_modified === false) {
            return false;
        }

        return $artifact_modified >= $po_modified || @touch($artifact);
    }

    /**
     * Every shipped `.po` of every contributed directory with a module prefix (the main `lang/`
     * directory has none and holds no `.po`), keyed by its artifact's path below the artifact
     * directory.
     *
     * @param callable(string): void $inform
     * @return array<string, array{0: LanguageFileDirectory, 1: string}> "<lang>/<module>.mo" => [directory, shipped .po]
     */
    private function findShippedFiles(callable $inform): array
    {
        $files = [];
        foreach ($this->language_file_directory_manager->getDirectories() as $directory) {
            if ($directory->getPrefix() === '') {
                continue;
            }
            try {
                $pattern = MigratedLanguageFilePaths::shippedFileNamePattern($directory);
                $shipped_files = MigratedLanguageFilePaths::findShippedPoFiles($this->ilias_absolute_path, $directory);
            } catch (\InvalidArgumentException $e) {
                $inform(sprintf('WARNING: The language files of module "%s" are not compiled: %s', $directory->getPrefix(), $e->getMessage()));
                continue;
            }
            // files matching the pattern that yield no language key, e.g. "tos_pt_BR.po"
            $base = rtrim($this->ilias_absolute_path, '/') . '/' . ltrim($directory->getPath(), '/');
            foreach (array_diff(glob($base . sprintf($pattern, '*') . '.po') ?: [], $shipped_files) as $unknown) {
                $inform(sprintf('WARNING: "%s" is not compiled: its name contains no valid language key.', $this->relativePath($unknown)));
            }
            foreach ($shipped_files as $lang_key => $shipped_po) {
                try {
                    $artifact = MigratedLanguageFilePaths::shippedArtifactFile($this->ilias_absolute_path, $directory, $lang_key);
                } catch (\InvalidArgumentException $e) {
                    // a module name that cannot be a file name
                    $inform(sprintf('WARNING: "%s" is not compiled: %s', $this->relativePath($shipped_po), $e->getMessage()));
                    continue;
                }
                $files[$lang_key . '/' . basename($artifact)] = [$directory, $shipped_po];
            }
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /**
     * The index of the previous build - empty if there is none, it cannot be read, or it was written
     * by code that compiles differently (another fingerprint).
     *
     * @return array<string, IndexEntry>
     */
    private function readIndex(string $target_directory, string $fingerprint): array
    {
        $content = @file_get_contents($target_directory . '/' . self::INDEX_FILE);
        $data = $content === false ? null : json_decode($content, true);
        if (!is_array($data) || ($data['fingerprint'] ?? null) !== $fingerprint || !is_array($data['files'] ?? null)) {
            return [];
        }

        $index = [];
        foreach ($data['files'] as $artifact_name => $entry) {
            if (
                is_string($artifact_name)
                && is_array($entry)
                && is_string($entry['source'] ?? null)
                && is_string($entry['hash'] ?? null)
                && is_int($entry['artifact_size'] ?? null)
                && is_string($entry['artifact_hash'] ?? null)
                && is_array($entry['warnings'] ?? null)
                && array_is_list($entry['warnings'])
                && array_filter($entry['warnings'], 'is_string') === $entry['warnings']
            ) {
                $index[$artifact_name] = [
                    'source' => $entry['source'],
                    'hash' => $entry['hash'],
                    'artifact_size' => $entry['artifact_size'],
                    'artifact_hash' => $entry['artifact_hash'],
                    'warnings' => $entry['warnings'],
                ];
            }
        }

        return $index;
    }

    /**
     * Removes every `<lang>/*.mo` below $target_directory that is not in $index, and language
     * directories left empty.
     *
     * @param array<string, IndexEntry> $index
     */
    private function removeObsoleteArtifacts(string $target_directory, array $index): int
    {
        $removed = 0;
        foreach (glob($target_directory . '/*/*.mo') ?: [] as $artifact) {
            $artifact_name = basename(dirname($artifact)) . '/' . basename($artifact);
            if (!isset($index[$artifact_name]) && $this->remove($target_directory, $artifact)) {
                $removed++;
            }
        }
        foreach (glob($target_directory . '/*', GLOB_ONLYDIR) ?: [] as $language_directory) {
            if (!is_link($language_directory) && (glob($language_directory . '/*') ?: []) === []) {
                @rmdir($language_directory);
            }
        }

        return $removed;
    }

    /**
     * Temporary files of AtomicFileWriter (".<name>.<random>") a build aborted between writing and
     * renaming them left behind.
     */
    private function removeLeftoverTemporaryFiles(string $target_directory): void
    {
        $patterns = [$target_directory . '/.' . self::INDEX_FILE . '.*', $target_directory . '/*/.*.mo.*'];
        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $this->remove($target_directory, $file);
            }
        }
    }

    /**
     * Removes $file only if it lies in $target_directory or one of its direct subdirectories - a
     * symbolic link at the language directory level must not let the build delete files elsewhere.
     */
    private function remove(string $target_directory, string $file): bool
    {
        return $this->isBelow($target_directory, $file)
            && (is_file($file) || is_link($file))
            && @unlink($file);
    }

    /**
     * Whether the directory of $file resolves to $target_directory or a direct subdirectory of it
     * (following symbolic links).
     */
    private function isBelow(string $target_directory, string $file): bool
    {
        $resolved_target = realpath($target_directory);
        $resolved_directory = realpath(dirname($file));
        if ($resolved_target === false || $resolved_directory === false) {
            return false;
        }

        return $resolved_directory === $resolved_target
            || dirname($resolved_directory) === $resolved_target;
    }

    /**
     * Over the code that determines the compiled bytes, see FORMAT_VERSION.
     */
    private function fingerprint(): string
    {
        $parts = [(string) self::FORMAT_VERSION];
        // MigratedLanguageFileSync: compile() takes the module's entries from its moduleEntries()
        // PluralForms/PluralFormKey: the plural rule and the keys of plural forms in warnings
        foreach ([ShippedTranslations::class, TranslationMarkupPolicy::class, TranslationCatalog::class, TranslationEntry::class, MigratedLanguageFileSync::class, PluralForms::class, PluralFormKey::class] as $class) {
            $file = (new \ReflectionClass($class))->getFileName();
            $parts[] = is_string($file) ? (string) @hash_file('sha256', $file) : '';
        }

        return hash('sha256', implode('|', $parts));
    }

    private function relativePath(string $file): string
    {
        $root = rtrim($this->ilias_absolute_path, '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create the directory "%s".', $directory));
        }
    }
}
