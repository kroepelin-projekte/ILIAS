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

namespace ILIAS;

/**
 * Class AlwaysOutputCondition
 *
 * Bei Always Condition beachten, dass es keine Option gibt eine andere OutputCondition zu wählen. ALWAYS!!!
 */
final class AlwaysOutputCondition extends AbstractCondition implements OutputCondition
{
    final protected const NAME = "always";

    /**
     * @inheritDoc
     */
    public function migrate(): array
    {
        // TODO: To implement
        return [];
    }

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        return true;
    }

    /**
     * @inheritDoc
     */
    public function setupSteps(): array
    {
        $icon = $this->ui_factory->symbol()->icon()->standard('', '')->withSize('small')->withAbbreviation('+');
        $url = $this->dic->ctrl()->getLinkTargetByClass(
            parent::class,
            parent::SAVE
        );

        $uri = new \ILIAS\Data\URI(ILIAS_HTTP_PATH . '/' . $url);

        return [
            $this->ui_factory->link()->bulky(
                $icon,
                $this->lang->txt(static::NAME),
                $uri
            )
        ];
    }
}
