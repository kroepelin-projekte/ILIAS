<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\Question as I;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class MultipleAnswer extends Question implements I\CloseEnded\MultipleChoice\MultipleAnswer
{
    use ComponentHelper;
}
