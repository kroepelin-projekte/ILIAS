<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;
use ILIAS\UI\Component\TestQuestion as T;

class Feedback implements T\Feedback
{
    protected string $reachedPoints;
    
    protected array $bestSolutions;
    
    protected array $feedbackFullyCorrectAnswer;
    
    protected array $feedbackForEachAnswer;
    
    public function withReachedPoints(string $reachedPoints) : TestQuestion
    {
        // TODO: Implement withReachedPoints() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getReachedPoints() : string
    {
        // TODO: Implement getReachedPoints() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withBestSolutions(array $bestSolutions) : TestQuestion
    {
        // TODO: Implement withBestSolutions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getBestSolutions() : array
    {
        // TODO: Implement getBestSolutions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withFeedbackOnFullyCorrectAnswer(array $feedbackFullyCorrectAnswer) : TestQuestion
    {
        // TODO: Implement withFeedbackOnFullyCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getFeedbackOnFullyCorrectAnswer()
    {
        // TODO: Implement getFeedbackOnFullyCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withSpezificFeedbackForEachAnswer(array $feedbackForEachAnswer) : TestQuestion
    {
        // TODO: Implement withSpezificFeedbackForEachAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getSpezificFeedbackForEachAnswer() : array
    {
        // TODO: Implement getSpezificFeedbackForEachAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}