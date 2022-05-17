<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\ComponentHelper;

abstract class TestQuestion implements T\TestQuestion
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
    
    public function withReachedPoints(string $reachedPoints) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withReachedPoints() method.
    }
    
    public function getReachedPoints() : string
    {
        // TODO: Implement getReachedPoints() method.
    }
    
    public function withBestSolutions(array $bestSolutions) : \ILIAS\UI\Component\TestQuestion\TestQuestion
    {
        // TODO: Implement withBestSolutions() method.
    }
    
    public function getBestSolutions() : array
    {
        // TODO: Implement getBestSolutions() method.
    }
    
    public function withFeedbackOnFullyCorrectAnswer(array $feedbackFullyCorrectAnswer
    ) : \ILIAS\UI\Component\TestQuestion\TestQuestion {
        // TODO: Implement withFeedbackOnFullyCorrectAnswer() method.
    }
    
    public function getFeedbackOnFullyCorrectAnswer()
    {
        // TODO: Implement getFeedbackOnFullyCorrectAnswer() method.
    }
    
    public function withSpezificFeedbackForEachAnswer(array $feedbackForEachAnswer
    ) : \ILIAS\UI\Component\TestQuestion\TestQuestion {
        // TODO: Implement withSpezificFeedbackForEachAnswer() method.
    }
    
    public function getSpezificFeedbackForEachAnswer() : array
    {
        // TODO: Implement getSpezificFeedbackForEachAnswer() method.
    }
}