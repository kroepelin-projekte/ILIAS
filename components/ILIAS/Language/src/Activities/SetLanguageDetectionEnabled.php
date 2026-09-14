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

use ILIAS\Administration\Setting;
use ILIAS\Data\Description;
use ILIAS\Data\Text;
use ILIAS\Language\Language;
use ILIAS\Refinery\Factory as RefineryFactory;
use ILIAS\UI\Component\Input\Container\Form\FormInput;
use ILIAS\UI\Factory as UIFactory;

class SetLanguageDetectionEnabled extends LanguageActivity
{
    private readonly \Closure $settings;

    public function __construct(
        RefineryFactory $refinery,
        UIFactory|\Closure $ui_factory,
        Language $language,
        \ilRbacSystem|\Closure $rbac_system,
        Setting|\Closure $settings,
        int|\Closure $language_folder_ref_id = 0,
    ) {
        parent::__construct($refinery, $ui_factory, $language, $rbac_system, $language_folder_ref_id);
        $this->settings = $settings instanceof \Closure
            ? $settings
            : static fn(): Setting => $settings;
    }

    public function getDescription(): Text\SimpleDocumentMarkdown
    {
        return $this->markdown(
            <<<'MARKDOWN'
Enables or disables the system-wide automatic language detection from the
browser's Accept-Language header. This is a single on/off flag for the
whole installation, not a per-language or per-user setting.
MARKDOWN
        );
    }

    public function getInputDescription(): FormInput
    {
        $ui_factory = ($this->ui_factory)();

        $enabled = $ui_factory->input()->field()->checkbox(
            'Enabled',
            'Whether automatic language detection from the browser should be enabled.'
        )->withDedicatedName('enabled');

        return $ui_factory->input()->field()->group([
            'enabled' => $enabled,
        ]);
    }

    public function getOutputDescription(Description\Factory $f): Description\Description
    {
        return $f->object(
            $this->markdown('Result of changing the language detection setting.'),
            [
                'enabled' => $f->bool(
                    $this->markdown('The language detection setting after this change.')
                ),
            ]
        );
    }

    public function perform(mixed $parameters): array
    {
        if (!is_array($parameters) || !array_key_exists('enabled', $parameters) || !is_bool($parameters['enabled'])) {
            throw new InvalidInputException('The enabled parameter (bool) is required.');
        }

        $enabled = $parameters['enabled'];

        ($this->settings)()->set('lang_detection', $enabled ? '1' : '0');

        return ['enabled' => $enabled];
    }

    /**
     * @param array{enabled: bool} $grind_result
     * @return array{enabled: bool}
     */
    protected function normalizeParameters(array $grind_result): array
    {
        return ['enabled' => $grind_result['enabled']];
    }
}
