<?php
function feedback3_combination()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        [["Antwort 17", false], ["Antwort 18", true], ["Antwort 19", false], ["Antwort 20", false]])
                 ->withButtons($buttons)
                 ->withReachedPoints("Sie haben 2 von 2 Punkten erreicht.")
                 ->withFeedbackOnCorrectAnswer(["Ihre Antwort ist richtig."]);
    return $renderer->render($content);
}
