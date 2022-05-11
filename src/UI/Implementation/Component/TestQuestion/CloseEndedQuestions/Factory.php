<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEndedQuestions;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\NotImplementedException;
use ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceMultipleAnswer;
use ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceKprimAnswer;
use ILIAS\UI\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceSingleAnswer;

class Factory implements T\CloseEndedQuestions\Factory
{
    
    public function single() : MultipleChoiceSingleAnswer
    {
        // TODO: Implement single() method.
        return new \ILIAS\UI\Implementation\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceSingleAnswer();
    }
    
    public function multiple() : MultipleChoiceMultipleAnswer
    {
        // TODO: Implement multiple() method.
        return new \ILIAS\UI\Implementation\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceMultipleAnswer();
    }
    
    public function kprim() : MultipleChoiceKprimAnswer
    {
        // TODO: Implement kprim() method.
        return new \ILIAS\UI\Implementation\Component\TestQuestion\CloseEndedQuestions\MultipleChoiceKprimAnswer();
    }
}