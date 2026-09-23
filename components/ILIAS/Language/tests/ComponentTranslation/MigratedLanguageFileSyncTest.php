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
use ILIAS\Language\ComponentTranslation\Gettext\Catalog;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use ILIAS\Language\ComponentTranslation\MainLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of MigratedLanguageFileSync (sync(), loadShippedModules(), loadModuleTranslations(),
 * getMigratedModules(), removeOverlay()), independent of its callers.
 *
 * Two roots, mirroring production:
 * - the SHIPPED `.po` lives below ILIAS_ABSOLUTE_PATH ($this->fixture_directory) and must never be
 *   written by the class under test;
 * - the OVERLAY `.po`/`.mo` lives below a throwaway client data directory ($this->client_data_dir).
 */
class MigratedLanguageFileSyncTest extends TestCase
{
    private const string MODULE = 'stest';

    private string $fixture_directory;
    private string $client_data_dir;
    private LanguageFileDirectory $directory;
    private LanguageFileDirectoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }

        $this->fixture_directory = __DIR__ . '/tmp-sync-fixtures-' . bin2hex(random_bytes(4));
        mkdir($this->fixture_directory, 0775, true);
        $this->client_data_dir = sys_get_temp_dir() . '/ilias_mlfs_test_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);

        $this->directory = MigratedPoFixture::directory(
            self::MODULE,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '/'
        );
        $this->manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory);
    }

    protected function tearDown(): void
    {
        MigratedPoFixture::removeDirectory($this->fixture_directory);
        MigratedPoFixture::removeDirectory($this->client_data_dir);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, string|array<string, mixed>> $entries see MigratedPoFixture::catalog()
     */
    private function seedShipped(array $entries, string $lang_key = 'de', string $module = self::MODULE): void
    {
        MigratedPoFixture::writePo($this->shippedPo($lang_key, $module), MigratedPoFixture::catalog($module, $entries));
    }

    /**
     * @param array<string, string|array<string, mixed>> $entries see MigratedPoFixture::catalog()
     */
    private function seedOverlay(array $entries, string $lang_key = 'de'): void
    {
        MigratedPoFixture::writePair($this->overlayBase($lang_key), MigratedPoFixture::catalog(self::MODULE, $entries));
    }

    private function shippedPo(string $lang_key = 'de', string $module = self::MODULE): string
    {
        return $this->fixture_directory . '/' . $module . '_' . $lang_key . '.po';
    }

    private function overlayBase(string $lang_key = 'de'): string
    {
        return $this->client_data_dir . '/lang/components/ILIAS/Language/tests/ComponentTranslation/'
            . basename($this->fixture_directory) . '/' . self::MODULE . '_' . $lang_key;
    }

    private function overlayPo(): Catalog
    {
        return MigratedPoFixture::readPo($this->overlayBase() . '.po');
    }

    /**
     * @param array<string, string> $entries
     */
    private function sync(array $entries, bool $refresh = false, ?string $client_data_dir = 'default'): void
    {
        MigratedLanguageFileSync::sync(
            $this->manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            self::MODULE,
            $entries,
            $client_data_dir === 'default' ? $this->client_data_dir : $client_data_dir,
            $refresh
        );
    }

    /**
     * @return list<string>
     */
    private function overlayIdentifiers(): array
    {
        return array_map(static fn($entry): string => $entry->getId(), $this->overlayPo()->getEntries());
    }

    // ------------------------------------------------------- overlay creation

    /**
     * The overlay mirrors "language installed": a missing one is created by every sync, also by an
     * ordinary admin edit (refresh = false) - the removed $create_missing_mo no longer gates it.
     */
    #[DataProvider('refreshFlags')]
    public function testCreatesAMissingOverlayFromTheShippedPo(bool $refresh): void
    {
        $this->seedShipped(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $shipped_before = file_get_contents($this->shippedPo());

        $this->sync(['greeting' => 'Hallo', 'farewell' => 'Tschüss'], $refresh);

        $this->assertFileExists($this->overlayBase() . '.po');
        $this->assertSame(
            ['farewell' => 'Tschüss', 'greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo')
        );
        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertNull(LocalChangeComments::getLocalChange($greeting));
        $this->assertSame($shipped_before, file_get_contents($this->shippedPo()), 'the shipped .po is never written');
    }

    public static function refreshFlags(): array
    {
        return ['admin edit (refresh = false)' => [false], 'reconciling write (refresh = true)' => [true]];
    }

    public function testCreatingTheOverlayWithADeviatingValueRecordsShippedOriginalAndALocalChange(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);

        $this->sync(['greeting' => 'Servus']);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $greeting->getTranslation());
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) LocalChangeComments::getLocalChange($greeting)
        );
    }

    public function testCreatingTheOverlayGivesAnEntryWithoutShippedCounterpartNoOriginalButALocalChange(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);

        $this->sync(['greeting' => 'Hallo', 'custom' => 'Eigener Wert']);

        $custom = $this->overlayPo()->find(self::MODULE, 'custom');
        $this->assertSame('Eigener Wert', $custom->getTranslation());
        $this->assertNull(LocalChangeComments::getOriginal($custom));
        $this->assertNotNull(LocalChangeComments::getLocalChange($custom));
    }

    /**
     * A shipped "fuzzy" (an unreviewed value, usually the English fallback) must survive the very
     * first sync as long as the value is still the shipped one - and must not be copied onto a
     * value that differs from it.
     */
    public function testCreatingTheOverlayKeepsShippedFuzzyOnlyForTheUnchangedShippedValue(): void
    {
        $this->seedShipped([
            'unchanged' => ['value' => 'Hello', 'fuzzy' => true],
            'changed' => ['value' => 'Bye', 'fuzzy' => true],
            'reviewed' => ['value' => 'Hallo'],
        ]);

        $this->sync(['unchanged' => 'Hello', 'changed' => 'Tschüss', 'reviewed' => 'Hallo']);

        $overlay = $this->overlayPo();
        $this->assertTrue($overlay->find(self::MODULE, 'unchanged')->hasFlag('fuzzy'));
        $this->assertFalse($overlay->find(self::MODULE, 'changed')->hasFlag('fuzzy'));
        $this->assertFalse($overlay->find(self::MODULE, 'reviewed')->hasFlag('fuzzy'));
    }

    /**
     * Extracted comments ("#. ...", context for translators) are part of the shipped file and are
     * carried into the overlay on creation/refresh.
     */
    public function testTheOverlayTakesTheExtractedCommentsOfTheShippedEntry(): void
    {
        $catalog = MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Hallo']);
        $catalog->find(self::MODULE, 'greeting')->addExtractedComment('shown on the login page');
        MigratedPoFixture::writePo($this->shippedPo(), $catalog);

        $this->sync(['greeting' => 'Servus']);

        $this->assertSame(['shown on the login page'], $this->overlayPo()->find(self::MODULE, 'greeting')->getExtractedComments());
    }

    public function testTheOverlayTakesTheHeadersOfTheShippedPo(): void
    {
        $catalog = MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Hallo']);
        $catalog->setHeader('Language', 'de');
        $catalog->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
        MigratedPoFixture::writePo($this->shippedPo(), $catalog);

        $this->sync(['greeting' => 'Hallo']);

        $this->assertSame('de', $this->overlayPo()->getHeader('Language'));
        $this->assertSame('nplurals=2; plural=(n != 1);', $this->overlayPo()->getHeader('Plural-Forms'));
    }

    // ------------------------------------------------------------ admin edit

    /**
     * refresh = false (an ordinary admin edit): the baseline "original" must never move - even when
     * the shipped file changed in the meantime.
     */
    public function testAnAdminEditNeverMovesTheOriginal(): void
    {
        $this->seedShipped(['greeting' => 'Hallo, neu']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);

        $this->sync(['greeting' => 'Servus']);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $greeting->getTranslation());
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertNotNull(LocalChangeComments::getLocalChange($greeting));
    }

    public function testAnAdminEditRemovesFuzzyOnlyFromTheValuesItActuallyChanges(): void
    {
        $this->seedShipped([
            'kept' => ['value' => 'Hello', 'fuzzy' => true],
            'edited' => ['value' => 'Bye', 'fuzzy' => true],
        ]);
        $this->seedOverlay([
            'kept' => ['value' => 'Hello', 'original' => 'Hello', 'fuzzy' => true],
            'edited' => ['value' => 'Bye', 'original' => 'Bye', 'fuzzy' => true],
        ]);

        $this->sync(['kept' => 'Hello', 'edited' => 'Tschüss']);

        $overlay = $this->overlayPo();
        $this->assertTrue($overlay->find(self::MODULE, 'kept')->hasFlag('fuzzy'));
        $this->assertFalse($overlay->find(self::MODULE, 'edited')->hasFlag('fuzzy'));
    }

    public function testWritingTheOriginalValueBackClearsTheLocalChange(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);

        $this->sync(['greeting' => 'Hallo']);

        $this->assertNull(LocalChangeComments::getLocalChange($this->overlayPo()->find(self::MODULE, 'greeting')));
    }

    /**
     * A full-replace write re-sends every value; an entry whose value did not change must keep its
     * existing local_change timestamp (seeded with a fixed, old value - no sleep()/clock needed).
     */
    public function testTheLocalChangeTimestampOfAnUntouchedEntrySurvivesAnUnrelatedWrite(): void
    {
        $this->seedShipped(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
        ]);

        $this->sync(['greeting' => 'Servus', 'farewell' => 'Pfiat di']);

        $overlay = $this->overlayPo();
        $this->assertSame('2020-01-01T00:00:00Z', LocalChangeComments::getLocalChange($overlay->find(self::MODULE, 'greeting')));
        $farewell_change = LocalChangeComments::getLocalChange($overlay->find(self::MODULE, 'farewell'));
        $this->assertNotNull($farewell_change);
        $this->assertNotSame('2020-01-01T00:00:00Z', $farewell_change);
    }

    // ------------------------------------------------------ reconciling write

    public function testRefreshMovesTheOriginalToTheNewShippedValueAndClearsTheLocalChangeOfAnUnmodifiedEntry(): void
    {
        $this->seedShipped(['greeting' => 'Hallo, überarbeitet']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);

        $this->sync(['greeting' => 'Hallo, überarbeitet'], true);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Hallo, überarbeitet', $greeting->getTranslation());
        $this->assertSame('Hallo, überarbeitet', LocalChangeComments::getOriginal($greeting));
        $this->assertNull(LocalChangeComments::getLocalChange($greeting));
    }

    public function testRefreshMovesTheOriginalButKeepsALocalCustomizationFlagged(): void
    {
        $this->seedShipped(['greeting' => 'Hallo, überarbeitet']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);

        $this->sync(['greeting' => 'Servus'], true);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $greeting->getTranslation());
        $this->assertSame('Hallo, überarbeitet', LocalChangeComments::getOriginal($greeting));
        $this->assertSame('2020-01-01T00:00:00Z', LocalChangeComments::getLocalChange($greeting));
    }

    public function testRefreshRemovesTheOriginalOfAnEntryTheShippedPoNoLongerContains(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'dropped' => ['value' => 'Alt', 'original' => 'Alt'],
        ]);

        $this->sync(['greeting' => 'Hallo', 'dropped' => 'Alt'], true);

        $dropped = $this->overlayPo()->find(self::MODULE, 'dropped');
        $this->assertNull(LocalChangeComments::getOriginal($dropped));
        $this->assertNotNull(LocalChangeComments::getLocalChange($dropped), 'without a baseline it counts as local');
    }

    /**
     * On refresh fuzzy is recomputed from the shipped file: set exactly when the value is the shipped
     * value and the shipped entry is fuzzy.
     *
     * @param array<string, mixed> $shipped
     * @param array<string, mixed> $overlay
     */
    #[DataProvider('refreshFuzzyCases')]
    public function testRefreshRecomputesFuzzyFromTheShippedEntry(
        array $shipped,
        array $overlay,
        string $value,
        bool $expected_fuzzy
    ): void {
        $this->seedShipped(['greeting' => $shipped]);
        $this->seedOverlay(['greeting' => $overlay]);

        $this->sync(['greeting' => $value], true);

        $this->assertSame($expected_fuzzy, $this->overlayPo()->find(self::MODULE, 'greeting')->hasFlag('fuzzy'));
    }

    public static function refreshFuzzyCases(): array
    {
        return [
            'shipped fuzzy, value is shipped' => [
                ['value' => 'Hello', 'fuzzy' => true], ['value' => 'Hello', 'original' => 'Hello'], 'Hello', true,
            ],
            'shipped fuzzy, value differs' => [
                ['value' => 'Hello', 'fuzzy' => true], ['value' => 'Hallo', 'original' => 'Hello', 'fuzzy' => true], 'Hallo', false,
            ],
            'shipped reviewed now, overlay still fuzzy' => [
                ['value' => 'Hallo'], ['value' => 'Hallo', 'original' => 'Hello', 'fuzzy' => true], 'Hallo', false,
            ],
        ];
    }

    // ------------------------------------------------ replace + ordering + guard

    public function testReplaceSemanticsRemoveEveryEntryThatIsNotPassed(): void
    {
        $this->seedShipped(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
        ]);

        $this->sync(['greeting' => 'Hallo']);

        $this->assertSame(['greeting'], $this->overlayIdentifiers());
        $this->assertSame(['greeting' => 'Hallo'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    public function testSyncingNoEntriesEmptiesTheOverlayButKeepsItAndTheShippedFile(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);

        $this->sync([]);

        $this->assertSame([], $this->overlayIdentifiers());
        $this->assertSame([], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
        $this->assertNotNull(MigratedPoFixture::readPo($this->shippedPo())->find(self::MODULE, 'greeting'));
    }

    /**
     * Entries of a different context (another module) are never taken over into this module's
     * overlay: the overlay holds exactly $entries.
     */
    public function testEntriesOfAnotherContextInTheOldOverlayAreNotCarriedOver(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'foreign' => ['value' => 'Fremd', 'context' => 'other_module'],
        ]);

        $this->sync(['greeting' => 'Hallo']);

        $this->assertNull($this->overlayPo()->find('other_module', 'foreign'));
    }

    /**
     * Sorted byte-wise by identifier, independent of the order of $entries - keeps the file
     * diff-stable. SORT_STRING: upper case before lower case, "10" before "9".
     */
    public function testEntriesAreWrittenSortedByIdentifier(): void
    {
        $this->seedShipped(['b' => 'B']);

        $this->sync(['b' => 'B', 'a' => 'A', 'C' => 'C', '9' => 'neun', '10' => 'zehn']);

        $this->assertSame(['10', '9', 'C', 'a', 'b'], $this->overlayIdentifiers());
    }

    /**
     * No-op guard: an unchanged `.po` with an existing `.mo` writes nothing. Proven via a sentinel
     * `.mo` content that a rewrite would replace.
     */
    public function testAnUnchangedOverlayIsNotRewritten(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->sync(['greeting' => 'Servus']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        file_put_contents($this->overlayBase() . '.mo', 'SENTINEL');

        $this->sync(['greeting' => 'Servus']);
        $this->sync(['greeting' => 'Servus'], true);

        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame('SENTINEL', file_get_contents($this->overlayBase() . '.mo'));
    }

    public function testAMissingMoIsRecompiledEvenWhenThePoIsUnchanged(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->sync(['greeting' => 'Hallo']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        unlink($this->overlayBase() . '.mo');

        $this->sync(['greeting' => 'Hallo']);

        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame(['greeting' => 'Hallo'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    public function testAChangedValueRewritesBothFiles(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->sync(['greeting' => 'Hallo']);
        file_put_contents($this->overlayBase() . '.mo', 'SENTINEL');

        $this->sync(['greeting' => 'Servus']);

        $this->assertSame(['greeting' => 'Servus'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    public function testASyncInvalidatesTheIlLanguageReadCache(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $cache = new ReflectionProperty(ilLanguage::class, 'migrated_language_file_cache');
        $cache->setValue(null, [self::MODULE . '|de' => ['greeting' => 'stale'], 'other|de' => ['x' => 'y']]);

        try {
            $this->sync(['greeting' => 'Servus']);

            $this->assertSame(['other|de' => ['x' => 'y']], $cache->getValue());
        } finally {
            $cache->setValue(null, []);
        }
    }

    // --------------------------------------------------------------- no-ops

    public function testIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);

        MigratedLanguageFileSync::sync($this->manager, ILIAS_ABSOLUTE_PATH, 'de', 'other_module', ['x' => 'y'], $this->client_data_dir);
        MigratedLanguageFileSync::sync($this->manager, ILIAS_ABSOLUTE_PATH, 'de', '', ['x' => 'y'], $this->client_data_dir, true);

        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    public function testIsANoOpWhenNoShippedPoExistsForTheLanguage(): void
    {
        $this->seedShipped(['greeting' => 'Hello'], 'en');

        $this->sync(['greeting' => 'Hallo'], true);

        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    public function testIsANoOpWhenTheClientDataDirIsNull(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $shipped_before = file_get_contents($this->shippedPo());

        $this->sync(['greeting' => 'Servus'], true, null);

        $this->assertSame($shipped_before, file_get_contents($this->shippedPo()));
        $this->assertSame(['stest_de.po'], array_values(array_diff(scandir($this->fixture_directory), ['.', '..'])));
    }

    // -------------------------------------------------------------- failures

    /**
     * A corrupt overlay `.po` is rebuilt like a missing one (original/local_change recomputed against
     * the shipped file) instead of blocking every further write.
     */
    #[DataProvider('refreshFlags')]
    public function testACorruptOverlayPoIsRebuiltFromEntriesAndTheShippedPo(bool $refresh): void
    {
        $this->seedShipped(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        file_put_contents($this->overlayBase() . '.po', "msgid \"broken\nmsgstr \"\"\n");

        $this->sync(['greeting' => 'Hallo', 'farewell' => 'Servus'], $refresh);

        $overlay = $this->overlayPo();
        $this->assertSame(['farewell', 'greeting'], $this->overlayIdentifiers());
        $this->assertNull(LocalChangeComments::getLocalChange($overlay->find(self::MODULE, 'greeting')));
        $farewell = $overlay->find(self::MODULE, 'farewell');
        $this->assertSame('Tschüss', LocalChangeComments::getOriginal($farewell));
        $this->assertNotNull(LocalChangeComments::getLocalChange($farewell));
        $this->assertSame(
            ['farewell' => 'Servus', 'greeting' => 'Hallo'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo')
        );
    }

    public function testACorruptShippedPoThrows(): void
    {
        file_put_contents($this->shippedPo(), "msgid \"broken\n");

        $this->expectException(RuntimeException::class);
        $this->sync(['greeting' => 'Hallo']);
    }

    /**
     * Root-proof write failure: a regular file sits where the overlay's "lang" directory has to be
     * created - mkdir() fails for every user, root included.
     */
    public function testThrowsWhenTheOverlayDirectoryCannotBeCreated(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        file_put_contents($this->client_data_dir . '/lang', 'not a directory');

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/Could not create directory/');
            $this->sync(['greeting' => 'Servus']);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Root-proof write failure of the file itself: a directory sits at the overlay `.po` path, so the
     * final rename() cannot replace it. The temporary file must not be left behind.
     */
    public function testThrowsWhenTheOverlayPoCannotBeReplacedAndLeavesNoTemporaryFile(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        mkdir($this->overlayBase() . '.po', 0775, true);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->sync(['greeting' => 'Servus']);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Could not replace', $e->getMessage());
        } finally {
            restore_error_handler();
        }

        $this->assertSame(
            ['stest_de.po'],
            array_values(array_diff(scandir(dirname($this->overlayBase())), ['.', '..'])),
            'neither a temporary file nor a .mo may be left behind'
        );
    }

    /**
     * The client data directory (or one of its parents) being a symlink is common (e.g. a data volume
     * mounted elsewhere). tempnam() resolves symlinks, so AtomicFileWriter's "same directory" check
     * rejected every write (regression test for that bug).
     */
    public function testWritesTheOverlayWhenTheClientDataDirIsASymlink(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $link = $this->client_data_dir . '-link';
        symlink($this->client_data_dir, $link);

        try {
            MigratedLanguageFileSync::sync($this->manager, ILIAS_ABSOLUTE_PATH, 'de', self::MODULE, ['greeting' => 'Servus'], $link);
        } finally {
            unlink($link);
        }

        $this->assertSame(['greeting' => 'Servus'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    // -------------------------------------------------------- loadShippedModules

    public function testLoadShippedModulesReturnsTheShippedValuesOfEveryMigratedModuleForTheLanguage(): void
    {
        $second_directory = MigratedPoFixture::directory('stwo', $this->directory->getPath());
        $not_migrated = MigratedPoFixture::directory('nomig', $this->directory->getPath());
        $manager = new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            $this->directory,
            $second_directory,
            $not_migrated
        );
        $this->seedShipped([
            'greeting' => 'Hallo',
            'foreign' => ['value' => 'Fremd', 'context' => 'other_module'],
        ]);
        $this->seedShipped(['farewell' => 'Tschüss'], 'de', 'stwo');
        $this->seedShipped(['farewell' => 'Bye'], 'en', 'stwo');
        $this->seedShipped(['x' => 'only english'], 'en', 'nomig');

        $this->assertSame(
            [self::MODULE => ['greeting' => 'Hallo'], 'stwo' => ['farewell' => 'Tschüss']],
            MigratedLanguageFileSync::loadShippedModules($manager, ILIAS_ABSOLUTE_PATH, 'de')
        );
    }

    public function testLoadShippedModulesReturnsAnEmptyListForAModuleWhoseShippedPoIsEmpty(): void
    {
        $this->seedShipped([]);

        $this->assertSame(
            [self::MODULE => []],
            MigratedLanguageFileSync::loadShippedModules($this->manager, ILIAS_ABSOLUTE_PATH, 'de')
        );
    }

    // ---------------------------------------------------- loadModuleTranslations

    public function testLoadModuleTranslationsReturnsValueLocalChangeDateAndOriginalPerEntry(): void
    {
        $this->seedOverlay([
            'changed' => ['value' => 'Servus', 'original' => 'Hallo', 'local_change' => '2026-09-21T10:11:12Z'],
            'unchanged' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
            'foreign' => ['value' => 'x', 'context' => 'other_module'],
        ]);

        $this->assertSame(
            [
                'changed' => [
                    'value' => 'Servus',
                    'local_change' => true,
                    'local_change_date' => '2026-09-21 10:11:12',
                    'original' => 'Hallo',
                ],
                'unchanged' => [
                    'value' => 'Tschüss',
                    'local_change' => false,
                    'local_change_date' => null,
                    'original' => 'Tschüss',
                ],
            ],
            MigratedLanguageFileSync::loadModuleTranslations($this->manager, 'de', self::MODULE, $this->client_data_dir)
        );
    }

    #[DataProvider('incompleteOverlayFiles')]
    public function testLoadModuleTranslationsAndGetMigratedModulesRequireBothOverlayFiles(string $missing): void
    {
        $this->seedOverlay(['greeting' => 'Hallo']);
        unlink($this->overlayBase() . $missing);

        $this->assertNull(MigratedLanguageFileSync::loadModuleTranslations($this->manager, 'de', self::MODULE, $this->client_data_dir));
        $this->assertSame([], MigratedLanguageFileSync::getMigratedModules($this->manager, 'de', $this->client_data_dir));
    }

    public static function incompleteOverlayFiles(): array
    {
        return ['no .mo' => ['.mo'], 'no .po' => ['.po']];
    }

    public function testGetMigratedModulesListsModulesWithACompleteOverlayForTheLanguageOnly(): void
    {
        $this->seedOverlay(['greeting' => 'Hallo']);

        $this->assertSame([self::MODULE], MigratedLanguageFileSync::getMigratedModules($this->manager, 'de', $this->client_data_dir));
        $this->assertSame([], MigratedLanguageFileSync::getMigratedModules($this->manager, 'en', $this->client_data_dir));
        $this->assertSame([], MigratedLanguageFileSync::getMigratedModules($this->manager, 'de', null));
    }

    // ------------------------------------------------------------ removeOverlay

    public function testRemoveOverlayDeletesBothOverlayFilesButLeavesTheShippedPoUntouched(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => 'Hallo']);
        $shipped_before = file_get_contents($this->shippedPo());

        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);

        $this->assertFileDoesNotExist($this->overlayBase() . '.po');
        $this->assertFileDoesNotExist($this->overlayBase() . '.mo');
        $this->assertSame($shipped_before, file_get_contents($this->shippedPo()));
    }

    public function testRemoveOverlayOnlyRemovesTheRequestedLanguage(): void
    {
        $this->seedOverlay(['greeting' => 'Hallo'], 'de');
        $this->seedOverlay(['greeting' => 'Hello'], 'en');

        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);

        $this->assertFileExists($this->overlayBase('en') . '.po');
        $this->assertFileExists($this->overlayBase('en') . '.mo');
    }

    public function testRemoveOverlayIsANoOpWithoutOverlayUnknownModuleOrClientDataDir(): void
    {
        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);
        $this->seedOverlay(['greeting' => 'Hallo']);

        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', 'other_module', $this->client_data_dir);
        MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, null);

        $this->assertFileExists($this->overlayBase() . '.po');
        $this->assertFileExists($this->overlayBase() . '.mo');
    }

    public function testRemoveOverlayThrowsWhenAnOverlayFileCannotBeRemoved(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('A non-removable file cannot be forced for root (no root-proof variant for unlink()).');
        }
        $this->seedOverlay(['greeting' => 'Hallo']);
        $overlay_directory = dirname($this->overlayBase());
        chmod($overlay_directory, 0555);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/overlay file/i');
            MigratedLanguageFileSync::removeOverlay($this->manager, 'de', self::MODULE, $this->client_data_dir);
        } finally {
            restore_error_handler();
            chmod($overlay_directory, 0775);
        }
    }
}
