/**
 * @author Jim McGowen
 * 
 * An implementation of an pre-trained OpenAI chatbot.
 */
import { Configuration, OpenAIApi } from 'openai';
import 'dotenv/config';
import 'regenerator-runtime/runtime'

const history = [];
const initPrompt = `[redacted]`;

const configuration = new Configuration({});

delete configuration.baseOptions.headers['User-Agent'];

const openai = new OpenAIApi(configuration);

// TODO: Move to config
const gptModel = "gpt-3.5-turbo";
const maxTokens = 4096;
const tokenCost = {'input': 0.0015, 'output': 0.002};
// const gptModel = "gpt-3.5-turbo-16k";
// const maxTokens = 16384;
// const tokenCost = {'input': 0.003, 'output': 0.004};
// const gptModel = "gpt-4";
// const maxTokens = 8192;
// const tokenCost = {'input': 0.03, 'output': 0.06};
// const gptModel = "gpt-4-32k";
// const maxTokens = 32768;
// const tokenCost = {'input': 0.06, 'output': 0.12};

const maxCompletionTokenCount = 500;
let tokensRunningTotal = 0;
let costRunningTotal = 0;

const muthur = document.getElementById('muthur-interface');
const outputWrapper = document.getElementById('output-wrapper');
const input = document.getElementById('input');

const addOutputLine = (text, outputDiv) => {
  const textSpan = outputDiv.getElementsByClassName('text')[0];
  const flashSpan = outputDiv.getElementsByClassName('letter-flash')[0];

  const charDelay = 45;
  const chars = text.split('');
  chars.forEach((char, index) => {
    setTimeout(() => {
      flashSpan.textContent = char;
      textSpan.textContent += char;
    }, index * charDelay);
  });

  const fullDelay = (text.length - 1) * charDelay;
  setTimeout(() => {
    flashSpan.textContent = ''
  }, fullDelay);
}

addEventListener('load', function() {
  // TODO: Doesn't work on load. Find workaround.
  //playSound('loading-screen');

  input.hidden = true;
  let flash = document.createElement('div');
  flash.classList.add('flash-container');
  flash.innerHTML = '<span class="flash">'
  outputWrapper.appendChild(flash);

  setTimeout(function() {
    outputWrapper.removeChild(flash);

    let output = document.createElement('div');
    output.classList.add('output');
    output.classList.add('generated-output');
    output.classList.add('blurry-text');
    output.textContent = 'INTERFACE 2037 READY FOR INQUIRY'
    outputWrapper.appendChild(output);

    input.hidden = false;
    input.focus();
  }, 250);
});

document.addEventListener('click', function() {
  playSound('beep-user');
  input.focus();
});

input.addEventListener('keydown', async function(event) {
  if (!event.key.match(/^(Tab|Shift|Control|Alt|Option|Command|Function|Arrow|Escape)$/)) {
    playSound('typing');
  }

  if (event.key === 'Enter') {
    event.preventDefault();

    playSound('beep-system');

    // Add user input to output
    const inputVal = input.value.trim();
    if (inputVal === '') {
      return;
    }

    input.value = '';
    let outputUser = document.createElement('div');
    outputUser.classList.add('output');
    outputUser.classList.add('user-output');
    outputUser.classList.add('blurry-text');
    outputUser.textContent = inputVal;
    outputWrapper.appendChild(outputUser);

    muthur.scrollTop = muthur.scrollHeight;

    const completionText = await getCompletion(inputVal);

    let prevDelay = 0;
    let runningDelay = 0;

    const flashDelay = 250;
    const charDelay = 45;
    const lines = completionText.split('\n');
    lines.forEach((line) => {
      runningDelay += prevDelay
      // number between 250 and 500
      const interLineDelay = Math.floor(Math.random() * (1000 - 250 + 1) + 250);
      prevDelay = ((line.length - 1) * charDelay) + interLineDelay;
      setTimeout(() => {
        let flash = null;
        if (line.length > 0) {
          // Do flash animation
          input.hidden = true;
          // <div className="flash-container"><span className="flash"></span></div>
          flash = document.createElement('div');
          flash.classList.add('flash-container');
          flash.innerHTML = '<span class="flash">'
          outputWrapper.appendChild(flash);
        }

        muthur.scrollTop = muthur.scrollHeight;
        setTimeout(function () {
          if (flash !== null) {
            outputWrapper.removeChild(flash);
          }

          // Add output
          // <div className="output generated-output blurry-text"><span className="text">asdfasdf</span><span className="letter-flash">E</span></div>
          let output = document.createElement('div');
          output.classList.add('output');
          output.classList.add('generated-output');
          output.classList.add('blurry-text');
          let textSpan = document.createElement('span');
          textSpan.classList.add('text');
          let textFlash = document.createElement('span');
          textFlash.classList.add('letter-flash');
          output.appendChild(textSpan);
          output.appendChild(textFlash);
          outputWrapper.appendChild(output);
          //addOutputText("PROCESSING INPUT...", output);
          addOutputLine(line, output);

          input.hidden = false;
          input.focus();

          muthur.scrollTop = muthur.scrollHeight;
        },  flashDelay);
      }, runningDelay);
    });
  }
});

