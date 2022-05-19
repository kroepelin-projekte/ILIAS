<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\Sorting;

use ILIAS\UI\Component\Question as I;

class Factory implements I\Sorting\Factory
{
    public function horizontalOrdering() : HorizontalOrdering
    {
        return new HorizontalOrdering();
    }
    
    public function matching() : Matching
    {
        return new Matching();
    }
    
    public function verticalOrdering() : VerticalOrdering
    {
        return new VerticalOrdering();
    }
}
