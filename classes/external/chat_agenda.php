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

namespace local_oksigeniamarandu\external;

use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\agenda;

/**
 * The address book of the conversations, and the star of a person on a contact: the favourites of whoever looks and
 * the contacts of the centre by what they are.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_agenda extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'star' => new external_value(PARAM_INT, 'A contact to star or unstar; 0 to only read', VALUE_DEFAULT, 0),
            'on' => new external_value(PARAM_BOOL, 'With the star', VALUE_DEFAULT, true),
        ]);
    }

    /**
     * The address book, after the star if there is one.
     *
     * @param int $star A contact.
     * @param bool $on With the star.
     * @return array
     */
    public static function execute(int $star = 0, bool $on = true): array {
        $params = self::validate_parameters(self::execute_parameters(), ['star' => $star, 'on' => $on]);
        $viewer = self::viewer();
        if ($params['star'] > 0) {
            agenda::star($viewer->userid, $params['star'], $params['on']);
        }
        return agenda::for_viewer($viewer);
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $contact = new external_single_structure([
            'contactid' => new external_value(PARAM_INT, 'The contact, in Chatwoot'),
            'name' => new external_value(PARAM_TEXT, 'Its name'),
            'phone' => new external_value(PARAM_RAW, 'Its number, in digits'),
            'kind' => new external_value(PARAM_ALPHA, 'family, applicant, both, staff, supplier, alumni or unknown'),
            'line' => new external_value(PARAM_TEXT, 'Who it is, in a line'),
            'rings' => new external_multiple_structure(new external_single_structure([
                'color' => new external_value(PARAM_RAW, 'The colour'),
                'dark' => new external_value(PARAM_RAW, 'The colour on a dark page', VALUE_DEFAULT, ''),
                'dashed' => new external_value(PARAM_BOOL, 'Whether it is not inside yet'),
                'name' => new external_value(PARAM_TEXT, 'The child', VALUE_DEFAULT, ''),
            ])),
            'conversation' => new external_value(PARAM_INT, 'Its latest conversation the person sees; 0 if none'),
            'lastactivity' => new external_value(PARAM_INT, 'When it last moved'),
            'favourite' => new external_value(PARAM_BOOL, 'Whether the person starred it'),
        ]);
        return new external_single_structure([
            'favourites' => new external_multiple_structure($contact),
            'groups' => new external_multiple_structure(new external_single_structure([
                'kind' => new external_value(PARAM_ALPHA, 'supplier, staff or alumni'),
                'contacts' => new external_multiple_structure($contact),
            ])),
        ]);
    }
}
