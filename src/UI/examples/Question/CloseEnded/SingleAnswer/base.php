<?php
function base()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();

    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", ["<i>Antwort1</i>", "Antwort2", "Antwort3", "Antwort4"]);
    return $renderer->render($content);
}
