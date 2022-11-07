<?php


static $feedback = [];



$post = json_decode(file_get_contents('php://input'), true);
if (isset($post['checkedID'])) {
    $result = 'Test4';
    if ($feedbackOnCorrectAnswer) {
        if ($feedbackOnCorrectAnswer[$post['data']]) $result = 'Ihre Antwort ist richtig. ';
        else $result = 'Ihre Antwort ist nicht ganz richtig. ';
    }
    if ($reachedPoints) $result .= "Sie haben " . $reachedPoints[$post['data']] . " von " . $maxPoints . " Punkten.";
    
    
    exit($result);
}

