<?php

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Component\Question as T;

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
