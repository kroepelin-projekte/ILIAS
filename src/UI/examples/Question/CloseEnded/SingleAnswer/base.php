<?php
function base()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();

    $content = $f->question()->closeEnded()->singleAnswer("Hier steht die Frage", [["Antwort 1", false], ["Antwort 2", false], ["Antwort 3", false], ["Antwort 4", false]]);
    return $renderer->render($content);
}
