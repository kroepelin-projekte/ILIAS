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

abstract class LearningProgressOutputCondition extends AbstractCondition implements OutputCondition
{
    final protected const NAME = "learning_progress";
    private const STATUS_NOT_STARTED = 'not_started';
    private const STATUS_IN_PROGRESS = 'in_progress';
    private const STATUS_COMPLETED = 'completed';
    private const STATUS_FAILED = 'failed';

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public function migrate(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function setupSteps(): array
    {
        $icon = $this->ui_factory->symbol()->icon()->standard('', '')->withSize('small')->withAbbreviation('+');
        $steps = [];

        foreach (
            [
            self::STATUS_NOT_STARTED,
            self::STATUS_IN_PROGRESS,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED
            ] as $status
        ) {
            $this->dic->ctrl()->setParameterByClass(parent::class, 'value', $status);
            $url = $this->dic->ctrl()->getLinkTargetByClass(parent::class, parent::SAVE);
            $uri = new \ILIAS\Data\URI(ILIAS_HTTP_PATH . '/' . $url);

            $steps[] = $this->ui_factory->link()->bulky(
                $icon->withAbbreviation('>'),
                $status,
                $uri
            );
        }

        $this->dic->ctrl()->setParameterByClass(parent::class, 'value', '');

        return [
            $this->ui_factory->menu()->sub($this->lang->txt(static::NAME), $steps)
        ];
    }
}
