<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Component\Question as I;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\ComponentHelper;
use ILIAS\UI\Implementation\Component\JavaScriptBindable;

class SingleAnswer extends Question implements I\CloseEnded\SingleAnswer
{
    use ComponentHelper;
    use JavaScriptBindable;
    

    public function __construct(string $questionTitle, string $questionStem, array $answers)
    {
        parent::__construct($questionTitle, $questionStem, $answers);
    }
}
