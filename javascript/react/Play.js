import '../lds-ellipsis.css';
import { useState, useEffect, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import axios from 'axios';
import { getTempUserId } from '../utils';
import Header from '../components/Header';
import Loading from '../components/Loading';
import Footer from '../components/Footer';

/**
 * createGroup
 *
 * Creates a new group on the server.
 * If the player from local storage doesn't exist it will be created on the server as well.
 *
 * @param subjectId
 * @returns {Promise<*|null>}
 */
const createGroup = async (subjectId) => {
  const player = JSON.parse(localStorage.getItem('player'));

  const createDt = new Date();
  createDt.setHours(0, 0, 0, 0);

  const response = await axios.post(process.env.REACT_APP_API_URL + '/addGroup', {
    subjectId: subjectId,
    player: player,
    localDate: createDt
  }, {
    headers: {
      'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
    }
  });

  if (response.data.error) {
    console.error('Failed to create new group: ', response.data.error);
    // TODO: Show error message
    return null;
  }

  // Get the new group guid and add it to the player
  const groups = player.groups ?? [];
  const groupGuid = response.data.group.guid;
  player.groups = [...groups, groupGuid];

  // Store player in local storage
  localStorage.setItem('player', JSON.stringify(player));

  return response.data.group;
}

/**
 * fetchQuestion
 *
 * Retrieves a new question from the server.
 *
 * @param subjectId
 * @param groupGuid
 * @returns {Promise<*|null>}
 */
const fetchQuestion = async (subjectId, groupGuid) => {
  let query = '?subjectId=' + subjectId + '&groupGuid=' + groupGuid;

  const player = JSON.parse(localStorage.getItem('player'));
  if (player && (player._id || player.temp_id)) {
    query += '&playerId=' + (player.email ? player._id : player.temp_id);
  }

  const response = await axios.get(process.env.REACT_APP_API_URL + '/getQuestion' + query, {
    headers: {
      'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
    }
  });
  if (response.data.error || !response.data.question) {
    console.error('Failed to get question: ', response.data.error);
    return null;
  }

  return response.data.question;
}

/**
 * sendAnswer
 *
 * Sends an answer to the server.
 *
 * @param groupGuid
 * @param questionId
 * @param answer (see useEffect)
 * @returns {Promise<boolean>}
 */
const sendAnswer = async (groupGuid, questionId, answer) => {
  const response = await axios.post(process.env.REACT_APP_API_URL + '/addAnswer', {
    groupGuid: groupGuid,
    questionId: questionId,
    answer: answer
  }, {
    headers: {
      'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
    }
  });
  if (response.data.error) {
    console.error('Failed to add answer: ', response.data.error);
    // TODO: Show error message
    return false;
  }

  if (response.player) {
    localStorage.setItem('plyaer', JSON.stringify(response.player));
  }

  return true;
}

/**
 * Play page.
 *
 * @returns {JSX.Element}
 * @constructor
 */
const Play = () => {
  const [isLoading, setIsLoading] = useState(true);
  const [question, setQuestion] = useState(null);
  const [result, setResult] = useState("");
  const [isAnswered, setIsAnswered] = useState(false);
  const [btnDisabled, setBtnDisabled] = useState({'A': false, 'B': false, 'C': false, 'D': false});
  const [groupGuid, setGroupGuid] = useState(null);
  const [subject, setSubject] = useState('');
  const [unansweredCount, setUnansweredCount] = useState(0);

  const points = useRef(Number(process.env.REACT_APP_DAILY_QUESTION_COUNT));
  const currQuestionNum = useRef(1);

  const navigate = useNavigate();

  const params = useParams();
  if (!params.subjectId && !params.groupGuid) {
    navigate('/dashboard', { replace: true });
  }

  /**
   * Create a new temp player if no existing player in local storage.
   * Create a new group if no group guid in params.
   * Create the answer object in local storage.
   * Get the first question.
   */
  useEffect(() => {
    if (isLoading) {
      // Initialize answer on first question
      if (currQuestionNum.current === 1) {
        if (params.groupGuid) {
          // Get the group from server
          let query = '?groupGuid=' + params.groupGuid;
          let player = JSON.parse(localStorage.getItem('player'));
          if (player && player._id) {
            query += '&playerId=' + player._id;
          } else {
            query += '&tempPlayerId=' + player.temp_id;
          }
          axios.get(process.env.REACT_APP_API_URL + '/getGroup' + query, {
            headers: {
              'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
            }
          }).then((response) => {
            if (response.data.error) {
              console.log('Failed to get group: ', response.data.error);

              if (response.data.error === 'Player not found') {
                localStorage.removeItem('player');
                window.location = '/';
              }
            }

            // Create the answer object in local storage
            const answer = JSON.parse(localStorage.getItem('answer-' + params.groupGuid));
            if (!answer) {
              localStorage.setItem('answer-' + params.groupGuid, JSON.stringify({
                group_guid: params.groupGuid,
                answers: []
              }));
            }

            setSubject(response.data.group.subject);
            setGroupGuid(params.groupGuid);

            let unAnsweredCount = Number(response.data.group.unanswered_count);
            if (response.data.group.todays_answers.length === 0) {
              // This just means today's question have not been generated yet
              unAnsweredCount += Number(process.env.REACT_APP_DAILY_QUESTION_COUNT);

              // TODO: Show and ad here, while they wait for the questions to be generated?
            }

            setUnansweredCount(unAnsweredCount);

            getQuestion(response.data.group);
          });
        } else {
          // Look for player in local storage
          let player = JSON.parse(localStorage.getItem('player'));
          if (!player) {
            // Create a temp player
            player = {
              temp_id: getTempUserId(),
              stats: {
                questions_answered: 0,
                total_score: 0
              },
              groups: []
            };
            localStorage.setItem('player', JSON.stringify(player));
          }

          createGroup(params.subjectId).then((group) => {
            if (!group || group.error) {
              console.error('Failed to create group.');
              if (group.error) {
                console.error(group.error);
              }
              // TODO: Show error message
              window.location = '/';
              return;
            }

            localStorage.setItem('answer-' + group.guid, JSON.stringify({
              group_guid: group.guid,
              answers: []
            }));
            setSubject(group.subject);
            setGroupGuid(group.guid);
            setUnansweredCount(Number(process.env.REACT_APP_DAILY_QUESTION_COUNT));
            getQuestion(group);
          });
        }
      }
    } else {
      restorePrevAnswers(question);
    }
  }, [isLoading]);

  /**
   * getQuestion
   *
   * Retrieves a new question from the server.
   *
   * @param group
   */
  const getQuestion = (group = null) => {
    const subjectId = params.subjectId ?? null;
    const groupGuidUse = group?.guid ?? groupGuid ?? params.groupGuid ?? null;
    if (!subject && !groupGuidUse) {
      console.error('Failed to get question: subject or group guid is null');
      // TODO: Show error message
      return;
    }

    fetchQuestion(subjectId, groupGuidUse).then((question) => {
      // Sanity check for 'all caught up' and other errors
      if (question === null) {
        window.location = '/stats/' + groupGuidUse;
      }

      const answer = JSON.parse(localStorage.getItem('answer-' + groupGuidUse));
      if (answer) {
        const thisQuestion = answer.answers.find((answer) => answer.question_id === question._id);
        if (!thisQuestion) {
          // Add the new question to the answer object in storage
          let answer = JSON.parse(localStorage.getItem('answer-' + groupGuidUse));
          const player = JSON.parse(localStorage.getItem('player'));
          answer.answers.push({
            question_id: question._id,
            player_id: player.email ? player._id : player.temp_id,
            score: 0,
            guesses: []
          });
          localStorage.setItem('answer-' + groupGuidUse, JSON.stringify(answer));
        }
      }

      setQuestion(question);

      // Reset UI state
      setIsAnswered(false);
      setBtnDisabled({'A': false, 'B': false, 'C': false, 'D': false});

      setIsLoading(false);
    });
  }

  /**
   * restorePrevAnswers
   *
   * Restore the state of the buttons from the existing answer in local storage if there is one.
   *
   * @param question
   * @param groupGuidUse
   * @returns {boolean}
   */
  const restorePrevAnswers = (question, groupGuidUse) => {
    const answer = JSON.parse(localStorage.getItem('answer-' + groupGuid));

    // Look for an existing answer for this question
    const thisQuestion = answer.answers.find((answer) => answer.question_id === question._id);
    if (thisQuestion) {
      // Restore the state of the buttons from the existing answer
      thisQuestion.guesses.forEach((guess) => {
        const btn = document.getElementById("btn" + guess.option);
        checkAnswer(guess.option, true);
      });

      return true;
    }

    return false
  }

  /**
   * checkAnswer
   *
   * Handler for option buttons. Checks if the option selected is correct and updates the UI
   * and score accordingly.
   *
   * @param answer
   * @param isRestore
   */
  const checkAnswer = (answer, isRestore = false) => {
    if (!isAnswered) {
      const correct = answer === question.answer;
      const btn = document.getElementById("btn" + answer);
      const resultText = document.getElementById("resultText");

      const answerObj = JSON.parse(localStorage.getItem('answer-' + groupGuid));

      if (!isRestore) {
        // Add the guess to the answer object in storage
        answerObj.answers[answerObj.answers.length - 1].guesses.push({
          option: answer,
          result: correct
        });
        localStorage.setItem('answer-' + groupGuid, JSON.stringify(answerObj));
      }

      document.getElementById("resultWrap").style.display = "block";

      if (correct) {
        setResult("Correct!");
        btn.classList.add("opt-button-correct");
        resultText.classList.remove("result-incorrect");
        resultText.classList.add("result-correct");
        setIsAnswered(true);
        document.getElementById("resultPoints").style.display = "block";
        document.getElementById("nextBtn").style.display = "block";
        document.getElementById('feedbackLink').style.display = "block";

        // Set the score
        answerObj.answers[answerObj.answers.length - 1].score = points.current;
        localStorage.setItem('answer-' + groupGuid, JSON.stringify(answerObj));
      } else {
        setBtnDisabled({...btnDisabled, [answer]: true});
        btn.classList.add("opt-button-disabled");
        resultText.classList.remove("result-correct");
        resultText.classList.add("result-incorrect");

        if (points.current === 1) {
          // Out of tries
          setResult("Incorrect.");
          setIsAnswered(true);
          // Set the score
          points.current = 0;
          answerObj.answers[answerObj.answers.length - 1].score = 0;
          localStorage.setItem('answer-' + groupGuid, JSON.stringify(answerObj));
          document.getElementById("resultPoints").style.display = "block";
          document.getElementById("nextBtn").style.display = "block";
          document.getElementById('feedbackLink').style.display = "block";
        } else {
          setResult("Incorrect. Try again.");
          points.current -= 1;
        }
      }
    }
  }

  /**
   * nextQuestion
   *
   * Handler for the Next button. Sends the answer to the server and loads the next question.
   */
  const nextQuestion = async () => {
    const player = JSON.parse(localStorage.getItem('player'));
    const answer = JSON.parse(localStorage.getItem('answer-' + groupGuid));

    if (currQuestionNum.current === Number(unansweredCount)) {
      // Send most recent answer to server
      setIsLoading(true);
      await sendAnswer(groupGuid, question._id, answer.answers[answer.answers.length - 1]);

      // Clean up
      localStorage.removeItem('answer-' + groupGuid);

      if (player.email) {
        // Logged in layer creating a new group
        // Logged in  player in existing group
        navigate('/stats/' + groupGuid, { replace: true });
      } else if (params.subjectId) {
        // Temp player creating new group
        navigate('/invite/' + groupGuid + '/' + subject, { replace: true });
      } else {
        // Temp players joining and existing group
        navigate('/dashboard', { replace: true });
      }
    } else {
      // Next question
      document.getElementById("resultWrap").style.display = "none";
      const optBtns = document.getElementsByClassName("opt-button");
      for (let i = 0; i < optBtns.length; i++) {
        optBtns[i].classList.remove("opt-button-correct");
        optBtns[i].classList.remove("opt-button-disabled");
      }

      currQuestionNum.current += 1;

      // Send most recent answer to server
      setIsLoading(true);
      await sendAnswer(groupGuid, question._id, answer.answers[answer.answers.length - 1]);

      // Reset the state and load a new question
      points.current = Number(process.env.REACT_APP_DAILY_QUESTION_COUNT);
      getQuestion(groupGuid);
    }
  }

  /**
   * onBadQuestionClick
   *
   * Handler for the 'bad question' link.
   */
  const onBadQuestionClick = () => {
    // Send bad question to server
    const payload = {
      question_id: question._id
    };
    axios.post(process.env.REACT_APP_API_URL + '/flagQuestion', payload, {
      headers: {
        'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
      }
    });

    document.getElementById('feedbackLink').style.display = "none";
    document.getElementById('feedbackThanks').style.display = "block";
  }

  if (isLoading) {
    return (
      <div className="page-wrap">
        <Header/>
        <div className="play-page">
          <Loading messages={[
            '',
            'Thinking of some questions',
            'Still thinking',
            'Stick around, trivia inbound!',
            'Patients is a virtue',
            'Trivia\'s near, never fear!',
            'Here comes the trivia!',
            'Are you excited?'
          ]}/>
        </div>
        <Footer/>
      </div>
    )
  } else {
    return (
      <div className="page-wrap">
        <Header/>
        <div className="play-page">
          <div className="question-subject" onClick={() => window.location = '/stats/' + groupGuid}>{subject}</div>
          <div className="question-wrap">
            <div className="question">
              {currQuestionNum.current}/{unansweredCount}: {question.question}
            </div>
            <ul>
              <li>
                {/*TODO: Move buttons to component*/}
                <div className="box-header clearfix">
                  <div className="left-cell">
                    <button id="btnA" className="opt-button" onClick={() => checkAnswer('A')}
                            disabled={btnDisabled.A}>A
                    </button>
                  </div>
                  <div className="right-cell">
                    <div className="opt">{question.choices.A}</div>
                  </div>
                </div>
              </li>
              <li>
                <div className="box-header clearfix">
                  <div className="left-cell">
                    <button id="btnB" className="opt-button" onClick={() => checkAnswer('B')}
                            disabled={btnDisabled.B}>B
                    </button>
                  </div>
                  <div className="right-cell">
                    <div className="opt">{question.choices.B}</div>
                  </div>
                </div>
              </li>
              <li>
                <div className="box-header clearfix">
                  <div className="left-cell">
                    <button id="btnC" className="opt-button" onClick={() => checkAnswer('C')}
                            disabled={btnDisabled.C}>C
                    </button>
                  </div>
                  <div className="right-cell">
                    <div className="opt">{question.choices.C}</div>
                  </div>
                </div>
              </li>
              <li>
                <div className="box-header clearfix">
                  <div className="left-cell">
                    <button id="btnD" className="opt-button" onClick={() => checkAnswer('D')}
                            disabled={btnDisabled.D}>D
                    </button>
                  </div>
                  <div className="right-cell">
                    <div className="opt">{question.choices.D}</div>
                  </div>
                </div>
              </li>
            </ul>
          </div>
          <div id="resultWrap">
            <p id="resultText">{result}</p>
            <p id="resultPoints">{points.current} point{(points.current === 0 || points.current > 1) && <span>s</span>}</p>
            <button id="nextBtn" onClick={nextQuestion}>Next</button>
            <div id="feedbackLink" className="link-button-wrap link-button-wrap-play-page">
              <span className="link-button link-button-dim" onClick={onBadQuestionClick}>bad question?</span>
            </div>
            <div id="feedbackThanks">Thanks for the feedback!<br/>We'll look into it.</div>
          </div>
        </div>
        <Footer/>
      </div>
    )
  }
}

export default Play
