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

use ILIAS\Component\Activities\ActivityImpl;
use ILIAS\Component\Activities\ActivityType;
use ILIAS\Data\Description;
use ILIAS\Data\Result;
use ILIAS\Data\Text;
use ILIAS\Data\Text\Shape\SimpleDocumentMarkdown as SimpleDocumentMarkdownShape;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Factory as UIFactory;

class InstallLanguage extends ActivityImpl
{
    use GrindsFormInput;

    /**
     * A language not yet installed is fully installed (base data, plus a
     * customizing/local file if one exists); a language already installed
     * is left completely untouched - use MODE_INSTALL_LOCAL to (re-)apply a
     * customizing file to it instead.
     */
    public const string MODE_INSTALL = 'install';

    /**
     * Only the customizing/local file is (re-)applied, on top of an already
     * installed language, without touching its base data; a language that
     * is not installed is left completely untouched - use MODE_INSTALL to
     * install it first.
     */
    public const string MODE_INSTALL_LOCAL = 'install_local';

    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;

    public function __construct(
        private readonly RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        private readonly \ilSetupLanguage $setup_language,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        $this->lng = $language;
        $this->ui_factory = $ui_factory instanceof \Closure
            ? $ui_factory
            : static fn(): UIFactory => $ui_factory;
        $this->rbac_system = $rbac_system instanceof \Closure
            ? $rbac_system
            : static fn(): \ilRbacSystem => $rbac_system;
        $this->language_folder_ref_id = $language_folder_ref_id instanceof \Closure
            ? $language_folder_ref_id
            : static fn(): int => $language_folder_ref_id;
    }

