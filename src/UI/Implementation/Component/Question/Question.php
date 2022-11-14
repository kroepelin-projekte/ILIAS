<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question;

use ILIAS\UI\Component as I;
use ILIAS\UI\Implementation\Component\ComponentHelper;
use ILIAS\UI\NotImplementedException;
use ILIAS\UI\Implementation\Component\Button\Standard;

abstract class Question implements I\Question\Question
{
    use ComponentHelper;
    
    protected string $title;
    protected string $questionStem;
    protected array $answers;
    
    // Rückmeldung: richtige Lösung bzw. Mind. eine Antwort ist noch nicht richtig
    protected ?array $feedback_OnCorrectAnswer = null;
    protected ?int $spezificFeedbackForEachAnswer = null;
    protected ?int $maxPointsPossible = null;
    
    // Textarea und möglicher Punktabzug
    protected array $lösunghinweis = [];
    
    protected bool $feedbackButton = false;
    protected bool $hintButton = false;
    
    public function __construct(string $questionTitle, string $questionStem, array $answers)
    {
        $this->title = $questionTitle;
        $this->questionStem = $questionStem;
        $this->answers = $answers;
    }
    
    public function getQuestionTitle(): string
    {
        return $this->title;
    }
    
    public function getQuestionStem(): string
    {
        return $this->questionStem;
    }
    
    public function getAnswers() : array
    {
        return $this->answers;
    }
    
    
    public function withFeedbackButton(): \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->feedbackButton = true;
        return $clone;
    }
    
    public function getFeedbackButton(): bool {
        return $this->feedbackButton;
    }
    
    public function withHintButton(): \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->hintButton = true;
        return $clone;
    }
    
    public function getHintButton(): bool {
        return $this->hintButton;
    }
    
    public function withReachedPoints(int $maxPointsPossible) : \ILIAS\UI\Component\Question\Question
    {
        $clone = clone $this;
        $clone->maxPointsPossible = $maxPointsPossible;
        return $clone;
    }
    
    public function getMaxPoints() : ?int
    {
        return $this->maxPointsPossible;
    }
    
    
    public function withBestSolutions(array $bestSolutions) : \ILIAS\UI\Component\Question\Question
    {
        throw new NotImplementedException("NYI");
    }
    
    public function getBestSolutions() : array
    {
        throw new NotImplementedException("NYI");
    }
    
    public function withFeedbackOnCorrectAnswer(
        array $feedbackOnCorrectAnswer
    ) : \ILIAS\UI\Component\Question\Question {
        $clone = clone $this;
        $clone->feedback_OnCorrectAnswer = $feedbackOnCorrectAnswer;
        return $clone;
    }
    
    public function getFeedbackOnCorrectAnswer() : ?array
    {
        return $this->feedback_OnCorrectAnswer;
    }
    
    public function withSpezificFeedbackForEachAnswer(int $modus) : \ILIAS\UI\Component\Question\Question {
        $clone = clone $this;
        $clone->spezificFeedbackForEachAnswer = $modus;
        return $clone;
    }
    
    public function getSpezificFeedbackForEachAnswer() : ?int
    {
        return $this->spezificFeedbackForEachAnswer;
    }
    
}
