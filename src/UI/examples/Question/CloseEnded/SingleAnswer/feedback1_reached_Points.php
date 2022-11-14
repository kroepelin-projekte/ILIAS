<?php

use ILIAS\UI\Implementation\Component\Question as Q;

function feedback1_reached_Points()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $answer1 = new Q\Answer(8,"Antwort 8", 0);
    $answer2 = new Q\Answer(9,"Antwort 9", 1);
    $answer3 = new Q\Answer(10,"Antwort 10", 0);
    $answer4 = new Q\Answer(11,"Antwort 11", 2);
    $answer5 = new Q\Answer(12,"Antwort 12", 0);
    
    $answers = [&$answer1, &$answer2, &$answer3, &$answer4, &$answer5];
    
    $question = new Q\CloseEnded\SingleAnswer("Hier steht der Fragentitel", "Hier steht die Frage 3", $answers);
    
    $content = $f->question()->closeEnded()->singleAnswer($question->getQuestionTitle(), $question->getQuestionStem(), $question->getAnswers())
                                           ->withFeedbackButton()
                                           ->withReachedPoints(2);
    return $renderer->render($content);
}
