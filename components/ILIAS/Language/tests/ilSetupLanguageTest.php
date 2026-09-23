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
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Class ilSetupLanguageTest
 */
class ilSetupLanguageTest extends ilLanguageBaseTestCase
{
    private ilSetupLanguage $newLangSetupDe;
    private ilSetupLanguage $newLangSetupEs;
    private array $langInstalled;
    private string $tempComponentDir = 'artifacts/test_lang_component';

    protected function setUp(): void
    {
        $this->newLangSetupDe = new ilSetupLanguage('de');
        $this->newLangSetupEs = new ilSetupLanguage('es');

        $this->langInstalled[] = $this->newLangSetupDe;
        $this->langInstalled[] = $this->newLangSetupEs;
    }

    protected function tearDown(): void
    {
        $this->cleanupTempDir();
        parent::tearDown();
    }

    private function cleanupTempDir(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        if (is_dir($fullPath)) {
            $files = glob($fullPath . '/*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($fullPath);
        }
    }

    private function createMockComponentDirectory(
        string $path = 'artifacts/test_lang_component',
        string $prefix = 'test'
    ): LanguageFileDirectory {
        return new class ($path, $prefix) implements LanguageFileDirectory {
            public function __construct(private string $path, private string $prefix)
            {
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
        };
    }

    private function createMockCustomizingDirectory(): LanguageFileDirectory
    {
        return new class ($this->tempComponentDir) implements LanguageFileDirectory {
            public function __construct(private string $path)
            {
            }

            public function getPrefix(): string
            {
                return '';
            }

            public function getPath(): string
            {
                return $this->path;
            }

            public function getSuffix(): string
            {
                return '.local';
            }

            public function isLocal(): bool
            {
                return true;
            }
        };
    }

    public function testRetrieveLanguageKey(): void
    {
        $this->assertEquals('de', $this->newLangSetupDe->getLangKey());
        $this->assertEquals('es', $this->newLangSetupEs->getLangKey());
    }

    public function testRetrieveInstalledLanguage(): void
    {
        $languagesAsKeys = [];
        foreach ($this->langInstalled as $languageAsKey) {
            $languagesAsKeys[] = $languageAsKey->getLangKey();
        }

        $this->assertContains('de', $languagesAsKeys);
        $this->assertContains('es', $languagesAsKeys);
    }

    private function callCheckLanguage(ilSetupLanguage $setup_language, string $lang_key): bool
    {
        $reflection = new ReflectionMethod($setup_language, 'checkLanguage');
        return $reflection->invoke($setup_language, $lang_key);
    }

    public function testCheckLanguageWithOnlyMainFile(): void
    {
        $mockDir = $this->createMockComponentDirectory();
        $manager = new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            $mockDir
        );
        $setup_language = new ilSetupLanguage('en', $manager);

        // 'en' exists in lang/ (MainLanguageFileDirectory) but mock component directory has no ilias_en.lang
        $this->assertTrue($this->callCheckLanguage($setup_language, 'en'));
        $this->assertTrue($this->callCheckLanguage($setup_language, 'de'));
    }

    public function testCheckLanguageWithBothMainAndComponentFiles(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        try {
            file_put_contents(
                $fullPath . '/ilias_en.lang',
                "<!-- language file start -->\ntest_key#:#test_value\n"
            );

            $mockDir = $this->createMockComponentDirectory($this->tempComponentDir, 'test');
            $manager = new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                new MainLanguageFileDirectory(),
                $mockDir
            );
            $setup_language = new ilSetupLanguage('en', $manager);

            $this->assertTrue($this->callCheckLanguage($setup_language, 'en'));
        } finally {
            $this->cleanupTempDir();
        }
    }

    public function testCheckLanguageWithMissingMainFile(): void
    {
        $mockDir = $this->createMockComponentDirectory();
        $manager = new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            $mockDir
        );
        $setup_language = new ilSetupLanguage('xx', $manager);

