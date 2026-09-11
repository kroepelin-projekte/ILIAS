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
 * Removes all local changes of one or more already installed languages -
 * both entries edited directly via the "adjust language variables" table and
 * any override coming from a customizing/local language file - and
 * reinstalls each language purely from the global/component language files.
 * A language that is not installed (or not a known language key at all) is
 * left completely untouched; use InstallLanguage to install it first.
 *
 * Separate Activity from Install/Update/UninstallLanguage on purpose:
 * "discard local customizations while keeping the language installed" is a
 * distinct domain action from installing, refreshing or uninstalling one -
 * and, like UninstallLanguage (see its class docblock for the shared
 * reasoning), has no Setup counterpart and hence no `forSetup()` factory.
 *
 * Like UninstallLanguage, this necessarily acts on the existing
 * `ilObjLanguage` domain object per language key - only that object carries
 * the business rule (`removeLocalChanges()`) needed here, which validates
 * the language file via `check()`, flushes the existing data and reinstalls
 * the language while explicitly excluding the customizing/local directory.
 * Both legacy access points this requires (enumerating "lng" objects,
 * constructing an ilObjLanguage by id) are the exact calls UninstallLanguage
 * also uses, for the same reasons (see its class docblock) - wrapped in
 * closures here only so tests can substitute fakes without bootstrapping the
 * legacy global $DIC.
 *
 * Resolving a requested language key to its object id
 * (resolveObjIdsByLanguageKey(), see ResolvesLanguageKeysToObjIds) matches
 * purely on title and rejects an ambiguous one outright - identical
 * reasoning to UninstallLanguage, see its class docblock.
 */
class RemoveLocalLanguageChanges extends LanguageActivity
{
    use DeclaresLanguageKeysOnlyInput;
    use ResolvesLanguageKeysToObjIds;

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
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $lng_objects = null,
        ?\Closure $obj_language_factory = null,
    ) {
        parent::__construct($refinery, $ui_factory, $language, $rbac_system, $language_folder_ref_id);
        $this->lng_objects = $lng_objects
            ?? static fn(): array => \ilObject::_getObjectsByType('lng');
        $this->obj_language_factory = $obj_language_factory
            ?? static fn(int $obj_id): \ilObjLanguage => new \ilObjLanguage($obj_id, false);
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

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new InvalidInputException('Parameters must be an array.');
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
            // aborted" message. Its message is plain text, not HTML - any
            // HTML-escaping needed for display is applied by the caller
            // (see \ILIAS\Language\RendersActivityErrors::activityErrorMessage()),
            // never here in the domain layer.
            throw new AmbiguousLanguageTitleException(
                'Multiple language objects share the title(s) "'
                . implode('", "', $requested_ambiguous_language_keys)
                . '" - cannot unambiguously resolve which one(s) to remove local changes from.'
            );
        }

        $removed_local_changes_language_keys = [];
        $invalid_language_file_keys = [];
        $not_installed_language_keys = [];

        // Not transactional across multiple keys: if removeLocalChanges()
        // throws partway through (e.g. a database error), languages
        // processed before the failing one remain changed while the whole
        // call is still reported as a single Result\Error.
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
}
