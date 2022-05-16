<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\Sorting;
use ILIAS\UI\Component\TestQuestion as T;

class Factory implements T\Sorting\Factory
{
    
    public function horizontalOrdering() : HorizontalOrdering
    {
        // TODO: Implement horizontalOrdering() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function matching() : Matching
    {
        // TODO: Implement matching() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function verticalOrdering() : VerticalOrdering
    {
        // TODO: Implement verticalOrdering() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}