<?php
function feedback2_on_correct_answer()
{
    global $DIC;
    $f = $DIC->ui()->factory();
    $renderer = $DIC->ui()->renderer();
    
    $buttons = [$f->button()->standard("Rückmeldung anfordern", "#")];
    
    $content = $f->question()->closeEnded()->singleAnswer(
        "Hier steht die Frage",
        [["Antwort 12", false], ["Antwort 13", false], ["Antwort 14", false], ["Antwort 15", false], ["Antwort 16", true]])
                 ->withButtons($buttons)
                 ->withFeedbackOnCorrectAnswer(["Ihre Antwort ist nicht richtig."]);
    return $renderer->render($content);
}