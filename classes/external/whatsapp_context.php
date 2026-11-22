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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\inbox;

/**
 * What the site knows of a WhatsApp number, for the shared inbox that gets its messages too (local\inbox).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class whatsapp_context extends external_api {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'phone' => new external_value(PARAM_RAW_TRIMMED, 'The number with its country code; a + and spaces are fine'),
            'messageid' => new external_value(
                PARAM_RAW_TRIMMED,
                'The id Meta gave to a message of that number (wamid)',
                VALUE_DEFAULT,
                ''
            ),
            'days' => new external_value(PARAM_INT, 'How many days back the last notice is looked for', VALUE_DEFAULT, 7),
        ]);
    }

    /**
     * What the site knows of the number.
     *
     * @param string $phone The number.
     * @param string $messageid A message of that number, if any.
     * @param int $days Days back for the last notice.
     * @return array
     */
    public static function execute(string $phone, string $messageid = '', int $days = 7): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['phone' => $phone, 'messageid' => $messageid, 'days' => $days]
        );
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/oksigeniamarandu:inbox', $context);
        return inbox::context($params['phone'], $params['messageid'], min(90, max(1, $params['days'])));
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'phone' => new external_value(PARAM_RAW, 'The number, only its digits'),
            'status' => new external_value(PARAM_ALPHA, 'active, pending or optedout; none if the site does not know it'),
            'joined' => new external_multiple_structure(new external_single_structure([
                'component' => new external_value(PARAM_COMPONENT, 'The plugin the number joined with'),
                'area' => new external_value(PARAM_RAW, 'The kind of item of the plugin, if it has more than one'),
                'label' => new external_value(PARAM_TEXT, 'What the number stands for, as the plugin says it'),
            ]), 'The plugins the number joined with, the last first'),
            'takenby' => new external_value(PARAM_RAW, 'The plugin that answered the message or kept it; empty if none did'),
            'accounts' => new external_multiple_structure(
                new external_single_structure([
                    'system' => new external_value(PARAM_ALPHANUMEXT, 'The management program, such as cifra'),
                    'label' => new external_value(PARAM_TEXT, 'Its name, as people know it'),
                    'username' => new external_value(PARAM_TEXT, 'The username there, hidden but its last five characters'),
                    'derived' => new external_value(PARAM_BOOL, 'Whether the username follows from a rule of the centre'),
                    'email' => new external_value(PARAM_TEXT, 'Where that program sends a new password, half hidden'),
                    'active' => new external_value(PARAM_ALPHA, 'Whether the account works there: yes, no or unknown'),
                ]),
                'The accounts in the programs of the centre of the one adult of the number, from the record of families',
                VALUE_DEFAULT,
                []
            ),
            'lastnotice' => new external_single_structure([
                'component' => new external_value(PARAM_COMPONENT, 'The plugin of the notice'),
                'notice' => new external_value(PARAM_ALPHANUMEXT, 'The notice'),
                'label' => new external_value(PARAM_TEXT, 'What the notice is about, as the plugin says it'),
                'timesent' => new external_value(PARAM_INT, 'When it left the site'),
            ], 'The last notice the site sent to the number in those days, if any', VALUE_OPTIONAL),
        ]);
    }
}
