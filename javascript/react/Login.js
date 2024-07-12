import { useState, useEffect } from 'react'
import axios from "axios"

/**
 * Login Component
 *
 * @param params { messages, errorMsg, showCreateLink, onCreateLinkClick }
 * @returns {JSX.Element}
 * @constructor
 */
const Login = (params) => {
  const [errorMsg, setErrorMsg] = useState('');

  const message = params.message || '';

  /**
   * Init error message.
   */
  useEffect(() => {
    if (params.errorMsg) {
      setErrorMsg(params.errorMsg);
    }
  }, [])

  /**
   * defaultLoginClick
   *
   * The default handler for when the login button is clicked. This can be overridden by passing a callback function
   * in the params.onCreateLinkClick.
   *
   * @param event
   * @returns {Promise<void>}
   */
  const defaultLoginClick = async (event) => {
    const email = document.querySelector('#email').value;
    const password = document.querySelector('#password').value;

    if (email && password) {
      const payload = {
        email: email,
        password: password
      };

      const result = await axios.post(process.env.REACT_APP_API_URL + '/login', payload, {
        headers: {
          'Authorization': 'Bearer ' + process.env.REACT_APP_API_ACCESS_TOKEN
        }
      });

      if (result.data.error) {
        setErrorMsg(result.data.error);
      } else if (result.data.success) {
        localStorage.setItem('player', JSON.stringify(result.data.player));
        window.location = '/dashboard';
      } else {
        setErrorMsg('Unknown error. Please try again.');
      }
    } else {
      setErrorMsg('Please enter an email and password.');
    }
  }

  const onLoginClick = params.callback || defaultLoginClick;

    // TODO: Add forgot password link

  return (
    <div className="login">
      <div className="title">Login</div>
      <div className="login-copy">{message}</div>
      <div className="input-label-wrap">
        <div className="input-label">Email</div>
      </div>
      <input id="email" className="input" type="email" onChange={() => setErrorMsg('')}/>
      <div className="input-label-wrap">
        <div className="input-label input-label-password">Password</div>
      </div>
      <input id="password" className="input" type="password" onChange={() => setErrorMsg('')}/>
      <div className="error-msg">{errorMsg}</div>
      <button className="main-btn login-btn" onClick={onLoginClick}>Login</button>
      {params.showCreateLink &&
        <div className="link-button-wrap">
          <span className="link-button login-link" onClick={params.onCreateLinkClick}>Create login</span>
        </div>
      }
    </div>
  )
}

export default Login
