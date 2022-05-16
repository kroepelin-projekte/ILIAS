<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded;

use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\CloseEnded\Factory
{
    public function __construct(){
    }
    
    public function clozeQuestionSelectGap() : ClozeQuestionSelectGap
    {
        // TODO: Implement clozeQuestionSelectGap() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function errorText() : ErrorText
    {
        // TODO: Implement errorText() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function imageMap() : ImageMap
    {
        // TODO: Implement imageMap() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function multipleChoice() : MultipleChoice\Factory
    {
        return new MultipleChoice\Factory();
    }
}