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

use ILIAS\UI\Component\Input\Container\Form\FormInput;

/**
 * Shared by UpdateLanguage, UninstallLanguage and RemoveLocalLanguageChanges:
 * these three Activities take EXACTLY one input, a single 'language_keys'
 * text field (unlike InstallLanguage, which additionally needs a 'mode'
 * field and therefore declares its own getInputDescription()/
 * normalizeParameters() instead of using this trait).
 *
 * Requires the using class to extend LanguageActivity (for $this->ui_factory
 * and the abstract normalizeParameters() this trait implements).
 */
trait DeclaresLanguageKeysOnlyInput
{
    use ParsesLanguageKeyList;

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

    /**
     * @param array{language_keys: string} $grind_result
     * @return array{language_keys: list<string>}
     */
    protected function normalizeParameters(array $grind_result): array
    {
        return [
            'language_keys' => $this->toLanguageKeyList($grind_result['language_keys']),
        ];
    }
}
