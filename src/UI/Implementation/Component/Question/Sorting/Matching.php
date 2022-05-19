<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\Sorting;

use ILIAS\UI\Component\Question as I;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class Matching extends Question implements I\Sorting\Matching
{
    use ComponentHelper;
}
