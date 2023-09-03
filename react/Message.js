import React from "react";
import ReactDOM from "react-dom";

const make = 'Ford';
const model = 'Mustang';
const car = { make, model };
console.log(car);

class Message extends React.Component {
  state = {
    display: "none"
  }

  aClicked = (event) => {
    console.log("visible: ", this.state.visible)
    if (this.state.display === "block") {
      this.setState({display: "none"});
    } else {
      this.setState({display: "block"});
    }
    console.log('click');
  };

  render() {
    return (
      <React.Fragment>
        <a href="#" onClick={this.aClicked}>Want to buy a new car?</a>
        <p style={{display: this.state.display}}>Call +11 22 33 44 now!</p>
      </React.Fragment>
    );
  }
}

document.body.innerHTML = "<div id='root'></div>";
const root = ReactDOM.createRoot(document.getElementById("root"));

root.render(<Message />);
const rootElement = document.getElementById("root");

setTimeout(() => {
  console.log("Before click: " + rootElement.innerHTML);

  document.querySelector("a").click();
  setTimeout(() => {
    console.log("After click: " + rootElement.innerHTML);
  });
});

export default Message;