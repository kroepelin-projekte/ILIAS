<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;
use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\CloseEnded\MultipleChoice\Factory
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
}