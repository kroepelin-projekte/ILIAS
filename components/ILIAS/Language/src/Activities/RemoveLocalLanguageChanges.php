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

class RemoveLocalLanguageChanges extends LanguageActivity
{
    use DeclaresLanguageKeysOnlyInput;
    use ResolvesLanguageKeysToObjIds;

    private readonly \Closure $lng_objects;
    private readonly \Closure $obj_language_factory;
    private readonly \Closure $refresh_plugins;

    /**
     * @param \Closure|null $lng_objects (): list<array{obj_id: int, title: string}>
     * @param \Closure|null $obj_language_factory (int $obj_id): \ilObjLanguage
     * @param \Closure|null $refresh_plugins (list<string> $lang_keys): void - re-applies the plugin
     *        language files (removing local changes flushes their rows as well); by default
     *        ilObjLanguage::refreshPlugins(), a no-op without the component repository
     */
    public function __construct(
        RefineryFactory $refinery,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        int|\Closure $language_folder_ref_id = 0,
        ?\Closure $lng_objects = null,
        ?\Closure $obj_language_factory = null,
        ?\Closure $refresh_plugins = null,
    ) {
        parent::__construct($refinery, $language, $rbac_system, $language_folder_ref_id);
        $this->lng_objects = $lng_objects
            ?? static fn(): array => \ilObject::_getObjectsByType('lng');
        $this->obj_language_factory = $obj_language_factory
            ?? static fn(int $obj_id): \ilObjLanguage => new \ilObjLanguage($obj_id, false);
        $this->refresh_plugins = $refresh_plugins
            ?? static function (array $lang_keys): void {
                global $DIC;
                if (isset($DIC) && $DIC->offsetExists('component.repository')) {
                    \ilObjLanguage::refreshPlugins($lang_keys);
                }
            };
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Removes all local changes of one or more already installed languages - both
entries edited directly via the "adjust language variables" table and any
override coming from a customizing/local language file, locally added variables
and remarks - and reinstalls each language purely from the global/component
language files (for a module maintained in PO files: from its shipped .po, its
overlay is removed); the language files of the active plugins are applied again.

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
                'overlay_write_failed_language_keys' => $this->overlayWriteFailedOutputField($f),
            ]
        );
    }

    private static function logWarning(string $message): void
    {
        global $DIC;
        try {
            $DIC->logger()->forComponent('lang')->warning($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new InvalidInputException('Parameters must be an array.');
        }

        $language_keys = $this->toLanguageKeyList($parameters['language_keys'] ?? null);
        [$obj_id_by_language_key, $ambiguous_language_keys] = $this->resolveObjIdsByLanguageKey();

        // Checked upfront, for all requested keys at once, so a request naming both an
        // unambiguous and an ambiguous key never changes the unambiguous one before
        // rejecting the whole call.
        $requested_ambiguous_language_keys = array_values(
            array_intersect($language_keys, array_keys($ambiguous_language_keys))
        );
        if ($requested_ambiguous_language_keys !== []) {
            throw new AmbiguousLanguageTitleException(
                'Multiple language objects share the title(s) "'
                . implode('", "', $requested_ambiguous_language_keys)
                . '" - cannot unambiguously resolve which one(s) to remove local changes from.'
            );
        }

        $removed_local_changes_language_keys = [];
        $invalid_language_file_keys = [];
        $not_installed_language_keys = [];
        $overlay_write_failed_language_keys = [];

        // Not transactional across multiple keys: a failure partway through (e.g. a database
        // error) leaves languages processed so far changed.
        foreach ($language_keys as $language_key) {
            if (!array_key_exists($language_key, $obj_id_by_language_key)) {
                $not_installed_language_keys[] = $language_key;
                continue;
            }

            $language_object = ($this->obj_language_factory)($obj_id_by_language_key[$language_key]);

            if (!$language_object->isInstalled()) {
                $not_installed_language_keys[] = $language_key;
                continue;
            }

            // removeLocalChanges() re-checks isInstalled() internally (and additionally
            // validates the language file) and returns false if either fails - "not installed"
            // is already excluded above, so false here can only mean validation failed.
            try {
                $removed = $language_object->removeLocalChanges();
            } catch (\Throwable $t) {
                // flush("all") and the rebuild are not one transaction: the language may be left
                // incomplete - reported, and the way back named
                self::logWarning(sprintf(
                    'Removing the local changes of language "%s" failed after its data was flushed - '
                    . 'the language may be incomplete, run "php cli/setup.php update" (or "Update" in the '
                    . 'language list) to rebuild it: %s',
                    $language_key,
                    $t->getMessage()
                ));
                throw $t;
            }
            if ($removed) {
                $removed_local_changes_language_keys[] = $language_key;
                if ($language_object->getModulesWithUnwrittenOverlay() !== []) {
                    $overlay_write_failed_language_keys[] = $language_key;
                }
            } else {
                $invalid_language_file_keys[] = $language_key;
            }
        }

        // Plugin language files are re-applied only for the languages whose local changes were
        // actually removed (their rows were flushed with the rest)
        if ($removed_local_changes_language_keys !== []) {
            ($this->refresh_plugins)($removed_local_changes_language_keys);
        }

        return [
            'removed_local_changes_language_keys' => $removed_local_changes_language_keys,
            'invalid_language_file_keys' => $invalid_language_file_keys,
            'not_installed_language_keys' => $not_installed_language_keys,
            'overlay_write_failed_language_keys' => $overlay_write_failed_language_keys,
        ];
    }
}
