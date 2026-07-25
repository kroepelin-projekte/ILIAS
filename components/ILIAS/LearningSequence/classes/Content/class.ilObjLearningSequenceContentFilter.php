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

/**
 * Filter für die Lernsequenz-Inhaltstabelle.
 */
class ilObjLearningSequenceContentFilter
{
    private \ILIAS\UI\Factory $ui_factory;
    private \ilLanguage $lng;
    private \ilCtrl $ctrl;
    private \ilObjectGUI $parent_gui;

    public function __construct(
        \ILIAS\UI\Factory $ui_factory,
        \ilLanguage $lng,
        \ilCtrl $ctrl,
        \ilObjectGUI $parent_gui
    ) {
        $this->ui_factory = $ui_factory;
        $this->lng = $lng;
        $this->ctrl = $ctrl;
        $this->parent_gui = $parent_gui;
    }

    /**
     * Erstellt die Filter-Komponente für das Kitchen Sink.
     * @param string $action Link für das Absenden des Filters
     * @param array $input_options Optionen für den Input-Filter
     * @param array $output_options Optionen für den Output-Filter
     */
    public function getFilter(string $action, array $input_options, array $output_options): \ILIAS\UI\Component\Input\Container\Filter\Standard
    {
        $fields = [
            'name' => $this->ui_factory->input()->field()->text($this->lng->txt('name'))
                ->withDedicatedName('name'),
            'input_conditions' => $this->ui_factory->input()->field()->multiselect(
                'Input Conditions',
                $input_options
            )->withDedicatedName('input_conditions'),
            'output_conditions' => $this->ui_factory->input()->field()->multiselect(
                'Output Conditions',
                $output_options
            )->withDedicatedName('output_conditions'),
        ];

        return $this->ui_factory->input()->container()->filter()->standard(
            $action, // toggle_on
            $action, // toggle_off
            $action, // expand
            $action, // collapse
            $action, // apply
            $this->ctrl->getLinkTarget($this->parent_gui, 'manageContent'), // reset
            $fields,
            [true, true, true], // Alle Filter standardmäßig aktiviert/sichtbar
            true, // Filter ist aktiviert
            true // Filter ist ausklappbar
        );
    }
}
