<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\Factory
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