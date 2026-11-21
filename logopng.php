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
 * Keeps the PNG copy of a logo of the site drawn in SVG, which the browser of whoever administers makes (js/logo-png.js):
 * the cards are drawn on the server, which cannot read an SVG.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\cards;

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('moodle/site:config', $context);
$PAGE->set_context($context);

$which = required_param('which', PARAM_ALPHA);
$file = $_FILES['png'] ?? null;
$kept = in_array($which, ['logo', 'logocompact'], true) && $file && empty($file['error'])
    && is_uploaded_file($file['tmp_name']) && (int)$file['size'] < 4 * 1024 * 1024
    && cards::keep_logo($which, (string)file_get_contents($file['tmp_name']));
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => $kept]);
