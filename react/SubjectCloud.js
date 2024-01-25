import React, { useEffect, useRef, useState } from 'react'
import axios from 'axios';

/**
 * fetchSubjects
 *
 * Retrieves subjects from the server.
 *
 * @returns {Promise<*|null>}
 */
const fetchSubjects = async () => {
  try {
    console.log('process.env.REACT_APP_API_URL: ', process.env.REACT_APP_API_URL);
    const response = await axios.get(process.env.REACT_APP_API_URL + '/getSubjects', {
      headers: {
        'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
      }
    })
    if (!response.data.success && response.data.error) {
      console.error('Failed to fetch subjects: ', response.data.error);
      return null;
    }
    return response.data.subjects;
  } catch (err) {
    console.error('Failed to fetch subjects: ', err);
    return null;
  }
}

/**
 * SubjectCloud Component
 *
 * @returns {Element}
 * @constructor
 */
const SubjectCloud = () => {
  const [subjects, setSubjects] = useState([])
  const [isLoading, setIsLoading] = useState(true)
  const [errorMsg, setErrorMsg] = useState('');
  const [infoMsg, setInfoMsg] = useState('');
  const customSubjectInput = useRef(null);

  /**
   * Wait for subjects to be loaded.
   */
  useEffect(() => {
    if (isLoading) {
      fetchSubjects().then((subjects) => {
        setSubjects(subjects);
        setIsLoading(false);
      });
    }
  }, [subjects]);

  /**
   * onCustomSubjectInputKeyDown
   *
   * Handler for custom subject input enter key. Validates the subject, creates it, and redirects to the play page.
   *
   * @param event
   */
  const onCustomSubjectInputKeyDown = (event) => {
    if (event.key !== 'Enter') {
      return;
    }

    setErrorMsg('');
    setInfoMsg('Checking...');

    const subject = customSubjectInput.current.value.trim().toLowerCase();
    if (subject.length === 0) {
      setInfoMsg('');
      return;
    }

    if (subject.length < 3) {
      setInfoMsg('');
      setErrorMsg('Subject must be at least 3 characters long.');
      return;
    }

    const player = JSON.parse(localStorage.getItem('player'));
    const playerId = player?._id ?? player?.temp_id ?? null;

    // Save the subject
    axios.post(process.env.REACT_APP_API_URL + '/newSubject', { subject: subject, playerId: playerId }, {
      headers: {
        'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
      }
    }).then((response) => {
      if (response.data.error) {
        setInfoMsg('');
        setErrorMsg(response.data.error);
        console.log(response.data.error);
        return;
      }

      if (!response.data.subject) {
        setInfoMsg('');
        setErrorMsg('Failed to create subject. Please try again later.');
        return;
      }

      // redirect to the play page with the new subject
      window.location.href = '/new/' + encodeURIComponent(response.data.subject._id);
    });
  }

  /**
   * subjectSelected
   *
   * Handler for when a subject is selected from the cloud. Redirects to the play page.
   *
   * @param event
   */
  const subjectSelected = (event) => {
    const subjectId = event.target.getAttribute('data-subjectid')
    window.location.href = '/new/' + encodeURIComponent(subjectId);
  }

  if (isLoading) {
    return (
      <div className="subjects">
        <div className="loading-icon">
          <div className="loading">
            <div id="ldsEllipsis" className="lds-ellipsis">
              <div></div>
              <div></div>
              <div></div>
              <div></div>
            </div>
          </div>
        </div>
      </div>
    )
  } else {
    return (
      <div>
        <div className="subjects">
          <div className="instruction">Choose a subject</div>
          <div className="subject-cloud">
            {subjects && subjects.length && subjects.map((subject, index) => (
              <button key={index} data-subjectid={subject._id} className="subject-button" onClick={subjectSelected}>{subject.name}</button>
            ))}
          </div>
          {/*<button className="main-btn">Add a Subject</button>*/}
        </div>
        <div className="subject-input-container">
          <div className="subject-input-fade"/>
          <div className="subject-input-wrap">
            <div className="input-label-wrap subject-input-label-wrap">
              <label className="input-label subject-input-label">Or create your own</label>
            </div>
            <input ref={customSubjectInput} className="input subject-input" type="text"
                   onKeyDown={onCustomSubjectInputKeyDown} maxLength={25}/>
            <div className="error-msg">{errorMsg}</div>
            <div className="info-msg">{infoMsg}</div>
          </div>
        </div>
      </div>
    )
  }
}

export default SubjectCloud
