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
        $tpl->setVariable("qtitle", $component->getQuestionStem());
        $tpl->setVariable("SAID", $uniqueId);
    
        $feedbackOnCorrectAnswer = $component->getFeedbackOnCorrectAnswer();
        $reachedPoints = $component->getReachedPoints();
        $maxPoints = $component->getMaxPoints();
        
        $rp_json = json_encode($reachedPoints);
        $fboca_json = json_encode($feedbackOnCorrectAnswer);
        $mp_json = json_encode($maxPoints);
        $buttons = $component->getButtons();
    
        //$this->processButton($feedbackOnCorrectAnswer,$reachedPoints, $maxPoints);
        $post = json_decode(file_get_contents('php://input'), true);
        if (isset($post['checked'])) {
            $result = 'Test1';
            if (Test::$feedback) {
                if ($feedbackOnCorrectAnswer[$post['data']]) $result = 'Ihre Antwort ist richtig. ';
                else $result = 'Ihre Antwort ist nicht ganz richtig. ';
            }
            if ($reachedPoints) $result .= "Sie haben " . $reachedPoints[$post['data']] . " von " . $maxPoints . " Punkten.";
        
        
            exit($result);
        }
        
        if (count($buttons) > 0) {
            $f = $this->getUIFactory();
            $url = json_encode($_SERVER['REQUEST_URI']);
            $feedbackButton = $f->button()->standard($buttons[0], "#")->withOnLoadCode(function ($id) use (
                $maxPoints,
                $reachedPoints,
                $feedbackOnCorrectAnswer,
                $url) {
                Test::$feedback += [$id => [$feedbackOnCorrectAnswer, $reachedPoints, $maxPoints]];
                return "$('#$id').click(function() {
                showFeedback($id, $url);
                });";
            });
            
            $tpl->setCurrentBlock("buttons");
            /** @var Button $feedbackButton */
            $tpl->setVariable("BUTTONS", $default_renderer->render($feedbackButton));
            $tpl->parseCurrentBlock();
        }
        
        foreach ($component->getAnswers() as $key => $answer)
        {
            $checkedID = $component->getCheckedId();
            if (isset($checkedID) && $checkedID === $key) {
                $tpl->setCurrentBlock("checked");
                $tpl->setVariable("CHECKED", "checked");
                $tpl->parseCurrentBlock();
            }
            if (!empty($feedbackOnCorrectAnswer)) {
                $tpl->setCurrentBlock("feedback_correct_answer");
                // check to mark the checked (and right) answer as right
                if ($feedbackOnCorrectAnswer[$key] && $key === $checkedID) {
                    $tpl->setVariable("LABEL", "il-correct");
                }
                //check to mark the checked answer and the unchecked (but right) answer as wrong
                if ((!$feedbackOnCorrectAnswer[$key] && $key === $checkedID) || ($feedbackOnCorrectAnswer[$key] && $key !== $checkedID)) {
                    $tpl->setVariable("LABEL", "il-notCorrect");
                }
                $tpl->parseCurrentBlock();
            }
            $tpl->setCurrentBlock("answer_row");
            $tpl->setVariable("uniqid", $uniqueId);
            $tpl->setVariable("ID", $key);
            $tpl->setVariable("ANSWER", htmlspecialchars($answer, 2, 'UTF-8'));
            $tpl->setVariable("FEEDBACK", "Feedback zur Antwort ".($key+1));
            $tpl->parseCurrentBlock();
        }
        return $tpl->get();
    }
    
    public function processButton($feedbackOnCorrectAnswer, $reachedPoints, $maxPoints): void{
        $post = json_decode(file_get_contents('php://input'), true);
        if (isset($post['data'])) {
//            var result = '';
//                if (fboca.length > 0) {
//                    if (fboca[checkedAnswer]) result = 'Ihre Antwort ist richtig. ';
//                    else result = 'Ihre Antwort ist nicht ganz richtig. ';
//                }
//
//                if (rp.length > 0) result += 'Sie haben ' + rp[checkedAnswer] + ' von ' + mp + ' Punkten.';
            
            $result = 'Test2';
            if ($feedbackOnCorrectAnswer) {
                if ($feedbackOnCorrectAnswer[$post['data']]) $result = 'Ihre Antwort ist richtig. ';
                else $result = 'Ihre Antwort ist nicht ganz richtig. ';
            }
            if ($reachedPoints) $result .= "Sie haben " . $reachedPoints[$post['data']] . " von " . $maxPoints . " Punkten.";
            
            
            exit($result);
        }
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
