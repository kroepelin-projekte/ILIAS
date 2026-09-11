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
 * Shared by InstallLanguage, UpdateLanguage, UninstallLanguage and
 * RemoveLocalLanguageChanges: turns the 'language_keys' value grind() (see
 * GrindsFormInput) produced - a single, possibly comma-separated string, or
 * (for a direct perform()/isAllowedToPerform() caller bypassing
 * maybePerformAs(), see README.md "As User of a Specific Activity") a plain
 * array of strings - into a de-duplicated, order-preserving list<string> of
 * trimmed, non-blank language keys.
 *
 * This is also the single, central place every "language_keys" input to any
 * of the four Activities above passes through, and therefore the one spot
 * where the actual SHAPE of a language key can be enforced once and for all,
 * rather than trusted at every call site individually: every language key
 * this component installs, refreshes, uninstalls or otherwise recognises is
 * exactly two lowercase ASCII letters (e.g. "de", "en", "fr") - see the
 * `ilias_<key>.lang...` file naming convention every language file directory
 * in this component relies on (\ILIAS\Language\Setup\InstalledLanguageDatabaseRepository::getLocalLanguages()/
 * getInstallableLanguages(), which extract exactly `substr($entry, 6, 2)`),
 * and the fixed two-letter list of shipped `lang/ilias_<key>.lang` files.
 * Rejecting anything else here closes this input down to its true domain at
 * the earliest possible point - in particular, it is a defense-in-depth
 * layer independent of, and in addition to, `ilObjLanguageFolderGUI`'s own
 * `ilObject::_lookupType() === 'lng'` check on every request-supplied id
 * before its title is ever treated as a language key: even if some other,
 * future caller of these Activities were to supply an arbitrary object's
 * title directly (bypassing that GUI-level check entirely), it would still
 * have to happen to also be a plausible two-letter language key to pass here.
 */
trait ParsesLanguageKeyList
{
    private const LANGUAGE_KEY_FORMAT = '/^[a-z]{2}$/';

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
                if ($language_key === '') {
                    continue;
                }

                // $language_key is caller-controlled (e.g. a REST body, or -
                // via ilObjLanguageFolderGUI - a request-supplied object's
                // title) and embedded here as plain text, not HTML - any
                // escaping needed for display is applied by the caller (see
                // \ILIAS\Language\RendersActivityErrors::activityErrorMessage()),
                // never here in the domain layer.
                if (preg_match(self::LANGUAGE_KEY_FORMAT, $language_key) !== 1) {
                    throw new InvalidInputException(
                        'Invalid language key "' . $language_key . '": a language key must be ' .
                        'exactly two lowercase ASCII letters (e.g. "de", "en", "fr").'
                    );
                }

                if (!in_array($language_key, $language_keys, true)) {
                    $language_keys[] = $language_key;
                }
            }
        }

        if ($language_keys === []) {
            throw new InvalidInputException('At least one language key is required.');
        }

        return $language_keys;
    }
}
