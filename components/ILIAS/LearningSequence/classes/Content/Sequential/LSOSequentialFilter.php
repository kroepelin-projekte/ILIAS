<?php

declare(strict_types=1);

namespace ILIAS\LearningSequence\Content\Sequential;

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

use ilObjLearningSequenceContentGUI;

class LSOSequentialFilter
{
    private \ILIAS\UI\Factory $ui_factory;
    private \ilLanguage $lng;
    private \ilCtrl $ctrl;
    private ilObjLearningSequenceContentGUI $parent_gui;

    public function __construct(
        \ILIAS\UI\Factory $ui_factory,
        \ilLanguage $lng,
        \ilCtrl $ctrl,
        ilObjLearningSequenceContentGUI $parent_gui
    ) {
        $this->ui_factory = $ui_factory;
        $this->lng = $lng;
        $this->ctrl = $ctrl;
        $this->parent_gui = $parent_gui;
    }

    public function getFilter(string $action): \ILIAS\UI\Component\Input\Container\Filter\Standard
    {
        // The filter needs fully qualified URLs (toggle_on/off, expand,
        // collapse, apply and the form action). Passing the bare command name
        // would make the browser submit to a non-existing relative URL, so
        // build a proper link target.
        $action_url = $this->ctrl->getLinkTarget($this->parent_gui, $action);

        $fields = [
            'name' => $this->ui_factory->input()->field()->text($this->lng->txt('name')),
            'condition' => $this->ui_factory->input()->field()->select(
                $this->lng->txt('table_may_proceed'),
                [
                    'always' => $this->lng->txt('condition_always'),
                    'lp' => 'Gemäß Lernfortschritt', // #ToDo Sprachvariable
                ]
            ),
            'online_status' => $this->ui_factory->input()->field()->select(
                $this->lng->txt('status'),
                [
                    'online' => $this->lng->txt('online'),
                    'offline' => $this->lng->txt('offline'),
                ]
            ),
        ];

        return $this->ui_factory->input()->container()->filter()->standard(
            $action_url, // toggle_on
            $action_url, // toggle_off
            $action_url, // expand
            $action_url, // collapse
            $action_url, // apply
            $action_url, // form action
            $fields,
            [true, true, true],
            true,
            true
        );
    }
}
