<?php declare(strict_types=1);

namespace ILIAS\UI\Component\Question;

use ILIAS\UI\Component\Input\Field\Textarea;

/**
 * Common interface to all Questions.
 */
interface Question extends \ILIAS\UI\Component\Component
{
    /**
     * Get the Question Title
     *
     * @return string
     */
    public function getQuestionTitle() : string;
    
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
     * @return Question
     */
    public function withFeedbackButton() : Question;
    
    /**
     * @return Question
     */
    public function withHintButton() : Question;
    
    
    
    /**
     * @param int $maxPointsPossible
     * @return Question
     */
    public function withReachedPoints(int $maxPointsPossible) : Question;
    
    /**
     * @return int
     */
    public function getMaxPoints() : ?int;
    
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
    public function getFeedbackOnCorrectAnswer() : ?array;
    
    /**
     * @param array $feedbackForEachAnswer
     * @return Question
     */
    public function withSpezificFeedbackForEachAnswer(int $modus) : Question;
    
    /**
     * @return array
     */
    public function getSpezificFeedbackForEachAnswer() : ?int;
    
    
}
