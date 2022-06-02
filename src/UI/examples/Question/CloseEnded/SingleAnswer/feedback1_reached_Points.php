<?php
function feedback1_reached_Points()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        ["Antwort 8", "Antwort 9", "Antwort 10", "Antwort 11"],
        2)
                                           ->withButtons($buttons)
                                           ->withReachedPoints("Sie haben 2 von 2 Punkten erreicht.");
    return $renderer->render($content);
}
