<?php

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded\MultipleChoice;

use ILIAS\UI\Implementation\Render\AbstractComponentRenderer;
use ILIAS\UI\Renderer as RendererInterface;
use ILIAS\UI\Component;

class Renderer extends AbstractComponentRenderer
{
    public function render(Component\Component $component, RendererInterface $default_renderer)
    {
        /**
         * @var Component\Question\CloseEnded\MultipleChoice\SingleAnswer $component
         */
        $this->checkComponent($component);
        $tpl = $this->getTemplate("tpl.question.html", true, true);
        $tpl->setVariable("TITLE", $component->getQuestionStem());
        $tpl->setVariable("Antwort1", $component->getQuestionCanvas()[0]);
        $tpl->setVariable("Antwort2", $component->getQuestionCanvas()[1]);
        $tpl->setVariable("Antwort3", $component->getQuestionCanvas()[2]);
        $tpl->setVariable("Antwort4", $component->getQuestionCanvas()[3]);
        return $tpl->get();
    }
    
    protected function getComponentInterfaceName()
    {
        return [Component\Question\CloseEnded\MultipleChoice\SingleAnswer::class];
    }
}
