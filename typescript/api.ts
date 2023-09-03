import express from 'express'
import cors from 'cors'
import OpenAIEx, {Message} from './OpenAIEx'

const app = express()
app.use(cors())
app.use(express.json())
const port = 3000

// Init global OpenAIEx instance
const openAIEx = new OpenAIEx()
//openAIEx.setModel("gpt-4")
openAIEx.setInitPrompt("I want you to quiz me on the content of the [subject]. Act as a teacher and an expert on [subject]. You will generate and ask me one question about [subject]. When I answer you will tell me if I'm correct or not. No need to repeat the answer unless you can provide more information relevant to the subject. If my answer was not correct, you will tell me what the correct answer is and why I was wrong. The question type will be either multiple choice, true or false, fill in the blank, or short answer chosen by random. Use a variety of question types. After your response you will ask another question using one of the four types chosen by random. Instead of answering, I may ask a question. If I do you will answer my question and then repeat your last quiz question. You will continue asking questions until I tell you to stop. Do not repeat questions you have already asked. Respond with markdown when appropriate. Answers should not be case sensitive. Begin with 'Great, [subject] it is! Let's begin with some questions. Feel free to interrupt at any time and ask for clarification or more information.' then ask the first question. Do not provide a summary of the [subject]. Never ask if I would like another question, just ask the next question. Use a different question type from the last question. The first user prompt will be the [subject].")

app.post('/getCompletion', async (req, res) => {
  const newPrompt: string = req.body.newPrompt
  const history: Message[] | null = typeof req.body.history === 'string' ? JSON.parse(req.body.history) as Message[] : req.body.history
  const completionResponse = await openAIEx.getCompletion(newPrompt, history)
  res.send(JSON.stringify(completionResponse))
})

app.listen(port, () => {
  console.log(`Server listening at http://localhost:${port}`)
})
