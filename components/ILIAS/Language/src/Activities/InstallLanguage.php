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

final class InstallLanguage extends ActivityImpl
{
    private Language $lng;

    public function __construct(
        private readonly RefineryFactory $refinery,
        Language $language,
    ) {
        $this->lng = $language;
    }

    public function getType(): ActivityType
    {
        return ActivityType::Command;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Installiert eine oder mehrere Sprachen im ILIAS-System.

Die Activity lädt die entsprechenden Sprachdateien, schreibt die
Sprachdaten in die Datenbank und macht die Sprachen anschließend als
installierte Systemsprachen verfügbar.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        global $DIC;

        return $DIC->ui()->factory()->input()->field()->text(
            'Sprachschlüssel',
            'Kommagetrennte Liste von Sprachschlüsseln, z. B. de, fr, it.'
        )->withRequired(true);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Ergebnis der Sprachinstallation.'),
            [
                'installed_language_keys' => $f->list(
                    $this->markdown('Neu installierte Sprachen.'),
                    $f->string($this->markdown('Sprachschlüssel einer neu installierten Sprache.'))
                ),
                'already_installed_language_keys' => $f->list(
                    $this->markdown('Sprachen, die bereits installiert waren.'),
                    $f->string($this->markdown('Sprachschlüssel einer bereits installierten Sprache.'))
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
        global $DIC;

        return $DIC->rbac()->system()->checkAccessOfUser(
            $usr_id,
            'write',
            \ilObjLanguageAccess::_lookupLangFolderRefId()
        );
    }

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            throw new \InvalidArgumentException('Parameters must be an array.');
        }

        $obj_ids = $this->toIntegerList($parameters['language_object_ids'] ?? []);

        $installed_language_keys = [];
        $already_installed_language_keys = [];
        $currently_installed_language_keys = \ilLanguage::_getInstalledLanguages();

        foreach ($obj_ids as $obj_id) {
            $langObj = new \ilObjLanguage($obj_id);
            $language_key = $langObj->getTitle();

            if (in_array($language_key, $currently_installed_language_keys, true)) {
                $already_installed_language_keys[] = $language_key;
                unset($langObj);
                continue;
            }

            $installed_language_key = $langObj->install();

            if ($installed_language_key !== '') {
                $installed_language_keys[] = $installed_language_key;
                $currently_installed_language_keys[] = $installed_language_key;
            }

            unset($langObj);
        }

        return [
            'installed_language_keys' => $installed_language_keys,
            'already_installed_language_keys' => $already_installed_language_keys,
        ];
    }

    public function maybePerformAs(int $usr_id, array $raw_parameters): Result
    {
        try {
            if (!$this->isAllowedToPerform($usr_id, $raw_parameters)) {
                return new Result\Error($this->lng->txt('msg_no_perm_read'));
            }

            return new Result\Ok($this->perform($raw_parameters));
        } catch (\Throwable $e) {
            return new Result\Error($e);
        }
    }

    /**
     * @param mixed $value
     * @return list<int>
     */
    private function toIntegerList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn(mixed $item): int => (int) $item,
                    $value
                ),
                static fn(int $item): bool => $item > 0
            )
        );
    }
}
