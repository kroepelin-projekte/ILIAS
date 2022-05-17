<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion\CloseEnded\MultipleChoice;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\TestQuestion\TestQuestion;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class SingleAnswer extends TestQuestion implements T\CloseEnded\MultipleChoice\SingleAnswer
{
    protected string $questionStem;
    
    protected array $questionCanvas;
    
    protected ?\ILIAS\UI\Component\Button\Standard $actions = null;
    
    public function __construct(string $questionStem, array $questionCanvas){
        $this->questionStem = $questionStem;
        $this->questionCanvas = $questionCanvas;
    }
    
    public function getQuestionStem() : string
    {
        return $this->questionStem;
    }
    
    public function getQuestionCanvas() : array
    {
        return $this->questionCanvas;
    }
    
    public function withActions(\ILIAS\UI\Component\Button\Standard $actions
    ) : TestQuestion {
        $clone = clone $this;
        $clone->actions = $actions;
        return $clone;
    }
    
    public function getActions()
    {
        return $this->actions;
    }
    
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