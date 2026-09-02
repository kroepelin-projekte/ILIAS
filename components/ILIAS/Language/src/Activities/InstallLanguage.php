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

final class InstallLanguage extends ActivityImpl implements InstallLanguageInterface
{
    private Language $lng;
    private readonly \Closure $rbac_system;
    private readonly \Closure $db;
    private readonly \Closure $language_folder_ref_id;

    public function __construct(
        private readonly RefineryFactory $refinery,
        private readonly UIFactory $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        \ilDBInterface|\Closure $db,
        private readonly \ilSetupLanguage $setup_language,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        $this->lng = $language;
        $this->rbac_system = $rbac_system instanceof \Closure
            ? $rbac_system
            : static fn(): \ilRbacSystem => $rbac_system;
        $this->db = $db instanceof \Closure
            ? $db
            : static fn(): \ilDBInterface => $db;
        $this->language_folder_ref_id = $language_folder_ref_id instanceof \Closure
            ? $language_folder_ref_id
            : static fn(): int => $language_folder_ref_id;
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
        $language_keys = $this->ui_factory->input()->field()->text(
            'Sprachschlüssel',
            'Kommagetrennte Liste von Sprachschlüsseln, z. B. de, fr, it.'
        )->withRequired(true)->withDedicatedName('language_keys');

        return $this->ui_factory->input()->field()->group([
            'language_keys' => $language_keys,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Ergebnis der Sprachinstallation.'),
            [
                'installed_language_keys' => $f->list(
                    $this->markdown('Neu installierte Sprachen ohne Custom-Sprachdatei.'),
                    $f->string($this->markdown('Sprachschlüssel einer neu installierten Sprache.'))
                ),
                'installed_with_local_language_keys' => $f->list(
                    $this->markdown(
                        'Sprachen, für die eine Custom-Sprachdatei (neu) installiert wurde - ' .
                        'unabhängig davon, ob die Sprache selbst bereits installiert war.'
                    ),
                    $f->string($this->markdown('Sprachschlüssel einer Sprache mit installierter Custom-Sprachdatei.'))
                ),
                'already_installed_language_keys' => $f->list(
                    $this->markdown(
                        'Sprachen, die bereits installiert waren und für die keine Custom-Sprachdatei ' .
                        '(neu) installiert wurde - hier hat dieser Lauf nichts verändert.'
                    ),
                    $f->string($this->markdown('Sprachschlüssel einer bereits installierten Sprache.'))
                ),
                'invalid_local_language_files' => $f->list(
                    $this->markdown('Lokale Sprachdateien mit einem ungültigen Dateinamen.'),
                    $f->string($this->markdown('Dateiname einer ungültigen lokalen Sprachdatei.'))
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
        $db = ($this->db)();

        $db_languages = $this->setup_language->getAvailableLanguagesForInstallation();
        $error_language_keys = [];

        foreach ($language_keys as $language_key) {
            if (!$this->setup_language->checkLanguageForInstallation($language_key)) {
                $error_language_keys[] = $language_key;
            }
        }

        if ($error_language_keys !== []) {
            throw new \RuntimeException(
                'Invalid language files: ' . implode(', ', $error_language_keys)
            );
        }

        $local_language_keys = $this->setup_language->getLocalLanguages();
        $invalid_local_language_files = $this->setup_language->getInvalidLocalLanguageFiles($language_keys);
        $currently_installed_language_keys = $this->setup_language->getInstalledLanguages();

        foreach ($language_keys as $language_key) {
            $this->setup_language->flushLanguageForInstallation($language_key);
            $this->setup_language->insertLanguageForInstallation($language_key);
            $this->setup_language->registerInstalledLanguage($db, $language_key, $db_languages, $local_language_keys);
        }

        // A language with a customizing/local file has that file
        // (re-)applied on every run, regardless of whether the language
        // object itself was already installed - so "already installed" on
        // its own would be misleading feedback for it: something did change.
        // That case therefore gets its own bucket instead of being folded
        // into either of the other two.
        $installed_language_keys = [];
        $installed_with_local_language_keys = [];
        $already_installed_language_keys = [];

        foreach ($language_keys as $language_key) {
            if (in_array($language_key, $local_language_keys, true)) {
                $installed_with_local_language_keys[] = $language_key;
            } elseif (in_array($language_key, $currently_installed_language_keys, true)) {
                $already_installed_language_keys[] = $language_key;
            } else {
                $installed_language_keys[] = $language_key;
            }
        }

        return [
            'installed_language_keys' => $installed_language_keys,
            'installed_with_local_language_keys' => $installed_with_local_language_keys,
            'already_installed_language_keys' => $already_installed_language_keys,
            'invalid_local_language_files' => $invalid_local_language_files,
        ];
    }

    public function maybePerformAs(int $usr_id, array $raw_parameters): Result
    {
        try {
            $parameters = $this->normalizeParameters($raw_parameters);
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
            throw new \InvalidArgumentException('language_keys must be a string or an array of strings.');
        }

        $values = is_array($value) ? $value : [$value];
        $language_keys = [];

        foreach ($values as $item) {
            if (!is_string($item)) {
                throw new \InvalidArgumentException('language_keys must be a string or an array of strings.');
            }

            foreach (explode(',', (string) $item) as $language_key) {
                $language_key = trim($language_key);
                if ($language_key !== '' && !in_array($language_key, $language_keys, true)) {
                    $language_keys[] = $language_key;
                }
            }
        }

        if ($language_keys === []) {
            throw new \InvalidArgumentException('At least one language key is required.');
        }

        return $language_keys;
    }

    /**
     * @param mixed $raw_parameters
     * @return array{language_keys: list<string>}
     */
    private function normalizeParameters(mixed $raw_parameters): array
    {
        if (!is_array($raw_parameters) || !array_key_exists('language_keys', $raw_parameters)) {
            throw new \InvalidArgumentException('The language_keys parameter is required.');
        }

        return [
            'language_keys' => $this->toLanguageKeyList($raw_parameters['language_keys']),
        ];
    }
}
