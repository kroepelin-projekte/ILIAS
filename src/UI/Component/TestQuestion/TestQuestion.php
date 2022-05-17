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
    
    /**
     * Get the Question Canvas
     *
     * @return array
     */
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
    
    /**
     * @param string $reachedPoints
     * @return TestQuestion
     */
    public function withReachedPoints(string $reachedPoints) : TestQuestion;
    
    /**
     * @return string
     */
    public function getReachedPoints() : string;
    
    /**
     * @param array $bestSolutions
     * @return TestQuestion
     */
    public function withBestSolutions(array $bestSolutions) : TestQuestion;
    
    /**
     * @return array
     */
    public function getBestSolutions() : array;
    
    /**
     * @param array $feedbackFullyCorrectAnswer
     * @return TestQuestion
     */
    public function withFeedbackOnFullyCorrectAnswer(array $feedbackFullyCorrectAnswer) : TestQuestion;
    
    /**
     * @return mixed
     */
    public function getFeedbackOnFullyCorrectAnswer();
    
    /**
     * @param array $feedbackForEachAnswer
     * @return TestQuestion
     */
    public function withSpezificFeedbackForEachAnswer(array $feedbackForEachAnswer) : TestQuestion;
    
    /**
     * @return array
     */
    public function getSpezificFeedbackForEachAnswer() : array;
}