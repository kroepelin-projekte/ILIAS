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
 * Uninstalls one or more already installed languages: DB-held base data
 * (and any customizing/local data) is flushed, the language's status
 * reverts to "not installed", and any user preference still pointing at it
 * is reset to the system default. A language that is not installed, the
 * current system language, or the language currently in use by the acting
 * session is left completely untouched and reported separately (see
 * getOutputDescription()).
 *
 * Separate Activity from Install/UpdateLanguage on purpose: uninstalling has
 * its own guard rails (the system/in-use language must never be removable)
 * and, unlike them, no Setup counterpart - Setup only ever installs/
 * refreshes the languages ILIAS ships with, never uninstalls one - hence no
 * `forSetup()` factory here.
 *
 * Necessarily acts on the existing `ilObjLanguage` domain object per
 * language key: only that object carries the business rules
 * (isSystemLanguage()/isUserLanguage()/isInstalled()/uninstall()) needed
 * here. The lower-level `flushLanguageForUninstallation()` on
 * ilSetupLanguage/LanguageInstallationManager is deliberately NOT used - it
 * neither updates the object's title/status nor resets a pointing user
 * preference, both of which `uninstall()` does and must be preserved. The
 * two legacy access points this requires (enumerating "lng" objects,
 * constructing an ilObjLanguage by id) are wrapped in closures purely so
 * tests can substitute fakes without bootstrapping the legacy global $DIC.
 *
 * Resolving a requested language key to its object id
 * (resolveObjIdsByLanguageKey(), shared with RemoveLocalLanguageChanges - see
 * ResolvesLanguageKeysToObjIds) matches purely on title, because "language
 * key" (title), not "obj_id", is this Activity's public vocabulary; a title
 * shared by two "lng" objects is rejected outright
 * (AmbiguousLanguageTitleException) rather than silently resolved to the
 * wrong one.
 */
class UninstallLanguage extends LanguageActivity
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
     *        Defaults to `new \ilObjLanguage($obj_id)`.
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
            ?? static fn(int $obj_id): \ilObjLanguage => new \ilObjLanguage($obj_id);
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Uninstalls one or more already installed languages, flushing their base data
(and any customizing/local data) and resetting the status of the according
language to "not installed". Any user preference still pointing at an
uninstalled language is reset to the system default.

A language is left completely untouched, and reported separately, if it is
not installed, if it is the current system language, or if it is the
language currently in use by the acting session - none of these may ever be
uninstalled.
MARKDOWN
        );
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of the language uninstallation.'),
            [
                'uninstalled_language_keys' => $f->list(
                    $this->markdown('Languages that were uninstalled.'),
                    $f->string($this->markdown('Language key of an uninstalled language.'))
                ),
                'system_language_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that were left untouched because they are the ' .
                        'current system language - the system language can never be uninstalled.'
                    ),
                    $f->string($this->markdown('Language key of the system language.'))
                ),
                'user_language_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that were left untouched because they are the ' .
                        'language currently in use by the acting session - a language currently ' .
                        'in use can never be uninstalled.'
                    ),
                    $f->string($this->markdown('Language key of the language currently in use.'))
                ),
                'not_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Requested languages that were left untouched because they are not ' .
                        'installed (or not a known language key at all) - there is nothing to ' .
                        'uninstall for them.'
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
        // already have uninstalled the unambiguous one by the time the
        // ambiguous one is reached, leaving the admin unaware that a partial
        // uninstallation already happened underneath an "aborted" message -
        // see resolveObjIdsByLanguageKey() for why an ambiguous title is
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
                . '" - cannot unambiguously resolve which one(s) to uninstall.'
            );
        }

        $uninstalled_language_keys = [];
        $system_language_keys = [];
        $user_language_keys = [];
        $not_installed_language_keys = [];

        // Not transactional across multiple keys: if uninstall() throws
        // partway through (e.g. a database error), languages processed
        // before the failing one remain uninstalled while the whole call is
        // still reported as a single Result\Error.
        foreach ($language_keys as $language_key) {
            if (!array_key_exists($language_key, $obj_id_by_language_key)) {
                // Not a known language key at all - certainly not installed.
                $not_installed_language_keys[] = $language_key;
                continue;
            }

            $language_object = ($this->obj_language_factory)($obj_id_by_language_key[$language_key]);

            if ($language_object->isSystemLanguage()) {
                $system_language_keys[] = $language_key;
            } elseif ($language_object->isUserLanguage()) {
                // isUserLanguage() compares against the *ambient*
                // \ILIAS\Language\Language service's lang_user (i.e. this
                // PHP request's session), not against $usr_id - inherited
                // from the legacy domain object, not introduced by this
                // extraction. A caller acting for a $usr_id other than the
                // current session's user (e.g. a webservice or background
                // job) will not protect *that* user's language this way.
                $user_language_keys[] = $language_key;
            } elseif (!$language_object->isInstalled()) {
                $not_installed_language_keys[] = $language_key;
            } else {
                // uninstall() re-checks these same three guards internally
                // and returns "" if any of them applies after all (e.g. a
                // future additional guard added there) - the outcome is
                // therefore decided by this return value, not merely by the
                // three checks above, so that a rejection inside uninstall()
                // is never misreported as a success.
                if ($language_object->uninstall() !== '') {
                    $uninstalled_language_keys[] = $language_key;
                } else {
                    $not_installed_language_keys[] = $language_key;
                }
            }
        }

        return [
            'uninstalled_language_keys' => $uninstalled_language_keys,
            'system_language_keys' => $system_language_keys,
            'user_language_keys' => $user_language_keys,
            'not_installed_language_keys' => $not_installed_language_keys,
        ];
    }
}
