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
 * The webhook Chatwoot calls when something changes in a conversation: the index asks for it again at once, so the
 * pages see it in seconds. Without it, the passes of the page and of the task do the same, a little later.
 *
 * There is no login: Chatwoot calls this page. What it sends is only taken if it is signed with the secret of the
 * webhook and is recent.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\mirror;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !chatwoot::is_configured()) {
    http_response_code(404);
    die();
}

$raw = file_get_contents('php://input');
$stored = (string)get_config('local_oksigeniamarandu', 'chatwootsecret');
try {
    $secret = $stored === '' ? '' : \core\encryption::decrypt($stored);
} catch (\moodle_exception $e) {
    $secret = '';
}
$time = (string)($_SERVER['HTTP_X_CHATWOOT_TIMESTAMP'] ?? '');
$signature = (string)($_SERVER['HTTP_X_CHATWOOT_SIGNATURE'] ?? '');
if (!mirror::verify($raw, $time, $signature, $secret)) {
    http_response_code(401);
    die();
}

$payload = json_decode($raw, true);
if (is_array($payload)) {
    try {
        mirror::webhook($payload);
    } catch (\moodle_exception $e) {
        // Chatwoot does not answer now: the next pass brings it.
        debugging('Marandu: a webhook of Chatwoot could not be read: ' . $e->getMessage(), DEBUG_DEVELOPER);
    }
}
http_response_code(200);
