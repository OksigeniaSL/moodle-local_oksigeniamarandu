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
 * The webhook of the bot of Telegram: what people write to it, the buttons of its menu, and whether they block it.
 * Telegram sends back, in a header, the secret the site gave it when connecting the bot; without it, nothing is read.
 * The answer of the bot goes in the reply itself, as Telegram allows, so it arrives at once.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\telegram;
use local_oksigeniamarandu\local\telegram_api;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !telegram::verify_secret($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) {
    http_response_code(403);
    die();
}

$update = json_decode(file_get_contents('php://input'), true);
$answer = null;
if (is_array($update)) {
    try {
        $api = telegram_api::from_config();
    } catch (api_exception $e) {
        $api = null;
    }
    $answer = telegram::handle($update, $api);
}
if ($answer) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($answer);
} else {
    echo 'OK';
}
