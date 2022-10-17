<?php
function feedback3_combination()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = ["Rückmeldung anfordern"];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        ["Antwort 17", "Antwort 18", "Antwort 19", "Antwort 20"],
        0)
                 ->withButtons($buttons)
                 ->withReachedPoints([2,0,0,0])
                 ->withFeedbackOnCorrectAnswer([true, false, false, false]);
    return $renderer->render($content);
}
