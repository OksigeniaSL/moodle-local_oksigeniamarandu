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
use local_oksigeniamarandu\local\chat\draft;

/**
 * «Propose an answer»: a draft of the answer to a conversation, for the person to read, change and send. Nothing is
 * sent. The first time, the person accepts the policy of the site on AI, as Moodle asks.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_draft extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'conversation' => new external_value(PARAM_INT, 'The conversation'),
            'hint' => new external_value(PARAM_TEXT, 'What the person already wrote in the box', VALUE_DEFAULT, ''),
            'accept' => new external_value(PARAM_BOOL, 'Whether the person accepts the policy on AI now', VALUE_DEFAULT, false),
        ]);
    }

    /**
     * The draft.
     *
     * @param int $conversation The conversation.
     * @param string $hint What the person already wrote.
     * @param bool $accept Whether they accept the policy now.
     * @return array status (ok, unavailable, policy or failed), text, error and the policy to accept.
     */
    public static function execute(int $conversation, string $hint = '', bool $accept = false): array {
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['conversation' => $conversation, 'hint' => $hint, 'accept' => $accept]
        );
        $viewer = self::writer();
        $row = self::conversation($viewer, $params['conversation']);
        if ($params['accept']) {
            draft::accept_policy($viewer->userid);
        }
        $result = draft::make($viewer->userid, $row, $params['hint']);
        return $result + ['policy' => $result['status'] === 'policy' ? draft::policy_text() : ''];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'ok, unavailable, policy or failed'),
            'text' => new external_value(PARAM_RAW, 'The draft, plain text'),
            'error' => new external_value(PARAM_TEXT, 'Why it failed, as the provider says'),
            'policy' => new external_value(PARAM_RAW, 'The policy of the site on AI, HTML, when it has to be accepted'),
        ]);
    }
}
