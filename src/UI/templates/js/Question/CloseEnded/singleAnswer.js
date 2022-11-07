function showFeedback(id, url) {
  const buttonid = id.id;
  var divid = $('#' + buttonid).parent().parent().attr('id');
  var checkedAnswer = $('#' + divid + ' input:checked').val();
  if (checkedAnswer) {
    console.log('----------------');
    console.log('checkedAnswer: ' + checkedAnswer);
    data = {
      id: buttonid,
      checked: checkedAnswer
    };

    fetch(url, {
      method: 'post',
      body: JSON.stringify(data),
      header: {
        'ContentType': 'application/json'
      }
    })
    .then(res => res.text())
    .then(res => $('#result_' + divid).text(res))
  }
}

//                var fboca = $fboca_json;
//                console.log('FeedbackOnCorrectAnswer: ' + fboca + ' - Länge: ' + fboca.length);
//                var rp = $rp_json;
//                console.log('Reached Points: ' + rp);
//                var mp = $mp_json;
//                console.log('MaxPoints: ' + mp);
//
//                var result = '';
//                if (fboca.length > 0) {
//                    if (fboca[checkedAnswer]) result = 'Ihre Antwort ist richtig. ';
//                    else result = 'Ihre Antwort ist nicht ganz richtig. ';
//                }
//
//                if (rp.length > 0) result += 'Sie haben ' + rp[checkedAnswer] + ' von ' + mp + ' Punkten.';
//
//                $('#result_' + divid).text(result);