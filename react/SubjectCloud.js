import React, { useEffect, useState } from "react"
import axios from 'axios';

const fetchSubjects = async () => {
  try {
    const response = await axios.get(process.env.REACT_APP_API_URL + '/getSubjects')
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

const SubjectCloud = (params) => {
  const [subjects, setSubjects] = useState([])
  const [isLoading, setIsLoading] = useState(true)

  useEffect(() => {
    if (isLoading) {
      fetchSubjects().then((subjects) => {
        setSubjects(subjects);
        setIsLoading(false);
      });
    }
  }, [subjects]);

  const subjectSelected = (event) => {
    const subject = event.target.textContent;
    window.location.href = '/new/' + encodeURIComponent(subject.toLowerCase());
  }

  if (isLoading) {
    return (
      <div className="subjects">
        <div className="loading">
          <div id="ldsEllipsis" className="lds-ellipsis"><div></div><div></div><div></div><div></div></div>
        </div>
      </div>
    )
  } else {
    return (
      <div className="subjects">
        <div className="instruction">Choose a subject</div>
        <div className="subject-cloud">
          {subjects && subjects.length && subjects.map((subject, index) => (
            <button key={index} className="subject-button" onClick={subjectSelected}>{subject.name}</button>
          ))}
        </div>
        {/*<button className="main-btn">Add a Subject</button>*/}
      </div>
    )
  }
}

export default SubjectCloud
