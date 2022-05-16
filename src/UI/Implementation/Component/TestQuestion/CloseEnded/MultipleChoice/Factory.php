<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;
use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\CloseEnded\MultipleChoice\Factory
{
    public function single() : SingleAnswer
    {
        return new SingleAnswer();
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