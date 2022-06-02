<?php
function feedback3_combination()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        ["Antwort 17", "Antwort 18", "Antwort 19", "Antwort 20"],
        2)
                 ->withButtons($buttons)
                 ->withReachedPoints("Sie haben 2 von 2 Punkten erreicht.")
                 ->withFeedbackOnCorrectAnswer(["Ihre Antwort ist richtig.", "correct, checked" => 2]);
    return $renderer->render($content);
}
