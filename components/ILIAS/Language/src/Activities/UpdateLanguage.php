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

namespace ILIAS\Language\Activities;

use ILIAS\Data\Description;
use ILIAS\Data\Text;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Factory as UIFactory;

/**
 * Refreshes languages that are already installed, re-seeding their base data
 * (plus a customizing/local file if one exists) from the current language
 * files - e.g. after a core update shipped new or changed translations. A
 * language that is not installed is left completely untouched; use
 * InstallLanguage to install it first.
 *
 * This is a separate Activity from InstallLanguage on purpose: "install a
 * language that is missing" and "refresh a language that is already there"
 * are different responsibilities with different callers and different
 * no-op semantics, not two modes of the same operation.
 */
class UpdateLanguage extends LanguageActivity
{
    use DeclaresLanguageKeysOnlyInput;

    public function __construct(
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        private readonly \ilSetupLanguage $setup_language,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        parent::__construct($refinery, $ui_factory, $language, $rbac_system, $language_folder_ref_id);
    }

    /**
     * Build the Activity for the Setup context, where only perform() is ever
     * called and no runtime container exists to resolve services from. See
     * InstallLanguage::forSetup() for why the collaborators perform() does
     * not touch are supplied as closures that fail loudly instead, and why
     * no database is needed here either.
     */
    public static function forSetup(\ilSetupLanguage $setup_language): self
    {
        return new self(
            new RefineryFactory(new \ILIAS\Data\Factory(), $setup_language),
            static fn(): UIFactory => throw new \LogicException(
                'The UI Factory is not available during Setup; '
                . self::class . '::getInputDescription() cannot be used here.'
            ),
            $setup_language,
            static fn(): \ilRbacSystem => throw new \LogicException(
                'RBAC is not available during Setup; '
                . self::class . '::isAllowedToPerform() cannot be used here.'
            ),
            $setup_language
        );
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Refreshes one or more already installed languages, re-seeding their base
data (plus a customizing/local file if one exists) from the current
language files. A language that is not installed is left completely
untouched - use InstallLanguage to install it first.
MARKDOWN
        );
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of the language update.'),
            [
                'updated_language_keys' => $f->list(
                    $this->markdown(
                        'Already installed languages that were refreshed from the current ' .
                        'language files (base data, plus a customizing/local file if one exists).'
                    ),
                    $f->string($this->markdown('Language key of a refreshed language.'))
                ),
                'not_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that are not installed - skipped entirely, since ' .
                        'there is nothing installed yet to refresh. Use InstallLanguage to ' .
                        'install them first.'
                    ),
                    $f->string($this->markdown('Language key of a not-yet-installed language.'))
                ),
            ]
        );
    }

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new InvalidInputException('Parameters must be an array.');
        }

        $language_keys = $this->toLanguageKeyList($parameters['language_keys'] ?? null);

        $currently_installed_language_keys = $this->setup_language->getInstalledLanguages();

        // Only an already installed language has anything to refresh; a
        // language that is not installed is a complete no-op - there is
        // nothing installed yet to update.
        $to_update = [];
        $not_installed_no_op = [];

        foreach ($language_keys as $language_key) {
            if (in_array($language_key, $currently_installed_language_keys, true)) {
                $to_update[] = $language_key;
            } else {
                $not_installed_no_op[] = $language_key;
            }
        }

        $error_language_keys = [];
        foreach ($to_update as $language_key) {
            if (!$this->setup_language->checkLanguageForInstallation($language_key)) {
                $error_language_keys[] = $language_key;
            }
        }

        if ($error_language_keys !== []) {
            throw new \RuntimeException(
                'Invalid language files: ' . implode(', ', $error_language_keys)
            );
        }

        // Nothing below is needed at all (not even a read) if every
        // requested language turned out to be a no-op above.
        if ($to_update !== []) {
            $db_languages = $this->setup_language->getAvailableLanguagesForInstallation();
            $local_language_keys = $this->setup_language->getLocalLanguages();

            foreach ($to_update as $language_key) {
                $this->setup_language->flushLanguageForInstallation($language_key);
                $this->setup_language->insertLanguageForInstallation($language_key);
                $this->setup_language->registerInstalledLanguage($language_key, $db_languages, $local_language_keys);
            }
        }

        return [
            'updated_language_keys' => $to_update,
            'not_installed_language_keys' => $not_installed_no_op,
        ];
    }
}
