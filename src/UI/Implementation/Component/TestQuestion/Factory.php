<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\NotImplementedException;
use ILIAS\UI\Component\TestQuestion\CloseEndedQuestions;

class Factory implements T\Factory
{
    
    public function closeEndedQuestions() : CloseEndedQuestions\Factory
    {
        // TODO: Implement closeEndedQuestions() method.
    }
}