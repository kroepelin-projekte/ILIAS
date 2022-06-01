<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Implementation\Render\AbstractComponentRenderer;
use ILIAS\UI\Renderer as RendererInterface;
use ILIAS\UI\Component;

class Renderer extends AbstractComponentRenderer
{
    public function render(Component\Component $component, RendererInterface $default_renderer)
    {
        /**
         * @var \ILIAS\UI\Component\Question\CloseEnded\SingleAnswer $component
         */
        $this->checkComponent($component);
        $tpl = $this->getTemplate("tpl.question.html", true, true);
        
        $tpl->setVariable("qtitle", $component->getQuestionStem());
        
        $buttons = $component->getButtons();
        if (count($buttons) > 0) {
            $tpl->setCurrentBlock("buttons");
            $tpl->setVariable("BUTTONS", $default_renderer->render($buttons));
            $tpl->parseCurrentBlock();
        }
        
        $reachedPoints = $component->getReachedPoints();
        $feedbackOnCorrectAnswer = $component->getFeedbackOnCorrectAnswer();
        if (!empty($reachedPoints) || !empty($feedbackOnCorrectAnswer)) {
            $tpl->setCurrentBlock("reached_points");
            $output = trim($feedbackOnCorrectAnswer[0] . ' ' . $reachedPoints);
            $tpl->setVariable("REACHED_POINTS", $output);
            $tpl->parseCurrentBlock();
        }
    
        foreach ($component->getQuestionCanvas() as $key => $answers)
        {
            if ($answers[1]) {
                $tpl->setCurrentBlock("checked");
                $tpl->setVariable("CHECKED", "checked");
                $tpl->parseCurrentBlock();
            }
            $tpl->setCurrentBlock("answer_row");
            $tpl->setVariable("ID", $key);
            $tpl->setVariable("ANSWER", htmlspecialchars($answers[0], 2, 'UTF-8'));
            $tpl->setVariable("FEEDBACK", "Feedback zur Antwort ".($key+1));
            $tpl->parseCurrentBlock();
        }
        return $tpl->get();
    }
    
    protected function getComponentInterfaceName()
    {
        return [Component\Question\CloseEnded\SingleAnswer::class];
    }
}
