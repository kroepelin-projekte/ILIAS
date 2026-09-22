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
use ILIAS\Language\ComponentTranslation\LocalChangeComments;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use Gettext\Generator\MoGenerator;
use Gettext\Generator\PoGenerator;
use Gettext\Loader\PoLoader;
use Gettext\Translation;
use Gettext\Translations;
use PHPUnit\Framework\TestCase;

/**
 * Direct unit coverage for MigratedLanguageFileSync::sync()/removeOverlay() themselves, independent
 * of any of its three callers (ilObjLanguage::replaceLangModule() - see PoMigrationWriteBackTest.php -,
 * ilObjLanguageExt::importLanguageFile(), ILIAS\Language\Setup\LanguageInstallationManager). Those
 * caller-level suites already exercise sync() end-to-end for their own scenarios; this file targets
 * behavior of sync() itself that either has no natural caller-level trigger (the "$entries = []"
 * full-wipe semantics; the getContext() guard on which stale entries get removed; the overlay-vs-
 * shipped split itself) or is more precisely pinned when asserted directly against the thrown
 * exception rather than through a caller's catch-and-swallow wrapper.
 *
 * Uses a throwaway fixture module, exactly like PoMigrationWriteBackTest.php - never real pilot
 * (tos) data.
 *
 * Two independent roots are used throughout, mirroring the production split:
 * - $this->fixture_directory (rooted so it resolves under ILIAS_ABSOLUTE_PATH) holds the SHIPPED
 *   `.po`/`.mo` pair - written once by seedFixtureModule(), and MigratedLanguageFileSync must never
 *   write to it again, ever, after this class' own constructor-time setup.
 * - $this->client_data_dir (a throwaway CLIENT_DATA_DIR-equivalent) holds the OVERLAY `.po`/`.mo`
 *   pair - the only thing sync()/removeOverlay() are allowed to write to or remove.
 */
