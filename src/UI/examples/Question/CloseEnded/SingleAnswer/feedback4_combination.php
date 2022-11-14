<?php

use ILIAS\UI\Implementation\Component\Question as Q;

function feedback4_combination()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    // preparing Question and Answers
    $answer1 = new Q\Answer(17,"Antwort 17", 2, "Das ist das Feedback zu Antwort 17", null, false, false);
    $answer2 = new Q\Answer(18,"Antwort 18", 0, "Das ist das Feedback zu Antwort 18", null, false, false);
    $answer3 = new Q\Answer(19,"Antwort 19", 0, "Das ist das Feedback zu Antwort 19", null, false, false);
    $answer4 = new Q\Answer(20,"Antwort 20", 0, "Das ist das Feedback zu Antwort 20", null, false, false);
    $answers = [&$answer1, &$answer2, &$answer3, &$answer4];
    
    $question = new Q\CloseEnded\SingleAnswer("Hier steht der Fragentitel", "Hier steht die Frage 5", $answers);
    
    $content = $f->question()->closeEnded()->singleAnswer($question->getQuestionTitle(), $question->getQuestionStem(), $question->getAnswers())
                 ->withFeedbackButton()
                 ->withReachedPoints(2)
                 ->withFeedbackOnCorrectAnswer(["Die Antwort ist richtig.", "Die Antwort ist nicht ganz richtig."])
                 ->withSpezificFeedbackForEachAnswer(1);
    return $renderer->render($content);
}