        // Non-existent language key must fail validation because main file is missing
        $this->assertFalse($this->callCheckLanguage($setup_language, 'xx'));
    }

    public function testCheckLanguageFailsOnInvalidComponentLanguageFile(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        try {
            // Write a component file without valid header
            file_put_contents($fullPath . '/ilias_en.lang', "invalid content without header\n");

            $mockDir = $this->createMockComponentDirectory($this->tempComponentDir, 'test');
            $manager = new LanguageFileDirectoryManager(
                new MainLanguageFileDirectory(),
                $mockDir
            );
            $setup_language = new ilSetupLanguage('en', $manager);

            $this->assertFalse($this->callCheckLanguage($setup_language, 'en'));

            // Write a component file with valid header but invalid line (wrong part count)
            file_put_contents(
                $fullPath . '/ilias_en.lang',
                "<!-- language file start -->\nonly_one_part\n"
            );
            $this->assertFalse($this->callCheckLanguage($setup_language, 'en'));
        } finally {
            $this->cleanupTempDir();
        }
    }

    public function testCheckLanguageFailsOnNonUtf8Value(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        if (!is_dir($fullPath)) {
            mkdir($fullPath, 0777, true);
        }

        try {
            file_put_contents(
                $fullPath . '/ilias_en.lang',
                "<!-- language file start -->\n" .
                "test_key#:#" . chr(0xFF) . chr(0xFE) . "\n"
            );

            $mockDir = $this->createMockComponentDirectory($this->tempComponentDir, 'test');
            $manager = new LanguageFileDirectoryManager(
                new CustomizingLanguageFileDirectory(),
                new MainLanguageFileDirectory(),
                $mockDir
            );
            $setup_language = new ilSetupLanguage('en', $manager);

            $this->assertFalse($this->callCheckLanguage($setup_language, 'en'));
        } finally {
            $this->cleanupTempDir();
        }
    }

    public function testGetInvalidLocalLanguageFilesReturnsEmptyForValidFile(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        mkdir($fullPath, 0777, true);
        file_put_contents($fullPath . '/ilias_en.lang.local', 'custom');

        $manager = new LanguageFileDirectoryManager(
            $this->createMockCustomizingDirectory(),
            new MainLanguageFileDirectory()
        );
        $setup_language = new ilSetupLanguage('en', $manager);

        $this->assertSame([], $setup_language->getInvalidLocalLanguageFiles(['en']));
    }

    public function testGetInvalidLocalLanguageFilesIgnoresMissingFile(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        mkdir($fullPath, 0777, true);

        $manager = new LanguageFileDirectoryManager(
            $this->createMockCustomizingDirectory(),
            new MainLanguageFileDirectory()
        );
        $setup_language = new ilSetupLanguage('en', $manager);

        $this->assertSame([], $setup_language->getInvalidLocalLanguageFiles(['en']));
    }

    public function testGetInvalidLocalLanguageFilesReturnsMalformedFileName(): void
    {
        $fullPath = __DIR__ . '/../../../../' . $this->tempComponentDir;
        mkdir($fullPath, 0777, true);
        file_put_contents($fullPath . '/ilias_en.lang.locl', 'custom');

        $manager = new LanguageFileDirectoryManager(
            $this->createMockCustomizingDirectory(),
            new MainLanguageFileDirectory()
        );
        $setup_language = new ilSetupLanguage('en', $manager);

        $this->assertSame(
            ['ilias_en.lang.locl'],
            $setup_language->getInvalidLocalLanguageFiles(['en'])
        );
    }

    private function createDatabaseMock(): ilDBInterface&MockObject
    {
        $db = $this->createMock(ilDBInterface::class);
        $db->method('nextId')->willReturn(42);
        $db->method('quote')->willReturnCallback(
            static fn(mixed $value): string => "'" . (string) $value . "'"
        );
        $db->method('now')->willReturn('NOW()');
        $db->method('manipulate')->willReturn(1);

        return $db;
    }

    public function testRegisterInstalledLanguageInsertsUnknownLanguage(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->logicalAnd(
                $this->stringContains(/** @lang text */ 'INSERT INTO object_data'),
                $this->stringContains("'de'"),
                $this->stringContains("'installed'")
            ))
            ->willReturn(1);

        $this->newLangSetupDe->setDbHandler($db);
        $this->newLangSetupDe->registerInstalledLanguage('de', [], []);
    }

    public function testRegisterInstalledLanguageInsertsAsInstalledLocalWhenLocalFileIsPresent(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->stringContains("'installed_local'"));

        $this->newLangSetupDe->setDbHandler($db);
        $this->newLangSetupDe->registerInstalledLanguage('de', [], ['de']);
    }

    public function testRegisterInstalledLanguageUpdatesKnownLanguage(): void
    {
        $db = $this->createDatabaseMock();
        $db->expects($this->once())
            ->method('manipulate')
            ->with($this->logicalAnd(
                $this->stringContains('UPDATE object_data'),
                $this->stringContains("'installed'"),
                $this->stringContains('obj_id = ' . "'7'")
            ))
            ->willReturn(1);

        $this->newLangSetupDe->setDbHandler($db);
        $this->newLangSetupDe->registerInstalledLanguage(
            'de',
            ['de' => ['obj_id' => 7, 'status' => 'not_installed']],
            []
        );
    }

    public function testWithoutInjectedDirectoriesOnlyTheDefaultDirectoriesAreUsed(): void
    {
        $setup_language = new ilSetupLanguage('de');

        $this->assertTrue($setup_language->usesDefaultLanguageFileDirectories());
        $this->assertSame(
            [MainLanguageFileDirectory::class, CustomizingLanguageFileDirectory::class],
            array_map(
                static fn(LanguageFileDirectory $d): string => $d::class,
                iterator_to_array($setup_language->getLanguageFileDirectoryManager()->getAllDirectories(), false)
            )
        );
    }

    /**
     * Even a manager with exactly the default directories counts as "injected" - the flag is about
     * who built the instance (component graph vs. plugin Setup objective), not about its content.
     */
    public function testAnInjectedDirectoryManagerIsUsedAndReported(): void
    {
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), new MainLanguageFileDirectory());
        $setup_language = new ilSetupLanguage('de', $manager);

        $this->assertFalse($setup_language->usesDefaultLanguageFileDirectories());
        $this->assertSame($manager, $setup_language->getLanguageFileDirectoryManager());
    }

    private function resolvedClientDataDir(ilSetupLanguage $setup_language): ?string
    {
        $manager = (new ReflectionProperty(ilSetupLanguage::class, 'manager'))->getValue($setup_language);
        $resolver = (new ReflectionProperty(\ILIAS\Language\Setup\LanguageInstallationManager::class, 'client_data_dir_resolver'))
            ->getValue($manager);

        return $resolver();
    }

    /**
     * setClientDataDir() overrides the resolution lazily (also after construction); null restores
     * MigratedLanguageFilePaths::resolveClientDataDir().
     */
    public function testSetClientDataDirOverridesTheResolutionUntilResetWithNull(): void
    {
        $setup_language = new ilSetupLanguage('de');
        $default = \ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths::resolveClientDataDir(
            (string) realpath(__DIR__ . '/../../../../')
        );

        $setup_language->setClientDataDir('/from/the/setup/environment');
        $this->assertSame('/from/the/setup/environment', $this->resolvedClientDataDir($setup_language));

        $setup_language->setClientDataDir(null);
        $this->assertSame($default, $this->resolvedClientDataDir($setup_language));
    }

    /**
     * CLI Setup has no global cache - the cache invalidator must be a silent no-op there.
     */
    public function testTheCacheInvalidatorIsANoOpWithoutGlobalCache(): void
    {
        $setup_language = new ilSetupLanguage('de');
        $manager = (new ReflectionProperty(ilSetupLanguage::class, 'manager'))->getValue($setup_language);
        $invalidator = (new ReflectionProperty(\ILIAS\Language\Setup\LanguageInstallationManager::class, 'language_cache_invalidator'))
            ->getValue($manager);

        $this->assertInstanceOf(Closure::class, $invalidator);
        $invalidator('de');
        $this->addToAssertionCount(1);
    }

    /**
     * getClientDataDir() is what the overlay is maintained in (and what Setup checks the owner of):
     * the directory set from the Setup environment, otherwise the default resolution.
     */
    public function testGetClientDataDirReturnsTheSetDirectoryOrTheDefaultResolution(): void
    {
        $setup_language = new ilSetupLanguage('de');
        $default = \ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths::resolveClientDataDir(
            (string) realpath(__DIR__ . '/../../../../')
        );

        $this->assertSame($default, $setup_language->getClientDataDir());
        $setup_language->setClientDataDir('/from/the/setup/environment');
        $this->assertSame('/from/the/setup/environment', $setup_language->getClientDataDir());
    }

    /**
     * findUnwritableOverlayDirectories() checks the overlay directories of the modules migrated for
     * the given languages in the client data directory set on this instance.
     */
    public function testFindUnwritableOverlayDirectoriesChecksTheSetClientDataDir(): void
    {
        $fixture = 'tmp-setup-unwritable-' . bin2hex(random_bytes(4));
        $client_data_dir = sys_get_temp_dir() . '/ilias_setup_unwritable_' . bin2hex(random_bytes(4));
        MigratedPoFixture::writePo(__DIR__ . '/' . $fixture . '/stest_de.po', MigratedPoFixture::catalog('stest', ['a' => 'b']));
        mkdir($client_data_dir);
        file_put_contents($client_data_dir . '/lang', 'not a directory');

        try {
            $setup_language = new ilSetupLanguage('de', new \ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager(
                new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
                MigratedPoFixture::directory('stest', 'components/ILIAS/Language/tests/' . $fixture . '/')
            ));
            $setup_language->setClientDataDir($client_data_dir);

            $this->assertSame(
                [$client_data_dir . '/lang/components/ILIAS/Language/tests/' . $fixture],
                $setup_language->findUnwritableOverlayDirectories(['de'])
            );
            $this->assertSame([], $setup_language->findUnwritableOverlayDirectories(['en']));
        } finally {
            MigratedPoFixture::removeDirectory(__DIR__ . '/' . $fixture);
            MigratedPoFixture::removeDirectory($client_data_dir);
        }
    }
}
