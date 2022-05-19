<?php

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Component\Question as T;
use ILIAS\UI\Implementation\Component\ComponentHelper;

abstract class Question implements T\Question
{
    protected string $questionStem;
    
    protected array $questionCanvas;
    
    protected ?\ILIAS\UI\Component\Button\Standard $actions = null;
    
    public function __construct(string $questionStem, array $questionCanvas)
    {
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

    public function withReachedPoints(string $reachedPoints) : \ILIAS\UI\Component\Question\Question
    {
        // TODO: Implement withReachedPoints() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getReachedPoints() : string
    {
        // TODO: Implement getReachedPoints() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withBestSolutions(array $bestSolutions) : \ILIAS\UI\Component\Question\Question
    {
        // TODO: Implement withBestSolutions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getBestSolutions() : array
    {
        // TODO: Implement getBestSolutions() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withFeedbackOnFullyCorrectAnswer(
        array $feedbackFullyCorrectAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        // TODO: Implement withFeedbackOnFullyCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getFeedbackOnFullyCorrectAnswer()
    {
        // TODO: Implement getFeedbackOnFullyCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function withSpezificFeedbackForEachAnswer(
        array $feedbackForEachAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        // TODO: Implement withSpezificFeedbackForEachAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getSpezificFeedbackForEachAnswer() : array
    {
        // TODO: Implement getSpezificFeedbackForEachAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
}
