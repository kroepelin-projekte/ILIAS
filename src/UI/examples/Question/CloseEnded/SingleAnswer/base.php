<?php

use ILIAS\UI\Implementation\Component\Question as Q;

function base()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    $answer1 = new Q\Answer(1,"Antwort 1");
    $answer2 = new Q\Answer(2,"Antwort 2");
    $answer3 = new Q\Answer(3,"Antwort 3");
    $answer4 = new Q\Answer(4,"Antwort 4");
    $answers = [&$answer1, &$answer2, &$answer3, &$answer4];
    
    $question = new Q\CloseEnded\SingleAnswer("Hier steht der Fragentitel", "Hier steht die Frage 1", $answers);
    

    $content = $f->question()->closeEnded()->singleAnswer($question->getQuestionTitle(), $question->getQuestionStem(), $question->getAnswers());
    return $renderer->render($content);
}
