<?php
function base()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();

    $content = $f->question()->closeEnded()->multipleChoice()->singleAnswer("Hier steht die Frage", ["Antwort1", "Antwort2", "Antwort3", "Antwort4"]);
    return $renderer->render($content);
}
