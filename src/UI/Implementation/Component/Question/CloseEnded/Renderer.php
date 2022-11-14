<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Implementation\Render\AbstractComponentRenderer;
use ILIAS\UI\Renderer as RendererInterface;
use ILIAS\UI\Component;
use ILIAS\UI\Implementation\Component\Button\Button;
use ILIAS\UI\Implementation\Render\ResourceRegistry;
use ILIAS\UI\Implementation\Component\Question\Question;
use ILIAS\UI\Implementation\Component\Question\CloseEnded as Q;

class Renderer extends AbstractComponentRenderer
{
    public function render(Component\Component $component, RendererInterface $default_renderer)
    {
        /**
         * @var $component Question
         */
        $this->checkComponent($component);
    
        switch (true) {
            case ($component instanceof Q\SingleAnswer):
                return $this->renderSingleAnswer($component, $default_renderer);
                
            default:
                throw new \LogicException("Cannot render '" . get_class($component) . "'");
        }
    }
    
    public function renderSingleAnswer(Q\SingleAnswer $component, RendererInterface $default_renderer)
    {
        $tpl = $this->getTemplate("tpl.question.html", true, true);

        $uniqueId = $this->createId();
        $tpl->setVariable("STEM", $component->getQuestionStem());
        $tpl->setVariable("SAID", $uniqueId);
    
        $feedbackOnCorrectAnswer = $component->getFeedbackOnCorrectAnswer();
        $maxPoints = $component->getMaxPoints();
        
        $mp_json = json_encode($maxPoints);
        $fboca_json = json_encode($feedbackOnCorrectAnswer);
        $modus = json_encode($component->getSpezificFeedbackForEachAnswer());
        $answers_json = $this->getJSON($component->getAnswers());
        
        if ($component->getFeedbackButton()) {
            $f = $this->getUIFactory();
            $feedbackButton = $f->button()->standard("Rückmeldung anfordern", "#")->withOnLoadCode(function ($id) use (
                $answers_json,
                $fboca_json,
                $mp_json,
                $modus
                ) {
                return "$('#$id').click(function() {
                showFeedback($id, $answers_json, $fboca_json, $mp_json, $modus);
                });";
            });
            
            $tpl->setCurrentBlock("buttons");
            /** @var Button $feedbackButton */
            $tpl->setVariable("BUTTONS", $default_renderer->render($feedbackButton));
            $tpl->parseCurrentBlock();
        }
        
        foreach ($component->getAnswers() as $key => $answer)
        {
            $tpl->setCurrentBlock("answer_row");
            $tpl->setVariable("uniqid", $uniqueId);
            $tpl->setVariable("ID", $key);
            $tpl->setVariable("ANSWER", htmlspecialchars($answer->getAnswerText(), 2, 'UTF-8'));
            $tpl->parseCurrentBlock();
        }
        return $tpl->get();
    }
    
    private function getJSON(array $answers) : string
    {
        $answers_json = [];
        foreach ($answers as $answer) {
            $answers_json[] = json_decode($answer->getJSONEncode());
        }
        return json_encode($answers_json);
    }
    
    /**
     * @inheritdoc
     */
    public function registerResources(ResourceRegistry $registry)
    {
        parent::registerResources($registry);
        $registry->register('./src/UI/templates/js/Question/CloseEnded/singleAnswer.js');
    }
    
    protected function getComponentInterfaceName()
    {
        return [Component\Question\CloseEnded\SingleAnswer::class];
    }
}
