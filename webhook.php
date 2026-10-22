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
 * The webhook Meta calls: once to check it (GET), then with every message to the number of the site (POST).
 *
 * There is no login: Meta calls this page. What it sends is only taken if it is signed with the app secret.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\inbound;

$config = get_config('local_oksigeniamarandu');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // PHP turns hub.mode, hub.verify_token and hub.challenge into hub_mode, hub_verify_token and hub_challenge.
    $mode = optional_param('hub_mode', '', PARAM_ALPHA);
    $token = optional_param('hub_verify_token', '', PARAM_RAW_TRIMMED);
    $challenge = optional_param('hub_challenge', '', PARAM_ALPHANUMEXT);
    if ($mode === 'subscribe' && !empty($config->verifytoken) && hash_equals((string)$config->verifytoken, $token)) {
        echo $challenge;
    } else {
        http_response_code(403);
    }
    die();
}

$raw = file_get_contents('php://input');
$secret = '';
if (!empty($config->appsecret)) {
    try {
        $secret = \core\encryption::decrypt($config->appsecret);
    } catch (\moodle_exception $e) {
        $secret = '';
    }
}
if (!inbound::verify_signature($raw, $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '', $secret)) {
    http_response_code(401);
    die();
}

$payload = json_decode($raw, true);
if (is_array($payload)) {
    inbound::handle($payload);
}
// Meta only needs to know it arrived; the replies go out later, in an ad hoc task.
echo 'OK';
