<?php

use ILIAS\UI\Implementation\Component\Question as Q;

function feedback3_on_each_answer()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $answer1 = new Q\Answer(5,"Antwort 5", 1, "Das ist das Feedback zu Antwort 5");
    $answer2 = new Q\Answer(6,"Antwort 6", 0, "Das ist das Feedback zu Antwort 6");
    $answer3 = new Q\Answer(7,"Antwort 7", 0, "Das ist das Feedback zu Antwort 7");
    $answers = [&$answer1, &$answer2, &$answer3];
    
    $question = new Q\CloseEnded\SingleAnswer("Hier steht der Fragentitel", "Hier steht die Frage 2", $answers);
    
    $content = $f->question()->closeEnded()->singleAnswer($question->getQuestionTitle(), $question->getQuestionStem(), $question->getAnswers())
                                           ->withFeedbackButton()
                                           ->withSpezificFeedbackForEachAnswer(0);
    return $renderer->render($content);
}