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

namespace ILIAS\Language\ComponentTranslation\Catalog;

/**
 * What a compiled `.mo` holds, as TranslationCatalog::readMoMessages() reads it: per message id one
 * value, and for plural messages additionally every form.
 */
final class CompiledTranslations
{
    /**
     * @param array<string, string> $translations message id => value; for a plural message the
     *        value of its default form (see ILIAS\Language\ComponentTranslation\PluralForms)
     * @param array<string, list<string>> $plural_translations message id => msgstr[0], msgstr[1], ...
     *        of every plural message
     * @param string|null $plural_forms_header the "Plural-Forms" header of the `.mo`, if any
     */
    public function __construct(
        private readonly array $translations,
        private readonly array $plural_translations,
        private readonly ?string $plural_forms_header
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getPluralTranslations(): array
    {
        return $this->plural_translations;
    }

    public function getPluralFormsHeader(): ?string
    {
        return $this->plural_forms_header;
    }
}
