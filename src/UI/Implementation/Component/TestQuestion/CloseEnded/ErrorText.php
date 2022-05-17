<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded;
use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class ErrorText extends TestQuestion implements T\CloseEnded\ErrorText
{
    use ComponentHelper;
}