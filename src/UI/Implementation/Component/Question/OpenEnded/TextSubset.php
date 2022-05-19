<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\OpenEnded;

use ILIAS\UI\Component\Question as I;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class TextSubset extends Question implements I\OpenEnded\TextSubset
{
    use ComponentHelper;
}
