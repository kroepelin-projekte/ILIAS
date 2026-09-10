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

/**
 * Shared by UninstallLanguage and RemoveLocalLanguageChanges: resolves
 * every "lng" object's title (i.e. language key) to its object id, as
 * needed to construct the ilObjLanguage for a requested language key (see
 * either class' own docblock for why). A title shared by more than one
 * object - a data integrity anomaly that should never occur in a healthy
 * installation, but is not otherwise guarded against anywhere in this
 * component - is deliberately excluded from the returned map rather than
 * arbitrarily resolved to "whichever object happened to be enumerated
 * last": doing so could silently act on the wrong object. Instead its
 * title is returned separately, in $ambiguous_language_keys, so perform()
 * can reject a request naming it explicitly (see AmbiguousLanguageTitleException)
 * rather than mis-resolving it silently.
 *
 * Requires the using class to declare `private readonly \Closure $lng_objects;`
 * (() => list<array{obj_id: int, title: string}>) exactly like
 * UninstallLanguage/RemoveLocalLanguageChanges already do.
 */
trait ResolvesLanguageKeysToObjIds
{
    /**
     * @return array{0: array<string, int>, 1: array<string, true>}
     */
    private function resolveObjIdsByLanguageKey(): array
    {
        // Resolved exactly once here (rather than once per loop, as before
        // this trait was extracted) - two separate calls could otherwise
        // observe two different snapshots of the "lng" objects (an
        // unlikely, but real, TOCTOU risk) and would always cost a second,
        // entirely redundant enumeration/DB round trip.
        $lng_objects = ($this->lng_objects)();

        $title_occurrences = [];
        foreach ($lng_objects as $lng_object) {
            $title_occurrences[$lng_object['title']] = ($title_occurrences[$lng_object['title']] ?? 0) + 1;
        }

        $obj_id_by_language_key = [];
        $ambiguous_language_keys = [];
        foreach ($lng_objects as $lng_object) {
            $title = $lng_object['title'];
            if ($title_occurrences[$title] > 1) {
                $ambiguous_language_keys[$title] = true;
                continue;
            }

            $obj_id_by_language_key[$title] = (int) $lng_object['obj_id'];
        }

        return [$obj_id_by_language_key, $ambiguous_language_keys];
    }
}
