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
 * Removes all local changes of one or more already installed languages -
 * both entries edited directly via the "adjust language variables" table and
 * any override coming from a customizing/local language file - and
 * reinstalls each language purely from the global/component language files.
 * A language that is not installed (or not a known language key at all) is
 * left completely untouched; use InstallLanguage to install it first.
 *
 * This is a separate Activity from InstallLanguage/UpdateLanguage/
 * UninstallLanguage on purpose: "discard local customizations of an already
 * installed language while keeping it installed" is a distinct domain action
 * from installing, refreshing or uninstalling one - it has no notion of "not
 * installed yet" becoming "installed" (unlike InstallLanguage), no notion of
 * "install this again as-is" (unlike UpdateLanguage, which also considers a
 * customizing/local file worth reapplying), and does not remove the language
 * object or reset any user preference (unlike UninstallLanguage). Like
 * UninstallLanguage, this has no Setup counterpart - the Setup process only
 * ever installs or refreshes the languages ILIAS ships with, it never
 * discards local changes of one - which is why, like UninstallLanguage, this
 * class deliberately has no `forSetup()` factory.
 *
 * Like UninstallLanguage, this necessarily acts on the already existing
 * `ilObjLanguage` domain object for each language key - only that object
 * carries the business rule (`removeLocalChanges()`) needed here, which
 * validates the language file via `check()`, flushes the existing data and
 * reinstalls the language while explicitly excluding the customizing/local
 * directory (see `ilObjLanguage::removeLocalChanges()` for why this must not
 * go through the ordinary install path). Both legacy access points this
 * requires - enumerating all "lng" objects to resolve a language key to its
 * object id, and constructing the `ilObjLanguage` for a given object id - are
 * the exact calls already used elsewhere in this component (see
 * UninstallLanguage's class docblock for the same reasoning); they are
 * wrapped in closures here only so tests can substitute fakes without
 * bootstrapping the legacy global $DIC that ilObjLanguage's constructor
 * needs.
 *
 * Resolving a requested language key back to its object id (see
 * resolveObjIdsByLanguageKey()) matches purely on title, for the exact same
 * reason and via the exact same round trip as UninstallLanguage (see its
 * class docblock) - including the same guard against two "lng" objects
 * sharing one title: such a title is rejected outright rather than silently
 * resolved to the wrong object.
 *
 * Known limitations, both inherited from the legacy domain object rather
 * than introduced by this extraction:
 *  - `removeLocalChanges()` returns false both when the language is not
 *    installed and when its underlying language file fails `check()`. This
 *    Activity already excludes the "not installed" case beforehand (via
 *    `isInstalled()`), so a false return it still observes can only be the
 *    "invalid language file" case - but this inference is only as reliable
 *    as that being the only remaining reason able to produce false, which
 *    depends on `ilObjLanguage::removeLocalChanges()` not gaining further
 *    failure conditions later on without an accompanying update here.
 *  - perform() is not transactional: if removeLocalChanges() throws partway
 *    through a multi-key request (e.g. a database error), languages
 *    processed before the failing one remain changed while the whole call
 *    is still reported as a single Result\Error, with no indication of
 *    which keys that were.
 */
class RemoveLocalLanguageChanges extends ActivityImpl
{
    use GrindsFormInput;
    use ResolvesLanguageKeysToObjIds;

    private Language $lng;
    private readonly \Closure $ui_factory;
    private readonly \Closure $rbac_system;
    private readonly \Closure $language_folder_ref_id;
    private readonly \Closure $lng_objects;
    private readonly \Closure $obj_language_factory;

    /**
     * @param \Closure|null $lng_objects () => list<array{obj_id: int, title: string}>
     *        Enumerates every "lng"-type object in the system, used to
     *        resolve a requested language key to its object id. Defaults to
     *        the same `ilObject::_getObjectsByType('lng')` call already used
     *        elsewhere in this component.
     * @param \Closure|null $obj_language_factory (int $obj_id) => \ilObjLanguage
     *        Constructs the domain object for a given language object id.
     *        Defaults to `new \ilObjLanguage($obj_id, false)`.
     */
    public function __construct(
        private readonly RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $lng_objects = null,
        ?\Closure $obj_language_factory = null,
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
        $this->lng_objects = $lng_objects
            ?? static fn(): array => \ilObject::_getObjectsByType('lng');
        $this->obj_language_factory = $obj_language_factory
            ?? static fn(int $obj_id): \ilObjLanguage => new \ilObjLanguage($obj_id, false);
    }

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Removes all local changes of one or more already installed languages - both
entries edited directly via the "adjust language variables" table and any
override coming from a customizing/local language file - and reinstalls each
language purely from the global/component language files.

A language is left completely untouched, and reported separately, if it is
not installed (or not a known language key at all), or if its underlying
language file fails validation.
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
            $this->markdown('Result of removing local language changes.'),
            [
                'removed_local_changes_language_keys' => $f->list(
                    $this->markdown(
                        'Already installed languages whose local changes were removed - they were ' .
                        'reinstalled purely from the global/component language files.'
                    ),
                    $f->string($this->markdown('Language key of a language whose local changes were removed.'))
                ),
                'invalid_language_file_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that were left untouched because their underlying ' .
                        'language file failed validation.'
                    ),
                    $f->string($this->markdown('Language key of a language with an invalid language file.'))
                ),
                'not_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that were left untouched because they are not ' .
                        'installed (or not a known language key at all) - there is nothing to ' .
                        'remove local changes from for them.'
                    ),
                    $f->string($this->markdown('Language key of a not-installed language.'))
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
        [$obj_id_by_language_key, $ambiguous_language_keys] = $this->resolveObjIdsByLanguageKey();

        // Checked BEFORE anything below is written, and for every requested
        // key at once (rather than inline in the loop, as before): otherwise
        // a request naming both an unambiguous and an ambiguous key would
        // already have changed the unambiguous one by the time the ambiguous
        // one is reached, leaving the admin unaware that a partial change
        // already happened underneath an "aborted" message - see
        // resolveObjIdsByLanguageKey() for why an ambiguous title is
        // rejected instead of silently guessed at.
        $requested_ambiguous_language_keys = array_values(
            array_intersect($language_keys, array_keys($ambiguous_language_keys))
        );
        if ($requested_ambiguous_language_keys !== []) {
            // AmbiguousLanguageTitleException (rather than a plain
            // \RuntimeException) marks this as a concrete, admin-actionable
            // data integrity problem that activityErrorMessage() shows
            // directly instead of hiding it behind a generic "action
            // aborted" message.
            throw new AmbiguousLanguageTitleException(
                'Multiple language objects share the title(s) "'
                . implode('", "', array_map($this->escapeForMessage(...), $requested_ambiguous_language_keys))
                . '" - cannot unambiguously resolve which one(s) to remove local changes from.'
            );
        }

        $removed_local_changes_language_keys = [];
        $invalid_language_file_keys = [];
        $not_installed_language_keys = [];

        foreach ($language_keys as $language_key) {
            if (!array_key_exists($language_key, $obj_id_by_language_key)) {
                // Not a known language key at all - certainly not installed.
                $not_installed_language_keys[] = $language_key;
                continue;
            }

            $language_object = ($this->obj_language_factory)($obj_id_by_language_key[$language_key]);

            if (!$language_object->isInstalled()) {
                $not_installed_language_keys[] = $language_key;
                continue;
            }

            // removeLocalChanges() re-checks isInstalled() internally (and
            // additionally validates the language file via check()) and
            // returns false if either fails - the "not installed" case is
            // already excluded above, so a false return here can only mean
            // the language file failed validation.
            if ($language_object->removeLocalChanges()) {
                $removed_local_changes_language_keys[] = $language_key;
            } else {
                $invalid_language_file_keys[] = $language_key;
            }
        }

        return [
            'removed_local_changes_language_keys' => $removed_local_changes_language_keys,
            'invalid_language_file_keys' => $invalid_language_file_keys,
            'not_installed_language_keys' => $not_installed_language_keys,
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
     * HTML-escapes a language key/title before it is embedded into a
     * SafeToDisplayActivityError message (see AmbiguousLanguageTitleException
     * above) - such a message is rendered unescaped by callers (e.g.
     * ilObjLanguageFolderGUI::activityErrorMessage()), so a language object
     * title containing HTML-significant characters must not reach it as-is.
     */
    private function escapeForMessage(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
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
