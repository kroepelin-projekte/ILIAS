<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\Sorting;
use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class VerticalOrdering extends TestQuestion implements T\Sorting\VerticalOrdering
{
    use ComponentHelper;
}