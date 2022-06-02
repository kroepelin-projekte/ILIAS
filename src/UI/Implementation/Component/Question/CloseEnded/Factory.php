<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Component\Question as I;

class Factory implements I\CloseEnded\Factory
{
    public function singleAnswer(string $questionStem, array $answers, int $checkedId = null) : SingleAnswer
    {
        return new SingleAnswer($questionStem, $answers, $checkedId);
    }
}
