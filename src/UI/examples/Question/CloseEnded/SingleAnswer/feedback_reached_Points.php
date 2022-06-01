<?php
function feedback_reached_Points()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        [["Antwort 1", false], ["Antwort 2", true], ["Antwort 3", false], ["Antwort 4", false]])
                                           ->withButtons($buttons)
                                           ->withReachedPoints("Sie haben 2 von 2 Punkten erreicht.");
    return $renderer->render($content);
}
