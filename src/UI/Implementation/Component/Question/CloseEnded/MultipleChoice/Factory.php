<?php

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded\MultipleChoice;
use ILIAS\UI\Component\Question as T;

class Factory implements T\CloseEnded\MultipleChoice\Factory
{
    public function singleAnswer($questionStem, $questionCanvas) : SingleAnswer
    {
        return new SingleAnswer($questionStem, $questionCanvas);
    }
    
    public function multipleAnswer() : MultipleAnswer
    {
        return new MultipleAnswer();
    }
    
    public function kprimAnswer() : KprimAnswer
    {
        return new KprimAnswer();
    }
}