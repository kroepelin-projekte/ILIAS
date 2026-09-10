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
class UpdateLanguage extends ActivityImpl
{
    use GrindsFormInput;

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

    public function getType(): ActivityType
    {
        return ActivityType::Command;
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

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $language_keys = $ui_factory->input()->field()->text(
            'Language keys',
            'Comma-separated list of language keys, e.g. de, fr, it.'
        )->withRequired(true)->withDedicatedName('language_keys');

        return $ui_factory->input()->field()->group([
            'language_keys' => $language_keys,
        ]);
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
     * Builds the parameters perform()/isAllowedToPerform() expect from the
     * already-grinded content of getInputDescription() (see grind() in the
     * GrindsFormInput trait) - 'language_keys' is guaranteed to be a
     * non-blank string at this point (the Text field is required), but
     * still needs toLanguageKeyList()'s own domain-level parsing (splitting
     * the comma-separated list).
     *
     * @param array{language_keys: string} $grind_result
     * @return array{language_keys: list<string>}
     */
    private function normalizeParameters(array $grind_result): array
    {
        return [
            'language_keys' => $this->toLanguageKeyList($grind_result['language_keys']),
        ];
    }
}
