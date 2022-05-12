<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\Sorting;

interface Factory
{
    public function horizontalOrdering() : HorizontalOrdering;
    
    public function matching() : Matching;
    
    public function verticalOrdering() : VerticalOrdering;
}