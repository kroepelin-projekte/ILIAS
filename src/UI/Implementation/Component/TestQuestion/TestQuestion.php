<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component as C;

class TestQuestion implements C\TestQuestion\TestQuestion
{
    
    
    public function getQuestionStem() : string
    {
        // TODO: Implement getQuestionStem() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getAnswers() : array
    {
        // TODO: Implement getAnswers() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withReachedPoints(string $reachedPoints) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withReachedPoints() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withBestSolutions(array $bestSolutions) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withBestSolutions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withFeedbackOnFullyCorrectAnswer(array $feedback) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withFeedbackOnFullyCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withSpezificFeedbackForEachAnswer(array $feedback) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withSpezificFeedbackForEachAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getCanonicalName()
    {
        // TODO: Implement getCanonicalName() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}