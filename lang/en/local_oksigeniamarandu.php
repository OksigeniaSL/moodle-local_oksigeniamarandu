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

$string['contacts'] = 'Numbers on WhatsApp';
$string['contacts:name'] = 'Name on WhatsApp';
$string['contacts:none'] = 'No number has joined yet. People join by writing {$a} to the number of the site, with the link or the QR code a plugin gives them.';
$string['contacts:phone'] = 'Number';
$string['contacts:since'] = 'Since';
$string['contacts:standsfor'] = 'Stands for';
$string['contacts:status'] = 'Status';
$string['contacts:unlinked'] = 'Not linked to anyone yet';
$string['error:language'] = 'A language code such as en_US or es.';
$string['error:meta'] = 'Meta did not take the message (code {$a->code}): {$a->message}';
$string['error:network'] = 'Meta could not be reached: {$a}';
$string['error:notconfigured'] = 'The WhatsApp account of the site is not connected yet.';
$string['error:parameter'] = 'Meta does not recognise some of the details: check the phone number ID and the template. ({$a->message})';
$string['error:phone'] = 'Write the number with + and the country code: +34 6xx xxx xxx for Spain.';
$string['error:recipientnotallowed'] = 'This phone is not on the list of the test number. Add it in Meta (API Setup, "To") and confirm the code it receives.';
$string['error:templatename'] = 'Only lower-case letters, digits and _, as in Meta.';
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
$string['testmessage'] = 'Send a test message';
$string['testmessage:gotosettings'] = 'Connect the account';
$string['testmessage:intro'] = 'Send the welcome template of Meta to your phone to see that the connection works. With the test number of Meta, it only reaches the phones added in Meta (five at most).';
$string['testmessage:language'] = 'Template language';
$string['testmessage:language_help'] = 'The language code of the template in Meta, such as en_US or es.';
$string['testmessage:notconfigured'] = 'Connect the WhatsApp account of the site first.';
$string['testmessage:phone'] = 'Your mobile';
$string['testmessage:phone_help'] = 'With + and the country code: +34 6xx xxx xxx for Spain.';
$string['testmessage:send'] = 'Send';
$string['testmessage:sent'] = 'Sent. Meta has taken the message (id {$a}); it should reach your phone in a few seconds.';
$string['testmessage:template'] = 'Template';
$string['testmessage:template_help'] = 'The name of an approved template in Meta. hello_world comes with every new account.';
