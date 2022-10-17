<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Component as I;
use ILIAS\UI\Implementation\Component\ComponentHelper;

abstract class Question implements I\Question\Question
{
    use ComponentHelper;
    
    protected string $questionStem;
    
    protected array $answers;
    
    protected array $buttons = [];
    
    protected array $reachedPoints = [];
    
    protected int $maxPoints = -1;

    protected array $feedbackOnCorrectAnswer = [];
    
    protected array $feedbackForEachAnswer = [];
    
    protected array $bestSolutions = [];
    
    public function __construct(string $questionStem, array $answers)
    {
        $this->questionStem = $questionStem;
        $this->answers = $answers;
    }
    
    public function getQuestionStem() : string
    {
        return $this->questionStem;
    }
    
    public function getAnswers() : array
    {
        return $this->answers;
    }
    
    public function getButtons() : array
    {
        return $this->buttons;
    }
    
    public function withButtons(array $buttons) : \ILIAS\UI\Component\Question\Question
    {
//        $types = array(I\Component::class);
//        $this->checkArgListElements("buttons", $buttons, $types);
        
        $clone = clone $this;
        $clone->buttons = $buttons;
        return $clone;
    }
    
    public function withReachedPoints(array $reachedPoints) : \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->reachedPoints = $reachedPoints;
        $clone->maxPoints = max($reachedPoints);
        return $clone;
    }
    
    public function getReachedPoints() : array
    {
        return $this->reachedPoints;
    }
    
    public function getMaxPoints() : int
    {
        return $this->maxPoints;
    }
    
    public function withBestSolutions(array $bestSolutions) : \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->bestSolutions = $bestSolutions;
        return $clone;
    }
    
    public function getBestSolutions() : array
    {
        return $this->bestSolutions;
    }
    
    public function withFeedbackOnCorrectAnswer(
        array $feedbackOnCorrectAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        $clone = clone $this;
        $clone->feedbackOnCorrectAnswer = $feedbackOnCorrectAnswer;
        return $clone;
    }
    
    public function getFeedbackOnCorrectAnswer() : array
    {
        return $this->feedbackOnCorrectAnswer;
    }
    
    public function withSpezificFeedbackForEachAnswer(
        array $feedbackForEachAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        $clone = clone $this;
        $clone->feedbackForEachAnswer = $feedbackForEachAnswer;
        return $clone;
    }
    
    public function getSpezificFeedbackForEachAnswer() : array
    {
        return $this->feedbackForEachAnswer;
    }
    
    /**
     * @inheritDoc
     */
    public function getUpdateOnLoadCode() : \Closure
    {
        return function ($id) {
            $code = "$('#$id').on('input', function(event) {
				il.UI.input.onFieldUpdate(event, '$id', $('#$id input:checked').val());
				console.log($this->reachedPoints);
			});
			il.UI.input.onFieldUpdate(event, '$id', $('#$id input:checked').val());
			console.log($this->reachedPoints);";
            return $code;
        };
    }
}
