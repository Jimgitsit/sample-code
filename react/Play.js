import '../lds-ellipsis.css'
import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom';
import axios from "axios"
import { getTempUserId } from '../utils'

const fetchQuestion = async () => {
  const response = await axios.get(process.env.REACT_APP_API_URL + '/getQuestion')
  if (response.data.error || !response.data.question) {
    console.error('Failed to get question: ', response.data.error);
    return null;
  }

  return response.data.question;
}

/**
 * Play page.
 * @returns {JSX.Element}
 * @constructor
 */
const Play = () => {
  const params = useParams();
  console.log('params: ', params);

  let [isLoading, setIsLoading] = useState(true)
  let [question, setQuestion] = useState(null)
  let [correctAnswer, setCorrectAnswer] = useState("")
  let [optA, setOptA] = useState("")
  let [optB, setOptB] = useState("")
  let [optC, setOptC] = useState("")
  let [optD, setOptD] = useState("")
  let [result, setResult] = useState("")
  let [points, setPoints] = useState(3)
  let [isAnswered, setIsAnswered] = useState(false)
  let [btnDisabled, setBtnDisabled] = useState({'A': false, 'B': false, 'C': false, 'D': false})
  let [questionCount, setQuestionCount] = useState(1)

  /**
   * Initialize first question
   */
  useEffect(() => {
    if (question === null) {
      getQuestion();
    }
  }, []);

  const getQuestion = () => {
    fetchQuestion().then((question) => {
      console.log('getQuestion: ', question);

      setQuestion(question.question);
      setCorrectAnswer(question.answer);
      setOptA(question.choices.A);
      setOptB(question.choices.B);
      setOptC(question.choices.C);
      setOptD(question.choices.D);

      setIsAnswered(false);
      setBtnDisabled({'A': false, 'B': false, 'C': false, 'D': false});

      setIsLoading(false);
    });
  }

  /**
   * checkAnswer
   *
   * Handler for option buttons.
   * @param answer
   */
  const checkAnswer = (answer) => {
    console.log("isAnswered: ", isAnswered)
    if (!isAnswered) {
      const correct = answer === correctAnswer
      const btn = document.getElementById("btn" + answer)
      const resultText = document.getElementById("resultText")
      document.getElementById("resultWrap").style.display = "block"
      if (correct) {
        setResult("Correct!")
        btn.classList.add("opt-button-correct")
        resultText.classList.remove("result-incorrect")
        resultText.classList.add("result-correct")
        setIsAnswered(true)
        document.getElementById("resultPoints").style.display = "block"
        document.getElementById("nextBtn").style.display = "block"
      } else {
        setBtnDisabled({...btnDisabled, [answer]: true})
        btn.classList.add("opt-button-disabled")
        resultText.classList.remove("result-correct")
        resultText.classList.add("result-incorrect")

        if (points === 1) {
          setResult("Incorrect.");
          setIsAnswered(true)
          setPoints(0)
          document.getElementById("resultPoints").style.display = "block"
          document.getElementById("nextBtn").style.display = "block"
        } else {
          setResult("Incorrect. Try again.")
          setPoints(points - 1);
        }
      }
    }
  }

  /**
   * nextQuestion
   *
   * Checks if question limit is reached and if this is a new player.
   * If new player, creates a new group and redirects to the invite page.
   * Otherwise, redirects to the stats page.
   */
  const nextQuestion = async () => {
    if (questionCount === Number(process.env.REACT_APP_DAILY_QUESTION_COUNT)) {
      if (params.groupGuid) {
        // TODO: Store the question's state and player's score


        const player = JSON.parse(localStorage.getItem('player'));
        if (!player.email) {
          // Show create account page
          window.location.href = '/newlogin';
        } else {
          window.location.href = '/stats/' + params.groupGuid;
        }
      } else if (params.subject) {
        // TODO: Store the question's state and player's score


        // Look for player in local storage
        let player = JSON.parse(localStorage.getItem('player'));
        if (player === null) {
          // Create a temp player
          player = {
            temp_id: getTempUserId(),
            stats: {
              questionsAnswered: questionCount
            }
          };
        }

        // Create new group and redirect to the invite page
        const response = await axios.post(process.env.REACT_APP_API_URL + '/addGroup', {
          subject: params.subject,
          player: player
        });

        if (response.data.error) {
          console.error('Failed to create new group: ', response.data.error);
          // TODO: Show error message
          return;
        }

        // Get the new group guid and add it to the player
        const groups = player.groups ?? [];
        const groupGuid = response.data.group.guid;
        player.groups = [...groups, groupGuid];

        // Store player in local storage
        localStorage.setItem('player', JSON.stringify(player));

        // Redirect to invite page
        window.location.href = '/invite/' + groupGuid;
      }
    } else {
      document.getElementById("resultWrap").style.display = "none"
      const optBtns = document.getElementsByClassName("opt-button")
      for (let i = 0; i < optBtns.length; i++) {
        optBtns[i].classList.remove("opt-button-correct")
        optBtns[i].classList.remove("opt-button-disabled")
      }

      // TODO: Store the question's state and player's score


      // Load a new question and reset the UI state
      setQuestionCount(questionCount + 1);
      getQuestion();
    }
  }

  // TODO: Add a time limit to answer the question. Should start with "You will have 30 seconds to answer the question.
  //  Are you ready?" and a "Ready" button. When the user clicks "Ready", the timer starts and the question is displayed.

  if (isLoading) {
    return (
      <div className="play-page">
        <div className="loading">
          <div id="ldsEllipsis" className="lds-ellipsis"><div></div><div></div><div></div><div></div></div>
        </div>
      </div>
    )
  } else {
    return (
      <div className="play-page">
        <div className="question-subject">{params.subject}</div>
        <div className="question-wrap">
          <div className="question">
            {questionCount}/{process.env.REACT_APP_DAILY_QUESTION_COUNT}: {question}
          </div>
          <ul>
            <li>
              {/*TODO: Move buttons to component*/}
              <div className="box-header clearfix">
                <div className="left-cell">
                  <button id="btnA" className="opt-button" onClick={() => checkAnswer("A")} disabled={btnDisabled.A}>A</button>
                </div>
                <div className="right-cell">
                  <div className="opt">{optA}</div>
                </div>
              </div>
            </li>
            <li>
              <div className="box-header clearfix">
                <div className="left-cell">
                  <button id="btnB" className="opt-button" onClick={() => checkAnswer("B")} disabled={btnDisabled.B}>B</button>
                </div>
                <div className="right-cell">
                  <div className="opt">{optB}</div>
                </div>
              </div>
            </li>
            <li>
              <div className="box-header clearfix">
                <div className="left-cell">
                  <button id="btnC" className="opt-button" onClick={() => checkAnswer("C")} disabled={btnDisabled.C}>C</button>
                </div>
                <div className="right-cell">
                  <div className="opt">{optC}</div>
                </div>
              </div>
            </li>
            <li>
              <div className="box-header clearfix">
                <div className="left-cell">
                  <button id="btnD" className="opt-button" onClick={() => checkAnswer("D")} disabled={btnDisabled.D}>D</button>
                </div>
                <div className="right-cell">
                  <div className="opt">{optD}</div>
                </div>
              </div>
            </li>
          </ul>
        </div>
        <div id="resultWrap">
          <p id="resultText">{result}</p>
          <p id="resultPoints">{points} points</p>
          <button id="nextBtn" onClick={nextQuestion}>Next</button>
        </div>
      </div>
    )
  }
}

export default Play
