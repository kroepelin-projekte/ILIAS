<?php

use ILIAS\UI\Implementation\Component\Question as Q;

function feedback2_on_correct_answer()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $answer1 = new Q\Answer(13,"Antwort 13", 0);
    $answer2 = new Q\Answer(14,"Antwort 14", 1);
    $answer3 = new Q\Answer(15,"Antwort 15", 0);
    $answer4 = new Q\Answer(16,"Antwort 16", 0);
    $answers = [&$answer1, &$answer2, &$answer3, &$answer4];
    
    $question = new Q\CloseEnded\SingleAnswer("Hier steht der Fragentitel", "Hier steht die Frage 4", $answers);
    
    $content = $f->question()->closeEnded()->singleAnswer($question->getQuestionTitle(), $question->getQuestionStem(), $question->getAnswers())
                 ->withFeedbackButton()
                 ->withFeedbackOnCorrectAnswer(["Die Antwort ist richtig.", "Die Antwort ist nicht ganz richtig."]);
    return $renderer->render($content);
}