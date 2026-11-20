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
 * In the Moodle app (and in apps made from it): no entry of its own in the menu, only the link of the notices of the
 * conversations, which opens the page inside the app with the session started instead of a browser.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$addons = [
    'local_oksigeniamarandu' => [
        'handlers' => [
            'chatlinks' => [
                // Never in the menu: its init only registers the link of the notices of the conversations.
                'delegate' => 'CoreMainMenuDelegate',
                'init' => 'mobile_init',
                'method' => 'mobile_init',
                'displaydata' => ['title' => 'pluginname', 'icon' => 'fas-comments'],
                'priority' => 0,
            ],
        ],
        'lang' => [['pluginname', 'local_oksigeniamarandu']],
    ],
];
