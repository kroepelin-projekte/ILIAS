<?php
function base_with_button()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", ["Antwort 5", "Antwort 6", "Antwort 7"])
                                           ->withButtons($buttons);
    return $renderer->render($content);
}