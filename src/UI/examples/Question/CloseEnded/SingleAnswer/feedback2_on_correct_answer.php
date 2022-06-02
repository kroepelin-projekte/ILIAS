<?php
function feedback2_on_correct_answer()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        ["Antwort 12", "Antwort 13", "Antwort 14", "Antwort 15", "Antwort 16"],
        1)
                 ->withButtons($buttons)
                 ->withFeedbackOnCorrectAnswer(["Ihre Antwort ist nicht richtig.", "notCorrect" => [1, 3]]);
    return $renderer->render($content);
}