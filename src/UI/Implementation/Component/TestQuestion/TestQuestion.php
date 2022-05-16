<?php

namespace ILIAS\UI\Implementation\Component\TestQuestion;

use ILIAS\UI\Component\TestQuestion as T;
use ILIAS\UI\Implementation\Component\ComponentHelper;

class TestQuestion implements T\TestQuestion
{
    use ComponentHelper;
    
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
}