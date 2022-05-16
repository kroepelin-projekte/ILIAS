<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\Factory
{
    public function closeEnded() : CloseEnded\Factory
    {
        return new CloseEnded\Factory();
    }
    
    public function openEnded() : OpenEnded\Factory
    {
        return new OpenEnded\Factory();
    }
    
    public function sorting() : Sorting\Factory
    {
        return new Sorting\Factory();
    }
}