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
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\chat\chatwoot;
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * What a person does with a conversation: keep it, pass it to a department or to a person, resolve it, open it again,
 * mark it read, or say who the contact is.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_action extends chat_base {
    /** The kinds a person can mark a contact with. */
    private const KINDS = ['staff', 'supplier', 'alumni', 'none'];

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'action' => new external_value(PARAM_ALPHA, 'take, resolve, reopen, read, team, person or kind'),
            'target' => new external_value(PARAM_INT, 'The department or the person', VALUE_DEFAULT, 0),
            'value' => new external_value(PARAM_ALPHA, 'The kind of contact', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Does it.
     *
     * @param int $conversation The conversation.
     * @param string $action What.
     * @param int $target The department or the person.
     * @param string $value The kind of contact.
     * @return array The conversation.
     */
    public static function execute(int $conversation, string $action, int $target = 0, string $value = ''): array {
        global $DB;
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'action' => $action, 'target' => $target, 'value' => $value]
        );
        $viewer = $params['action'] === 'read' ? self::viewer() : self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        $id = (int)$row->cwid;
        $path = '/conversations/' . $id;
        $target = $params['target'];
        switch ($params['action']) {
            case 'read':
                $viewer->read($id);
                return ['conversation' => self::export($viewer, $row)];
            case 'take':
                directory::as_person($viewer, function (chatwoot $client) use ($path, $viewer) {
                    $client->call('POST', $path . '/assignments', ['assignee_id' => $viewer->agent['id']]);
                });
                break;
            case 'resolve':
            case 'reopen':
                $status = $params['action'] === 'resolve' ? 'resolved' : 'open';
                directory::as_person($viewer, function (chatwoot $client) use ($path, $status) {
                    $client->call('POST', $path . '/toggle_status', ['status' => $status]);
                });
                break;
            case 'team':
                if (!isset(directory::teams()[$target])) {
                    throw new \invalid_parameter_exception('Unknown department');
                }
                directory::as_person($viewer, function (chatwoot $client) use ($path, $target) {
                    // To the department, and nobody has it until someone of it keeps it.
                    $client->call('POST', $path . '/assignments', ['team_id' => $target]);
                    $client->call('POST', $path . '/assignments', ['assignee_id' => null]);
                });
                mirror::refresh($id, false);
                mirror::passed($id, $viewer->userid, 0, $target);
                break;
            case 'person':
                $agent = directory::agent($target);
                if (!$agent) {
                    throw new \invalid_parameter_exception('Unknown person');
                }
                directory::as_person($viewer, function (chatwoot $client) use ($path, $target) {
                    $client->call('POST', $path . '/assignments', ['assignee_id' => $target]);
                });
                mirror::refresh($id, false);
                mirror::passed($id, $viewer->userid, $target, 0);
                break;
            case 'kind':
                if (!in_array($params['value'], self::KINDS) || !$row->contactid) {
                    throw new \invalid_parameter_exception('Unknown kind');
                }
                $kind = $params['value'] === 'none' ? '' : $params['value'];
                directory::client()->call('PUT', '/contacts/' . $row->contactid, [
                    'custom_attributes' => [
                        'marandu_kind' => $kind,
                        'marandu_kind_by' => fullname(\core_user::get_user($viewer->userid)),
                        'marandu_kind_at' => time(),
                    ],
                ]);
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        $row = in_array($params['action'], ['team', 'person']) ? $DB->get_record(mirror::TABLE, ['cwid' => $id])
            : mirror::refresh($id, false);
        $row = $row ?: self::conversation($viewer, $id);
        if (!$viewer->sees($row)) {
            // Passed to a department this person does not see: the page takes it out of the list.
            $row->status = 'gone';
        }
        return ['conversation' => self::export($viewer, $row)];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversation' => self::conversation_structure(),
        ]);
    }
}
