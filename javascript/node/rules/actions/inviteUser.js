/**
 * @module rules
 * @author Jim McGowen
 */
'use strict'
const { admin } = require('../../admin')
const { addDoc } = require('../../firestore')

/**
 * Sends an email invitation (via the messages collection).
 *
 * Makes use of 'user-invitation' template in Postmark.
 *
 * Params should be an object with the following properties:
 * fromName, fromEmail, memberName, memberEmail, orgName, domain
 *
 *
 * @param {object} params
 */
module.exports.inviteUser = async (params) => {
  const { fromName, fromEmail, memberName, memberEmail, orgName, domain, position } = params
  if (!fromName || !fromEmail || !memberName || !memberEmail || !orgName || !domain || !position) {
    console.error('Missing required parameter in inviteUser action. Required params: fromName, fromEmail, memberName, memberEmail, orgName, domain')
  }

  // Note: The domain must be authorized in the Firebase console
  // TODO: Encode the email address?
  const continueUrl = `https://${domain}/invite?email=${memberEmail}?position=${position}`

  // TODO: From the docs:
  //       "Do not pass the user’s email in the redirect URL parameters and re-use it as this may enable session injections."
  //       Only alternative I can think of is to ask the user for their email on the invite or onboarding page and then
  //       call signInWithEmailLink.

  // Generate a link to send to the user
  const actionCodeSettings = {
    url: continueUrl,
    // handleCodeInApp must be true because the web framework is handling the link request,
    // even though the link is being generated here with the admin api.
    handleCodeInApp: true
  }
  console.error('actionCodeSettings: ', actionCodeSettings)
  const link = await admin.auth().generateSignInWithEmailLink(memberEmail, actionCodeSettings)

  // Create a new message document which will trigger the email
  const emailDoc = {
    direction: 'outbound',
    messageType: 'email',
    status: 'new',
    emailData: {
      To: memberEmail,
      From: fromEmail,
      // TODO: Get template from settings
      TemplateAlias: 'user-invitation',
      TemplateModel: {
        from_name: fromName,
        member_name: memberName,
        user_name: memberName,
        company_name: orgName,
        invite_link: link
      }
    }
  }

  await addDoc('messages', emailDoc)
}
