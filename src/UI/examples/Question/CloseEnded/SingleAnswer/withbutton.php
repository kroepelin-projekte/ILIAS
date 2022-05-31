<?php
function withbutton()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", ["Antwort1", "Antwort2", "Antwort3", "Antwort4"])
                                           ->withButtons($buttons);
    return $renderer->render($content);
}