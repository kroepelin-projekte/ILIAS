<?php
function base()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();

    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", ["<i>Antwort</i> 1", "Antwort 2", "Antwort 3", "Antwort 4"]);
    return $renderer->render($content);
}
