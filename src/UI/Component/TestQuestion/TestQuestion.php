<?php declare(strict_types=1);

namespace ILIAS\UI\Component\TestQuestion;

/**
 * Common interface to all TestQuestions.
 */
interface TestQuestion extends \ILIAS\UI\Component\Component
{
    /**
     * Get the Question Stem
     *
     * @return string
     */
    public function getQuestionStem() : string;
    
    public function getQuestionCanvas() : array;
    
    /**
     *
     * @param \ILIAS\UI\Component\Button\Standard $actions
     * @return TestQuestion
     */
    public function withActions(\ILIAS\UI\Component\Button\Standard $actions) : TestQuestion;
    
    /**
     * Get the actions of the TestQuestion.
     *
     * @return \ILIAS\UI\Component\Button\Standard
     */
    public function getActions();
}