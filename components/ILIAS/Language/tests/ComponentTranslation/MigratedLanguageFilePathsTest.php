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

use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\NamesShippedLanguageFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * MigratedLanguageFilePaths - the single resolver of shipped/overlay paths and of the client data
 * directory (replaces the former private ilSetupLanguage::resolveClientDataDir(), which guessed the
 * client by counting subdirectories of the data directory).
 *
 * Every resolveClientDataDir() test that exercises the ilias.ini.php path runs in its own process:
 * CLIENT_DATA_DIR takes precedence and other tests of this suite define it (a constant cannot be
 * undefined again).
 */
class MigratedLanguageFilePathsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ilias_mlfp_test_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->root);
    }

    private function writeIni(string $clients_section): void
    {
        file_put_contents($this->root . '/ilias.ini.php', "; <?php exit; ?>\n[server]\nabsolute_path = \"x\"\n[clients]\n" . $clients_section);
    }

    // ------------------------------------------------ resolveClientDataDir

    /**
     * Regression: a data directory regularly holds more than the client directory (e.g. "logs").
     * The old resolver returned null here (two subdirectories); the client id comes from the ini.
     */
    #[RunInSeparateProcess]
    public function testUsesTheIniDefaultClientEvenWhenTheDataDirHoldsFurtherDirectories(): void
    {
        mkdir($this->root . '/data/default', 0775, true);
        mkdir($this->root . '/data/logs', 0775, true);
        $this->writeIni("datadir = \"{$this->root}/data\"\ndefault = \"default\"\n");

        $this->assertSame($this->root . '/data/default', MigratedLanguageFilePaths::resolveClientDataDir($this->root));
    }

    /**
     * No guessing: without "[clients] default" a single existing subdirectory is not picked.
     */
    #[RunInSeparateProcess]
    public function testDoesNotGuessTheClientFromTheOnlySubdirectory(): void
    {
        mkdir($this->root . '/data/myclient', 0775, true);
        $this->writeIni("datadir = \"{$this->root}/data\"\n");

        $this->assertNull(MigratedLanguageFilePaths::resolveClientDataDir($this->root));
    }

    #[RunInSeparateProcess]
    public function testResolvesARelativeDatadirAgainstTheIliasRoot(): void
    {
        mkdir($this->root . '/public/data/myclient', 0775, true);
        $this->writeIni("datadir = \"public/data\"\ndefault = \"myclient\"\n");

        $this->assertSame($this->root . '/public/data/myclient', MigratedLanguageFilePaths::resolveClientDataDir($this->root . '/'));
    }

    #[RunInSeparateProcess]
    public function testUsesAnAbsoluteDatadirAsIsAndToleratesATrailingSlash(): void
    {
        $datadir = $this->root . '/elsewhere';
        mkdir($datadir . '/myclient', 0775, true);
        mkdir($this->root . '/sub', 0775, true);
        file_put_contents($this->root . '/sub/ilias.ini.php', "[clients]\ndatadir = \"$datadir/\"\ndefault = \"myclient\"\n");

        $this->assertSame($datadir . '/myclient', MigratedLanguageFilePaths::resolveClientDataDir($this->root . '/sub'));
    }

    #[RunInSeparateProcess]
    #[DataProvider('unresolvableIniContents')]
    public function testReturnsNullForAnIncompleteIniOrAMissingClientDirectory(?string $clients_section): void
    {
        mkdir($this->root . '/data/default', 0775, true);
        if ($clients_section !== null) {
            $this->writeIni(str_replace('{root}', $this->root, $clients_section));
        }

        $this->assertNull(MigratedLanguageFilePaths::resolveClientDataDir($this->root));
    }

    public static function unresolvableIniContents(): array
    {
        return [
            'no ilias.ini.php' => [null],
            'no datadir' => ["default = \"default\"\n"],
            'empty datadir' => ["datadir = \"\"\ndefault = \"default\"\n"],
            'datadir is an array' => ["datadir[] = \"{root}/data\"\ndefault = \"default\"\n"],
            'no default client' => ["datadir = \"{root}/data\"\n"],
            'empty default client' => ["datadir = \"{root}/data\"\ndefault = \"\"\n"],
            'client directory does not exist (yet)' => ["datadir = \"{root}/data\"\ndefault = \"other\"\n"],
            'datadir does not exist' => ["datadir = \"{root}/nothing\"\ndefault = \"default\"\n"],
            'path traversal in client id' => ["datadir = \"{root}/data/default\"\ndefault = \"..\"\n"],
        ];
    }

    #[RunInSeparateProcess]
    public function testAnUnparsableIniYieldsNullWithoutAWarning(): void
    {
        file_put_contents($this->root . '/ilias.ini.php', "[clients\ndatadir = \"x\n");

        $this->assertNull(MigratedLanguageFilePaths::resolveClientDataDir($this->root));
    }

    /**
     * In a bootstrapped request CLIENT_DATA_DIR is authoritative - also over a (different) ini.
     */
    #[RunInSeparateProcess]
    public function testTheClientDataDirConstantWins(): void
    {
        mkdir($this->root . '/data/default', 0775, true);
        $this->writeIni("datadir = \"{$this->root}/data\"\ndefault = \"default\"\n");
        define('CLIENT_DATA_DIR', '/from/the/constant');

        $this->assertSame('/from/the/constant', MigratedLanguageFilePaths::resolveClientDataDir($this->root));
        $this->assertSame('/from/the/constant', MigratedLanguageFilePaths::resolveClientDataDir());
    }

    // --------------------------------------------- fromDataDirAndClientId

    public function testFromDataDirAndClientIdComposesAnExistingDirectory(): void
    {
        mkdir($this->root . '/client', 0775);

        $this->assertSame($this->root . '/client', MigratedLanguageFilePaths::fromDataDirAndClientId($this->root, 'client'));
        $this->assertSame($this->root . '/client', MigratedLanguageFilePaths::fromDataDirAndClientId($this->root . '//', 'client'));
    }

    #[DataProvider('invalidParts')]
    public function testFromDataDirAndClientIdRejectsMissingOrUnsafeParts(?string $data_dir, ?string $client_id): void
    {
        mkdir($this->root . '/client', 0775);
        $data_dir = $data_dir === null ? null : str_replace('{root}', $this->root, $data_dir);

        $this->assertNull(MigratedLanguageFilePaths::fromDataDirAndClientId($data_dir, $client_id));
    }

    public static function invalidParts(): array
    {
        return [
            'no data dir' => [null, 'client'],
            'empty data dir' => ['', 'client'],
            'no client id' => ['{root}', null],
            'empty client id' => ['{root}', ''],
            'dot' => ['{root}/client', '.'],
            'dot dot' => ['{root}/client', '..'],
            'slash' => ['{root}', 'client/'],
            'nested' => ['/', ltrim('{root}', '/') . '/client'],
            'backslash' => ['{root}', 'cli\\ent'],
            'nul byte' => ['{root}', "client\0"],
            'not existing' => ['{root}', 'other'],
        ];
    }

    public function testFromDataDirAndClientIdRejectsAFileInsteadOfADirectory(): void
    {
        file_put_contents($this->root . '/client', 'x');

        $this->assertNull(MigratedLanguageFilePaths::fromDataDirAndClientId($this->root, 'client'));
    }

    // -------------------------------------------------- base paths

    public function testShippedAndOverlayBasePathsShareTheRelativePart(): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->assertSame(
            '/srv/ilias/components/ILIAS/TermsOfService/lang/tos_de',
            MigratedLanguageFilePaths::shippedBasePath('/srv/ilias/', $directory, 'de')
        );
        $this->assertSame(
            '/var/iliasdata/default/lang/components/ILIAS/TermsOfService/lang/tos_de',
            MigratedLanguageFilePaths::overlayBasePath('/var/iliasdata/default/', $directory, 'de')
        );
    }

    public function testALeadingSlashOfTheDirectoryPathDoesNotEscapeTheRoot(): void
    {
        $directory = MigratedPoFixture::directory('tos', '/components/ILIAS/TermsOfService/lang/');

        $this->assertSame(
            '/srv/ilias/components/ILIAS/TermsOfService/lang/tos_en',
            MigratedLanguageFilePaths::shippedBasePath('/srv/ilias', $directory, 'en')
        );
        $this->assertSame(
            '/data/client/lang/components/ILIAS/TermsOfService/lang/tos_en',
            MigratedLanguageFilePaths::overlayBasePath('/data/client', $directory, 'en')
        );
    }

    /**
     * Regression coverage for LANGUAGE_KEY_FORMAT's trailing `\z` anchor (rather than `$`, which in
     * PCRE also matches right before a trailing newline): a language key with a trailing "\n" or
     * "\r\n" must be rejected exactly like any other malformed key, not silently accepted because it
     * ends in a line break.
     */
    #[DataProvider('invalidLangKeys')]
    public function testShippedBasePathRejectsAMalformedLangKey(string $lang_key): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->expectException(\InvalidArgumentException::class);

        MigratedLanguageFilePaths::shippedBasePath('/srv/ilias/', $directory, $lang_key);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function invalidLangKeys(): array
    {
        return [
            'trailing newline' => ["de\n"],
            'trailing CRLF' => ["de\r\n"],
            'uppercase' => ['DE'],
            'one letter' => ['d'],
            'three letters' => ['deu'],
        ];
    }

    public function testShippedBasePathAcceptsAPlainTwoLetterLangKey(): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->assertSame(
            '/srv/ilias/components/ILIAS/TermsOfService/lang/tos_de',
            MigratedLanguageFilePaths::shippedBasePath('/srv/ilias/', $directory, 'de')
        );
    }

    // -------------------------------------------------- shipped artifact paths

    public function testShippedArtifactDirectoryIsBelowArtifactsLanguageOfTheRoot(): void
    {
        $this->assertSame(
            '/srv/ilias/artifacts/language',
            MigratedLanguageFilePaths::shippedArtifactDirectory('/srv/ilias/')
        );
        $this->assertSame(
            '/srv/ilias/artifacts/language',
            MigratedLanguageFilePaths::shippedArtifactDirectory('/srv/ilias')
        );
    }

    public function testShippedArtifactFileComposesLangAndModuleBelowTheArtifactDirectory(): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->assertSame(
            '/srv/ilias/artifacts/language/de/tos.mo',
            MigratedLanguageFilePaths::shippedArtifactFile('/srv/ilias/', $directory, 'de')
        );
    }

    /**
     * The module (the directory's prefix) becomes part of a file name below the artifact directory -
     * a prefix that is not a plain file name (path traversal, a path separator) must be rejected
     * rather than silently building a path outside the intended `<lang>/` subdirectory.
     */
    #[DataProvider('unsafeModuleNames')]
    public function testShippedArtifactFileRejectsAModuleThatIsNotAPlainFileName(string $module): void
    {
        $directory = MigratedPoFixture::directory($module, 'somewhere/');

        $this->expectException(\InvalidArgumentException::class);

        MigratedLanguageFilePaths::shippedArtifactFile('/srv/ilias/', $directory, 'de');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unsafeModuleNames(): array
    {
        return [
            'path traversal' => ['../../etc/passwd'],
            'slash' => ['tos/evil'],
            'backslash' => ['tos\\evil'],
            'nul byte' => ["tos\0evil"],
            'empty' => [''],
        ];
    }

    /**
     * Regression coverage mirroring testShippedBasePathRejectsAMalformedLangKey(): the artifact path
     * is just as much a path-traversal-relevant sink as the shipped base path.
     */
    #[DataProvider('invalidLangKeys')]
    public function testShippedArtifactFileRejectsAMalformedLangKey(string $lang_key): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->expectException(\InvalidArgumentException::class);

        MigratedLanguageFilePaths::shippedArtifactFile('/srv/ilias/', $directory, $lang_key);
    }

    // -------------------------------------------------- shipped file name pattern

    /**
     * A directory implementing NamesShippedLanguageFiles with a given pattern.
     */
    private function directoryWithPattern(string $prefix, string $path, string $pattern): LanguageFileDirectory
    {
        return new class ($prefix, $path, $pattern) implements LanguageFileDirectory, NamesShippedLanguageFiles {
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

    #[DataProvider('invalidShippedFileNamePatterns')]
    public function testAssertValidShippedFileNamePatternRejectsInvalidPatterns(string $pattern): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MigratedLanguageFilePaths::assertValidShippedFileNamePattern($pattern);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidShippedFileNamePatterns(): array
    {
        return [
            'no %s at all' => ['x'],
            'slash' => ['a/%s'],
            'path traversal' => ['..%s'],
            'two placeholders' => ['%s%s'],
            'another "%" besides the placeholder' => ['ilias_%d%s'],
        ];
    }

    #[DataProvider('validShippedFileNamePatterns')]
    public function testAssertValidShippedFileNamePatternAcceptsValidPatterns(string $pattern): void
    {
        MigratedLanguageFilePaths::assertValidShippedFileNamePattern($pattern);
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function validShippedFileNamePatterns(): array
    {
        return [
            'prefix-based default shape' => ['tos_%s'],
            'stable, module-independent shape' => ['ilias_%s'],
            'dot and dash are allowed' => ['ilias-plugin.v2_%s'],
            'placeholder at the very start' => ['%s_suffix'],
        ];
    }

    public function testShippedFileNamePatternFallsBackToPrefixUnderscoreForAPlainDirectory(): void
    {
        $directory = MigratedPoFixture::directory('tos', 'components/ILIAS/TermsOfService/lang/');

        $this->assertSame('tos_%s', MigratedLanguageFilePaths::shippedFileNamePattern($directory));
    }

    public function testShippedFileNamePatternUsesTheDirectorysOwnPatternWhenItImplementsTheInterface(): void
    {
        $directory = $this->directoryWithPattern('tos', 'components/ILIAS/TermsOfService/lang/', 'ilias_%s');

        $this->assertSame('ilias_%s', MigratedLanguageFilePaths::shippedFileNamePattern($directory));
    }

    public function testShippedBasePathUsesTheDirectorysOwnShippedFileNamePattern(): void
    {
        $directory = $this->directoryWithPattern('tos', 'components/ILIAS/TermsOfService/lang/', 'ilias_%s');

        $this->assertSame(
            '/srv/ilias/components/ILIAS/TermsOfService/lang/ilias_de',
            MigratedLanguageFilePaths::shippedBasePath('/srv/ilias/', $directory, 'de')
        );
    }

    /**
     * Overlay and build-artifact paths stay purely module-based (the prefix) even for a directory
     * with the "ilias_%s" shipped pattern - only the shipped `.po` itself moves.
     */
    public function testOverlayAndArtifactPathsStayModuleBasedEvenWithTheIliasSchema(): void
    {
        $directory = $this->directoryWithPattern('tos', 'components/ILIAS/TermsOfService/lang/', 'ilias_%s');

        $this->assertSame(
            '/data/client/lang/components/ILIAS/TermsOfService/lang/tos_de',
            MigratedLanguageFilePaths::overlayBasePath('/data/client', $directory, 'de')
        );
        $this->assertSame(
            '/srv/ilias/artifacts/language/de/tos.mo',
            MigratedLanguageFilePaths::shippedArtifactFile('/srv/ilias', $directory, 'de')
        );
    }

    public function testFindShippedPoFilesListsOnlyFilesMatchingThePatternKeyedByLangKeySorted(): void
    {
        mkdir($this->root . '/lang', 0775, true);
        touch($this->root . '/lang/ilias_de.po');
        touch($this->root . '/lang/ilias_en.po');
        touch($this->root . '/lang/ilias_fr.po');
        // does not yield a plain two-letter language key - must not be listed
        touch($this->root . '/lang/ilias_pt_BR.po');
        // wrong extension / wrong prefix - must not be listed either
        touch($this->root . '/lang/ilias_de.lang');
        touch($this->root . '/lang/other_de.po');
        $directory = $this->directoryWithPattern('tos', 'lang/', 'ilias_%s');

        $files = MigratedLanguageFilePaths::findShippedPoFiles($this->root, $directory);

        $this->assertSame(['de', 'en', 'fr'], array_keys($files));
        $this->assertSame($this->root . '/lang/ilias_de.po', $files['de']);
    }

    public function testFindShippedPoFilesWithThePlainPrefixPattern(): void
    {
        mkdir($this->root . '/lang', 0775, true);
        touch($this->root . '/lang/tos_de.po');
        touch($this->root . '/lang/tos_pt_BR.po');
        $directory = MigratedPoFixture::directory('tos', 'lang/');

        $files = MigratedLanguageFilePaths::findShippedPoFiles($this->root, $directory);

        $this->assertSame(['de'], array_keys($files));
    }

    public function testFindShippedPoFilesThrowsForAnInvalidPattern(): void
    {
        $directory = $this->directoryWithPattern('tos', 'lang/', 'x');

        $this->expectException(\InvalidArgumentException::class);

        MigratedLanguageFilePaths::findShippedPoFiles($this->root, $directory);
    }

    // -------------------------------------------------- templateFileName()

    #[DataProvider('templateFileNamePatterns')]
    public function testTemplateFileName(string $pattern, string $module, string $expected): void
    {
        $this->assertSame($expected, MigratedLanguageFilePaths::templateFileName($pattern, $module));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function templateFileNamePatterns(): array
    {
        return [
            'prefix-based default shape' => ['tos_%s', 'tos', 'tos.pot'],
            'stable, module-independent shape' => ['ilias_%s', 'tos', 'ilias.pot'],
            'placeholder alone falls back to the module' => ['%s', 'tos', 'tos.pot'],
            'dashes and dots around the placeholder are trimmed too' => ['ilias-plugin.v2_%s', 'tos', 'ilias-plugin.v2.pot'],
            'placeholder at the very start' => ['%s_suffix', 'tos', 'suffix.pot'],
        ];
    }
}
