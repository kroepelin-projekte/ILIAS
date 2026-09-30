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
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFilePaths;
use ILIAS\Language\ComponentTranslation\MigratedLanguageFileSync;
use ILIAS\Language\ComponentTranslation\ShippedPoMerger;
use PHPUnit\Framework\TestCase;

/**
 * Direct coverage of ShippedPoMerger (merge(), backupShippedPoFiles(), findShippedChangesSinceBackup()),
 * independent of ilObjLanguageExt/ilObjLanguageExtGUI.
 *
 * Two roots, mirroring production (like MigratedLanguageFileSyncTest):
 * - the SHIPPED `.po`/`.pot` live below ILIAS_ABSOLUTE_PATH ($this->fixture_directory) - the only
 *   files this class is allowed to write;
 * - the OVERLAY `.po`/`.mo` live below a throwaway client data directory ($this->client_data_dir).
 */
class ShippedPoMergerTest extends TestCase
{
    private const string MODULE = 'mtest';
    private const string LANG = 'de';

    private string $fixture_directory;
    private string $client_data_dir;
    private string $backup_directory;
    private LanguageFileDirectory $directory;
    private LanguageFileDirectoryManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ILIAS_ABSOLUTE_PATH')) {
            define('ILIAS_ABSOLUTE_PATH', realpath(__DIR__ . '/../../../../../'));
        }

        $this->fixture_directory = __DIR__ . '/tmp-shipped-po-merger-' . bin2hex(random_bytes(4));
        mkdir($this->fixture_directory, 0775, true);
        $this->client_data_dir = sys_get_temp_dir() . '/ilias_spm_test_client_' . bin2hex(random_bytes(4));
        mkdir($this->client_data_dir, 0775, true);
        // AtomicFileWriter confines a shipped-.po backup write to below ILIAS_ABSOLUTE_PATH (like the
        // shipped .po/.pot themselves) - unlike the overlay, which lives below CLIENT_DATA_DIR
        $this->backup_directory = $this->fixture_directory . '-backup';
        mkdir($this->backup_directory, 0775, true);

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
        MigratedPoFixture::removeDirectory($this->backup_directory);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private function shippedPo(string $lang_key = self::LANG): string
    {
        return $this->fixture_directory . '/' . self::MODULE . '_' . $lang_key . '.po';
    }

    private function templatePo(): string
    {
        return $this->fixture_directory . '/' . self::MODULE . '.pot';
    }

    /**
     * @param array<string, string|array<string, mixed>> $entries see MigratedPoFixture::catalog()
     */
    private function seedShipped(array $entries, string $lang_key = self::LANG): void
    {
        MigratedPoFixture::writePo($this->shippedPo($lang_key), MigratedPoFixture::catalog(self::MODULE, $entries));
    }

    /**
     * @param array<string, string|list<string>> $entries plain value, or the forms of a plural message
     */
    private function seedShippedPlural(array $entries): void
    {
        $catalog = new TranslationCatalog();
        $catalog->setHeader('Content-Type', 'text/plain; charset=UTF-8');
        $catalog->setHeader('Plural-Forms', 'nplurals=2; plural=(n != 1);');
        foreach ($entries as $identifier => $value) {
            $entry = new TranslationEntry(self::MODULE, (string) $identifier);
            if (is_array($value)) {
                $entry->setPlural($identifier . '_plural', $value);
            } else {
                $entry->translate($value);
            }
            $catalog->add($entry);
        }
        MigratedPoFixture::writePo($this->shippedPo(), $catalog);
    }

    /**
     * @param array<string, string|array<string, mixed>> $entries
     */
    private function seedTemplate(array $entries): void
    {
        MigratedPoFixture::writePo($this->templatePo(), MigratedPoFixture::catalog(self::MODULE, $entries));
    }

    private function overlayBase(string $lang_key = self::LANG): string
    {
        return MigratedLanguageFilePaths::overlayBasePath($this->client_data_dir, $this->directory, $lang_key);
    }

    /**
     * A local change written the ordinary way (like an admin edit) - the overlay's "original"/
     * "local_change" bookkeeping is exactly what production writes.
     *
     * @param array<string, string> $entries
     * @param array<string, string>|null $remarks
     */
    private function seedOverlay(array $entries, ?array $remarks = null): void
    {
        MigratedLanguageFileSync::sync(
            $this->manager,
            ILIAS_ABSOLUTE_PATH,
            self::LANG,
            self::MODULE,
            $entries,
            $this->client_data_dir,
            false,
            null,
            $remarks
        );
    }

    /**
     * Writes a single overlay entry directly, bypassing sync() - for shapes sync()'s flat
     * identifier => value map cannot produce (a plural overlay message, an entry with an empty
     * msgstr[0] next to other forms).
     */
    private function seedManualOverlayEntry(TranslationEntry $entry): void
    {
        $catalog = new TranslationCatalog();
        $catalog->add($entry);
        // .po only: a plural message with an empty msgstr[0] next to other forms (exactly the shape
        // some of these tests need) cannot be compiled into a `.mo` at all (see toMoString()) - the
        // overlay is read from its `.po` regardless (see readOverlayCatalog()).
        MigratedPoFixture::writePo($this->overlayBase() . '.po', $catalog);
    }

    private function readShippedPo(string $lang_key = self::LANG): TranslationCatalog
    {
        return TranslationCatalog::fromPoFile($this->shippedPo($lang_key));
    }

    /**
     * @return array{
     *     written: array<string, string>, skipped: array<string, string>,
     *     invalid_markup: array<string, array<string, list<string>>>, not_merged: array<string, list<string>>,
     *     unwritten_overlay: list<string>, unwritten_database: array<string, string>, stale_artifacts: list<string>
     * }
     */
    private function merge(): array
    {
        return ShippedPoMerger::merge(
            $this->manager,
            ILIAS_ABSOLUTE_PATH,
            self::LANG,
            $this->client_data_dir,
            $this->backup_directory
        );
    }

    // ---------------------------------------------------------------- merge(): the successful case

    public function testMergeTakesOverTheOverlayValueRemovesFuzzyAndReconcilesTheOverlay(): void
    {
        $this->seedShipped(['greeting' => ['value' => 'Hallo', 'fuzzy' => true]]);
        $this->seedOverlay(['greeting' => 'Servus']);

        $result = $this->merge();

        $this->assertSame(['mtest' => $this->shippedPo()], $result['written']);
        $this->assertSame([], $result['skipped'] + $result['invalid_markup'] + $result['not_merged']);
        $this->assertSame([], $result['unwritten_overlay']);

        $entry = $this->readShippedPo()->find(self::MODULE, 'greeting');
        $this->assertSame('Servus', $entry?->getTranslation());
        $this->assertFalse($entry->hasFlag('fuzzy'));
        // the overlay now equals the shipped state - nothing left to keep
        $this->assertFalse(is_file($this->overlayBase() . '.po'));
    }

    public function testMergeBacksUpTheShippedPoByteForByteBeforeWriting(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $original_bytes = (string) file_get_contents($this->shippedPo());
        $this->seedOverlay(['greeting' => 'Servus']);

        $this->merge();

        $backup_file = $this->backup_directory . '/' . self::MODULE . '_' . self::LANG . '.po';
        $this->assertFileExists($backup_file);
        $this->assertSame($original_bytes, file_get_contents($backup_file));
    }

    public function testASecondMergeRunWithoutFurtherLocalChangesDoesNothing(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => 'Servus']);
        $this->merge();
        $shipped_after_first_merge = (string) file_get_contents($this->shippedPo());

        $result = $this->merge();

        $this->assertSame(
            ['written' => [], 'skipped' => [], 'invalid_markup' => [], 'not_merged' => [], 'unwritten_overlay' => [], 'unwritten_database' => [], 'stale_artifacts' => []],
            $result
        );
        $this->assertSame($shipped_after_first_merge, file_get_contents($this->shippedPo()));
    }

    /**
     * An admin remark becomes an extracted comment - added after an existing one, an identical one
     * is not added twice.
     */
    public function testMergeAddsAnAdminRemarkAsExtractedCommentWithoutDuplicatingAnIdenticalOne(): void
    {
        $shipped = MigratedPoFixture::catalog(self::MODULE, ['farewell' => 'Tschüss']);
        $shipped->find(self::MODULE, 'farewell')->addExtractedComment('Old note');
        MigratedPoFixture::writePo($this->shippedPo(), $shipped);
        // value unchanged, but a remark - and a second one identical to the existing extracted comment
        $this->seedOverlay(['farewell' => 'Tschüss'], ['farewell' => 'Old note']);

        $result = $this->merge();

        $this->assertSame(['mtest' => $this->shippedPo()], $result['written']);
        $this->assertSame(['Old note'], $this->readShippedPo()->find(self::MODULE, 'farewell')?->getExtractedComments());
        $this->assertFalse(is_file($this->overlayBase() . '.po'));
    }

    // ---------------------------------------------------------------- merge(): not taken over

    public function testMergeSkipsAValueWithDisallowedMarkupAndKeepsItInTheOverlay(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['greeting' => '<script>alert(1)</script>']);

        $result = $this->merge();

        $this->assertSame([], $result['written']);
        $this->assertArrayHasKey('mtest', $result['invalid_markup']);
        $this->assertArrayHasKey('greeting', $result['invalid_markup']['mtest']);
        $this->assertSame('Hallo', $this->readShippedPo()->find(self::MODULE, 'greeting')?->getTranslation());
        $this->assertTrue(is_file($this->overlayBase() . '.po'));
    }

    public function testMergeSkipsAPluralOverlayForASingularShippedEntryAndKeepsItInTheOverlay(): void
    {
        $this->seedShipped(['items' => 'Element']);
        $entry = new TranslationEntry(self::MODULE, 'items');
        $entry->setPlural('items_plural', ['Kein Element', 'Elemente']);
        $this->seedManualOverlayEntry($entry);

        $result = $this->merge();

        $this->assertSame([], $result['written']);
        $this->assertSame(['mtest' => ['items']], $result['not_merged']);
        $this->assertSame('Element', $this->readShippedPo()->find(self::MODULE, 'items')?->getTranslation());
        $this->assertTrue(is_file($this->overlayBase() . '.po'));
    }

    public function testMergeSkipsAPluralWithAnEmptyFirstFormNextToOtherFormsAndKeepsItInTheOverlay(): void
    {
        $shipped = new TranslationEntry(self::MODULE, 'items');
        $shipped->setPlural('items_plural', ['Kein Element', 'Elemente']);
        $catalog = new TranslationCatalog();
        $catalog->add($shipped);
        MigratedPoFixture::writePo($this->shippedPo(), $catalog);

        $entry = new TranslationEntry(self::MODULE, 'items');
        $entry->setPlural('items_plural', ['', 'Elemente (lokal)']);
        $this->seedManualOverlayEntry($entry);

        $result = $this->merge();

        $this->assertSame([], $result['written']);
        $this->assertSame(['mtest' => ['items']], $result['not_merged']);
        $this->assertTrue(is_file($this->overlayBase() . '.po'));
    }

    /**
     * An identifier of the form "<identifier> [<n>]" would be read back as a plural form
     * (PluralFormKey) - a new key added locally in that shape is not taken over.
     */
    public function testMergeDoesNotAddANewKeyShapedLikeAPluralFormAndKeepsItInTheOverlay(): void
    {
        $this->seedShipped(['greeting' => 'Hallo']);
        $this->seedOverlay(['newkey [0]' => 'Wert']);

        $result = $this->merge();

        $this->assertSame([], $result['written']);
        $this->assertSame(['mtest' => ['newkey [0]']], $result['not_merged']);
        $this->assertNull($this->readShippedPo()->find(self::MODULE, 'newkey [0]'));
        $this->assertTrue(is_file($this->overlayBase() . '.po'));
    }

    // ---------------------------------------------------------------- merge(): new keys

    /**
     * A new identifier is inserted into the shipped `.po` in id order and, empty, into the template
     * (`.pot`) - the `.po` of another language is left untouched.
     */
    public function testMergeAddsANewKeySortedIntoThePoAndEmptyIntoThePotOtherLanguageStaysUnchanged(): void
    {
        $this->seedShipped(['aaa' => 'A', 'zzz' => 'Z']);
        $this->seedTemplate(['aaa' => '', 'zzz' => '']);
        $this->seedShipped(['aaa' => 'A (en)', 'zzz' => 'Z (en)'], 'en');
        $other_language_bytes = (string) file_get_contents($this->shippedPo('en'));
        $this->seedOverlay(['mmm' => 'M']);

        $result = $this->merge();

        $this->assertSame(['mtest' => $this->shippedPo()], $result['written']);
        $ids = array_map(static fn(TranslationEntry $e): string => $e->getId(), $this->readShippedPo()->getEntries());
        $this->assertSame(['aaa', 'mmm', 'zzz'], $ids);
        // a new entry is added without context - the shipped .po of a single module need not repeat
        // its name in every entry (see MigratedLanguageFileSync::findModuleEntry())
        $this->assertSame('M', $this->readShippedPo()->find(null, 'mmm')?->getTranslation());

        $template_entry = TranslationCatalog::fromPoFile($this->templatePo())->find(null, 'mmm');
        $this->assertNotNull($template_entry);
        $this->assertSame('', $template_entry->getTranslation());

        $this->assertSame($other_language_bytes, file_get_contents($this->shippedPo('en')));
    }

    public function testMergeSkipsTheWholeModuleWhenTheTemplateForANewKeyIsMissing(): void
    {
        $this->seedShipped(['aaa' => 'A']);
        // no .pot at all
        $this->seedOverlay(['mmm' => 'M']);

        $result = $this->merge();

        $this->assertSame([], $result['written']);
        $this->assertArrayHasKey('mtest', $result['skipped']);
        $this->assertNull($this->readShippedPo()->find(self::MODULE, 'mmm'));
    }

    // ---------------------------------------------------------------- backupShippedPoFiles()

    public function testBackupShippedPoFilesCopiesTheShippedPoNamedLikeTheFile(): void
    {
        $this->seedShipped(['aaa' => 'A']);

        $failed = ShippedPoMerger::backupShippedPoFiles($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory, ILIAS_ABSOLUTE_PATH);

        $this->assertSame([], $failed);
        $this->assertSame(
            file_get_contents($this->shippedPo()),
            file_get_contents($this->backup_directory . '/' . self::MODULE . '_' . self::LANG . '.po')
        );
    }

    /**
     * Two distinct modules whose shipped `.po` happen to share the same file name (both use the
     * module-independent "shared_%s" shipped file name pattern, see NamesShippedLanguageFiles, each
     * in its own directory) collide under the same backup file name - neither can be backed up at
     * all, rather than one silently overwriting the other's backup.
     */
    public function testBackupShippedPoFilesReportsAModuleWhoseShippedFileNameIsNotUnique(): void
    {
        $other_module = self::MODULE . '2';
        $other_directory = $this->directoryNamedSharedByLanguage(
            $other_module,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '-other/'
        );
        $shared_directory = $this->directoryNamedSharedByLanguage(
            self::MODULE,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '/'
        );
        MigratedPoFixture::writePo(
            $this->fixture_directory . '/shared_' . self::LANG . '.po',
            MigratedPoFixture::catalog(self::MODULE, ['aaa' => 'A'])
        );
        mkdir($this->fixture_directory . '-other', 0775, true);
        MigratedPoFixture::writePo(
            $this->fixture_directory . '-other/shared_' . self::LANG . '.po',
            MigratedPoFixture::catalog($other_module, ['bbb' => 'B'])
        );
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $shared_directory, $other_directory);

        try {
            $failed = ShippedPoMerger::backupShippedPoFiles($manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory, ILIAS_ABSOLUTE_PATH);

            $this->assertSame([self::MODULE, $other_module], $failed);
        } finally {
            MigratedPoFixture::removeDirectory($this->fixture_directory . '-other');
        }
    }

    private function directoryNamedSharedByLanguage(string $prefix, string $path): LanguageFileDirectory
    {
        return new class ($prefix, $path) implements LanguageFileDirectory, \ILIAS\Language\ComponentTranslation\NamesShippedLanguageFiles {
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

            public function getShippedFileNamePattern(): string
            {
                return 'shared_%s';
            }
        };
    }

    // ---------------------------------------------------------------- findShippedChangesSinceBackup()

    public function testFindShippedChangesSinceBackupReportsWhatChangedSinceTheBackup(): void
    {
        $this->seedShipped(['aaa' => 'A', 'bbb' => 'B']);
        ShippedPoMerger::backupShippedPoFiles($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory, ILIAS_ABSOLUTE_PATH);
        // changed after the backup, directly on the shipped file (simulating a later ILIAS update)
        $this->seedShipped(['aaa' => 'A (geändert)', 'bbb' => 'B']);

        $changes = ShippedPoMerger::findShippedChangesSinceBackup($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory);

        $this->assertSame(['mtest' => ['aaa' => 'A (geändert)']], $changes['changes']);
        $this->assertSame([], $changes['without_backup']);
    }

    public function testFindShippedChangesSinceBackupListsAModuleWithoutABackupInstead(): void
    {
        $this->seedShipped(['aaa' => 'A']);
        // no backup written at all

        $changes = ShippedPoMerger::findShippedChangesSinceBackup($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory);

        $this->assertSame([], $changes['changes']);
        $this->assertSame(['mtest'], $changes['without_backup']);
    }

    /**
     * The "conflicts" filter compares plural messages form by form ("<identifier> [<form>]"); an entry
     * added to the shipped .po after the backup is a change, an entry removed after it is not (there
     * is no current shipped value to show).
     */
    public function testFindShippedChangesSinceBackupReportsPluralFormsNewEntriesAndIgnoresRemovedOnes(): void
    {
        $this->seedShippedPlural(['items' => ['Element', 'Elemente'], 'gone' => 'Weg', 'same' => 'Gleich']);
        ShippedPoMerger::backupShippedPoFiles($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory, ILIAS_ABSOLUTE_PATH);
        $this->seedShippedPlural(['items' => ['Element', 'Elemente (neu)'], 'added' => 'Neu', 'same' => 'Gleich']);

        $changes = ShippedPoMerger::findShippedChangesSinceBackup($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory);

        $this->assertSame(
            ['mtest' => ['items [1]' => 'Elemente (neu)', 'added' => 'Neu']],
            $changes['changes']
        );
        $this->assertSame([], $changes['without_backup']);
    }

    public function testFindShippedChangesSinceBackupListsAModuleWithoutReadableBackupEvenIfItChanged(): void
    {
        $this->seedShipped(['aaa' => 'A']);
        ShippedPoMerger::backupShippedPoFiles($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory, ILIAS_ABSOLUTE_PATH);
        file_put_contents($this->backup_directory . '/' . self::MODULE . '_' . self::LANG . '.po', "msgid \"broken\nmsgstr");
        $this->seedShipped(['aaa' => 'A (geändert)']);

        $changes = ShippedPoMerger::findShippedChangesSinceBackup($this->manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->backup_directory);

        $this->assertSame([], $changes['changes']);
        $this->assertSame(['mtest'], $changes['without_backup']);
    }

    // ---------------------------------------------------------------- merge(): plural messages

    public function testMergeTakesOverAllFormsOfAPluralOverlayMessageAndKeepsTheShippedPluralId(): void
    {
        $this->seedShippedPlural(['items' => ['Element', 'Elemente']]);
        $entry = new TranslationEntry(self::MODULE, 'items');
        $entry->setPlural('other_plural_id', ['Ein Ding', 'Dinge']);
        $this->seedManualOverlayEntry($entry);

        $result = $this->merge();

        $this->assertSame(['mtest' => $this->shippedPo()], $result['written']);
        $this->assertSame([], $result['not_merged']);
        $merged = $this->readShippedPo()->find(self::MODULE, 'items');
        $this->assertTrue($merged->isPlural());
        $this->assertSame('items_plural', $merged->getPluralId());
        $this->assertSame(['Ein Ding', 'Dinge'], $merged->getPluralTranslations());
    }

    /**
     * A plain value for a shipped plural message is its default form (the last one, see
     * PluralForms); the other forms keep their shipped value.
     */
    public function testMergeOfASingularOverlayValueReplacesOnlyTheDefaultFormOfAShippedPluralMessage(): void
    {
        $this->seedShippedPlural(['items' => ['Element', 'Elemente']]);
        $entry = new TranslationEntry(self::MODULE, 'items');
        $entry->translate('Dinge');
        $this->seedManualOverlayEntry($entry);

        $result = $this->merge();

        $this->assertSame([], $result['not_merged']);
        $merged = $this->readShippedPo()->find(self::MODULE, 'items');
        $this->assertTrue($merged->isPlural());
        $this->assertSame(['Element', 'Dinge'], $merged->getPluralTranslations());
    }

    // ---------------------------------------------------------------- merge(): $after_module / database

    /**
     * $after_module (the database write) failing for one module must not stop the others: the files
     * of every module are written regardless, only the failing one is reported in
     * unwritten_database.
     */
    public function testMergeContinuesOtherModulesWhenTheDatabaseCallbackThrowsForOne(): void
    {
        $other_module = self::MODULE . '2';
        $other_directory = MigratedPoFixture::directory(
            $other_module,
            'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '-other2/'
        );
        mkdir($this->fixture_directory . '-other2', 0775, true);
        MigratedPoFixture::writePo(
            $this->fixture_directory . '-other2/' . $other_module . '_' . self::LANG . '.po',
            MigratedPoFixture::catalog($other_module, ['bbb' => 'B'])
        );
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $this->directory, $other_directory);
        $this->seedShipped(['aaa' => 'A']);
        $this->seedOverlay(['aaa' => 'A (lokal)']);
        MigratedLanguageFileSync::sync(
            $manager,
            ILIAS_ABSOLUTE_PATH,
            self::LANG,
            $other_module,
            ['bbb' => 'B (lokal)'],
            $this->client_data_dir
        );

        try {
            $calls = [];
            $result = ShippedPoMerger::merge(
                $manager,
                ILIAS_ABSOLUTE_PATH,
                self::LANG,
                $this->client_data_dir,
                $this->backup_directory,
                static function (string $module) use (&$calls): void {
                    $calls[] = $module;
                    if ($module === self::MODULE) {
                        throw new RuntimeException('database is down');
                    }
                }
            );

            $this->assertSame([self::MODULE, $other_module], $calls);
            $this->assertArrayHasKey(self::MODULE, $result['unwritten_database']);
            $this->assertArrayNotHasKey($other_module, $result['unwritten_database']);
            // both files are written regardless of the database failure
            $this->assertCount(2, $result['written']);
            $this->assertSame('A (lokal)', $this->readShippedPo()->find(self::MODULE, 'aaa')?->getTranslation());
            $this->assertSame(
                'B (lokal)',
                TranslationCatalog::fromPoFile($this->fixture_directory . '-other2/' . $other_module . '_' . self::LANG . '.po')
                    ->find($other_module, 'bbb')?->getTranslation()
            );
        } finally {
            MigratedPoFixture::removeDirectory($this->fixture_directory . '-other2');
        }
    }

    // ---------------------------------------------------------------- merge(): build artifact

    /**
     * A stale build artifact (older than the shipped .po it was compiled from, so a rebuild would
     * still be picked up) is left as is - dating it back is only needed for one that is not older,
     * i.e. would otherwise still be served (see ShippedTranslations::readCompiled()).
     */
    public function testMergeDatesBackACurrentBuildArtifactToBeforeTheShippedPo(): void
    {
        // "mtest" is not a real shipped module - shares artifacts/language/<lang>/ with real ones,
        // so only the one file this test creates is ever removed again, never the directory itself
        $artifact = MigratedLanguageFilePaths::shippedArtifactFile(ILIAS_ABSOLUTE_PATH, $this->directory, self::LANG);
        if (!is_dir(dirname($artifact))) {
            mkdir(dirname($artifact), 0775, true);
        }
        file_put_contents($artifact, 'compiled');
        try {
            $this->seedShipped(['aaa' => 'A']);
            touch($artifact, time() + 3600); // not older than the .po that is about to be written
            $this->seedOverlay(['aaa' => 'A (lokal)']);

            $result = $this->merge();

            $this->assertSame([], $result['stale_artifacts']);
            clearstatcache(true, $artifact);
            $this->assertLessThan(filemtime($this->shippedPo()), filemtime($artifact));
        } finally {
            @unlink($artifact);
        }
    }

    // ---------------------------------------------------------------- merge(): symlinked directory

    /**
     * A module whose shipped directory is reached through a symbolic link pointing outside
     * ILIAS_ABSOLUTE_PATH is skipped entirely - AtomicFileWriter refuses to write there (CWE-59), the
     * shipped `.po` stays exactly as it was.
     */
    public function testMergeSkipsAModuleWhoseShippedDirectoryIsASymlinkOutsideTheIliasRoot(): void
    {
        $outside = sys_get_temp_dir() . '/ilias_spm_outside_' . bin2hex(random_bytes(4));
        mkdir($outside, 0775, true);
        MigratedPoFixture::writePo($outside . '/' . self::MODULE . '_' . self::LANG . '.po', MigratedPoFixture::catalog(self::MODULE, ['aaa' => 'A']));
        $linked_relative = 'components/ILIAS/Language/tests/ComponentTranslation/' . basename($this->fixture_directory) . '-link/';
        symlink($outside, rtrim(ILIAS_ABSOLUTE_PATH, '/') . '/' . rtrim($linked_relative, '/'));
        $linked_directory = MigratedPoFixture::directory(self::MODULE, $linked_relative);
        $manager = new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory(), $linked_directory);
        MigratedLanguageFileSync::sync($manager, ILIAS_ABSOLUTE_PATH, self::LANG, self::MODULE, ['aaa' => 'A (lokal)'], $this->client_data_dir);

        try {
            $result = ShippedPoMerger::merge($manager, ILIAS_ABSOLUTE_PATH, self::LANG, $this->client_data_dir, $this->backup_directory);

            $this->assertSame([], $result['written']);
            $this->assertArrayHasKey(self::MODULE, $result['skipped']);
            $this->assertSame(
                'A',
                TranslationCatalog::fromPoFile($outside . '/' . self::MODULE . '_' . self::LANG . '.po')->find(self::MODULE, 'aaa')?->getTranslation()
            );
        } finally {
            @unlink(rtrim(ILIAS_ABSOLUTE_PATH, '/') . '/' . rtrim($linked_relative, '/'));
            MigratedPoFixture::removeDirectory($outside);
        }
    }
}
