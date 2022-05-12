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
     * Get the Answers
     *
     * @return array
     */
    public function getAnswers() : array;
    
    /**
     * @param string $reachedPoints
     * @return TestQuestion
     */
    public function withReachedPoints(string $reachedPoints) : TestQuestion;
    
    /**
     * @param array $bestSolutions
     * @return TestQuestion
     */
    public function withBestSolutions(array $bestSolutions) : TestQuestion;
    
    /**
     * @param array $feedback
     * @return TestQuestion
     */
    public function withFeedbackOnFullyCorrectAnswer(array $feedback) : TestQuestion;
    
    /**
     * @param array $feedback
     * @return TestQuestion
     */
    public function withSpezificFeedbackForEachAnswer(array $feedback) : TestQuestion;
}