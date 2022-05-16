<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded;

use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\CloseEnded\Factory
{
    public function clozeQuestionSelectGap() : ClozeQuestionSelectGap
    {
        return new ClozeQuestionSelectGap();
    }
    
    public function errorText() : ErrorText
    {
        return new ErrorText();
    }
    
    public function imageMap() : ImageMap
    {
        return new ImageMap();
    }
    
    public function multipleChoice() : MultipleChoice\Factory
    {
        return new MultipleChoice\Factory();
    }
}