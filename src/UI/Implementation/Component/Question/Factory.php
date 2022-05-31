<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Component\Question as I;

class Factory implements I\Factory
{
    /**
     * @inheritdoc
     */
    public function closeEnded() : CloseEnded\Factory
    {
        return new CloseEnded\Factory();
    }
}
