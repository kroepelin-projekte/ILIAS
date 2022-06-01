<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Component as I;
use ILIAS\UI\Implementation\Component\ComponentHelper;

abstract class Question implements I\Question\Question
{
    use ComponentHelper;
    
    protected string $questionStem;
    
    protected array $questionCanvas;
    
    protected array $buttons = [];
    
    protected string $reachedPoints = '';
    
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
    
    public function getButtons() : array
    {
        return $this->buttons;
    }
    
    public function withButtons(array $buttons)
    {
        $types = array(I\Component::class);
        $this->checkArgListElements("buttons", $buttons, $types);
        
        $clone = clone $this;
        $clone->buttons = $buttons;
        return $clone;
    }
    
    public function withReachedPoints(string $reachedPoints) : \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->reachedPoints = $reachedPoints;
        return $clone;
    }
    
    public function getReachedPoints() : string
    {
        return $this->reachedPoints;
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
    
    public function withFeedbackOnCorrectAnswer(
        array $feedbackOnCorrectAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        // TODO: Implement withFeedbackOnCorrectAnswer() method.
        throw new \ILIAS\UI\NotImplementedException('NYI');
    }
    
    public function getFeedbackOnCorrectAnswer()
    {
        // TODO: Implement getFeedbackOnCorrectAnswer() method.
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
