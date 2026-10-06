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

use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\ShippedTranslationsBuild;
use ILIAS\Language\Setup\ShippedLanguageFilesCompiledObjective;
use ILIAS\Setup\AdminInteraction;
use ILIAS\Setup\Environment;
use PHPUnit\Framework\TestCase;

/**
 * ShippedLanguageFilesCompiledObjective: Setup's build step building every shipped `.po` for native
 * gettext (see ShippedTranslationsBuild) - an unchanged intact build is kept, a damaged one rebuilt,
 * obsolete files removed, warning (without aborting) about markup that had to be cleaned or a `.po`
 * that could not be compiled, and never writing outside the artifact directory.
 */
class ShippedLanguageFilesCompiledObjectiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // never the build of the installation, see MigratedPoFixture::resetRuntime()
        MigratedPoFixture::resetRuntime();
        $this->root = sys_get_temp_dir() . '/ilias_shipped_build_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->root);
    }

    /**
     * @param array<string, string> $entries identifier => value
     */
    private function writeShippedPo(string $relative_path, string $module, string $lang_key, array $entries): void
    {
        $directory = $this->root . '/' . trim($relative_path, '/');
        MigratedPoFixture::writePo($directory . '/' . $module . '_' . $lang_key . '.po', MigratedPoFixture::catalog($module, $entries));
    }

    private function directoryManager(\ILIAS\Language\ComponentTranslation\LanguageFileDirectory ...$directories): LanguageFileDirectoryManager
    {
        return new LanguageFileDirectoryManager(new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(), ...$directories);
    }

    /**
     * @param list<string> $messages filled with every message inform() receives, in order
     */
    private function informer(array &$messages): AdminInteraction
    {
        $messages = [];
        $io = $this->createStub(AdminInteraction::class);
        $io->method('inform')->willReturnCallback(function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        return $io;
    }

    private function achieve(LanguageFileDirectoryManager $manager, ?AdminInteraction $io = null): void
    {
        $objective = new ShippedLanguageFilesCompiledObjective($manager, $this->root);
        $environment = $this->createStub(Environment::class);
        $environment->method('getResource')->willReturn($io);
        $objective->achieve($environment);
    }

    /**
     * The catalog of $module/$lang_key in the build current.json names - a path that does not exist
     * without one.
     */
    private function artifactPath(string $lang_key, string $module): string
    {
        $artifact_directory = MigratedLanguageFilePaths::shippedArtifactDirectory($this->root);
        $build = ShippedTranslationsBuild::readPointer($artifact_directory)['build'] ?? null;
        if ($build === null) {
            return $artifact_directory . '/no-build.mo';
        }

        return MigratedLanguageFilePaths::buildMoFile(MigratedLanguageFilePaths::buildDirectory($artifact_directory, $build), $module, $lang_key);
    }

    public function testCompilesAShippedPoIntoAnArtifact(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');

        $this->achieve($this->directoryManager($directory));

        $this->assertFileExists($this->artifactPath('de', 'itest'));
        $this->assertSame(
            ['greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->artifactPath('de', 'itest'))
        );
    }

    /**
     * The main `lang/` directory (empty prefix) holds no `.po` at all and must simply be skipped, not
     * cause an error.
     */
    public function testSkipsADirectoryWithoutAPrefix(): void
    {
        $directory = new \ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory();
        mkdir($this->root . '/lang', 0775, true);

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($directory), $io);

        $this->assertStringContainsString('no migrated module, 0 compiled', $messages[array_key_last($messages)]);
    }

    /**
     * Second run with nothing changed: the artifact is not recompiled (same hash, same source, and
     * the artifact still exists) - reported as "unchanged", not "compiled".
     */
    public function testASecondRunWithNoChangesReportsTheArtifactAsUnchanged(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);
        $first_mtime = filemtime($this->artifactPath('de', 'itest'));

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString('unchanged, 0 compiled', $messages[array_key_last($messages)]);
        $this->assertSame($first_mtime, filemtime($this->artifactPath('de', 'itest')));
    }

    /**
     * A changed `.po` gives a new build (every `.po` compiled into a new directory - a running
     * process never reloads a catalog file it has read), the previous build is kept.
     */
    public function testAChangedPoGivesANewBuildAndKeepsThePreviousOne(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $this->writeShippedPo('utest/', 'utest', 'de', ['greeting' => 'Servus']);
        $manager = $this->directoryManager(
            MigratedPoFixture::directory('itest', 'itest/'),
            MigratedPoFixture::directory('utest', 'utest/')
        );
        $this->achieve($manager);
        $previous = dirname($this->artifactPath('de', 'utest'), 3);

        // change only "itest"'s shipped .po - the incremental decision is by content hash, not mtime
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo geändert']);
        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString('2 compiled', $messages[array_key_last($messages)]);
        $this->assertNotSame($previous, dirname($this->artifactPath('de', 'utest'), 3));
        $this->assertDirectoryExists($previous, 'the previous build is kept');
        $this->assertSame(
            ['greeting' => 'Hallo geändert'],
            MigratedPoFixture::readMo($this->artifactPath('de', 'itest'))
        );
    }

    /**
     * A `.po` that no longer exists must have its artifact removed on the next build.
     */
    public function testRemovesAnArtifactWhoseShippedPoNoLongerExists(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);
        $this->assertFileExists($this->artifactPath('de', 'itest'));

        unlink($this->root . '/itest/itest_de.po');
        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertFileDoesNotExist($this->artifactPath('de', 'itest'));
        $this->assertStringContainsString('no migrated module, 0 compiled', $messages[array_key_last($messages)]);
    }

    /**
     * Markup TranslationMarkupPolicy does not allow is compiled cleaned and warned about - the build
     * must not abort, and the artifact must still be produced (with the cleaned value).
     */
    public function testWarnsAboutDisallowedMarkupButStillCompilesTheCleanedArtifact(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => '<script>alert(1)</script>Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($directory), $io);

        $warning = implode("\n", $messages);
        $this->assertStringContainsString('WARNING: Markup not allowed in a language value', $warning);
        $this->assertStringContainsString('itest', $warning);
        $this->assertStringContainsString('greeting', $warning);
        $this->assertFileExists($this->artifactPath('de', 'itest'));
        $translations = MigratedPoFixture::readMo($this->artifactPath('de', 'itest'));
        $this->assertStringNotContainsString('<script', $translations['greeting']);
    }

    /**
     * A markup warning is repeated on every run, not just the one that compiled it - until the `.po`
     * is fixed (see the class docblock).
     */
    public function testRepeatsTheMarkupWarningOnASubsequentUnchangedRun(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => '<script>x</script>Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString('WARNING: Markup not allowed', implode("\n", $messages));
    }

    /**
     * A `.po` that cannot be compiled (syntactically broken) is reported and its artifact removed -
     * the build itself does not abort, and other, valid modules are still compiled.
     */
    public function testABrokenPoIsReportedAndDoesNotAbortTheBuild(): void
    {
        mkdir($this->root . '/broken', 0775, true);
        file_put_contents($this->root . '/broken/broken_de.po', "msgid \"kaputt\n");
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $manager = $this->directoryManager(
            MigratedPoFixture::directory('broken', 'broken/'),
            MigratedPoFixture::directory('itest', 'itest/')
        );

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $warning = implode("\n", $messages);
        $this->assertStringContainsString('WARNING: Could not compile', $warning);
        $this->assertStringContainsString('broken', $warning);
        $this->assertFileDoesNotExist($this->artifactPath('de', 'broken'));
        $this->assertFileExists($this->artifactPath('de', 'itest'));
    }

    /**
     * Every write must stay confined to the artifact directory below the ILIAS root passed to the
     * constructor - AtomicFileWriter::write() is given that directory as $confine_to_directory, so a
     * symbolic link swapped into the artifact tree cannot redirect a write elsewhere. Proven here by
     * making the language-specific subdirectory a symlink pointing outside the artifact tree.
     */
    public function testNeverWritesOutsideTheArtifactDirectoryEvenViaASymlinkedLanguageDirectory(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $outside = $this->root . '/outside-de';
        mkdir($outside, 0775, true);
        mkdir(MigratedLanguageFilePaths::shippedArtifactDirectory($this->root), 0775, true);
        symlink($outside, MigratedLanguageFilePaths::shippedArtifactDirectory($this->root) . '/de');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($directory), $io);

        $this->assertSame([], array_values(array_diff(scandir($outside) ?: [], ['.', '..'])));
    }

    public function testGetHashChangesWhenAContributedDirectoryChanges(): void
    {
        $manager_one = $this->directoryManager(MigratedPoFixture::directory('itest', 'itest/'));
        $manager_two = $this->directoryManager(MigratedPoFixture::directory('other', 'other/'));

        $objective_one = new ShippedLanguageFilesCompiledObjective($manager_one, $this->root);
        $objective_two = new ShippedLanguageFilesCompiledObjective($manager_two, $this->root);

        $this->assertNotSame($objective_one->getHash(), $objective_two->getHash());
    }

    // ------------------------------------------------------------ Round 2: index integrity (artifact_size/artifact_hash)

    /**
     * The index also records the SIZE and HASH of the artifact itself (not only the source .po's
     * hash): a truncated/corrupted artifact (e.g. by a crash without fsync(), see the class docblock)
     * must be rebuilt, not kept as "unchanged" merely because its .po did not change.
     */
    public function testATruncatedArtifactIsRebuiltEvenThoughItsShippedPoDidNotChange(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);

        // simulate a crash that left a truncated artifact behind - same source .po, damaged artifact
        // (not the file this process has just read through native gettext, see the fixture)
        MigratedPoFixture::moveArtifactsToFreshDirectory();
        $full = (string) file_get_contents($this->artifactPath('de', 'itest'));
        file_put_contents($this->artifactPath('de', 'itest'), substr($full, 0, 10));

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString(', 1 compiled', $messages[array_key_last($messages)]);
        $this->assertSame(
            ['greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->artifactPath('de', 'itest'))
        );
    }

    /**
     * An artifact whose bytes were silently changed (same size, different content - e.g. a bit flip)
     * is caught by the hash check the size check alone would miss, and rebuilt.
     */
    public function testAnArtifactWithTheSameSizeButDifferentContentIsRebuilt(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);

        MigratedPoFixture::moveArtifactsToFreshDirectory();
        $original = (string) file_get_contents($this->artifactPath('de', 'itest'));
        $tampered = substr($original, 0, -1) . ($original[-1] === "\x00" ? "\x01" : "\x00");
        file_put_contents($this->artifactPath('de', 'itest'), $tampered);
        $this->assertSame(strlen($original), strlen($tampered), 'precondition: same size, different content');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString(', 1 compiled', $messages[array_key_last($messages)]);
    }

    /**
     * A build whose manifest cannot be read is never trusted: it is rebuilt.
     */
    public function testABrokenManifestCausesARebuild(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);

        file_put_contents(dirname($this->artifactPath('de', 'itest'), 3) . '/manifest.json', '{not valid json');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString(', 1 compiled', $messages[array_key_last($messages)]);
    }

    /**
     * The index.json of the former layout (here even a broken one) does not abort the build - it is
     * removed as obsolete.
     */
    public function testAnIndexJsonOfTheFormerLayoutIsRemoved(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        mkdir(MigratedLanguageFilePaths::shippedArtifactDirectory($this->root), 0775, true);
        file_put_contents(MigratedLanguageFilePaths::shippedArtifactDirectory($this->root) . '/index.json', '{not valid json');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($directory), $io);

        $this->assertStringContainsString(', 1 compiled', $messages[array_key_last($messages)]);
        $this->assertFileExists($this->artifactPath('de', 'itest'));
        $this->assertFileDoesNotExist(MigratedLanguageFilePaths::shippedArtifactDirectory($this->root) . '/index.json');
    }

    /**
     * Identical content but a newer .po (e.g. a checkout reset its mtime): the build is decided by
     * content, it stays unchanged and is not rewritten.
     */
    public function testATouchedButOtherwiseIdenticalPoKeepsTheBuild(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $manager = $this->directoryManager($directory);
        $this->achieve($manager);
        $artifact_inode_before = fileinode($this->artifactPath('de', 'itest'));

        // the .po's mtime is newer than the artifact's (e.g. a checkout reset it) - achieved by
        // moving the ARTIFACT into the past, never the .po into the future (clock skew territory the
        // touch()-then-check logic does not have to cope with)
        touch($this->artifactPath('de', 'itest'), time() - 1000);

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($manager, $io);

        $this->assertStringContainsString('unchanged, 0 compiled', $messages[array_key_last($messages)]);
        clearstatcache(true, $this->artifactPath('de', 'itest'));
        $this->assertSame($artifact_inode_before, fileinode($this->artifactPath('de', 'itest')), 'the artifact is not rewritten (same inode)');
    }

    // ------------------------------------------------------------ Round 2: robustness

    /**
     * A .po that cannot even be hashed (unreadable) is a generic \Throwable, not one of the two
     * specific failure branches above - it, too, must not abort the whole build.
     */
    public function testAnUnreadablePoIsReportedAndDoesNotAbortTheBuild(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot make a file unreadable while running as root; skipping.');
        }
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $this->writeShippedPo('unreadable/', 'unreadable', 'de', ['greeting' => 'Hallo']);
        chmod($this->root . '/unreadable/unreadable_de.po', 0000);
        $manager = $this->directoryManager(
            MigratedPoFixture::directory('itest', 'itest/'),
            MigratedPoFixture::directory('unreadable', 'unreadable/')
        );

        try {
            $messages = [];
            $io = $this->informer($messages);
            $this->achieve($manager, $io);
        } finally {
            chmod($this->root . '/unreadable/unreadable_de.po', 0664);
        }

        $warning = implode("\n", $messages);
        $this->assertStringContainsString('WARNING: Could not compile', $warning);
        $this->assertStringContainsString('unreadable', $warning);
        $this->assertFileExists($this->artifactPath('de', 'itest'));
    }

    /**
     * Leftover temporary files of AtomicFileWriter (".<name>.<random>") from a build that crashed
     * between writing and renaming them must be cleaned up on the next build - they must not
     * accumulate indefinitely, and must not be mistaken for real artifacts anywhere.
     */
    public function testRemovesLeftoverTemporaryFilesFromAnAbortedPreviousBuild(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $target_directory = MigratedLanguageFilePaths::shippedArtifactDirectory($this->root);
        mkdir($target_directory . '/de', 0775, true);
        file_put_contents($target_directory . '/.index.json.ab12cd', 'leftover');
        file_put_contents($target_directory . '/de/.itest.mo.ab12cd', 'leftover');

        $this->achieve($this->directoryManager($directory));

        $this->assertFileDoesNotExist($target_directory . '/.index.json.ab12cd');
        $this->assertFileDoesNotExist($target_directory . '/de/.itest.mo.ab12cd');
    }

    /**
     * removeObsoleteArtifacts() must not delete through a symlinked LANGUAGE directory: a `.mo` no
     * longer in the index but reached only via a symlink pointing outside the artifact tree must be
     * left alone - isBelow()'s realpath() check applies to deletions exactly as it does to writes
     * (see testNeverWritesOutsideTheArtifactDirectoryEvenViaASymlinkedLanguageDirectory()).
     */
    public function testDoesNotDeleteAnObsoleteArtifactReachedOnlyThroughASymlinkedLanguageDirectory(): void
    {
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $directory = MigratedPoFixture::directory('itest', 'itest/');
        $target_directory = MigratedLanguageFilePaths::shippedArtifactDirectory($this->root);
        mkdir($target_directory, 0775, true);
        $outside = $this->root . '/outside-language-dir';
        mkdir($outside, 0775, true);
        file_put_contents($outside . '/obsolete.mo', 'not tracked by the index at all');
        symlink($outside, $target_directory . '/xx');

        $this->achieve($this->directoryManager($directory));

        $this->assertFileExists($outside . '/obsolete.mo');
    }

    /**
     * The build log must never carry raw control characters (e.g. an ANSI escape sequence or a
     * bidirectional override smuggled in via a translation identifier) through to the terminal -
     * withoutControlCharacters() replaces them with "?".
     */
    public function testControlCharactersInABuildLogMessageAreReplaced(): void
    {
        $catalog = new \ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog();
        $entry = \MigratedPoFixture::entry('itest', "bad\x1bkey", '<script>x</script>Hallo');
        $catalog->add($entry);
        MigratedPoFixture::writePo($this->root . '/itest/itest_de.po', $catalog);
        $directory = MigratedPoFixture::directory('itest', 'itest/');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($directory), $io);

        $warning = implode("\n", $messages);
        $this->assertStringNotContainsString("\x1b", $warning);
        $this->assertStringContainsString('bad?key', $warning);
    }

    // ------------------------------------------------------------ shipped file name pattern

    private function directoryWithPattern(string $prefix, string $path, string $pattern): \ILIAS\Language\ComponentTranslation\LanguageFileDirectory
    {
        return new class ($prefix, $path, $pattern) implements
            \ILIAS\Language\ComponentTranslation\LanguageFileDirectory,
            \ILIAS\Language\ComponentTranslation\NamesShippedLanguageFiles {
            public function __construct(
                private string $prefix,
                private string $path,
                private string $pattern
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
                return '';
            }

            public function isLocal(): bool
            {
                return false;
            }

            public function getShippedFileNamePattern(): string
            {
                return $this->pattern;
            }
        };
    }

    /**
     * A module using the "ilias_%s" schema: its shipped `.po` is found and compiled into the same
     * module-based artifact path as any other module.
     */
    public function testFindsAndCompilesAShippedPoNamedByTheIliasSchema(): void
    {
        mkdir($this->root . '/itest', 0775, true);
        MigratedPoFixture::writePo(
            $this->root . '/itest/ilias_de.po',
            MigratedPoFixture::catalog('itest', ['greeting' => 'Hallo'])
        );
        $directory = $this->directoryWithPattern('itest', 'itest/', 'ilias_%s');

        $this->achieve($this->directoryManager($directory));

        $this->assertFileExists($this->artifactPath('de', 'itest'));
        $this->assertSame(
            ['greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->artifactPath('de', 'itest'))
        );
    }

    /**
     * An invalid shipped file name pattern is reported as a warning and skips only that module - the
     * build does not abort, and other, validly-patterned modules are still compiled.
     */
    public function testAnInvalidShippedFileNamePatternWarnsAndDoesNotAbortTheBuild(): void
    {
        $invalid = $this->directoryWithPattern('badmod', 'badmod/', 'no-placeholder-at-all');
        $this->writeShippedPo('itest/', 'itest', 'de', ['greeting' => 'Hallo']);
        $valid = MigratedPoFixture::directory('itest', 'itest/');

        $messages = [];
        $io = $this->informer($messages);
        $this->achieve($this->directoryManager($invalid, $valid), $io);

        $warning = implode("\n", $messages);
        $this->assertStringContainsString('WARNING', $warning);
        $this->assertStringContainsString('badmod', $warning);
        $this->assertFileExists($this->artifactPath('de', 'itest'));
    }

    public function testIsNotableAndHasNoPreconditions(): void
    {
        $objective = new ShippedLanguageFilesCompiledObjective(
            $this->directoryManager(),
            $this->root
        );

        $this->assertTrue($objective->isNotable());
        $this->assertSame([], $objective->getPreconditions($this->createStub(Environment::class)));
        $this->assertTrue($objective->isApplicable($this->createStub(Environment::class)));
    }
}
