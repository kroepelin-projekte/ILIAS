<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class SingleAnswer extends TestQuestion implements T\CloseEnded\MultipleChoice\SingleAnswer
{
    use ComponentHelper;
}