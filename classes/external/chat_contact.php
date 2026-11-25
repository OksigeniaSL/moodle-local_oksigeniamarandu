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
use local_oksigeniamarandu\local\chat\family;
use local_oksigeniamarandu\local\chat\held;
use local_oksigeniamarandu\local\chat\marks;
use local_oksigeniamarandu\local\consent;
use local_oksigeniamarandu\local\joinask;
use local_oksigeniamarandu\local\chat\mirror;
use local_oksigeniamarandu\local\chat\starter;

/**
 * Who the contact of a conversation is, for the card beside it: their number, their other conversations and, if the
 * site has it, the card of admissions of Eguahe in a frame. Also of a number without a conversation, for whoever is
 * about to start one.
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
            'conversation' => new external_value(PARAM_INT, 'The conversation, or 0 for a number'),
            'phone' => new external_value(PARAM_RAW_TRIMMED, 'A number, with no conversation', VALUE_DEFAULT, ''),
            'name' => new external_value(PARAM_TEXT, 'Its name, if known', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * The card.
     *
     * @param int $conversation The conversation, or 0.
     * @param string $phone A number, when there is no conversation.
     * @param string $name Its name, if known.
     * @return array
     */
    public static function execute(int $conversation, string $phone = '', string $name = ''): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'phone' => $phone, 'name' => $name]
        );
        $viewer = self::viewer();
        if ($params['conversation']) {
            $row = self::conversation($viewer, $params['conversation']);
        } else {
            $number = starter::number($params['phone']);
            if ($number === null) {
                throw new \moodle_exception('chat:start:badnumber', 'local_oksigeniamarandu');
            }
            $row = (object)['cwid' => 0, 'contactid' => 0, 'kind' => '', 'name' => $params['name'], 'phone' => $number];
        }
        // Who it is: the marks of the team, and a family if the records have the number, whatever else it is.
        $marks = marks::parse((string)$row->kind);
        $family = $row->phone !== '' ? family::of_phone((string)$row->phone) : null;
        $auto = $family && !in_array('family', $marks, true);
        if ($auto) {
            $marks[] = 'family';
        }
        $kind = marks::face($marks, (string)($row->kindface ?? ''), (bool)$family);
        $others = [];
        if ($row->contactid || $row->cwid === 0) {
            [$where, $wparams] = $viewer->where();
            $same = $row->contactid ? 'c.contactid = :contactid' : 'c.phone = :phone';
            $rows = $DB->get_records_sql(
                "SELECT c.* FROM {" . mirror::TABLE . "} c
                                           WHERE $where AND $same AND c.cwid <> :cwid
                                        ORDER BY c.lastactivity DESC",
                $wparams + ['contactid' => $row->contactid, 'phone' => $row->phone, 'cwid' => $row->cwid],
                0,
                10
            );
            foreach ($rows as $other) {
                $others[] = ['id' => (int)$other->cwid, 'status' => (string)$other->status, 'preview' => (string)$other->preview,
                    'at' => (int)$other->lastactivity];
            }
        }
        $result = ['kind' => $kind, 'marks' => $marks, 'face' => (string)($row->kindface ?? ''), 'notes' => marks::notes_of($row),
            'familyauto' => $auto, 'familyfile' => (bool)$family, 'title' => (string)$row->name, 'phone' => (string)$row->phone,
            'line' => '', 'children' => [], 'services' => [], 'contacts' => [], 'rows' => [], 'source' => '',
            'conversations' => $others, 'maybe' => [], 'optout' => self::optout((string)$row->phone), 'held' => []];
        if ($family) {
            $result['title'] = $family['title'] !== '' ? $family['title'] : (string)$row->name;
            $result['line'] = $family['line'];
            foreach ($family['children'] as $child) {
                $result['children'][] = ['name' => trim($child['name'] . ' ' . $child['lastname']), 'group' => $child['group'],
                    'tutor' => '', 'stage' => $child['stage'] ?? ['label' => '', 'color' => '', 'dark' => ''], 'applying' => false];
            }
            [$seeswhere, $seesparams] = $viewer->where();
            // The adults of the family and their numbers, only when the centre checked this one; beside them, never
            // instead of them, the students of age with a mobile, when the site writes to them.
            $people = $family['verified'] ? $family['adults'] : [];
            foreach ($family['verified'] ? $family['students'] ?? [] : [] as $student) {
                $people[] = ['name' => $student['name'], 'phone' => $student['phone'], 'student' => true];
            }
            foreach ($people as $adult) {
                // The newest conversation of that adult that this person can open.
                $conversation = $adult['phone'] === '' ? false : $DB->get_field_sql(
                    "SELECT MAX(c.cwid) FROM {" . mirror::TABLE . "} c WHERE c.phone = :phone AND $seeswhere",
                    ['phone' => $adult['phone']] + $seesparams
                );
                $student = !empty($adult['student']);
                $result['contacts'][] = ['name' => $adult['name'],
                    'role' => $student ? get_string('chat:family:adultstudent', 'local_oksigeniamarandu') : '',
                    'phone' => $adult['phone'], 'conversation' => (int)$conversation, 'student' => $student];
            }
            // Who of the team already attends this family in another conversation.
            if ($row->cwid) {
                $result['held'] = held::elsewhere($viewer, $row);
            }
            $result['source'] = get_string(
                $family['verified'] ? 'chat:family:source' : 'chat:family:sourcehint',
                'local_oksigeniamarandu'
            );
        }
        // Admissions, for a number without a family file that is not marked as something else only.
        if (self::admissions($viewer->userid) && !$family && (!$marks || in_array('family', $marks, true))) {
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
     * What this number said no to, and the trail: so that whoever writes to it knows, and since when.
     *
     * @param string $phone The number, in digits.
     * @return array all (since when, or 0), scopes (scope, name, since) and trail (action, source, scope, name, time).
     */
    protected static function optout(string $phone): array {
        global $DB;
        $none = ['all' => 0, 'scopes' => [], 'trail' => []];
        $contact = $phone === '' ? null : $DB->get_record(consent::CONTACTS, ['phone' => $phone]);
        $asked = $phone === '' ? [] : joinask::asked_to($phone);
        if (!$contact && !$asked) {
            return $none;
        }
        $name = function (string $scope): string {
            if ($scope === 'all') {
                return '';
            }
            return get_string_manager()->string_exists('marandu:label', $scope) ? get_string('marandu:label', $scope) : $scope;
        };
        $now = $contact ? consent::optout_now($contact) : $none;
        $scopes = [];
        foreach ($now['scopes'] as $scope => $since) {
            $scopes[] = ['scope' => (string)$scope, 'name' => $name((string)$scope), 'since' => $since];
        }
        $trail = array_map(function ($step) use ($name) {
            return $step + ['name' => $name($step['scope'])];
        }, array_merge($contact ? consent::trail((int)$contact->id, 8) : [], $asked));
        usort($trail, function ($a, $b) {
            return $b['time'] <=> $a['time'];
        });
        $trail = array_slice($trail, 0, 8);
        return ['all' => $now['all'], 'scopes' => $scopes, 'trail' => $trail];
    }

    /**
     * Whether the site has the admissions of Eguahe and the person is in its team: only then its card goes beside the
     * conversation (to anyone else it would only say they are not in the team).
     *
     * @param int $userid The person.
     * @return bool
     */
    protected static function admissions(int $userid): bool {
        $dir = \core_component::get_component_directory('local_oksigeniaeguahe');
        $centres = '\\local_oksigeniaeguahe\\local\\centres';
        if (!$dir || !file_exists($dir . '/chat.php') || !method_exists($centres, 'for_user')) {
            return false;
        }
        foreach ($centres::for_user($userid) as $centre) {
            if (!method_exists($centres, 'is_practice') || !$centres::is_practice((int)$centre->id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $notes = [];
        foreach (array_keys(marks::NOTES) as $key) {
            $notes[$key] = new external_value(PARAM_TEXT, 'A line of its marks', VALUE_DEFAULT, '');
        }
        return new external_single_structure([
            'kind' => new external_value(PARAM_ALPHA, 'Its face: family, applicant, both, staff, supplier, alumni, other, unknown'),
            'marks' => new external_multiple_structure(new external_value(PARAM_ALPHA, 'A mark'), 'Its marks, in their order'),
            'face' => new external_value(PARAM_ALPHA, 'The mark whose face it has, if chosen'),
            'notes' => new external_single_structure($notes),
            'familyauto' => new external_value(PARAM_BOOL, 'Whether family comes from the records, without marking it'),
            'familyfile' => new external_value(PARAM_BOOL, 'Whether the family records have the number'),
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
                    'dark' => new external_value(PARAM_RAW, 'Its colour on a dark page', VALUE_DEFAULT, ''),
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
                'student' => new external_value(PARAM_BOOL, 'Whether it is a student of age, by the adults', VALUE_DEFAULT, false),
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
            'optout' => new external_single_structure([
                'all' => new external_value(PARAM_INT, 'Since when it wants nothing from the centre; 0 if it does'),
                'scopes' => new external_multiple_structure(new external_single_structure([
                    'scope' => new external_value(PARAM_COMPONENT, 'The plugin whose messages it does not want'),
                    'name' => new external_value(PARAM_TEXT, 'Its name'),
                    'since' => new external_value(PARAM_INT, 'Since when'),
                ])),
                'trail' => new external_multiple_structure(new external_single_structure([
                    'action' => new external_value(PARAM_ALPHA, 'optin, optout, permission, asked or askedback'),
                    'source' => new external_value(PARAM_TEXT, 'Where it was asked'),
                    'scope' => new external_value(PARAM_RAW, 'all, or a plugin'),
                    'name' => new external_value(PARAM_TEXT, 'The name of the plugin, if any'),
                    'time' => new external_value(PARAM_INT, 'When'),
                ])),
            ], 'What this number said no to, and the trail', VALUE_DEFAULT, ['all' => 0, 'scopes' => [], 'trail' => []]),
            'held' => new external_multiple_structure(new external_single_structure([
                'conversation' => new external_value(PARAM_INT, 'The other conversation of the family'),
                'status' => new external_value(PARAM_ALPHA, 'open, pending or snoozed'),
                'teamid' => new external_value(PARAM_INT, 'Its department, or 0'),
                'teamname' => new external_value(PARAM_TEXT, 'The name of its department, or empty'),
                'agentid' => new external_value(PARAM_INT, 'Who has it, in Chatwoot'),
                'name' => new external_value(PARAM_TEXT, 'Their name'),
                'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
                'adult' => new external_value(PARAM_TEXT, 'With whom of the family'),
                'phone' => new external_value(PARAM_RAW, 'Their number, only digits'),
                'preview' => new external_value(PARAM_RAW, 'Its last message, if whoever looks sees it'),
                'at' => new external_value(PARAM_INT, 'When, if whoever looks sees it'),
                'sees' => new external_value(PARAM_BOOL, 'Whether whoever looks can open it'),
            ]), 'Who of the team already attends this family in another conversation', VALUE_DEFAULT, []),
        ]);
    }
}
