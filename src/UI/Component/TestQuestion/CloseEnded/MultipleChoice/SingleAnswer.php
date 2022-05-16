<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Component\TestQuestion\Feedback;

interface SingleAnswer extends Feedback, \ILIAS\UI\Component\Component
{
    /**
     * Get the Answers
     *
     * @return array
     */
    public function getAnswers() : array;
}