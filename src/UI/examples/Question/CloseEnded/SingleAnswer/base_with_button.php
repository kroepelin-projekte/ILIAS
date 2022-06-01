<?php
function base_with_button()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", [["Antwort 1", false], ["Antwort 2", false], ["Antwort 3", false], ["Antwort 4", false]])
                                           ->withButtons($buttons);
    return $renderer->render($content);
}