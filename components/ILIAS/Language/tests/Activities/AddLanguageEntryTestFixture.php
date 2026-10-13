<?php

/**
 * This file is part of ILIAS, a powerful learning management system
 * published by ILIAS open source e-Learning e.V.
 *
 * ILIAS is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with
 * the source code, too.
 *
 * If this is not the case or you just want to try ILIAS, you'll find
 * us at:
 * https://www.ilias.de
 * https://github.com/ILIAS-eLearning
 *
 *********************************************************************/

declare(strict_types=1);

namespace ILIAS\Language\Tests\Activities;

use ILIAS\Language\Activities\AddLanguageEntry;
use ILIAS\Language\ComponentTranslation\CustomizingLanguageFileDirectory;
use ILIAS\Language\ComponentTranslation\LanguageFileDirectoryManager;
use ILIAS\Language\Language;
use ILIAS\Language\Setup\InstalledLanguageRepository;
use ILIAS\Refinery\Factory as RefineryFactory;

trait AddLanguageEntryTestFixture
{
    /**
     * @param array<string, array> $calls
     */
    protected function spyReplaceLangEntry(array &$calls): \Closure
    {
        return static function (
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ) use (&$calls): bool {
            $calls['replace'][] = [$module, $identifier, $lang_key, $value, $local_change, $remarks];
            return true;
        };
    }

    /**
     * @param array<string, array> $calls
     */
    protected function spyUpdateModuleCache(array &$calls): \Closure
    {
        return static function (
            string $lang_key,
            string $module,
            string $identifier,
            string $value
        ) use (&$calls): void {
            $calls['cache'][] = [$lang_key, $module, $identifier, $value];
        };
    }

    protected function createUnusedDb(): \ilDBInterface
    {
        $db = $this->createMock(\ilDBInterface::class);
        $db->expects(self::never())->method(self::anything());

        return $db;
    }

    protected function noMigratedModules(): LanguageFileDirectoryManager
    {
        return new LanguageFileDirectoryManager(new CustomizingLanguageFileDirectory());
    }

    /**
     * The fixture directories (MigratedPoFixture::directory()) are relative to the installation root.
     */
    protected function iliasRoot(): string
    {
        return (string) realpath(__DIR__ . '/../../../../../');
    }

    /**
     * @param list<string> $installed_languages
     */
    protected function createActivity(
        array $installed_languages,
        ?\ilRbacSystem $rbac = null,
        ?Language $language = null,
        ?InstalledLanguageRepository $installed_language_repository = null,
        ?\ilDBInterface $db = null,
        int $language_folder_ref_id = 0,
        ?\Closure $replace_lang_entry = null,
        ?\Closure $update_module_cache = null,
        ?\Closure $user_login = null,
        ?RefineryFactory $refinery = null,
        ?LanguageFileDirectoryManager $language_file_directory_manager = null,
    ): AddLanguageEntry {
        // no-op defaults: the real closures need global $DIC; tests proving a closure is never called pass a throwing one
        $replace_lang_entry ??= static fn(
            string $module,
            string $identifier,
            string $lang_key,
            string $value,
            string $local_change,
            string $remarks
        ): bool => true;
        $update_module_cache ??= static function (
            string $lang_key,
            string $module,
            string $identifier,
            string $value
        ): void {
        };
        $user_login ??= static fn(int $usr_id): string => 'default-login';
        // never reached: every test overrides update_module_cache, so a test relying on the real $db default fails
        $db ??= $this->createUnusedDb();

        return new AddLanguageEntry(
            refinery: $refinery ?? $this->createStub(RefineryFactory::class),
            language: $language ?? $this->createStub(Language::class),
            rbac: $this->rbacOf($rbac ?? $this->createStub(\ilRbacSystem::class)),
            installed_language_repository: $installed_language_repository ?? new FakeInstalledLanguageRepository(
                static fn(): array => $installed_languages
            ),
            language_folder: $this->languageFolder($language_folder_ref_id),
            language_file_directory_manager: $language_file_directory_manager ?? $this->noMigratedModules(),
            ilias_root: $this->iliasRoot(),
            client_data_dir_resolver: static fn(): ?string => null,
            replace_lang_entry: $replace_lang_entry,
            update_module_cache: $update_module_cache,
            user_login: $user_login,
            db: $db,
        );
    }
}