    /**
     * Build the Activity for the Setup context, where only perform() is ever
     * called and no runtime container exists to resolve services from.
     *
     * Setup Objectives (see ilLanguagesInstalledAndUpdatedObjective) used to
     * fetch this Activity from $GLOBALS['DIC'], which is only populated by
     * AllModernComponents::enter() and therefore never during Setup - that
     * made setup.php update fail outright. The collaborators that perform()
     * does not touch are supplied here as closures that fail loudly if the
     * Setup path ever starts using the corresponding methods:
     *  - the UI Factory (getInputDescription() only),
     *  - ilRbacSystem and the language folder ref id (isAllowedToPerform()
     *    only, which the Objectives must not call - Setup runs without a
     *    user).
     * The Refinery, by contrast, is built for real: it only needs a Data
     * Factory and any \ILIAS\Language\Language, and ilSetupLanguage is one.
     *
     * No database is needed here: every database access in perform() goes
     * through $setup_language, which resolves it itself - inject the
     * Setup-provided one via ilSetupLanguage::setDbHandler().
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

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Installs one or more languages in the ILIAS system, or applies just their
customizing/local language file, depending on the chosen mode.

Mode "install" fully installs a language that is not yet installed (base
data, plus a customizing/local file if one exists) and leaves an already
installed language completely untouched. Mode "install_local" instead
(re-)applies only the customizing/local file on top of an already installed
language, without touching its base data, and leaves a not-yet-installed
language completely untouched.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $language_keys = $ui_factory->input()->field()->text(
            'Language keys',
            'Comma-separated list of language keys, e.g. de, fr, it.'
        )->withRequired(true)->withDedicatedName('language_keys');

        $mode = $ui_factory->input()->field()->select(
            'Mode',
            [
                self::MODE_INSTALL => 'Install',
                self::MODE_INSTALL_LOCAL => 'Install local',
            ],
            'Whether to fully install the given languages, or to only ' .
            '(re-)apply their customizing/local file on top of an existing ' .
            'installation.'
        )->withRequired(true)->withDedicatedName('mode');

        return $ui_factory->input()->field()->group([
            'language_keys' => $language_keys,
            'mode' => $mode,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of the language installation.'),
            [
                'installed_language_keys' => $f->list(
                    $this->markdown('Newly installed languages without a custom language file.'),
                    $f->string($this->markdown('Language key of a newly installed language.'))
                ),
                'installed_with_local_language_keys' => $f->list(
                    $this->markdown(
                        'Languages for which a custom/local language file was (re-)installed - ' .
                        'either as part of a fresh installation (mode "install"), or applied on ' .
                        'top of an already installed language (mode "install_local").'
                    ),
                    $f->string($this->markdown('Language key of a language with an installed custom language file.'))
                ),
                'already_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Languages for which this run changed nothing: mode "install" was ' .
                        'requested for a language that was already installed (a no-op by design ' .
                        '- use mode "install_local" to (re-)apply a customizing file instead), ' .
                        'or mode "install_local" was requested for an installed language that ' .
                        'has no customizing/local file to apply.'
                    ),
                    $f->string($this->markdown('Language key of an already installed language.'))
                ),
                'not_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Languages requested with mode "install_local" that are not installed - ' .
                        'skipped entirely, since there is nothing installed yet to apply local ' .
                        'changes on top of.'
                    ),
                    $f->string($this->markdown('Language key of a not-yet-installed language.'))
                ),
                'invalid_local_language_files' => $f->list(
                    $this->markdown('Local language files with an invalid file name.'),
                    $f->string($this->markdown('File name of an invalid local language file.'))
                ),
            ]
        );
    }

    private function markdown(string $raw): Text\SimpleDocumentMarkdown
    {
        return new Text\SimpleDocumentMarkdown(
            new SimpleDocumentMarkdownShape(
                $this->refinery->string()->markdown()
            ),
            $raw
        );
    }

    public function isAllowedToPerform(int $usr_id, mixed $parameters): bool
    {
        return ($this->rbac_system)()->checkAccessOfUser(
            $usr_id,
            'write',
            ($this->language_folder_ref_id)()
        );
    }

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new \InvalidArgumentException('Parameters must be an array.');
        }

        $language_keys = $this->toLanguageKeyList($parameters['language_keys'] ?? null);
        $mode = $this->toMode($parameters['mode'] ?? null);

        $currently_installed_language_keys = $this->setup_language->getInstalledLanguages();

        // Split the requested language keys by what must actually happen to
        // each of them, given $mode and whether they are already installed -
        // the four combinations mean completely different things:
        //  - MODE_INSTALL,       not installed: full installation (the base
        //    files are validated below, then it is installed like before).
        //  - MODE_INSTALL,       already installed: complete no-op - not
        //    even a pending customizing/local file is (re-)applied anymore;
        //    that is now exclusively MODE_INSTALL_LOCAL's job.
        //  - MODE_INSTALL_LOCAL, already installed: (re-)apply only the
        //    customizing/local file, the base data is left untouched.
        //  - MODE_INSTALL_LOCAL, not installed: no-op - there is nothing
        //    installed yet to apply local changes on top of.
        $to_fully_install = [];
        $to_apply_local_changes = [];
        $already_installed_no_op = [];
        $not_installed_no_op = [];

        foreach ($language_keys as $language_key) {
            $is_installed = in_array($language_key, $currently_installed_language_keys, true);
            if ($mode === self::MODE_INSTALL) {
                if ($is_installed) {
                    $already_installed_no_op[] = $language_key;
                } else {
                    $to_fully_install[] = $language_key;
                }
            } elseif ($is_installed) {
                $to_apply_local_changes[] = $language_key;
            } else {
                $not_installed_no_op[] = $language_key;
            }
        }

        $error_language_keys = [];
        foreach ($to_fully_install as $language_key) {
            if (!$this->setup_language->checkLanguageForInstallation($language_key)) {
                $error_language_keys[] = $language_key;
            }
        }

        if ($error_language_keys !== []) {
            throw new \RuntimeException(
                'Invalid language files: ' . implode(', ', $error_language_keys)
            );
        }

        $installed_language_keys = [];
        $installed_with_local_language_keys = [];
        $invalid_local_language_files = [];

        // Nothing below is needed at all (not even a read) if every
        // requested language turned out to be a no-op above.
        $affected_language_keys = array_merge($to_fully_install, $to_apply_local_changes);
        if ($affected_language_keys !== []) {
            $db_languages = $this->setup_language->getAvailableLanguagesForInstallation();
            $local_language_keys = $this->setup_language->getLocalLanguages();
            $invalid_local_language_files = $this->setup_language->getInvalidLocalLanguageFiles(
                $affected_language_keys
            );

            foreach ($to_fully_install as $language_key) {
                $this->setup_language->flushLanguageForInstallation($language_key);
                $this->setup_language->insertLanguageForInstallation($language_key);
                $this->setup_language->registerInstalledLanguage($language_key, $db_languages, $local_language_keys);

                if (in_array($language_key, $local_language_keys, true)) {
                    $installed_with_local_language_keys[] = $language_key;
                } else {
                    $installed_language_keys[] = $language_key;
                }
            }

            foreach ($to_apply_local_changes as $language_key) {
                $this->setup_language->insertLanguageForApplyingLocalChanges($language_key);
                $this->setup_language->registerInstalledLanguage($language_key, $db_languages, $local_language_keys);

                if (in_array($language_key, $local_language_keys, true)) {
                    $installed_with_local_language_keys[] = $language_key;
                } else {
                    // No customizing/local file actually exists for this
                    // language - "install_local" had nothing to apply, which
                    // is exactly the "already installed, nothing changed"
                    // case.
                    $already_installed_no_op[] = $language_key;
                }
            }
        }

        return [
            'installed_language_keys' => $installed_language_keys,
            'installed_with_local_language_keys' => $installed_with_local_language_keys,
            'already_installed_language_keys' => $already_installed_no_op,
            'not_installed_language_keys' => $not_installed_no_op,
            'invalid_local_language_files' => $invalid_local_language_files,
        ];
    }

    public function maybePerformAs(int $usr_id, array $raw_parameters): Result
    {
        $grind_result = $this->grind($this->getInputDescription(), $raw_parameters);
        if ($grind_result->isError()) {
            return new Result\Error($grind_result->error());
        }

        try {
            $parameters = $this->normalizeParameters($grind_result->value());
            if (!$this->isAllowedToPerform($usr_id, $parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_write'));
            }

            return new Result\Ok($this->perform($parameters));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function toLanguageKeyList(mixed $value): array
    {
        if (!is_string($value) && !is_array($value)) {
            throw new InvalidInputException('language_keys must be a string or an array of strings.');
        }

        $values = is_array($value) ? $value : [$value];
        $language_keys = [];

        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new InvalidInputException('language_keys must be a string or an array of strings.');
            }

            foreach (explode(',', (string) $item) as $language_key) {
                $language_key = trim($language_key);
                if ($language_key !== '' && !in_array($language_key, $language_keys, true)) {
                    $language_keys[] = $language_key;
                }
            }
        }

        if ($language_keys === []) {
            throw new InvalidInputException('At least one language key is required.');
        }

        return $language_keys;
    }

    /**
     * @param mixed $value
     */
    private function toMode(mixed $value): string
    {
        if ($value === self::MODE_INSTALL || $value === self::MODE_INSTALL_LOCAL) {
            return $value;
        }

        throw new InvalidInputException(
            'mode must be either "' . self::MODE_INSTALL . '" or "' . self::MODE_INSTALL_LOCAL . '".'
        );
    }

    /**
     * Builds the parameters perform()/isAllowedToPerform() expect from the
     * already-grinded content of getInputDescription() (see grind() in the
     * GrindsFormInput trait) - both 'language_keys' and 'mode' are
     * guaranteed to be non-blank strings at this point (both Text/Select
     * fields are required), but still need the actual domain-level parsing/
     * validation toLanguageKeyList()/toMode() already did before (splitting
     * the comma-separated list, checking 'mode' is one of the two allowed
     * values) - getInputDescription()'s own required-check only enforces a
     * minimum length of 1 on the raw string.
     *
     * @param array{language_keys: string, mode: string} $grind_result
     * @return array{language_keys: list<string>, mode: string}
     */
    private function normalizeParameters(array $grind_result): array
    {
        return [
            'language_keys' => $this->toLanguageKeyList($grind_result['language_keys']),
            'mode' => $this->toMode($grind_result['mode']),
        ];
    }
}
