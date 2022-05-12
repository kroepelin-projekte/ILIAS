<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\NotImplementedException;
use ILIAS\UI\Component\TestQuestion\CloseEnded;
use ILIAS\UI\Component\TestQuestion\OpenEnded;
use ILIAS\UI\Component\TestQuestion\Sorting;

class Factory implements T\Factory
{
    
    public function closeEndedQuestions() : CloseEnded\Factory
    {
        // TODO: Implement closeEndedQuestions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function closeEnded() : CloseEnded\Factory
    {
        // TODO: Implement closeEnded() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
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