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
use local_oksigeniamarandu\local\chat\starter;

/**
 * Whom a person can start a conversation with: a number, or a name of the record of families, of the admissions or of
 * the contacts of the account, with the conversation each already has.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_find extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'query' => new external_value(PARAM_TEXT, 'A number, or a name of a family or of one of its children'),
        ]);
    }

    /**
     * Looks.
     *
     * @param string $query What was typed.
     * @return array
     */
    public static function execute(string $query): array {
        $params = self::validate_parameters(self::execute_parameters(), ['query' => $query]);
        $viewer = self::writer();
        return starter::find($viewer, $params['query']) + ['prefix' => starter::prefix()];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'candidates' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'Who it is'),
                'phone' => new external_value(PARAM_RAW, 'Their number, only digits'),
                'line' => new external_value(PARAM_TEXT, 'Whose family it is, in a line'),
                'rings' => new external_multiple_structure(new external_single_structure([
                    'color' => new external_value(PARAM_RAW, 'The colour'),
                    'dark' => new external_value(PARAM_RAW, 'The colour on a dark page', VALUE_DEFAULT, ''),
                    'dashed' => new external_value(PARAM_BOOL, 'Whether it is not inside yet'),
                    'name' => new external_value(PARAM_TEXT, 'The child', VALUE_DEFAULT, ''),
                ])),
                'source' => new external_value(PARAM_ALPHA, 'number, family, admissions or contact'),
                'verified' => new external_value(PARAM_BOOL, 'Whether the family vouched for the number'),
                'conversation' => new external_value(PARAM_INT, 'The last conversation of it that whoever asks sees, or 0'),
                'open' => new external_value(PARAM_BOOL, 'Whether that conversation is open'),
                'elsewhere' => new external_value(PARAM_TEXT, 'Where an open conversation of it is that whoever asks does not see'),
            ])),
            'needprefix' => new external_value(PARAM_BOOL, 'Whether the number needs its country code'),
            'prefix' => new external_value(PARAM_RAW, 'The country code of the organisation, only digits, or empty'),
        ]);
    }
}
