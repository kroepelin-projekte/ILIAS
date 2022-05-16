<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class SingleAnswer implements T\CloseEnded\MultipleChoice\SingleAnswer
{
    use ComponentHelper;
    
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
    
    public function getAnswers() : array
    {
        // TODO: Implement getAnswers() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}