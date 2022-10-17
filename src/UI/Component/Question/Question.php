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
    public function getAnswers() : array;
    
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
    public function withReachedPoints(array $reachedPoints) : Question;
    
    /**
     * @return string
     */
    public function getReachedPoints() : array;
    
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
    
    /**
     * Get update code
     *
     * This method has to return JS code that calls
     * il.UI.filter.onFieldUpdate(event, '$id', string_value);
     * - initially "onload" and
     * - on every input change.
     * It must pass a readable string representation of its value in parameter 'string_value'.
     *
     * @param \Closure $binder
     * @return string
     */
    public function getUpdateOnLoadCode() : \Closure;
}
