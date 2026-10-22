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
$string['privacy:metadata'] = 'This version only sends a test message to a number the administrator types; it stores no personal data.';
$string['settings:accesstoken'] = 'Access token';
$string['settings:accesstoken_desc'] = 'A permanent token of a system user of the business portfolio, with the permissions whatsapp_business_messaging and whatsapp_business_management. The temporary token of API Setup lasts a few hours. It is stored encrypted.';
$string['settings:account'] = 'WhatsApp account';
$string['settings:account_intro'] = 'The site sends from its own official WhatsApp Business account. Paste here the details of the app the site has in Meta for Developers, under WhatsApp > API Setup.';
$string['settings:graphversion'] = 'Graph API version';
$string['settings:graphversion_desc'] = 'Leave it as it is unless Meta retires this version.';
$string['settings:phonenumberid'] = 'Phone number ID';
$string['settings:phonenumberid_desc'] = 'The number Meta gives to the phone of the site, under API Setup. It is not the phone number itself.';
$string['settings:wabaid'] = 'WhatsApp Business account ID';
$string['settings:wabaid_desc'] = 'Under API Setup too. It is needed to register the templates.';
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
