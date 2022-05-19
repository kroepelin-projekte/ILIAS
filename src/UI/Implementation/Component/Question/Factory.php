<?php

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
    
    /**
     * @inheritdoc
     */
    public function openEnded() : OpenEnded\Factory
    {
        return new OpenEnded\Factory();
    }
    
    /**
     * @inheritdoc
     */
    public function sorting() : Sorting\Factory
    {
        return new Sorting\Factory();
    }
}
