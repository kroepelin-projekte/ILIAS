function showFeedback(id, answers, fboca, mp, modus) {
  const buttonid = id.id;
  var divid = $('#' + buttonid).parent().parent().attr('id');
  var answerdiv = $('#' + buttonid).parent().siblings();
  var checkedAnswer = $('#' + divid + ' input:checked').val();
  if (checkedAnswer) {
    console.log('----------------');
    console.log('checkedAnswer: ' + checkedAnswer);
    answerdiv.children(".ilc_qanswer_Answer").removeClass("il-correct").removeClass("il-notCorrect").removeClass("il-feedbackOnCorrectAnswer");
    answerdiv.children(".ilc_qanswer_Feedback").text("");
    var result = '';
    var correctAnswerID = -1;
    for (let i = 0; i < answers.length; i++) {
      if (answers[i].points > correctAnswerID) correctAnswerID = i;
    }
    console.log('correct Answer: ' + correctAnswerID);
    console.log('Points: ' + answers[checkedAnswer].points);
    console.log('max Points: ' + mp);

    if (fboca !== null) {
      if (answers[checkedAnswer].points) {
        result = fboca[0] + ' ';
        answerdiv
        .children(".ilc_qanswer_Answer:eq(" + checkedAnswer + ")")
        .addClass("il-feedbackOnCorrectAnswer")
        .addClass("il-correct");
      } else {
        result = fboca[1] + ' ';
        answerdiv
        .children(".ilc_qanswer_Answer:eq(" + checkedAnswer + ")")
        .addClass("il-feedbackOnCorrectAnswer")
        .addClass("il-notCorrect");
        answerdiv
        .children(".ilc_qanswer_Answer:eq(" + correctAnswerID + ")")
        .addClass("il-feedbackOnCorrectAnswer")
        .addClass("il-notCorrect");
      }
    }

    if (mp !== null) result += 'Sie haben ' + answers[checkedAnswer].points + ' von ' + mp + ' Punkten.';

    $('#result_' + divid).text(result);

    if (modus !== null) {
      switch (modus) {
        case 0: answers.forEach((answer, index) => {
                  answerdiv.children(".ilc_qanswer_Feedback:eq(" + index + ")").text(answer.feedback);
                });
                break;
        case 1: answerdiv.children(".ilc_qanswer_Feedback:eq(" + checkedAnswer + ")").text(answers[checkedAnswer].feedback);
                break;
        case 2: answerdiv.children(".ilc_qanswer_Feedback:eq(" + correctAnswerID + ")").text(answers[correctAnswerID].feedback);
                break;
      }
    }
  }
}
