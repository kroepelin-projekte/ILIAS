<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\Factory
{
    public function __construct(){
    }
    
    public function closeEnded() : CloseEnded\Factory
    {
        return new CloseEnded\Factory();
    }
    
    public function openEnded() : OpenEnded\Factory
    {
        // TODO: Implement openEnded() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function sorting() : Sorting\Factory
    {
        // TODO: Implement sorting() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}