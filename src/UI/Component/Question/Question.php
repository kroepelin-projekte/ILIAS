<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question;

/**
 * Common interface to all Questions.
 */
interface Question extends \ILIAS\UI\Component\Component
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
     * @param array $buttons
     * @return Question
     */
    public function withButtons(array $buttons) : Question;
    
    /**
     * @return array
     */
    public function getButtons() : array;
    
    /**
     * @param string $reachedPoints
     * @return Question
     */
    public function withReachedPoints(string $reachedPoints) : Question;
    
    /**
     * @return string
     */
    public function getReachedPoints() : string;
    
    /**
     * @param array $bestSolutions
     * @return Question
     */
    public function withBestSolutions(array $bestSolutions) : Question;
    
    /**
     * @return array
     */
    public function getBestSolutions() : array;
    
    /**
     * @param array $feedbackOnCorrectAnswer
     * @return Question
     */
    public function withFeedbackOnCorrectAnswer(array $feedbackOnCorrectAnswer) : Question;
    
    /**
     * @return array
     */
    public function getFeedbackOnCorrectAnswer() : array;
    
    /**
     * @param array $feedbackForEachAnswer
     * @return Question
     */
    public function withSpezificFeedbackForEachAnswer(array $feedbackForEachAnswer) : Question;
    
    /**
     * @return array
     */
    public function getSpezificFeedbackForEachAnswer() : array;
}
