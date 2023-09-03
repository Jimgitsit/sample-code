import {
  ChatCompletionRequestMessage,
  Configuration,
  CreateChatCompletionResponse,
  OpenAIApi
} from 'openai'
import {AxiosError, AxiosResponse} from "axios";
//import dotenv from 'dotenv'
//dotenv.config()
import 'dotenv/config'

export interface Message {
  type: string,
  msg: string
}

export interface CompletionResponse {
  error: string,
  completionText: string
  completion: AxiosResponse<CreateChatCompletionResponse> | null
}

/**
 * Wrapper for the OpenAI API. Contains optional pre-training prompt and history for continuous context.
 */
export default class OpenAIEx {
  openai: OpenAIApi | null = null
  gptModel: string = "gpt-3.5-turbo"
  history: Message[] = []
  systemPrompt = ""
  
  /**
   * Initializes the OpenAI API with the API key from the environment.
   */
  constructor() {
    const configuration = new Configuration({
      apiKey: process.env.OPENAI_API_KEY,
    })
    this.openai = new OpenAIApi(configuration)
  }
  
  /**
   * Sets the initial pre-training prompt to send to OpenAI.
   * @param prompt
   */
  setInitPrompt = (prompt: string) => {
    this.systemPrompt = prompt
  }
  
  /**
   * Sets the model to use with openai. Default is "gpt-3.5-turbo".
   * @param model
   */
  setModel = (model: string) => {
    this.gptModel = model
  }
  
  /**
   * Gets a completion from OpenAI. The entire history is sent for context.
   * @param newPrompt The new prompt to append to the history.
   * @param history Optional history of prompts and completions. If not supplied, the internal history is used.
   */
  getCompletion = async (newPrompt: string, history: Message[] | null = null): Promise<CompletionResponse> => {
    
    if (history !== null) {
      this.history = history
    }
    
    const messages = this.formatMessages(newPrompt)
    let completion = null
    
    if (this.openai === null)
      throw new Error("OpenAI is not initialized.")
    
    try {
      completion = await this.openai.createChatCompletion({
        model: this.gptModel,
        messages: messages as ChatCompletionRequestMessage[],
        temperature: 0,
        max_tokens: 500
      })
      
      const completionText = completion.data.choices[0].message?.content?.replace(/^"+/, '').replace(/"+$/, '')
      if (completionText !== undefined) {
        // Add the new prompt to the history
        this.history.push({type: 'user', msg: newPrompt})
        this.history.push({type: 'agent', msg: completionText})
        return {
          error: '',
          completionText: completionText,
          completion: {status: completion.status, statusText: completion.statusText, data: completion.data} as AxiosResponse<CreateChatCompletionResponse>,
        }
      } else {
        return {
          error: 'Error: Did not receive completion text.',
          completionText: '',
          completion: null,
        }
      }
    } catch (error) {
      console.log("error: ", error)
      console.log("completion: ", completion)
      let msg = 'Something bad happened.'
      if (error instanceof AxiosError && error.response !== undefined) {
        msg += ' ' + error.message + error.response.statusText
      }
      return {
        error: msg,
        completionText: '',
        completion: {status: completion?.status, statusText: completion?.statusText, data: completion?.data} as AxiosResponse<CreateChatCompletionResponse>,
      }
    }
  }
  
  /**
   * Assembles the messages to send to OpenAI from the inti prompt and history.
   * @param newPrompt A new prompt to send to append to the messages prior to completion.
   */
  private formatMessages = (newPrompt: string) => {
    const messages = []
    
    // Add the system prompt
    messages.push({role: "system", content: this.systemPrompt})
    
    // Add the history
    for (const msg of this.history) {
      msg.type === 'user' ? messages.push({role: "user", content: msg.msg}) : null
      msg.type === 'agent' ? messages.push({role: "assistant", content: msg.msg}) : null
    }
    
    // Add the new prompt
    messages.push({role: "user", content: newPrompt})
    return messages
  }

  // TODO: Streaming version of getCompletion
  /*
  getCompletionStream = async (newPrompt: string): Promise<string> => {
    const messages = getRequestMessages(newPrompt)
    
    const completion = await openai.createChatCompletion({
      model: gptModel,
      messages: messages as ChatCompletionRequestMessage[],
      temperature: 0,
      max_tokens: 500,
      stream: true
    })
    
    /*
    const response = await openai.createCompletion({
      model: "text-davinci-003",
      prompt: "The following is a conversation with an AI assistant. The assistant is helpful, creative, clever, and very friendly.\n\nHuman: Hello, who are you?\nAI: I am an AI created by OpenAI. How can I help you today?\nHuman: ",
      temperature: 0.9,
      max_tokens: 150,
      top_p: 1,
      frequency_penalty: 0,
      presence_penalty: 0.6,
      stop: [" Human:", " AI:"],
    })
    */
  
    /*
    return new Promise((resolve) => {
      let result = ""
      completionText.data.on("data", (data) => {
        const lines = data
          ?.toString()
          ?.split("\n")
          .filter((line) => line.trim() !== "")
        for (const line of lines) {
          const message = line.replace(/^data: /, "")
          if (message === "[DONE]") {
            resolve(result)
          } else {
            let token
            try {
              token = JSON.parse(message)?.choices?.[0]?.text
            } catch {
              console.log("ERROR", json)
            }
            result += token
            if (token) {
              callback(token)
            }
          }
        }
      })
    })
    *
  }
  */
}

