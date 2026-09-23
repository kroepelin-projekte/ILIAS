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
use ILIAS\Language\ComponentTranslation\Catalog\TranslationCatalog;
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

    private function overlayPo(): TranslationCatalog
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

    /**
     * An entry the shipped `.po` no longer contains (kept by the caller as a real local change, see
     * LanguageInstallationManager::resolveMigratedModule()) keeps its last "original" and its
     * local_change date - so a later update can still tell it from an unchanged entry.
     */
    public function testRefreshKeepsTheOriginalOfAnEntryTheShippedPoNoLongerContains(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'dropped' => ['value' => 'Neu', 'original' => 'Alt', 'local_change' => '2020-01-01T00:00:00Z'],
        ]);

        $this->sync(['greeting' => 'Hallo', 'dropped' => 'Neu'], true);

        $dropped = $this->overlayPo()->find(self::MODULE, 'dropped');
        $this->assertSame('Alt', LocalChangeComments::getOriginal($dropped));
        $this->assertSame('2020-01-01T00:00:00Z', LocalChangeComments::getLocalChange($dropped));
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
     * No-op guard: an unchanged `.po` next to a `.mo` that holds exactly what TranslationCatalog::toMoString() compiles writes
     * nothing - neither file is replaced (AtomicFileWriter renames a new file into place, so a
     * rewrite would change the inode) and the read cache is not invalidated.
     */
    #[DataProvider('refreshFlags')]
    public function testAnUnchangedOverlayWithACorrectMoWritesNothing(bool $refresh): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->sync(['greeting' => 'Servus']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        $mo_before = file_get_contents($this->overlayBase() . '.mo');
        $po_inode = fileinode($this->overlayBase() . '.po');
        $mo_inode = fileinode($this->overlayBase() . '.mo');
        $cache = new ReflectionProperty(ilLanguage::class, 'migrated_language_file_cache');
        $cache->setValue(null, [self::MODULE . '|de' => ['greeting' => 'cached']]);

        try {
            $this->sync(['greeting' => 'Servus'], $refresh);

            $this->assertSame([self::MODULE . '|de' => ['greeting' => 'cached']], $cache->getValue(), 'no invalidation');
        } finally {
            $cache->setValue(null, []);
        }
        clearstatcache();
        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame($mo_before, file_get_contents($this->overlayBase() . '.mo'));
        $this->assertSame($po_inode, fileinode($this->overlayBase() . '.po'), 'the .po was not replaced');
        $this->assertSame($mo_inode, fileinode($this->overlayBase() . '.mo'), 'the .mo was not replaced');
    }

    public function testAMissingMoIsRecompiledEvenWhenThePoIsUnchanged(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->sync(['greeting' => 'Hallo']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        $mo_before = file_get_contents($this->overlayBase() . '.mo');
        $po_inode = fileinode($this->overlayBase() . '.po');
        unlink($this->overlayBase() . '.mo');

        $this->sync(['greeting' => 'Hallo']);

        clearstatcache();
        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame($po_inode, fileinode($this->overlayBase() . '.po'), 'the unchanged .po is not rewritten');
        $this->assertSame(['greeting' => 'Hallo'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
        $this->assertSame($mo_before, file_get_contents($this->overlayBase() . '.mo'));
    }

    /**
     * Self-healing: a `.mo` that does not hold exactly what TranslationCatalog::toMoString() compiles from the (unchanged)
     * `.po` - garbage, truncated, empty, or the compiled content of another catalog - is rebuilt,
     * byte for byte, although the `.po` itself is left untouched. ilLanguage serves the `.mo`, so
     * without this a broken `.mo` would be served until the next actual value change.
     */
    #[DataProvider('staleMoContents')]
    public function testAStaleMoNextToAnUnchangedPoIsRebuilt(\Closure $stale_content, bool $refresh): void
    {
        $this->seedShipped(['greeting' => 'Hallo', 'farewell' => 'Tschüss']);
        $this->sync(['greeting' => 'Servus', 'farewell' => 'Tschüss']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        $po_inode = fileinode($this->overlayBase() . '.po');
        $mo_before = file_get_contents($this->overlayBase() . '.mo');
        file_put_contents($this->overlayBase() . '.mo', $stale_content($mo_before));
        $cache = new ReflectionProperty(ilLanguage::class, 'migrated_language_file_cache');
        $cache->setValue(null, [self::MODULE . '|de' => ['greeting' => 'stale']]);

        try {
            $this->sync(['greeting' => 'Servus', 'farewell' => 'Tschüss'], $refresh);

            $this->assertSame([], $cache->getValue(), 'the rebuilt .mo invalidates the read cache');
        } finally {
            $cache->setValue(null, []);
        }

        clearstatcache();
        $mo_after = file_get_contents($this->overlayBase() . '.mo');
        $this->assertSame($mo_before, $mo_after);
        $this->assertSame(MigratedPoFixture::readPo($this->overlayBase() . '.po')->toMoString(), $mo_after);
        $this->assertSame(
            ['farewell' => 'Tschüss', 'greeting' => 'Servus'],
            MigratedPoFixture::readMo($this->overlayBase() . '.mo')
        );
        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame($po_inode, fileinode($this->overlayBase() . '.po'), 'the unchanged .po is not rewritten');
    }

    public static function staleMoContents(): array
    {
        return [
            'garbage' => [static fn(string $mo): string => 'SENTINEL', false],
            'truncated' => [static fn(string $mo): string => substr($mo, 0, intdiv(strlen($mo), 2)), false],
            'one byte missing' => [static fn(string $mo): string => substr($mo, 0, -1), false],
            'empty' => [static fn(string $mo): string => '', false],
            'another catalog' => [
                static fn(string $mo): string => MigratedPoFixture::catalog(self::MODULE, ['greeting' => 'Veraltet'])->toMoString(),
                false,
            ],
            'garbage, reconciling write' => [static fn(string $mo): string => 'SENTINEL', true],
        ];
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

    // ---------------------------------------------------------- value "0"

    /**
     * gettext/gettext treats a falsy translation as absent; the adapter's workaround must hold on
     * the whole way through sync(): "0" (it occurs in the legacy ilias_fr.lang/ilias_pl.lang) is
     * written as `msgstr "0"`, served from the `.mo` as "0", is no local change when shipped that
     * way - and re-syncing it is a no-op (the `.mo` byte comparison must see identical output).
     */
    public function testTheValueZeroSurvivesTheSyncAndResyncingIsANoOp(): void
    {
        $this->seedShipped(['zero' => '0', 'greeting' => 'Hallo']);

        $this->sync(['zero' => '0', 'greeting' => 'Hallo']);

        $po = file_get_contents($this->overlayBase() . '.po');
        $this->assertStringContainsString("msgid \"zero\"\nmsgstr \"0\"\n", $po);
        $this->assertSame(['greeting' => 'Hallo', 'zero' => '0'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
        $zero = $this->overlayPo()->find(self::MODULE, 'zero');
        $this->assertSame('0', LocalChangeComments::getOriginal($zero));
        $this->assertNull(LocalChangeComments::getLocalChange($zero));

        $po_inode = fileinode($this->overlayBase() . '.po');
        $mo_inode = fileinode($this->overlayBase() . '.mo');
        $this->sync(['zero' => '0', 'greeting' => 'Hallo'], true);
        $this->sync(['zero' => '0', 'greeting' => 'Hallo']);

        clearstatcache();
        $this->assertSame($po_inode, fileinode($this->overlayBase() . '.po'), 'the .po was not replaced');
        $this->assertSame($mo_inode, fileinode($this->overlayBase() . '.mo'), 'the .mo was not replaced');
    }

    public function testAValueChangedToZeroIsALocalChangeAndServedAsZero(): void
    {
        $this->seedShipped(['count' => '1']);
        $this->sync(['count' => '1']);

        $this->sync(['count' => '0']);

        $count = $this->overlayPo()->find(self::MODULE, 'count');
        $this->assertSame('0', $count?->getTranslation());
        $this->assertSame('1', LocalChangeComments::getOriginal($count));
        $this->assertNotNull(LocalChangeComments::getLocalChange($count));
        $this->assertSame(['count' => '0'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    // ------------------------------------------------- multi-line originals

    /**
     * A shipped value with line breaks or backslashes is stored as "original" losslessly: re-syncing
     * the unchanged value is no local change - neither on creation nor on a reconciling write - and
     * produces byte-identical files (formerly the flattened "original" never matched the value again).
     */
    public function testAnUnchangedMultiLineShippedValueIsNoLocalChangeAndResyncingIsANoOp(): void
    {
        $shipped = ['lf' => "Zeile 1\nZeile 2", 'crlf' => "Zeile 1\r\nZeile 2", 'backslash' => 'C:\\pfad', 'literal' => 'a\\nb'];
        $this->seedShipped($shipped);

        $this->sync($shipped);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        $this->sync($shipped, true);
        $this->sync($shipped);

        $overlay = $this->overlayPo();
        foreach ($shipped as $identifier => $value) {
            $entry = $overlay->find(self::MODULE, $identifier);
            $this->assertSame($value, $entry->getTranslation(), $identifier);
            $this->assertSame($value, LocalChangeComments::getOriginal($entry), $identifier);
            $this->assertNull(LocalChangeComments::getLocalChange($entry), $identifier);
        }
        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $expected_mo = $shipped;
        ksort($expected_mo);
        $this->assertSame($expected_mo, $this->sortedMo());
    }

    public function testChangingOnlyTheLineBreakOfAMultiLineValueIsALocalChange(): void
    {
        $this->seedShipped(['lf' => "Zeile 1\nZeile 2"]);
        $this->sync(['lf' => "Zeile 1\nZeile 2"]);

        $this->sync(['lf' => "Zeile 1\r\nZeile 2"]);

        $entry = $this->overlayPo()->find(self::MODULE, 'lf');
        $this->assertSame("Zeile 1\nZeile 2", LocalChangeComments::getOriginal($entry));
        $this->assertNotNull(LocalChangeComments::getLocalChange($entry));
    }

    /**
     * An overlay written by an earlier version has the multi-line original flattened into spaces - a
     * reconciling write (refresh) replaces it by the losslessly escaped shipped value and clears the
     * resulting false local change.
     */
    public function testAReconcilingWriteRepairsALegacyFlattenedOriginal(): void
    {
        $this->seedShipped(['lf' => "Zeile 1\nZeile 2"]);
        $catalog = MigratedPoFixture::catalog(self::MODULE, ['lf' => ['value' => "Zeile 1\nZeile 2", 'local_change' => '2020-01-01T00:00:00Z']]);
        $catalog->find(self::MODULE, 'lf')->addTranslatorComment('original: Zeile 1 Zeile 2');
        MigratedPoFixture::writePair($this->overlayBase(), $catalog);

        $this->sync(['lf' => "Zeile 1\nZeile 2"], true);

        $entry = $this->overlayPo()->find(self::MODULE, 'lf');
        $this->assertSame("Zeile 1\nZeile 2", LocalChangeComments::getOriginal($entry));
        $this->assertNull(LocalChangeComments::getLocalChange($entry));
        $this->assertSame(
            ['original_escaped: Zeile 1\\nZeile 2'],
            array_values(array_filter($entry->getTranslatorComments(), static fn(string $c): bool => str_starts_with($c, 'original')))
        );
    }

    /**
     * An "original: " comment of the legacy format containing a backslash is read verbatim: an admin
     * edit of another entry keeps the untouched entry unchanged and not locally changed.
     */
    public function testALegacyOriginalWithABackslashIsReadVerbatimByAnAdminEdit(): void
    {
        $this->seedShipped(['path' => 'C:\\pfad', 'greeting' => 'Hallo']);
        $catalog = MigratedPoFixture::catalog(self::MODULE, ['path' => 'C:\\pfad', 'greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        $catalog->find(self::MODULE, 'path')->addTranslatorComment('original: C:\\pfad');
        MigratedPoFixture::writePair($this->overlayBase(), $catalog);

        $this->sync(['path' => 'C:\\pfad', 'greeting' => 'Servus']);

        $path = $this->overlayPo()->find(self::MODULE, 'path');
        $this->assertSame('C:\\pfad', LocalChangeComments::getOriginal($path));
        $this->assertNull(LocalChangeComments::getLocalChange($path));
        $this->assertContains('original: C:\\pfad', $path->getTranslatorComments(), 'an admin edit never moves the original');
    }

    public function testLoadModuleTranslationsReturnsTheDecodedMultiLineOriginal(): void
    {
        $this->seedShipped(['lf' => 'Zeile 1']);
        $this->seedOverlay(['lf' => ['value' => "Zeile 1\nZeile 2", 'original' => "Zeile 1\nZeile 2"]]);

        $this->assertSame(
            "Zeile 1\nZeile 2",
            MigratedLanguageFileSync::loadModuleTranslations($this->manager, 'de', self::MODULE, $this->client_data_dir)['lf']['original']
        );
    }

    public function testRefreshKeepsAnEscapedOriginalOfAnEntryTheShippedPoNoLongerContains(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay([
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'dropped' => ['value' => "Neu\nZeile", 'original' => "Alt\nZeile", 'local_change' => '2020-01-01T00:00:00Z'],
        ]);

        $this->sync(['greeting' => 'Hallo', 'dropped' => "Neu\nZeile"], true);

        $dropped = $this->overlayPo()->find(self::MODULE, 'dropped');
        $this->assertSame("Alt\nZeile", LocalChangeComments::getOriginal($dropped));
        $this->assertSame('2020-01-01T00:00:00Z', LocalChangeComments::getLocalChange($dropped));
    }

    /**
     * @return array<string, string>
     */
    private function sortedMo(): array
    {
        $translations = MigratedPoFixture::readMo($this->overlayBase() . '.mo');
        ksort($translations);

        return $translations;
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

    /**
     * The un-migration case: a module that used to be migrated (shipped `.po` existed, an overlay had
     * already been compiled for it) but whose shipped `.po` has since been removed (e.g. reverted by
     * the conversion tool, or only temporarily missing). sync() must leave the existing overlay
     * completely untouched - not migrated (any more) means "do not read or write it", never "remove
     * it": an overlay is only ever removed when its language is uninstalled (removeOverlay()), so that
     * a later reconcile can still tell an unchanged, dropped entry from a real local change by
     * comparing against the "original" value the overlay preserved (see
     * LanguageInstallationManagerMigratedModulesTest::testAWholeMissingShippedPoIsBridgedByThePreservedOverlayAcrossTwoUpdates()
     * for the three-way reconciliation this enables once the `.po` is back).
     */
    public function testAnExistingOverlayIsLeftUntouchedWhenTheShippedPoNoLongerExists(): void
    {
        $this->seedShipped(['greeting' => 'Hello']);
        $this->seedOverlay(['greeting' => 'Hallo']);
        $po_before = file_get_contents($this->overlayBase() . '.po');
        $mo_before = file_get_contents($this->overlayBase() . '.mo');
        unlink($this->shippedPo());

        $this->sync(['greeting' => 'Hallo']);

        $this->assertSame($po_before, file_get_contents($this->overlayBase() . '.po'));
        $this->assertSame($mo_before, file_get_contents($this->overlayBase() . '.mo'));
    }

    /**
     * Readers must not serve a frozen overlay while its shipped `.po` is missing: getMigratedModules()/
     * loadModuleTranslations() (and, through it, ilLanguage) fall back to lng_modules/lng_data instead,
     * exactly as if no overlay existed at all - even though sync() (see the test above) deliberately
     * left the files on disk.
     */
    public function testReadersIgnoreAnExistingOverlayWhileTheShippedPoIsMissing(): void
    {
        $this->seedShipped(['greeting' => 'Hello']);
        $this->seedOverlay(['greeting' => 'Hallo']);
        unlink($this->shippedPo());

        $this->assertNull(MigratedLanguageFileSync::loadModuleTranslations(
            $this->manager,
            'de',
            self::MODULE,
            $this->client_data_dir,
            ILIAS_ABSOLUTE_PATH
        ));
        $this->assertSame(
            [],
            MigratedLanguageFileSync::getMigratedModules($this->manager, 'de', $this->client_data_dir, ILIAS_ABSOLUTE_PATH)
        );
    }

    /**
     * sync() itself only ever writes exactly the entries it is given (updating "original"/fuzzy
     * bookkeeping via refresh) - the actual shipped-vs-local three-way decision after the shipped
     * `.po` comes back is LanguageInstallationManager::resolveMigratedModule()'s job, using
     * whatever "original" the overlay preserved throughout the outage (see
     * LanguageInstallationManagerMigratedModulesTest's own coverage of that reconciliation). What
     * sync() itself must still guarantee once the `.po` is back: a plain refresh (no entries
     * changed) keeps the preserved original as its baseline instead of resetting it to the
     * newly-reappeared shipped value.
     */
    public function testARefreshAfterTheShippedPoComesBackStillMovesTheOriginalForwardCorrectly(): void
    {
        $this->seedShipped(['greeting' => 'Hello']);
        // A real local change, tracked against the shipped value at the time it was made - this is
        // the "original" that must survive the whole gap below untouched.
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hello', 'local_change' => '2020-01-01T00:00:00Z']]);
        unlink($this->shippedPo());

        // no-op while missing (see testAnExistingOverlayIsLeftUntouchedWhenTheShippedPoNoLongerExists()
        // above) - asserted here explicitly so a regression that has sync() touch the overlay's
        // "original"/local_change bookkeeping while the `.po` is missing fails right here, not only
        // once the `.po` is back (by which point $refresh_original_from_shipped would overwrite it
        // anyway and mask the loss, see below)
        $this->sync(['greeting' => 'Hallo']);
        $preserved_during_the_gap = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Hello', LocalChangeComments::getOriginal($preserved_during_the_gap), 'original survives the gap');
        $this->assertSame('2020-01-01T00:00:00Z', LocalChangeComments::getLocalChange($preserved_during_the_gap));

        // the module is migrated again - the shipped `.po` is back, with a changed value
        $this->seedShipped(['greeting' => 'Servus']);

        // an unmodified local value ("Hallo" is still tracked against the preserved original
        // "Hello") is refreshed to the new shipped value, exactly like an ordinary update
        $this->sync(['greeting' => 'Servus'], true);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $greeting->getTranslation());
        $this->assertSame('Servus', LocalChangeComments::getOriginal($greeting), 'original now moves to the newly shipped value');
        $this->assertNull(LocalChangeComments::getLocalChange($greeting));
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

    /**
     * gettext/gettext's StrictPoLoader rejects more than the old parser did (e.g., a duplicate or a
     * message without msgstr) - every such overlay is rebuilt, too, instead of failing the write.
     */
    #[DataProvider('overlaysOnlyAStrictParserRejects')]
    public function testAnOverlayPoTheStrictParserRejectsIsRebuilt(string $content): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => ['value' => 'Hallo', 'original' => 'Hallo']]);
        file_put_contents($this->overlayBase() . '.po', $content);

        $this->sync(['greeting' => 'Servus']);

        $greeting = $this->overlayPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $greeting?->getTranslation());
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($greeting));
        $this->assertNotNull(LocalChangeComments::getLocalChange($greeting));
        $this->assertSame(['greeting' => 'Servus'], MigratedPoFixture::readMo($this->overlayBase() . '.mo'));
    }

    public static function overlaysOnlyAStrictParserRejects(): array
    {
        $message = "msgctxt \"" . self::MODULE . "\"\nmsgid \"greeting\"\nmsgstr \"Hallo\"\n";

        return [
            'duplicate message' => [$message . "\n" . $message],
            'message without msgstr' => ["msgctxt \"" . self::MODULE . "\"\nmsgid \"greeting\"\n"],
            'truncated inside a string' => [substr($message, 0, -4)],
            'gap in the plural forms' => ["msgid \"a\"\nmsgid_plural \"as\"\nmsgstr[0] \"x\"\nmsgstr[2] \"z\"\n"],
            'binary data' => ["\x00\x01\x02\xde\x12\x04\x95"],
            'header value with a line break' => ["msgid \"\"\nmsgstr \"\"\n\"X-A: a\\rb\\n\"\n\n" . $message],
        ];
    }

    public function testACorruptShippedPoThrows(): void
    {
        file_put_contents($this->shippedPo(), "msgid \"broken\n");

        $this->expectException(RuntimeException::class);
        $this->sync(['greeting' => 'Hallo']);
    }

    /**
     * A shipped `.po` whose header keeps a line break is broken (checkLanguage() refuses the
     * language): sync() fails with a RuntimeException before writing anything, never with the
     * InvalidArgumentException setHeader() would raise while copying the header.
     */
    public function testAShippedPoWithALineBreakInAHeaderValueThrowsARuntimeExceptionAndWritesNothing(): void
    {
        file_put_contents(
            $this->shippedPo(),
            "msgid \"\"\nmsgstr \"\"\n\"X-A: a\\rb\\n\"\n\nmsgctxt \"" . self::MODULE . "\"\nmsgid \"greeting\"\nmsgstr \"Hallo\"\n"
        );

        try {
            $this->sync(['greeting' => 'Hallo']);
            $this->fail('Expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('contains a line break', $e->getMessage());
        }

        $this->assertFileDoesNotExist($this->overlayBase() . '.po');
        $this->assertFileDoesNotExist($this->overlayBase() . '.mo');
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
            // the lock file (see MigratedLanguageFileSync::withOverlayLock()) is never removed
            ['stest_de.lock', 'stest_de.po'],
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

    // ------------------------------------ findShippedModuleFiles / loadShippedModuleEntries

    /**
     * Every module with a shipped `.po` for the language - also one whose `.po` cannot be parsed
     * (the callers need to know it is maintained in PO files to report it) - but neither a module
     * without shipped `.po` for that language nor the main directory (empty prefix).
     */
    public function testFindShippedModuleFilesListsEveryModuleWithAShippedPoForTheLanguage(): void
    {
        $corrupt = MigratedPoFixture::directory('scorrupt', $this->directory->getPath());
        $english_only = MigratedPoFixture::directory('senonly', $this->directory->getPath());
        $manager = new LanguageFileDirectoryManager(
            new CustomizingLanguageFileDirectory(),
            new MainLanguageFileDirectory(),
            $this->directory,
            $corrupt,
            $english_only
        );
        $this->seedShipped(['greeting' => 'Hallo']);
        file_put_contents($this->shippedPo('de', 'scorrupt'), "msgid \"kaputt\n");
        $this->seedShipped(['x' => 'only english'], 'en', 'senonly');

        $this->assertSame(
            [self::MODULE => $this->shippedPo(), 'scorrupt' => $this->shippedPo('de', 'scorrupt')],
            MigratedLanguageFileSync::findShippedModuleFiles($manager, ILIAS_ABSOLUTE_PATH, 'de')
        );
    }

    /**
     * The extracted comments ("#.", the counterpart of a .lang "###" comment) of an entry are joined
     * into one line; an entry without one gets `null`, entries of another context are not returned.
     */
    public function testLoadShippedModuleEntriesReturnsValueAndJoinedExtractedComment(): void
    {
        $catalog = MigratedPoFixture::catalog(self::MODULE, [
            'greeting' => "Hallo\nWelt",
            'farewell' => 'Tschüss',
            'foreign' => ['value' => 'Fremd', 'context' => 'other_module'],
        ]);
        $catalog->find(self::MODULE, 'greeting')->addExtractedComment('shown on the login page');
        $catalog->find(self::MODULE, 'greeting')->addExtractedComment('keep it short');
        $catalog->find('other_module', 'foreign')->addExtractedComment('not returned');
        MigratedPoFixture::writePo($this->shippedPo(), $catalog);

        $entries = MigratedLanguageFileSync::loadShippedModuleEntries($this->shippedPo(), self::MODULE);
        ksort($entries);

        $this->assertSame(
            [
                'farewell' => ['value' => 'Tschüss', 'comment' => null],
                'greeting' => ['value' => "Hallo\nWelt", 'comment' => 'shown on the login page keep it short'],
            ],
            $entries
        );
    }

    public function testLoadShippedModuleEntriesThrowsForACorruptPo(): void
    {
        file_put_contents($this->shippedPo(), "msgid \"kaputt\n");

        $this->expectException(RuntimeException::class);
        MigratedLanguageFileSync::loadShippedModuleEntries($this->shippedPo(), self::MODULE);
    }

    // ---------------------------------------------- findUnwritableOverlayDirectories

    private function overlayDirectory(): string
    {
        return dirname($this->overlayBase());
    }

    /**
     * @param list<string> $lang_keys
     * @return list<string>
     */
    private function findUnwritable(array $lang_keys = ['de'], ?string $client_data_dir = 'default', ?LanguageFileDirectoryManager $manager = null): array
    {
        return MigratedLanguageFileSync::findUnwritableOverlayDirectories(
            $manager ?? $this->manager,
            ILIAS_ABSOLUTE_PATH,
            $lang_keys,
            $client_data_dir === 'default' ? $this->client_data_dir : $client_data_dir
        );
    }

    /**
     * Root-proof: a regular file occupies the place of the overlay directory - no user can create
     * the directory, so it is reported (the exact directory the overlay would be written to).
     */
    public function testAFileInPlaceOfTheOverlayDirectoryIsReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        mkdir(dirname($this->overlayDirectory()), 0775, true);
        file_put_contents($this->overlayDirectory(), 'not a directory');

        $this->assertSame([$this->overlayDirectory()], $this->findUnwritable());
    }

    /**
     * A file further up (here: where "<client data dir>/lang" has to be created) blocks the creation
     * of the whole path just as well.
     */
    public function testAFileInPlaceOfAParentOfTheOverlayDirectoryIsReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        file_put_contents($this->client_data_dir . '/lang', 'not a directory');

        $this->assertSame([$this->overlayDirectory()], $this->findUnwritable());
    }

    /**
     * A dangling symlink does not "exist" for file_exists(), but mkdir() cannot create anything in
     * its place - at the position of the overlay directory or of one of its parents.
     */
    #[DataProvider('danglingSymlinkPositions')]
    public function testADanglingSymlinkInTheOverlayPathIsReported(\Closure $link_path): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $link = $link_path($this);
        if (!is_dir(dirname($link))) {
            mkdir(dirname($link), 0775, true);
        }
        symlink($this->client_data_dir . '/does-not-exist', $link);

        $this->assertSame([$this->overlayDirectory()], $this->findUnwritable());
    }

    public static function danglingSymlinkPositions(): array
    {
        return [
            'the overlay directory' => [static fn(self $test): string => $test->overlayDirectory()],
            'a parent ("lang")' => [static fn(self $test): string => $test->client_data_dir . '/lang'],
        ];
    }

    /**
     * A symlink to an existing writable directory is fine (e.g., a data volume mounted elsewhere).
     */
    public function testASymlinkToAWritableDirectoryIsNotReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        mkdir($this->client_data_dir . '/elsewhere');
        symlink($this->client_data_dir . '/elsewhere', $this->client_data_dir . '/lang');

        $this->assertSame([], $this->findUnwritable());
    }

    /**
     * Not existing yet is not a problem as long as the closest existing parent is a writable
     * directory - sync() creates the missing directories.
     */
    public function testAMissingButCreatableOverlayDirectoryIsNotReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);

        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
        $this->assertSame([], $this->findUnwritable());
        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang', 'the check itself creates nothing');
    }

    public function testAMissingClientDataDirWithACreatableParentIsNotReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);

        $this->assertSame([], $this->findUnwritable(['de'], $this->client_data_dir . '/not-yet-created'));
    }

    public function testAnExistingWritableOverlayDirectoryIsNotReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        mkdir($this->overlayDirectory(), 0775, true);

        $this->assertSame([], $this->findUnwritable());
    }

    public function testWithoutClientDataDirNothingIsReported(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        file_put_contents($this->client_data_dir . '/lang', 'not a directory');

        $this->assertSame([], $this->findUnwritable(['de'], null));
    }

    /**
     * Only modules migrated for one of the given languages count: without a shipped `.po` for the
     * language no overlay is written, so a blocked overlay directory does not matter - nor does one
     * of a module without contributed directory or the main directory.
     */
    public function testAModuleThatIsNotMigratedForTheLanguageIsIgnored(): void
    {
        $this->seedShipped(['greeting' => 'Hello'], 'en');
        file_put_contents($this->client_data_dir . '/lang', 'not a directory');

        $this->assertSame([], $this->findUnwritable(['de']));
        $this->assertSame([$this->overlayDirectory()], $this->findUnwritable(['de', 'en']));
    }

    /**
     * The overlay directory is the same for every language of a module (the language is part of the
     * file name) - it is reported once, and the overlay directories of several modules are all
     * reported.
     */
    public function testEachUnwritableOverlayDirectoryIsReportedOnceAcrossLanguagesAndModules(): void
    {
        $second = MigratedPoFixture::directory('stwo', 'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '/sub/');
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory, $second);
        $this->seedShipped(['greeting' => 'Hallo'], 'de');
        $this->seedShipped(['greeting' => 'Hello'], 'en');
        MigratedPoFixture::writePo(
            $this->fixture_directory . '/sub/stwo_de.po',
            MigratedPoFixture::catalog('stwo', ['x' => 'y'])
        );
        mkdir(dirname($this->overlayDirectory()), 0775, true);
        file_put_contents($this->overlayDirectory(), 'not a directory');

        $this->assertSame(
            [$this->overlayDirectory(), $this->overlayDirectory() . '/sub'],
            $this->findUnwritable(['de', 'en'], 'default', $manager)
        );
    }

    // ---------------------------------------------------- loadModuleTranslations

    public function testLoadModuleTranslationsReturnsValueLocalChangeDateAndOriginalPerEntry(): void
    {
        $this->seedShipped(['changed' => 'Hallo']);
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
        $this->seedShipped(['greeting' => 'Hallo']);
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