class MigratedLanguageFileSyncTest extends TestCase
{
    private ?string $fixture_directory = null;
    private ?string $client_data_dir = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }

        $this->client_data_dir = sys_get_temp_dir() . '/ilias_mlfs_test_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture_directory) && is_dir($this->fixture_directory)) {
            array_map('chmod', glob($this->fixture_directory . '/*') ?: [], array_fill(0, count(glob($this->fixture_directory . '/*') ?: []), 0664));
            array_map('unlink', glob($this->fixture_directory . '/*') ?: []);
            rmdir($this->fixture_directory);
        }

        if (isset($this->client_data_dir) && is_dir($this->client_data_dir)) {
            $this->removeDirectoryRecursively($this->client_data_dir);
        }

        parent::tearDown();
    }

    private function removeDirectoryRecursively(string $directory): void
    {
        chmod($directory, 0775);
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                $this->removeDirectoryRecursively($path);
            } else {
                chmod($path, 0664);
                unlink($path);
            }
        }
        rmdir($directory);
    }

    /**
     * @param array<string, array{value: string, context?: string, original?: string}> $entries
     *        identifier => details; 'context' defaults to $module - pass a different value to seed a
     *        translation belonging to a different module's context inside the same fixture file (see
     *        testLeavesTranslationsOfADifferentContextAloneWhenWipingAllEntries()). 'original', if
     *        given, seeds an "original:" LocalChangeComments comment (see LocalChangeCommentsTest.php)
     *        directly into this SHIPPED fixture - a shortcut standing in for "an overlay that
     *        sync() already bootstrapped from here earlier", since the real shipped `.po` never
     *        carries this comment itself (only MigratedLanguageFileSync::sync() adds it, into the
     *        overlay, the first time it seeds one from a shipped file).
     */
    private function seedFixtureModule(string $module, string $lang_key, array $entries): LanguageFileDirectory
    {
        $this->fixture_directory ??= __DIR__ . '/tmp-sync-fixtures-' . bin2hex(random_bytes(4));
        if (!is_dir($this->fixture_directory)) {
            mkdir($this->fixture_directory, 0775, true);
        }

        $translations = Translations::create($module, $lang_key);
        foreach ($entries as $identifier => $entry) {
            $translation = Translation::create($entry['context'] ?? $module, $identifier)
                ->translate($entry['value']);
            if (isset($entry['original'])) {
                \ILIAS\Language\ComponentTranslation\LocalChangeComments::setOriginal($translation, $entry['original']);
            }
            $translations->add($translation);
        }

        $base_path = $this->fixture_directory . '/' . $module . '_' . $lang_key;
        (new PoGenerator())->generateFile($translations, $base_path . '.po');
        (new MoGenerator())->includeHeaders(true)->generateFile($translations, $base_path . '.mo');

        $relative_path = 'components/ILIAS/Language/tests/ComponentTranslation/'
            . basename($this->fixture_directory) . '/';

        return new class ($module, $relative_path) implements LanguageFileDirectory {
            public function __construct(private string $prefix, private string $path)
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

    private function loadFixturePo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po');
    }

    private function overlayBase(string $module, string $lang_key): string
    {
        return rtrim((string) $this->client_data_dir, '/') . '/lang/components/ILIAS/Language/tests/ComponentTranslation/'
            . basename((string) $this->fixture_directory) . '/' . $module . '_' . $lang_key;
    }

    private function loadOverlayPo(string $module, string $lang_key): Translations
    {
        return (new PoLoader())->loadFile($this->overlayBase($module, $lang_key) . '.po');
    }

    /**
     * Copies the current shipped `.po`/`.mo` pair for $module/$lang_key into the overlay location,
     * simulating an already-migrated module whose overlay was compiled by an earlier install - the
     * normal starting point for every test that exercises an "update" (as opposed to a first-ever
     * "install") sync.
     */
    private function bootstrapOverlayFromShipped(string $module, string $lang_key): void
    {
        $overlay_base = $this->overlayBase($module, $lang_key);
        if (!is_dir(dirname($overlay_base))) {
            mkdir(dirname($overlay_base), 0775, true);
        }
        copy($this->fixture_directory . '/' . $module . '_' . $lang_key . '.po', $overlay_base . '.po');
        copy($this->fixture_directory . '/' . $module . '_' . $lang_key . '.mo', $overlay_base . '.mo');
    }

    public function testSyncingWithEmptyEntriesRemovesEveryExistingEntryForThatModule(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $this->bootstrapOverlayFromShipped('stest', 'de');
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', [], false, $this->client_data_dir);

        $overlay = $this->loadOverlayPo('stest', 'de');
        $this->assertNull($overlay->find('stest', 'greeting'));
        $this->assertNull($overlay->find('stest', 'farewell'));
        $this->assertCount(0, iterator_to_array($overlay->getTranslations()));

        // the shipped file must never be touched by sync(), no matter what
        $shipped = $this->loadFixturePo('stest', 'de');
        $this->assertNotNull($shipped->find('stest', 'greeting'));
        $this->assertNotNull($shipped->find('stest', 'farewell'));
    }

    /**
     * The stale-detection only removes translations whose getContext() equals the module being
     * synced (see sync()'s docblock/implementation) - a translation that happens to live in the same
     * .po file under a different context must survive an "$entries = []" full wipe untouched. This
     * pins that guard against a mutation that drops the getContext() check (which would wipe the
     * entire file's contents regardless of context).
     */
    public function testLeavesTranslationsOfADifferentContextAloneWhenWipingAllEntries(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'unrelated' => ['value' => 'Bleibt', 'context' => 'other_module'],
        ]);
        $this->bootstrapOverlayFromShipped('stest', 'de');
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', [], false, $this->client_data_dir);

        $overlay = $this->loadOverlayPo('stest', 'de');
        $this->assertNull($overlay->find('stest', 'greeting'));
        $survivor = $overlay->find('other_module', 'unrelated');
        $this->assertNotNull($survivor);
        $this->assertSame('Bleibt', $survivor->getTranslation());
    }

    public function testIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // "other_module" is not contributed by anyone -> must not throw, must not create any file
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'other_module', ['x' => 'y'], false, $this->client_data_dir);

        $this->assertFileDoesNotExist($this->fixture_directory . '/other_module_de.po');
        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    public function testIsANoOpWhenNoShippedPoFileExists(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.po');
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert'], true, $this->client_data_dir);

        $this->assertDirectoryDoesNotExist($this->client_data_dir . '/lang');
    }

    /**
     * The central overlay-vs-shipped behavior change: sync() must NEVER fall back to writing the
     * shipped file when $client_data_dir cannot be resolved - it must no-op entirely instead. Falling
     * back would reintroduce exactly the git-dirtying bug the overlay exists to fix.
     */
    public function testIsANoOpWhenClientDataDirIsNull(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $shipped_po_before = file_get_contents($this->fixture_directory . '/stest_de.po');
        $shipped_mo_before = file_get_contents($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // Both an ordinary update and an install-flavored call (create_missing_mo = true) must no-op
        // when there is no client data dir to write an overlay into at all.
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert'], true, null);

        $this->assertSame($shipped_po_before, file_get_contents($this->fixture_directory . '/stest_de.po'));
        $this->assertSame($shipped_mo_before, file_get_contents($this->fixture_directory . '/stest_de.mo'));
    }

    /**
     * Without an already-compiled overlay `.mo`, an ordinary write (the default, $create_missing_mo =
     * false - see sync()'s docblock) must leave the overlay missing entirely, exactly mirroring the
     * pre-overlay "a missing .mo stays missing" behavior, just against the overlay location now.
     */
    public function testIsANoOpWhenNoCompiledOverlayMoFileExistsYet(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        // deliberately no bootstrapOverlayFromShipped() call - no overlay exists at all yet

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert'], false, $this->client_data_dir);

        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.po');
        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.mo');
        // and, again, the shipped file was never touched
        $this->assertSame(
            'Hallo',
            $this->loadFixturePo('stest', 'de')->find('stest', 'greeting')->getTranslation()
        );
    }

    /**
     * $create_missing_mo = true (only ever passed by LanguageInstallationManager for a genuine
     * install, see its docblock) is the one case allowed to compile an overlay `.mo` that doesn't
     * exist yet - bootstrapped from the shipped `.po`, the only place carrying every identifier's
     * "original" comment to begin with. The counterpart to
     * testIsANoOpWhenNoCompiledOverlayMoFileExistsYet() above, which pins the default (update/edit)
     * behavior of leaving it missing.
     */
    public function testCreatesAMissingOverlayMoFileWhenCreateMissingMoIsTrue(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $shipped_po_before = file_get_contents($this->fixture_directory . '/stest_de.po');
        $shipped_mo_before = file_get_contents($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, neu'],
            true,
            $this->client_data_dir
        );

        $this->assertFileExists($this->overlayBase('stest', 'de') . '.mo');
        $this->assertSame(
            'Hallo, neu',
            $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting')->getTranslation()
        );
        // the shipped pair is the seed, never the target - it must be byte-identical afterwards
        $this->assertSame($shipped_po_before, file_get_contents($this->fixture_directory . '/stest_de.po'));
        $this->assertSame($shipped_mo_before, file_get_contents($this->fixture_directory . '/stest_de.mo'));
    }

    /**
     * $create_missing_mo = true still requires a shipped `.po` file to exist - it bootstraps the
     * overlay from the shipped `.po`, it does not fabricate translations out of nothing.
     */
    public function testStillANoOpWithCreateMissingMoTrueWhenNoShippedPoFileExists(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        unlink($this->fixture_directory . '/stest_de.po');
        unlink($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo'], true, $this->client_data_dir);

        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.po');
        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.mo');
    }

    /**
     * A bootstrap install must compile the overlay `.mo` even when the `.po` content itself would
     * otherwise be byte-identical to the existing overlay (the no-op guard that skips rewriting an
     * unchanged file must not also skip creating the still-missing `.mo` - see sync()'s docblock on
     * $overlay_mo_missing).
     */
    public function testCreatesAMissingOverlayMoFileEvenWhenPoContentIsAlreadyUpToDate(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');
        unlink($this->overlayBase('stest', 'de') . '.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // Same value as already seeded - the overlay .po content will not change.
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo'], true, $this->client_data_dir);

        $this->assertFileExists($this->overlayBase('stest', 'de') . '.mo');
    }

    /**
     * Core overlay-continuity guarantee: once an overlay exists, every following sync() bases
     * $translations on THE OVERLAY, never on the shipped `.po` again, UNLESS $refresh_original_from_
     * shipped explicitly asks for the shipped `.po` to be additionally consulted per-entry (see
     * testRefreshOriginalFromShippedUpdatesOriginalAndClearsLocalChangeForAnUnmodifiedEntry() below) -
     * with the flag at its default (`false`, as here), an already-set "local_change" timestamp for one
     * identifier must never be spuriously refreshed just because a sync() call touched a different
     * identifier of the same module/language.
     *
     * A real sleep (not an injected clock - sync() has none, it always uses the wall clock) is used to
     * make the two writes fall in different clock seconds; local_change timestamps carry
     * second-resolution only (see LocalChangeComments::refresh()'s 'Y-m-d\TH:i:s\Z' format), so without
     * the sleep a regression that re-reads the shipped file and recomputes the timestamp could
     * coincidentally produce the same string and this test would not reliably catch it.
     */
    public function testLocalChangeTimestampOfAnUntouchedIdentifierSurvivesAnUnrelatedSync(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo', 'original' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss', 'original' => 'Tschüss'],
        ]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // First (bootstrap) sync: only "greeting" is locally changed.
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, geändert', 'farewell' => 'Tschüss'],
            true,
            $this->client_data_dir
        );

        $greeting_after_first_sync = $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting');
        $timestamp_after_first_sync = LocalChangeComments::getLocalChange($greeting_after_first_sync);
        $this->assertNotNull($timestamp_after_first_sync);

        sleep(1);

        // Second sync: "greeting" is written again with the SAME value (a full-replace write, as
        // every real caller does), only "farewell" actually changes this time.
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, geändert', 'farewell' => 'Tschüss, geändert'],
            false,
            $this->client_data_dir
        );

        $overlay = $this->loadOverlayPo('stest', 'de');
        $greeting_after_second_sync = $overlay->find('stest', 'greeting');
        $farewell_after_second_sync = $overlay->find('stest', 'farewell');

        $this->assertSame(
            $timestamp_after_first_sync,
            LocalChangeComments::getLocalChange($greeting_after_second_sync),
            'local_change of an identifier untouched in value must stay stable across an unrelated sync'
        );
        $this->assertNotNull(LocalChangeComments::getLocalChange($farewell_after_second_sync));
    }

    /**
     * The scenario $refresh_original_from_shipped exists for: an ILIAS core update ships a revised
     * translation for a module that is already installed (see MigratedLanguageFileSync::sync()'s own
     * docblock for exactly which real callers set this). Without the flag (the default - see
     * testWithoutRefreshOriginalFromShippedAnUnmodifiedEntryIsWronglyFlaggedAfterAShippedUpdate()
     * below), "original" stays frozen on the value shipped at overlay-creation time forever, so an
     * entry nobody ever customized locally would incorrectly start looking "locally changed" the
     * moment the caller starts passing the new shipped value through $entries. With the flag, sync()
     * brings "original" up to date with the shipped `.po` FIRST, so an unmodified entry ends up
     * exactly as if it had shipped with the new value from the start: no local_change.
     */
    public function testRefreshOriginalFromShippedUpdatesOriginalAndClearsLocalChangeForAnUnmodifiedEntry(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // A genuine first install - creates the overlay, "original" = "Hallo".
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo'],
            true,
            $this->client_data_dir
        );

        // An ILIAS core update ships a revised translation. Nobody customized this entry locally, so
        // the caller (e.g. LanguageInstallationManager, re-parsing the updated .lang file) passes the
        // new shipped value straight through.
        $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo, überarbeitet']]);
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, überarbeitet'],
            false,
            $this->client_data_dir,
            true
        );

        $translation = $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting');
        $this->assertSame('Hallo, überarbeitet', $translation->getTranslation());
        $this->assertSame('Hallo, überarbeitet', LocalChangeComments::getOriginal($translation));
        $this->assertNull(LocalChangeComments::getLocalChange($translation));
    }

    /**
     * The flip side of the test above: an entry an admin already customized locally stays flagged as
     * locally changed even after "original" is brought up to date with a newer shipped value - it is
     * still, correctly, a deviation from whatever the current shipped default is.
     */
    public function testRefreshOriginalFromShippedUpdatesOriginalButKeepsALocalCustomizationFlagged(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // First install, then an ordinary admin edit customizes the entry (flag stays false, exactly
        // like ilObjLanguage::replaceLangModule() would pass it).
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo'],
            true,
            $this->client_data_dir
        );
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Servus'],
            false,
            $this->client_data_dir
        );
        $this->assertNotNull(LocalChangeComments::getLocalChange(
            $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting')
        ));

        // An ILIAS core update ships a revised translation too. The caller (re-parsing the updated
        // .lang file, then merging the still-newer DB-recorded local change back on top) passes the
        // admin's customization through unchanged - it remains the effectively correct value.
        $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo, überarbeitet']]);
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Servus'],
            false,
            $this->client_data_dir,
            true
        );

        $translation = $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting');
        $this->assertSame('Servus', $translation->getTranslation());
        $this->assertSame(
            'Hallo, überarbeitet',
            LocalChangeComments::getOriginal($translation),
            'original must track the new shipped baseline even while the entry stays locally customized'
        );
        $this->assertNotNull(LocalChangeComments::getLocalChange($translation));
    }

    /**
     * The guard $refresh_original_from_shipped's docblock promises: "original" is rewritten only for
     * an entry whose shipped value actually changed - an entry the update didn't touch must be left
     * exactly as it was, even while a sibling entry in the very same sync() call does get updated.
     */
    public function testRefreshOriginalFromShippedOnlyTouchesEntriesWhoseShippedValueActuallyChanged(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo', 'farewell' => 'Tschüss'],
            true,
            $this->client_data_dir
        );

        // The update revises only "greeting" - "farewell" ships completely unchanged.
        $this->seedFixtureModule('stest', 'de', [
            'greeting' => ['value' => 'Hallo, überarbeitet'],
            'farewell' => ['value' => 'Tschüss'],
        ]);
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, überarbeitet', 'farewell' => 'Tschüss'],
            false,
            $this->client_data_dir,
            true
        );

        $overlay = $this->loadOverlayPo('stest', 'de');
        $this->assertSame('Hallo, überarbeitet', LocalChangeComments::getOriginal($overlay->find('stest', 'greeting')));
        $this->assertSame('Tschüss', LocalChangeComments::getOriginal($overlay->find('stest', 'farewell')));
        $this->assertNull(LocalChangeComments::getLocalChange($overlay->find('stest', 'greeting')));
        $this->assertNull(LocalChangeComments::getLocalChange($overlay->find('stest', 'farewell')));
    }

    /**
     * Documents the exact staleness bug $refresh_original_from_shipped fixes, by pinning what happens
     * WITHOUT it (the default, and what every caller except LanguageInstallationManager and the
     * "reset to shipped defaults" import passes): "original" stays frozen on the value shipped at
     * overlay-creation time, so an entry nobody ever touched locally is incorrectly flagged as locally
     * changed the moment a caller starts passing the new shipped value through $entries.
     */
    public function testWithoutRefreshOriginalFromShippedAnUnmodifiedEntryIsWronglyFlaggedAfterAShippedUpdate(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo'],
            true,
            $this->client_data_dir
        );

        $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo, überarbeitet']]);
        // $refresh_original_from_shipped omitted - defaults to false.
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo, überarbeitet'],
            false,
            $this->client_data_dir
        );

        $translation = $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting');
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation), 'original stays frozen without the flag');
        $this->assertNotNull($translation ? LocalChangeComments::getLocalChange($translation) : null);
    }

    /**
     * $refresh_original_from_shipped must have no effect on the very first sync for a module+language
     * (overlay does not exist yet, see the !$overlay_exists branch) - that case already seeds
     * "original" from the shipped `.po` unconditionally, regardless of this flag.
     */
    public function testRefreshOriginalFromShippedHasNoEffectOnTheVeryFirstOverlayCreation(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            'de',
            'stest',
            ['greeting' => 'Hallo'],
            true,
            $this->client_data_dir,
            true
        );

        $translation = $this->loadOverlayPo('stest', 'de')->find('stest', 'greeting');
        $this->assertSame('Hallo', LocalChangeComments::getOriginal($translation));
        $this->assertNull(LocalChangeComments::getLocalChange($translation));
    }

    /**
     * Per its own docblock, sync() always throws instead of logging/swallowing - every caller decides
     * that for itself. Pinned directly here rather than only observed indirectly through a caller's
     * catch block.
     */
    public function testThrowsWhenTheOverlayPoFileCannotBeWritten(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');
        chmod($this->overlayBase('stest', 'de') . '.po', 0444);

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/PO file/');
            MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, 'de', 'stest', ['greeting' => 'Hallo, geändert'], false, $this->client_data_dir);
        } finally {
            restore_error_handler();
            chmod($this->overlayBase('stest', 'de') . '.po', 0664);
        }
    }

    /**
     * removeOverlay() is the uninstall counterpart to sync()'s $create_missing_mo (see its docblock) -
     * unlike the old removeMoFile() (which removed only the .mo, deliberately leaving the then-precious
     * shipped .po alone), it removes BOTH overlay files together: the overlay has no "precious source"
     * status of its own, so there is no reason to keep one half of it around. The shipped pair must
     * stay completely untouched - it is never written to, and never removed, by this class.
     */
    public function testRemoveOverlayDeletesBothOverlayFilesButLeavesShippedFilesUntouched(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');
        $shipped_po_before = file_get_contents($this->fixture_directory . '/stest_de.po');
        $shipped_mo_before = file_get_contents($this->fixture_directory . '/stest_de.mo');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::removeOverlay($manager, 'de', 'stest', $this->client_data_dir);

        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.po');
        $this->assertFileDoesNotExist($this->overlayBase('stest', 'de') . '.mo');
        $this->assertSame($shipped_po_before, file_get_contents($this->fixture_directory . '/stest_de.po'));
        $this->assertSame($shipped_mo_before, file_get_contents($this->fixture_directory . '/stest_de.mo'));
    }

    public function testRemoveOverlayIsANoOpWhenNoOverlayExistsYet(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        // deliberately no bootstrapOverlayFromShipped() call

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // must not throw even though there is nothing to remove
        MigratedLanguageFileSync::removeOverlay($manager, 'de', 'stest', $this->client_data_dir);

        $this->assertFileExists($this->fixture_directory . '/stest_de.po');
    }

    public function testRemoveOverlayIsANoOpWhenTheModuleHasNoContributedDirectory(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        // "other_module" is not contributed by anyone -> must not throw, must not touch stest's files
        MigratedLanguageFileSync::removeOverlay($manager, 'de', 'other_module', $this->client_data_dir);

        $this->assertFileExists($this->overlayBase('stest', 'de') . '.mo');
        $this->assertFileExists($this->overlayBase('stest', 'de') . '.po');
    }

    /**
     * $client_data_dir === null must no-op, exactly like sync() - never interpreted as "nothing to
     * resolve, so fall back to the shipped location" (which removeOverlay() must never touch anyway).
     */
    public function testRemoveOverlayIsANoOpWhenClientDataDirIsNull(): void
    {
        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );

        MigratedLanguageFileSync::removeOverlay($manager, 'de', 'stest', null);

        $this->assertFileExists($this->overlayBase('stest', 'de') . '.po');
        $this->assertFileExists($this->overlayBase('stest', 'de') . '.mo');
    }

    /**
     * Per its own docblock, removeOverlay() always throws instead of logging/swallowing - every caller
     * decides that for itself, exactly like sync().
     */
    public function testRemoveOverlayThrowsWhenAnOverlayFileCannotBeRemoved(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot force an unremovable file while running as root; skipping.');
        }

        $directory = $this->seedFixtureModule('stest', 'de', ['greeting' => ['value' => 'Hallo']]);
        $this->bootstrapOverlayFromShipped('stest', 'de');

        $manager = new LanguageFileDirectoryManager(
            new \ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory(),
            $directory
        );
        // unlink() fails on a directory permission, not a file permission, and emits a PHP warning
        // before returning false - silenced the same way testThrowsWhenTheOverlayPoFileCannotBeWritten() does.
        $overlay_directory = dirname($this->overlayBase('stest', 'de'));
        chmod($overlay_directory, 0555);

        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessageMatches('/overlay file/i');
            MigratedLanguageFileSync::removeOverlay($manager, 'de', 'stest', $this->client_data_dir);
        } finally {
            restore_error_handler();
            chmod($overlay_directory, 0775);
        }
    }
}
