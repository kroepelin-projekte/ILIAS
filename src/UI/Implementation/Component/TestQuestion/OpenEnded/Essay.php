<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\OpenEnded;
use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class Essay extends TestQuestion implements T\OpenEnded\Essay
{
    use ComponentHelper;
}