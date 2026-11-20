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
 * Sends a file in a conversation of the team, with its text and the signature of whoever sends it, or as a note: the
 * page posts it here (a file does not go through the web services of the page).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\chat\upload;

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('local/oksigeniamarandu:chat', $context);
$PAGE->set_context($context);

$conversation = required_param('conversation', PARAM_INT);
$text = optional_param('text', '', PARAM_RAW);
$private = optional_param('private', 0, PARAM_BOOL);

header('Content-Type: application/json; charset=utf-8');
try {
    $file = $_FILES['file'] ?? null;
    if (!$file || !empty($file['error']) || !is_uploaded_file($file['tmp_name'])) {
        throw new moodle_exception('chat:nofile', 'local_oksigeniamarandu');
    }
    echo json_encode(upload::send(
        $conversation,
        (string)$file['tmp_name'],
        clean_param((string)$file['name'], PARAM_FILE),
        (int)$file['size'],
        $text,
        $private
    ));
} catch (moodle_exception $e) {
    http_response_code(400);
    echo json_encode(['errorcode' => $e->errorcode, 'message' => $e->getMessage()]);
}
