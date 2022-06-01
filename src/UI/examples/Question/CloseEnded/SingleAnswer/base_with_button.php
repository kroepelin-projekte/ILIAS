<?php
function base_with_button()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", [["Antwort 5", false], ["Antwort 6", false], ["Antwort 7", false]])
                                           ->withButtons($buttons);
    return $renderer->render($content);
}