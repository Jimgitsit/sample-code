/**
 * @module rules
 * @author Jim McGowen
 */
const { addDoc } = require('../../firestore')

/**
 * Action to send an email. 
 * 
 * params:
 * {
 *   to: {string},
 *   from: {string}, (required)
 *   cc: {string},
 *   bcc: {string},
 *   replyTo: {string},
 *   subject: {string}, (required)
 *   htmlBody: {string},
 *   textBody: {string}
 *   metadata: {},
 *   attachments: [],
 *   // For templates:
 *   templateId: {string}
 *   templateAlias: {string},
 *   templateModel: {string}
 * }
 * 
 * @param {object} params
 */
module.exports.sendEmail = async (params) => {
  console.log('Called sendEmail with params: ', params)

  const emailDoc = {
    direction: 'outbound',
    messageType: 'email',
    status: 'new',
    emailData: {
      ...(params.to && { To: params.to }),
      ...(params.from && { From: params.from }),
      ...(params.cc && { Cc: params.cc }),
      ...(params.bcc && { Bcc: params.bcc }),
      ...(params.replyTo && { ReplyTo: params.replyTo }),
      ...(params.subject && { Subject: params.subject }),
      ...(params.htmlBody && { HtmlBody: params.htmlBody }),
      ...(params.textBody && { TextBody: params.textBody }),
      ...(params.metadata && { Metadata: params.metadata }),
      ...(params.attachments && { Attachments: params.attachments }),
      ...(params.templateId && { TemplateId: params.templateId }),
      ...(params.templateAlias && { TemplateAlias: params.templateAlias }),
      ...(params.templateModel && { TemplateModel: params.templateModel })
    }
  }

  await addDoc('messages', emailDoc)
}
