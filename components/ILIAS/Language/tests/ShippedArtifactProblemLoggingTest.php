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

use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * ilLanguage::readMigratedLanguageFile()'s distinction between a MISSING build artifact (logged as a
 * Notice, and only ONCE per request via the static $missing_shipped_artifact_logged flag - a missing
 * build affects every migrated module, not just the one currently requested) and a BROKEN/unreadable
 * one (logged as a Warning, every time it happens - not deduplicated).
 *
 * Uses a throwaway ILIAS_ABSOLUTE_PATH (not the real repository root PoMigrationLoadLanguageModuleTest
 * uses for its real 'tos' fixture): this class never builds a real artifact, so it never needs to
 * write anything below the real, gitignored `artifacts/` directory of this checkout.
 *
 * Runs every test method in its own separate process for the same reason as
 * PoMigrationLoadLanguageModuleTest/UninstallRemovesMigratedMoFilesTest: CLIENT_DATA_DIR is a PHP
 * constant that cannot be redefined, and a full-suite run may already have it defined.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
class ShippedArtifactProblemLoggingTest extends ilLanguageBaseTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/ilias_shipped_problem_logging_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', $this->root);
        }
        if (!defined('CLIENT_DATA_DIR')) {
            mkdir($this->root . '/clientdata', 0775, true);
            define('CLIENT_DATA_DIR', $this->root . '/clientdata');
        }

        // Both are static state shared for the rest of this process (see class.ilLanguage.php) - must
        // be reset here exactly like PoMigrationLoadLanguageModuleTest resets the file cache, or a
        // result/flag from an earlier test method would silently leak into this one.
        (new ReflectionClass(ilLanguage::class))->getProperty('migrated_language_file_cache')->setValue(null, []);
        (new ReflectionClass(ilLanguage::class))->getProperty('missing_shipped_artifact_logged')->setValue(null, false);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @param array<string, string> $entries identifier => value
     */
    private function writeShippedPo(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        MigratedPoFixture::writePo(
            $this->root . '/' . $module . '_' . $lang_key . '.po',
            MigratedPoFixture::catalog($module, $entries)
        );

        return MigratedPoFixture::directory($module, '/');
    }

    private function writeCorruptArtifact(string $module, string $lang_key): void
    {
        $artifact = \ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths::shippedArtifactFile(
            $this->root,
            MigratedPoFixture::directory($module, '/'),
            $lang_key
        );
        if (!is_dir(dirname($artifact))) {
            mkdir(dirname($artifact), 0775, true);
        }
        file_put_contents($artifact, 'not a valid mo file at all, but long enough to pass the size check');
        touch($artifact, time() + 10);
    }

    private function registerDirectoryManager(LanguageFileDirectory ...$contributed): void
    {
        $this->setGlobalVariable(
            LanguageFileDirectoryManager::class,
            new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), ...$contributed)
        );
    }

    private function stubLogger(): PHPUnit\Framework\MockObject\MockObject&ilLogger
    {
        $logger = $this->createMock(ilLogger::class);
        $logger_factory = $this->createStub(ilLoggerFactory::class);
        $logger_factory->method('getComponentLogger')->willReturn($logger);
        $this->setGlobalVariable('ilLoggerFactory', $logger_factory);

        return $logger;
    }

    /**
     * @return array<string, string>|null
     */
    private function callLoadFromMigratedLanguageFile(string $module, string $lang_key): ?array
    {
        return (new ReflectionClass(ilLanguage::class))
            ->getMethod('loadFromMigratedLanguageFile')
            ->invoke(null, $module, $lang_key);
    }

    /**
     * A single missing artifact: a Notice, not a Warning.
     */
    public function testAMissingArtifactIsLoggedAsANoticeNotAWarning(): void
    {
        $directory = $this->writeShippedPo('modmiss', 'de', ['greeting' => 'Hallo']);
        $this->registerDirectoryManager($directory);
        $logger = $this->stubLogger();
        $logger->expects($this->never())->method('warning');
        $logger->expects($this->once())->method('notice')->with($this->stringContains('setup.php build'));

        $result = $this->callLoadFromMigratedLanguageFile('modmiss', 'de');

        $this->assertSame(['greeting' => 'Hallo'], $result);
    }

    /**
     * A broken (unreadable) artifact: a Warning, not a Notice.
     */
    public function testABrokenArtifactIsLoggedAsAWarningNotANotice(): void
    {
        $directory = $this->writeShippedPo('modbroken', 'de', ['greeting' => 'Hallo']);
        $this->writeCorruptArtifact('modbroken', 'de');
        $this->registerDirectoryManager($directory);
        $logger = $this->stubLogger();
        $logger->expects($this->never())->method('notice');
        $logger->expects($this->once())->method('warning')->with($this->stringContains('cannot be read'));

        $result = $this->callLoadFromMigratedLanguageFile('modbroken', 'de');

        $this->assertSame(['greeting' => 'Hallo'], $result);
    }

    /**
     * The very point of the flag: a build missing entirely affects EVERY migrated module, so the
     * Notice must be logged exactly once per request - even though two independently migrated
     * modules each individually hit "artifact missing" here.
     */
    public function testAMissingArtifactIsReportedOnlyOnceAcrossTwoDifferentModules(): void
    {
        $first = $this->writeShippedPo('modone', 'de', ['greeting' => 'Hallo']);
        $second = $this->writeShippedPo('modtwo', 'de', ['greeting' => 'Servus']);
        $this->registerDirectoryManager($first, $second);
        $logger = $this->stubLogger();
        $logger->expects($this->once())->method('notice');

        $this->assertSame(['greeting' => 'Hallo'], $this->callLoadFromMigratedLanguageFile('modone', 'de'));
        $this->assertSame(['greeting' => 'Servus'], $this->callLoadFromMigratedLanguageFile('modtwo', 'de'));
    }

    /**
     * A broken artifact, by contrast, is NOT deduplicated by the same flag: it is specific to the one
     * module/language pair that failed, so two independently broken modules are each reported.
     */
    public function testABrokenArtifactIsReportedForEachAffectedModuleIndependently(): void
    {
        $first = $this->writeShippedPo('modbrokenone', 'de', ['greeting' => 'Hallo']);
        $this->writeCorruptArtifact('modbrokenone', 'de');
        $second = $this->writeShippedPo('modbrokentwo', 'de', ['greeting' => 'Servus']);
        $this->writeCorruptArtifact('modbrokentwo', 'de');
        $this->registerDirectoryManager($first, $second);
        $logger = $this->stubLogger();
        $logger->expects($this->exactly(2))->method('warning');

        $this->assertSame(['greeting' => 'Hallo'], $this->callLoadFromMigratedLanguageFile('modbrokenone', 'de'));
        $this->assertSame(['greeting' => 'Servus'], $this->callLoadFromMigratedLanguageFile('modbrokentwo', 'de'));
    }
}
