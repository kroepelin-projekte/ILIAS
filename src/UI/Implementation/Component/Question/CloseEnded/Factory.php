<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Component\Question as I;

class Factory implements I\CloseEnded\Factory
{
    public function singleAnswer($questionStem, $questionCanvas) : SingleAnswer
    {
        return new SingleAnswer($questionStem, $questionCanvas);
    }
    
    public function multipleAnswer() : MultipleAnswer
    {
        return new MultipleAnswer();
    }
    
    public function kprimAnswer() : KprimAnswer
    {
        return new KprimAnswer();
    }
    
    public function clozeQuestionSelectGap() : ClozeQuestionSelectGap
    {
        return new ClozeQuestionSelectGap();
    }
    
    public function errorText() : ErrorText
    {
        return new ErrorText();
    }
    
    public function imageMap() : ImageMap
    {
        return new ImageMap();
    }
    
    public function multipleChoice() : MultipleChoice\Factory
    {
        return new MultipleChoice\Factory();
    }
}
