<?php declare(strict_types=1);

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

use ILIAS\UI\Implementation\Render\AbstractComponentRenderer;
use ILIAS\UI\Renderer as RendererInterface;
use ILIAS\UI\Component;
use ILIAS\UI\Implementation\Component\Button\Button;

class Renderer extends AbstractComponentRenderer
{
    public function render(Component\Component $component, RendererInterface $default_renderer)
    {
        /**
         * @var \ILIAS\UI\Component\Question\CloseEnded\SingleAnswer $component
         */
        $this->checkComponent($component);
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
    
        
        // AJAX TEST
        // Beispiel 1
        if (isset($_POST['ajax'])) {
            exit("ajax funktioniert");
        }
    
        $f = $this->getUIFactory();
        $url = $_SERVER['REQUEST_URI'];
        $button = $f->button()->primary("Click Ajax Button", '')->withAdditionalOnLoadCode(function($id) use ($url) {
            return "
                document.getElementById('$id').addEventListener('click', el => {
                   fetch('$url', {
                        method: 'post',
                        body: $id
                   })
                   .then(res => res.text())
                   .then(res => alert(res))
                });
            ";
        });
    
        // Beispiel 2
        $post = json_decode(file_get_contents('php://input'), true);
        if (isset($post['data'])) {
            exit("ajax funktioniert " . $post['data']);
        }
    
        $f = $this->getUIFactory();
        $url = $_SERVER['REQUEST_URI'];
        $button = $f->button()->primary("Click Ajax Button", '')->withAdditionalOnLoadCode(function($id) use ($url) {
            return "
                document.getElementById('$id').addEventListener('click', button => {
                
                    data = {
                        data: 'hallo'
                    };
                    
                    fetch('$url', {
                        method: 'post',
                        body: JSON.stringify(data),
                        header: {
                            'ContentType': 'application/json'
                        }
                    })
                   .then(res => res.text())
                   .then(res => alert(res))
                });
            ";
        });
    
    
        // END AJAX TEST
    
    
        if (count($buttons) > 0) {
            $f = $this->getUIFactory();
            $feedbackButton = $f->button()->standard($buttons[0], "#")->withOnLoadCode(function ($id) use (
                $fboca_json,
                $rp_json,
                $mp_json
            ) {
                return "$('#$id').click(function() {
                console.log('----------------');
                var divid = $('#$id').parent().parent().attr('id');
                console.log(divid);
                var checkedAnswer = $('#' + divid + ' input:checked').val();
                console.log('checkedAnswer: ' + checkedAnswer);
                var fboca = $fboca_json;
                console.log('FeedbackOnCorrectAnswer: ' + fboca + ' - Länge: ' + fboca.length);
                var rp = $rp_json;
                console.log('Reached Points: ' + rp);
                var mp = $mp_json;
                console.log('MaxPoints: ' + mp);
                
                var result = '';
                if (fboca.length > 0) {
                    if (fboca[checkedAnswer]) result = 'Ihre Antwort ist richtig. ';
                    else result = 'Ihre Antwort ist nicht ganz richtig. ';
                }
                
                if (rp.length > 0) result += 'Sie haben ' + rp[checkedAnswer] + ' von ' + mp + ' Punkten.';
                
                $('#result_' + divid).text(result);
                
                });";
            });
            $tpl->setCurrentBlock("buttons");
            /** @var Button $feedbackButton */
            $tpl->setVariable("BUTTONS", $default_renderer->render($feedbackButton));
            $tpl->parseCurrentBlock();
        }
    
//        $output = "";
    
//        if (!empty($feedbackOnCorrectAnswer)) {
//            if (!$feedbackOnCorrectAnswer[$component->getCheckedId()])
//            $output = 'Ihre Antwort ist nicht ganz richtig.';
//        else
//            $output = 'Ihre Antwort ist richtig.';
//        }
//
//        if (!empty($reachedPoints)) {
//            $maxPoints = $component->getMaxPoints();
//            $output .= ' Sie haben ' . $reachedPoints[$component->getCheckedId()] . ' von ' . $maxPoints . ' Punkten.';
//        }
        
//        if (!empty($feedbackOnCorrectAnswer) || !empty($reachedPoints)) {
//            $tpl->setCurrentBlock("reached_points");
//            $tpl->setVariable("REACHED_POINTS", trim($output));
//            $tpl->parseCurrentBlock();
//        }
        
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
        //$component->withAdditionalOnloadCode($component->getUpdateOnLoadCode());
        return $tpl->get();
    }
    
    protected function getComponentInterfaceName()
    {
        return [Component\Question\CloseEnded\SingleAnswer::class];
    }
}
