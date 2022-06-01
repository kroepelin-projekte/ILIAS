<?php
function feedback1_reached_Points()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        [["Antwort 8", false], ["Antwort 9", true], ["Antwort 10", false], ["Antwort 11", false]])
                                           ->withButtons($buttons)
                                           ->withReachedPoints("Sie haben 2 von 2 Punkten erreicht.");
    return $renderer->render($content);
}
