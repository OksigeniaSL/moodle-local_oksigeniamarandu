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
 * Web services: what the site knows of a WhatsApp number, for the shared inbox that gets its messages too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_oksigeniamarandu_whatsapp_context' => [
        'classname' => \local_oksigeniamarandu\external\whatsapp_context::class,
        'description' => 'What the site knows of a WhatsApp number: whom it joined with, the last notice it got and '
            . 'whether a plugin answered one of its messages.',
        'type' => 'read',
        'capabilities' => 'local/oksigeniamarandu:inbox',
    ],
];

$services = [
    'Oksigenia Marandu, shared inbox' => [
        'functions' => ['local_oksigeniamarandu_whatsapp_context'],
        'shortname' => 'local_oksigeniamarandu_inbox',
        'restrictedusers' => 1,
        'enabled' => 1,
    ],
];
