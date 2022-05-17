<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;
use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\CloseEnded\MultipleChoice\Factory
{
    public function single($questionStem, $questionCanvas) : SingleAnswer
    {
        return new SingleAnswer($questionStem, $questionCanvas);
    }
    
    public function multiple() : MultipleAnswer
    {
        return new MultipleAnswer();
    }
    
    public function kprim() : KprimAnswer
    {
        return new KprimAnswer();
    }
}