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
 * A file of a conversation of the team (a photo, an audio, a document), served by the site to whoever sees that
 * conversation: so it opens inside the app too, and Chatwoot stays out of sight.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');

use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\viewer;

$conversation = required_param('c', PARAM_INT);
$messageid = required_param('m', PARAM_INT);
$index = optional_param('a', 0, PARAM_INT);
$thumb = optional_param('t', 0, PARAM_BOOL);
$download = optional_param('d', 0, PARAM_BOOL);

require_login(null, false);
$context = context_system::instance();
require_capability('local/oksigeniamarandu:chat', $context);
$viewer = viewer::for_user();
$row = $DB->get_record(mirror::TABLE, ['cwid' => $conversation]);
if (!$viewer || !$row || !$viewer->sees($row)) {
    throw new moodle_exception('chat:notyours', 'local_oksigeniamarandu');
}

// The message, among those after the one before it.
$client = directory::client();
$answer = $client->call('GET', '/conversations/' . $conversation . '/messages', ['after' => $messageid - 1]);
$message = null;
foreach ($answer['payload'] ?? [] as $one) {
    if ((int)($one['id'] ?? 0) === $messageid) {
        $message = $one;
        break;
    }
}
$attachment = $message['attachments'][$index] ?? null;
$url = $attachment ? (string)($thumb && !empty($attachment['thumb_url']) ? $attachment['thumb_url'] : $attachment['data_url']) : '';
if ($url === '') {
    send_file_not_found();
}

$dir = make_request_directory();
$path = $dir . '/file';
[$code, $type] = $client->download($url, $path);
if ($code !== 200 || !filesize($path)) {
    send_file_not_found();
}
$name = rawurldecode(basename((string)parse_url((string)$attachment['data_url'], PHP_URL_PATH))) ?: 'file';
$type = trim(strtok($type, ';')) ?: mimeinfo('type', $name);
// Pictures, audio and video open in the page; the rest is downloaded with its name.
$inline = !$download && preg_match('~^(image|audio|video)/|^application/pdf$~', $type);
send_file($path, $name, HOURSECS, 0, false, !$inline, $type);