const playSound = (type) => {
  let audio = document.querySelector('audio#' + type);
  audio.currentTime = 0;
  audio.play();
}

const getCompletion = async (newPrompt) => {
  const messages = [];
  let charCount = 0;

  messages.push({ role: "system", content: initPrompt });
  charCount += initPrompt.length;
  for (const [prompt, completion] of history) {
    messages.push({ role: "user", content: prompt });
    charCount += prompt.length;
    messages.push({ role: "assistant", content: completion });
    charCount += completion.length;
  }
  messages.push({ role: "user", content: newPrompt });
  charCount += newPrompt.length;

  try {
    // Count tokens before sending
    let tokenCont = getApproximateTokenCount(charCount);
    console.log("aprox. token count: ", tokenCont + '/' + maxTokens);

    // Check for over max token count
    while (tokenCont + maxCompletionTokenCount > maxTokens) {
      // Remove first prompt and completion from messages
      if (messages.length >= 3) {
        messages.splice(1, 1);
        messages.splice(1, 1);
      } else {
        console.error("Something is seriously amiss with messages.")
        return "Critical system error\nInitiating self-destruct sequence\nHave a nice day";
      }
      // Remove the oldest element in history
      const [prompt, completion] = history.shift();

      // Recalculate token count
      charCount -= prompt.length + completion.length;
      tokenCont = getApproximateTokenCount(charCount);
      console.log("modified aprox. token count: ", tokenCont + '/' + maxTokens);
    }

    // Get the completion
    const completion = await openai.createChatCompletion({
      model: gptModel,
      messages: messages,
      max_tokens: maxCompletionTokenCount,
      temperature: 0.8,
    });

    console.log(completion.data.usage);
    tokensRunningTotal += completion.data.usage.total_tokens;
    console.log("Running total token count: ", tokensRunningTotal);

    costRunningTotal += (completion.data.usage.prompt_tokens / 1000 * tokenCost.input)
                      + (completion.data.usage.completion_tokens / 1000 * tokenCost.output);
    console.log("Running total cost: ", costRunningTotal);

    const completionText = completion.data.choices[0].message.content.replace(/^"+/, '').replace(/"+$/, '');
    history.push([newPrompt, completionText]);

    return completionText;
  } catch (error) {
    console.log("error: ", error);
    return "System malfunction. Please try again later.";
  }
}

/**
 * Return aproxamate token count for a given character count. Based on answer here:
 * https://stackoverflow.com/a/76416463/1385227
 *
 * TODO: Not sure how accurate this is.
 *
 * @param charCount
 * @returns {number}
 */
const getApproximateTokenCount = (charCount) => {
  // #tokens <? #characters * (1/e) + safety_margin
  // where 1/e = 0.36787944117144232159552377016146. an adequate choice for safety_margin seems to be 2.
  //return Math.floor(charCount * 0.36787944117144232159552377016146) + 2;
  return Math.floor(charCount / 4);
}
