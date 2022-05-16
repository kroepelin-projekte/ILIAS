<?php

namespace ILIAS\UI\Component\TestQuestion;

interface Feedback
{
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