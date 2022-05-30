<?php declare(strict_types=1);

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
    
        $tpl->setCurrentBlock("answer_row");
        foreach ($component->getQuestionCanvas() as $key => $answer)
        {
            $tpl->setVariable("ID", $key);
            $tpl->setVariable("ANSWER", htmlspecialchars($answer));
            $tpl->setVariable("FEEDBACK", "Feedback zur Antwort ".($key+1));
            $tpl->parseCurrentBlock();
        }
        return $tpl->get();
    }
    
    protected function getComponentInterfaceName()
    {
        return [Component\Question\CloseEnded\MultipleChoice\SingleAnswer::class];
    }
}
