<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEndedQuestions;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice\MultipleAnswer;
use ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice\KprimAnswer;
use ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice\SingleAnswer;
use ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice;
use ILIAS\UI\Component\TestQuestion\CloseEnded\ErrorText;
use ILIAS\UI\Component\TestQuestion\CloseEnded\ImageMap;
use ILIAS\UI\Component\TestQuestion\CloseEnded\ClozeQuestionSelectGap;

class Factory implements T\CloseEnded\Factory
{
    
    public function single() : SingleAnswer
    {
        // TODO: Implement single() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function multiple() : MultipleAnswer
    {
        // TODO: Implement multiple() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function kprim() : KprimAnswer
    {
        // TODO: Implement kprim() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
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
        // TODO: Implement multipleChoice() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}