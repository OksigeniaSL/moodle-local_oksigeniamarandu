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
use local_oksigeniamarandu\local\chat\mirror;

/**
 * Who the contact of a conversation is, for the card beside it: their number, their other conversations and, if the
 * site has it, the card of admissions of Eguahe in a frame.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_contact extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
        ]);
    }

    /**
     * The card.
     *
     * @param int $conversation The conversation.
     * @return array
     */
    public static function execute(int $conversation): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['conversation' => $conversation]);
        $viewer = self::viewer();
        $row = self::conversation($viewer, $params['conversation']);
        $kind = $row->kind !== '' ? (string)$row->kind : 'unknown';
        $others = [];
        if ($row->contactid) {
            [$where, $wparams] = $viewer->where();
            $rows = $DB->get_records_sql(
                "SELECT c.* FROM {" . mirror::TABLE . "} c
                                           WHERE $where AND c.contactid = :contactid AND c.cwid <> :cwid
                                        ORDER BY c.lastactivity DESC",
                $wparams + ['contactid' => $row->contactid, 'cwid' => $row->cwid],
                0,
                10
            );
            foreach ($rows as $other) {
                $others[] = ['id' => (int)$other->cwid, 'status' => (string)$other->status, 'preview' => (string)$other->preview,
                    'at' => (int)$other->lastactivity];
            }
        }
        $result = ['kind' => $kind, 'title' => (string)$row->name, 'phone' => (string)$row->phone, 'line' => '',
            'children' => [], 'services' => [], 'contacts' => [], 'rows' => [], 'source' => '', 'conversations' => $others,
            'maybe' => []];
        $eguahe = \core_component::get_component_directory('local_oksigeniaeguahe');
        if ($eguahe && file_exists($eguahe . '/chat.php') && in_array($kind, ['unknown', 'applicant', 'both'])) {
            $result['panel'] = [
                'url' => (new \moodle_url('/local/oksigeniaeguahe/chat.php'))->out(false),
                'name' => (string)$row->name,
                'email' => '',
                'phone' => $row->phone === '' ? '' : '+' . $row->phone,
            ];
        }
        return $result;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'kind' => new external_value(PARAM_ALPHA, 'family, applicant, both, staff, supplier, alumni or unknown'),
            'title' => new external_value(PARAM_TEXT, 'Who it is'),
            'phone' => new external_value(PARAM_RAW, 'Their number, only digits'),
            'line' => new external_value(PARAM_TEXT, 'Who it is, in a line'),
            'children' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'The name'),
                'group' => new external_value(PARAM_TEXT, 'The group'),
                'tutor' => new external_value(PARAM_TEXT, 'The tutor'),
                'stage' => new external_single_structure([
                    'label' => new external_value(PARAM_TEXT, 'The stage'),
                    'color' => new external_value(PARAM_RAW, 'Its colour'),
                ]),
                'applying' => new external_value(PARAM_BOOL, 'Whether they ask for a place'),
            ])),
            'services' => new external_multiple_structure(new external_single_structure([
                'label' => new external_value(PARAM_TEXT, 'The service'),
                'detail' => new external_value(PARAM_TEXT, 'Its detail'),
            ])),
            'contacts' => new external_multiple_structure(new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'The name'),
                'role' => new external_value(PARAM_TEXT, 'Who they are'),
                'phone' => new external_value(PARAM_RAW, 'Their number'),
                'conversation' => new external_value(PARAM_INT, 'Their conversation, or 0'),
            ])),
            'rows' => new external_multiple_structure(new external_single_structure([
                'icon' => new external_value(PARAM_ALPHANUMEXT, 'The icon'),
                'title' => new external_value(PARAM_TEXT, 'The line'),
                'detail' => new external_value(PARAM_TEXT, 'Its detail'),
            ])),
            'source' => new external_value(PARAM_TEXT, 'Where it comes from'),
            'panel' => new external_single_structure([
                'url' => new external_value(PARAM_URL, 'The page of the card of another plugin'),
                'name' => new external_value(PARAM_TEXT, 'The name of the contact, for it'),
                'email' => new external_value(PARAM_RAW, 'Their email, for it'),
                'phone' => new external_value(PARAM_RAW, 'Their number with +, for it'),
            ], 'The card of another plugin, in a frame', VALUE_OPTIONAL),
            'conversations' => new external_multiple_structure(new external_single_structure([
                'id' => new external_value(PARAM_INT, 'The conversation'),
                'status' => new external_value(PARAM_ALPHA, 'Its state'),
                'preview' => new external_value(PARAM_RAW, 'Its last message'),
                'at' => new external_value(PARAM_INT, 'Its last movement'),
            ])),
            'maybe' => new external_multiple_structure(new external_single_structure([
                'conversation' => new external_value(PARAM_INT, 'The other conversation'),
                'name' => new external_value(PARAM_TEXT, 'Its contact'),
                'reason' => new external_value(PARAM_TEXT, 'Why they may be the same'),
            ])),
        ]);
    }
}
