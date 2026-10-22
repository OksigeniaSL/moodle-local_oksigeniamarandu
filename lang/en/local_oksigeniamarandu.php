<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * English strings for local_oksigeniamarandu.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['alta:done'] = 'Done: you have joined';
$string['alta:for'] = 'For {$a}';
$string['alta:howto'] = 'WhatsApp will open with your message already written. You only have to tap Send.';
$string['alta:invalid'] = 'This link does not work';
$string['alta:invalid_help'] = 'It may be incomplete, or the site has not finished setting up WhatsApp. Ask whoever sent it to you for the link again.';
$string['alta:leave'] = 'Leave the notices';
$string['alta:leavenote'] = 'To stop them, write {$a} in that chat whenever you want. Only your mobile is kept, to send you these notices.';
$string['alta:open'] = 'Open WhatsApp';
$string['alta:openhere'] = 'Or open WhatsApp on this computer';
$string['alta:optoutnote'] = 'WhatsApp will open with {$a} written. Send it and you will get no more notices.';
$string['alta:preview'] = 'You will see:';
$string['alta:qr'] = 'QR code to join on WhatsApp';
$string['alta:reopen'] = 'Open WhatsApp again';
$string['alta:savecontact'] = 'Save the number';
$string['alta:savecontact_help'] = 'So you will recognise the notices as soon as they arrive.';
$string['alta:scan'] = 'Scan it with your phone';
$string['alta:send'] = 'Send';
$string['alta:stuck'] = 'Not changing? Check in WhatsApp that the message went out (with its sent tick). If it still does not change, ask the school office.';
$string['alta:title'] = 'Notices from {$a} on WhatsApp';
$string['alta:wait'] = 'Waiting for your message';
$string['alta:wait_help'] = 'If you have already tapped Send in WhatsApp, this changes by itself in a few seconds.';
$string['alta:yourphone'] = 'Your mobile';
$string['contacts'] = 'Numbers on WhatsApp';
$string['contacts:name'] = 'Name on WhatsApp';
$string['contacts:none'] = 'No number has joined yet. People join by writing {$a} to the number of the site, with the link or the QR code a plugin gives them.';
$string['contacts:phone'] = 'Number';
$string['contacts:since'] = 'Since';
$string['contacts:standsfor'] = 'Stands for';
$string['contacts:status'] = 'Status';
$string['contacts:unlinked'] = 'Not linked to anyone yet';
$string['error:meta'] = 'Meta did not take the message (code {$a->code}): {$a->message}';
$string['error:network'] = 'Meta could not be reached: {$a}';
$string['error:notconfigured'] = 'The WhatsApp account of the site is not connected yet.';
$string['error:parameter'] = 'Meta does not recognise some of the details: check the phone number ID and the template. ({$a->message})';
$string['error:phone'] = 'Write the number with + and the country code: +34 6xx xxx xxx for Spain.';
$string['error:recipientnotallowed'] = 'This phone is not on the list of the test number. Add it in Meta (API Setup, "To") and confirm the code it receives.';
$string['error:templatenotfound'] = 'Meta has no approved template with that name in that language.';
$string['error:token'] = 'Meta does not take the access token: it may have expired or lack permissions. Paste a permanent token of a system user.';
$string['error:unexpected'] = 'Meta answered something this version does not understand.';
$string['pluginname'] = 'Oksigenia Marandu for WhatsApp';
$string['privacy:metadata:consent'] = 'Every opt-in and opt-out of a number, kept as proof of consent.';
$string['privacy:metadata:consent:action'] = 'Whether the number opted in or out.';
$string['privacy:metadata:consent:source'] = 'How it happened: a word written on WhatsApp, a page or the administrator.';
$string['privacy:metadata:consent:timecreated'] = 'When it happened.';
$string['privacy:metadata:contact'] = 'The WhatsApp numbers that joined or left.';
$string['privacy:metadata:contact:consenttime'] = 'When the number last opted in.';
$string['privacy:metadata:contact:lastinbound'] = 'When the number last wrote to the site, which lets the site reply for 24 hours.';
$string['privacy:metadata:contact:name'] = 'The profile name WhatsApp sends with the messages.';
$string['privacy:metadata:contact:optouttime'] = 'When the number last opted out.';
$string['privacy:metadata:contact:phone'] = 'The phone number, with its country code.';
$string['privacy:metadata:contact:status'] = 'Whether the number receives notices, is waiting or has opted out.';
$string['privacy:metadata:link'] = 'Whom a number stands for: a user of the site or an item of another plugin, such as a family.';
$string['privacy:metadata:link:contactid'] = 'The number linked.';
$string['privacy:metadata:link:timeclaimed'] = 'When the number was linked.';
$string['privacy:metadata:link:userid'] = 'The user the number stands for.';
$string['privacy:metadata:whatsapp'] = 'Messages go out through the WhatsApp Business Platform of Meta, with the account of the site.';
$string['privacy:metadata:whatsapp:message'] = 'The text of the notice or the reply.';
$string['privacy:metadata:whatsapp:phone'] = 'The number that receives it.';
$string['reply:optin'] = 'Done: notices from {$a->site} will now arrive here. To stop them, just write {$a->optout}.';
$string['reply:optout'] = 'Done: no more notices from {$a->site} will reach this number. To get them again, just write {$a->optin}.';
$string['reply:unknowncode'] = 'That code is not valid or has already been used. Ask {$a->site} for a new one.';
$string['settings:accesstoken'] = 'Access token';
$string['settings:accesstoken_desc'] = 'A permanent token of a system user of the business portfolio, with the permissions whatsapp_business_messaging and whatsapp_business_management. The temporary token of API Setup lasts a few hours. It is stored encrypted.';
$string['settings:account'] = 'WhatsApp account';
$string['settings:account_intro'] = 'The site sends from its own official WhatsApp Business account. Paste here the details of the app the site has in Meta for Developers, under WhatsApp > API Setup.';
$string['settings:appsecret'] = 'App secret';
$string['settings:appsecret_desc'] = 'In Meta for Developers, the app of the site, App settings > Basic. With it the site checks that what reaches the webhook comes from Meta. It is stored encrypted.';
$string['settings:displayphone'] = 'Number of the site';
$string['settings:displayphone_desc'] = 'The WhatsApp number of the site, with + and the country code, as people would write it. It goes in the links that open WhatsApp to join.';
$string['settings:graphversion'] = 'Graph API version';
$string['settings:graphversion_desc'] = 'Leave it as it is unless Meta retires this version.';
$string['settings:keywords'] = 'Joining and leaving';
$string['settings:keywords_intro'] = 'People join by writing a word to the number of the site, and leave by writing another one. Upper or lower case and accents do not matter.';
$string['settings:optinkeywords'] = 'Words to join';
$string['settings:optinkeywords_desc'] = 'Separated by commas. The first one goes in the links and the replies.';
$string['settings:optoutkeywords'] = 'Words to leave';
$string['settings:optoutkeywords_desc'] = 'Separated by commas. The first one goes in the replies.';
$string['settings:phonenumberid'] = 'Phone number ID';
$string['settings:phonenumberid_desc'] = 'The number Meta gives to the phone of the site, under API Setup. It is not the phone number itself.';
$string['settings:verifytoken'] = 'Verify token';
$string['settings:verifytoken_desc'] = 'Paste it in Meta, next to the callback URL. The site made one when the plugin was installed; you can change it.';
$string['settings:wabaid'] = 'WhatsApp Business account ID';
$string['settings:wabaid_desc'] = 'Under API Setup too. It is needed to register the templates.';
$string['settings:webhook'] = 'What Meta sends to the site';
$string['settings:webhook_intro'] = 'In Meta for Developers, the app of the site, WhatsApp > Configuration, set this callback URL and subscribe to the field «messages»: {$a}';
$string['status:active'] = 'Receives notices';
$string['status:optedout'] = 'Opted out';
$string['status:pending'] = 'Waiting to join';
$string['wizard'] = 'Set up WhatsApp';
$string['wizard:accesstoken'] = 'Access token';
$string['wizard:accesstoken_help'] = 'It starts with EAA. Paste the permanent one: the one on that page expires in a day.';
$string['wizard:accesstoken_saved'] = 'Saved; paste another one only to change it';
$string['wizard:account:lead'] = 'Two parts: copy here three details of your app in Meta (the drawing shows where they are) and, then, two details from here into Meta.';
$string['wizard:account:title'] = 'Connect the WhatsApp of the site';
$string['wizard:appsecret'] = 'App secret';
$string['wizard:appsecret_help'] = 'In Meta, your app › App settings › Basic. With it the site knows that what arrives comes from Meta.';
$string['wizard:appsecret_saved'] = 'Saved; paste another one only to change it';
$string['wizard:callbackurl'] = 'Callback URL';
$string['wizard:callbackurl_help'] = 'The address where Meta tells Marandu what happens.';
$string['wizard:change'] = 'Change the details';
$string['wizard:check'] = 'Check the connection';
$string['wizard:checkhook'] = 'I have pasted them in Meta: check';
$string['wizard:connected'] = '<b>First part done: connected with Meta.</b> Number: {$a->number} · Name: {$a->name}.';
$string['wizard:copied'] = 'Copied';
$string['wizard:copy'] = 'Copy';
$string['wizard:done:contacts'] = 'See who has joined';
$string['wizard:done:lead'] = 'Only the most important thing is left: that families join. Each one does it from its private link, on the phone, or with a QR code.';
$string['wizard:done:settings'] = 'Other settings';
$string['wizard:done:testok'] = 'Reached {$a}';
$string['wizard:done:testpending'] = 'Sent; Meta has not confirmed yet that it arrived';
$string['wizard:done:title'] = 'Done: the WhatsApp of the site is connected';
$string['wizard:error:digits'] = 'Only digits: a long number, without spaces.';
$string['wizard:error:nosecret'] = 'Paste the app secret: without it the site cannot know what comes from Meta.';
$string['wizard:error:notoken'] = 'Paste the access token.';
$string['wizard:hookbad'] = 'Meta has not called the address yet. Check that you pressed «Verify and save» and that «messages» is subscribed.';
$string['wizard:hookok'] = 'Meta is already talking to Marandu.';
$string['wizard:meta:apisetup'] = 'API Setup';
$string['wizard:meta:phonenumberid'] = 'Phone number ID';
$string['wizard:meta:temptoken'] = 'Temporary access token';
$string['wizard:meta:wabaid'] = 'WhatsApp Business Account ID';
$string['wizard:next:done'] = 'Continue';
$string['wizard:next:test'] = 'Continue: test message';
$string['wizard:noapp'] = 'No app in Meta yet? You need one, of the WhatsApp type, in Meta for Developers.';
$string['wizard:phonenumberid'] = 'Phone number ID';
$string['wizard:phonenumberid_help'] = 'A long number; it is not the phone.';
$string['wizard:progress'] = 'Progress';
$string['wizard:step:account'] = 'Account';
$string['wizard:step:test'] = 'Test';
$string['wizard:stepof'] = 'Step {$a->num} of {$a->total} · {$a->name}';
$string['wizard:test:again'] = 'Send it again';
$string['wizard:test:english'] = 'It arrives in English: it is the test message Meta puts in every account.';
$string['wizard:test:lead'] = 'So you see that the notices really arrive. Write your mobile, send it and look at the phone.';
$string['wizard:test:nope'] = 'It has not arrived';
$string['wizard:test:nope_help'] = 'Check the number, with the country code. If you use the test number of Meta, add your mobile there (WhatsApp › API Setup › «To» › Manage phone number list) and type the code you receive. If it stays at «Sent», Meta could not deliver it: try again in a minute.';
$string['wizard:test:phone'] = 'Your mobile';
$string['wizard:test:phone_help'] = 'With + and the country code. With the test number of Meta, it only reaches the phones added there (five at most).';
$string['wizard:test:preview'] = 'What you will see on the phone';
$string['wizard:test:send'] = 'Send the test';
$string['wizard:test:title'] = 'Send yourself a test message';
$string['wizard:track:delivered'] = 'It has reached your mobile';
$string['wizard:track:failed'] = 'Meta could not deliver it:';
$string['wizard:track:replied'] = 'If you want, answer something from the phone: so you see that what families write arrives too';
$string['wizard:track:sent'] = 'Sent';
$string['wizard:verifytoken'] = 'Verify token';
$string['wizard:verifytoken_help'] = 'A key Marandu made up; you do not need to note it down.';
$string['wizard:wabaid'] = 'WhatsApp Business account ID';
$string['wizard:wabaid_help'] = 'Just below the previous one.';
$string['wizard:webhook:lead'] = 'In Meta, your app › WhatsApp › Configuration › Webhook › «Edit»: paste the first two and subscribe to «messages». The third goes the other way, from Meta to here.';
$string['wizard:webhook:title'] = 'Second part: let Meta tell Marandu what happens';
$string['wizard:where'] = 'The page of Meta for Developers, WhatsApp, API Setup, with the details marked';
$string['wizard:where:caption'] = 'In Meta for Developers, your app › WhatsApp › API Setup.';
$string['wizard:where:fold'] = 'Where they are in Meta';
$string['wizard:where:temporary'] = 'Not this one: it expires in 24 hours';
$string['wizard:where:token'] = 'The permanent token is somewhere else: Business settings › System users › Generate token.';
