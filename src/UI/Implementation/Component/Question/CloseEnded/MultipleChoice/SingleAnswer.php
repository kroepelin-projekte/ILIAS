<?php

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\Question as T;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class SingleAnswer extends Question implements T\CloseEnded\MultipleChoice\SingleAnswer
{
    use ComponentHelper;
}