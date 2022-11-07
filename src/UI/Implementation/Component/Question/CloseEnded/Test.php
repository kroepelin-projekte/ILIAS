<?php

namespace ILIAS\UI\Implementation\Component\Question\CloseEnded;

class Test
{
   public static array $feedback = [];
}

$post = json_decode(file_get_contents('php://input'), true);
if (isset($post['checkeddd'])) {
    $result = 'Test3';
    if (Test::$feedback) {
        if (Test::$feedback) { //todo: Abfrage muss noch angepasst werden
            $result = 'Ihre Antwort ist richtig. ';
        } else {
            $result = 'Ihre Antwort ist nicht ganz richtig. ';
        }
    }
    if ($reachedPoints) { //todo: Abfrage muss noch angepasst werden
        $result .= "Sie haben " . $reachedPoints[$post['data']] . " von " . $maxPoints . " Punkten.";
    }
    
    
    exit($result);
}