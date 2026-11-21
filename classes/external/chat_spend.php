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
use local_oksigeniamarandu\local\bulk;
use local_oksigeniamarandu\local\spend;

/**
 * What WhatsApp costs this month and the sends to many that wait for approval, on the home of whoever sees everything;
 * and what whoever may approve does there: approve (by every channel, or only by Telegram), reject with its why, and
 * the threshold and the budget of the month.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_spend extends chat_base {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(
                PARAM_ALPHA,
                'Empty to read; approve, telegram, reject, threshold or budget',
                VALUE_DEFAULT,
                ''
            ),
            'id' => new external_value(PARAM_INT, 'The send', VALUE_DEFAULT, 0),
            'value' => new external_value(PARAM_FLOAT, 'The threshold or the budget; 0 for no budget', VALUE_DEFAULT, 0),
            'reason' => new external_value(PARAM_TEXT, 'Why it is rejected', VALUE_DEFAULT, ''),
            'people' => new external_multiple_structure(
                new external_value(PARAM_INT, 'A user'),
                'Who sees what WhatsApp costs and approves the sends',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Does it, and gives the month and the sends.
     *
     * @param string $action What.
     * @param int $id The send.
     * @param float $value The threshold or the budget.
     * @param string $reason Why it is rejected.
     * @param array $people Who sees what WhatsApp costs and approves the sends.
     * @return array
     */
    public static function execute(
        string $action = '',
        int $id = 0,
        float $value = 0,
        string $reason = '',
        array $people = []
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['action' => $action, 'id' => $id, 'value' => $value,
            'reason' => $reason, 'people' => $people]);
        self::viewer();
        $system = \context_system::instance();
        // Only the people the site chose see it; whoever administers the site chooses them.
        $admin = has_capability('moodle/site:config', $system);
        $approver = spend::is_person((int)$USER->id);
        if (!$approver && !$admin) {
            throw new \required_capability_exception($system, 'local/oksigeniamarandu:bulkapprove', 'nopermissions', '');
        }
        if ($params['action'] === 'people') {
            require_capability('moodle/site:config', $system);
            spend::set_people($params['people']);
            $approver = spend::is_person((int)$USER->id);
        } else if ($params['action'] !== '' && !$approver) {
            throw new \required_capability_exception($system, 'local/oksigeniamarandu:bulkapprove', 'nopermissions', '');
        }
        switch ($params['action']) {
            case '':
            case 'people':
                break;
            case 'approve':
            case 'telegram':
                bulk::decide($params['id'], true, (int)$USER->id, '', $params['action'] === 'telegram' ? 'telegram' : '');
                break;
            case 'reject':
                bulk::decide($params['id'], false, (int)$USER->id, $params['reason']);
                break;
            case 'threshold':
                set_config('bulkthreshold', (string)max(0, round($params['value'], 2)), 'local_oksigeniamarandu');
                break;
            case 'budget':
                set_config('budgetmonth', (string)max(0, round($params['value'], 2)), 'local_oksigeniamarandu');
                break;
            default:
                throw new \invalid_parameter_exception('Unknown action');
        }
        $month = spend::month();
        $categories = [];
        foreach (spend::CATEGORIES as $key) {
            $categories[] = ['key' => $key, 'count' => (int)($month['categories'][$key]['count'] ?? 0),
                'cost' => (float)($month['categories'][$key]['cost'] ?? 0)];
        }
        $days = [];
        foreach ($month['days'] as $day => $bycategory) {
            $days[] = ['day' => (int)$day, 'utility' => (float)($bycategory['utility'] ?? 0),
                'marketing' => (float)($bycategory['marketing'] ?? 0),
                'authentication' => (float)($bycategory['authentication'] ?? 0)];
        }
        $now = time();
        $recent = bulk::recent();
        return [
            'month' => [
                'name' => userdate($now, '%B'),
                'cost' => (float)$month['cost'],
                'count' => (int)$month['count'],
                'currency' => (string)($month['currency'] ?: \local_oksigeniamarandu\local\chat\templates::prices()['currency']),
                'budget' => (float)$month['budget'],
                'share' => (int)$month['share'],
                'time' => (int)$month['time'],
                'today' => (int)userdate($now, '%d', 99, false, false),
                'days' => (int)userdate(strtotime('last day of this month', $now), '%d', 99, false, false),
                'categories' => $categories,
                'bydays' => $days,
            ],
            'threshold' => bulk::threshold(),
            'canapprove' => $approver,
            'canchoose' => $admin,
            'approvers' => array_values(array_map(function ($user) {
                return ['userid' => (int)$user->id, 'firstname' => (string)$user->firstname, 'name' => fullname($user)];
            }, spend::people())),
            'pending' => $recent['pending'],
            'decided' => $recent['decided'],
        ];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $send = new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The send'),
            'label' => new external_value(PARAM_TEXT, 'What it is'),
            'plugin' => new external_value(PARAM_TEXT, 'The plugin that asked for it, as people call it'),
            'status' => new external_value(PARAM_ALPHA, 'pending, queued, rejected or cancelled'),
            'whatsapp' => new external_value(PARAM_INT, 'Messages that cost'),
            'telegram' => new external_value(PARAM_INT, 'By Telegram, free'),
            'skipped' => new external_value(PARAM_INT, 'Items nobody of which can get it'),
            'cost' => new external_value(PARAM_FLOAT, 'What it costs'),
            'currency' => new external_value(PARAM_TEXT, 'Its currency'),
            'category' => new external_value(PARAM_ALPHA, 'utility, marketing or authentication'),
            'askedby' => new external_value(PARAM_TEXT, 'Who asked for it'),
            'askerid' => new external_value(PARAM_INT, 'Their user'),
            'askerpicture' => new external_value(PARAM_RAW, 'The picture of their profile, or empty'),
            'asked' => new external_value(PARAM_INT, 'When'),
            'decidedby' => new external_value(PARAM_TEXT, 'Who decided'),
            'decidedbyfirst' => new external_value(PARAM_TEXT, 'Their first name'),
            'deciderid' => new external_value(PARAM_INT, 'Their user, or 0'),
            'decided' => new external_value(PARAM_INT, 'When'),
            'reason' => new external_value(PARAM_TEXT, 'Why it was rejected'),
            'only' => new external_value(PARAM_ALPHA, 'Approved only by this channel, or empty'),
            'said' => new external_value(PARAM_TEXT, 'What whoever asked reads of it'),
            'sent' => new external_value(PARAM_INT, 'Numbers and chats it went to'),
            'preview' => new external_value(PARAM_TEXT, 'Its text, as the first person reads it'),
            'why' => new external_value(PARAM_ALPHA, 'Why it waits: threshold, budget or empty'),
        ]);
        return new external_single_structure([
            'month' => new external_single_structure([
                'name' => new external_value(PARAM_TEXT, 'The name of the month'),
                'cost' => new external_value(PARAM_FLOAT, 'What it cost so far'),
                'count' => new external_value(PARAM_INT, 'Messages, the free ones too'),
                'currency' => new external_value(PARAM_TEXT, 'The currency'),
                'budget' => new external_value(PARAM_FLOAT, 'The budget; 0 for none'),
                'share' => new external_value(PARAM_INT, 'The share of the budget spent'),
                'time' => new external_value(PARAM_INT, 'When Meta was read; 0 if never'),
                'today' => new external_value(PARAM_INT, 'The day of today'),
                'days' => new external_value(PARAM_INT, 'The days of the month'),
                'categories' => new external_multiple_structure(new external_single_structure([
                    'key' => new external_value(PARAM_ALPHA, 'utility, marketing, authentication or service'),
                    'count' => new external_value(PARAM_INT, 'Messages'),
                    'cost' => new external_value(PARAM_FLOAT, 'What they cost'),
                ])),
                'bydays' => new external_multiple_structure(new external_single_structure([
                    'day' => new external_value(PARAM_INT, 'The day'),
                    'utility' => new external_value(PARAM_FLOAT, 'What utility cost that day'),
                    'marketing' => new external_value(PARAM_FLOAT, 'What marketing cost that day'),
                    'authentication' => new external_value(PARAM_FLOAT, 'What authentication cost that day'),
                ])),
            ]),
            'threshold' => new external_value(PARAM_FLOAT, 'The cost from which a send waits for approval'),
            'canapprove' => new external_value(PARAM_BOOL, 'Whether whoever looks may approve'),
            'canchoose' => new external_value(PARAM_BOOL, 'Whether whoever looks chooses who sees it and approves'),
            'approvers' => new external_multiple_structure(new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'The user'),
                'firstname' => new external_value(PARAM_TEXT, 'Their first name'),
                'name' => new external_value(PARAM_TEXT, 'Their name'),
            ])),
            'pending' => new external_multiple_structure($send),
            'decided' => new external_multiple_structure($send),
        ]);
    }
}
