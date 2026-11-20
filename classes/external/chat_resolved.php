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
use local_oksigeniamarandu\local\chat\directory;
use local_oksigeniamarandu\local\chat\family;
use local_oksigeniamarandu\local\chat\message;
use local_oksigeniamarandu\local\chat\mirror;

/**
 * The conversations a person sees beyond what the page keeps (what is open and what was resolved today): what was
 * resolved in the last days, one old conversation, everything a search finds whenever it was (also by what was said in
 * it), and the history, a page at a time, with its filters.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_resolved extends chat_base {
    /** How many days back, at most. */
    public const DAYS = 30;

    /** How many at most in one answer. */
    public const LIMIT = 200;

    /** How many in a page of the history. */
    public const PAGE = 60;

    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'before' => new external_value(PARAM_INT, 'Only those resolved before this time; 0 for the newest', VALUE_DEFAULT, 0),
            'id' => new external_value(PARAM_INT, 'One conversation, whenever it was, if the person sees it', VALUE_DEFAULT, 0),
            'query' => new external_value(PARAM_TEXT, 'Every conversation of whom this finds, whenever it was', VALUE_DEFAULT, ''),
            'all' => new external_value(PARAM_BOOL, 'Whenever it was, a page at a time: the history', VALUE_DEFAULT, false),
            'mine' => new external_value(PARAM_BOOL, 'In the history, only those the person had', VALUE_DEFAULT, false),
            'team' => new external_value(PARAM_INT, 'In the history, only those of a department', VALUE_DEFAULT, 0),
            'from' => new external_value(PARAM_INT, 'In the history, only those from this time', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * The conversations.
     *
     * @param int $before Only those whose last activity was before this time; 0 for the newest.
     * @param int $id One conversation only, whenever it was (an old one the card of the contact lists).
     * @param string $query Every conversation of whom this finds (a name, a phone, a child of the family, what was
     *                      said), whenever it was.
     * @param bool $all Whenever it was, a page at a time: the history of the team, with what came from the phone.
     * @param bool $mine In the history, only those the person had.
     * @param int $team In the history, only those of a department.
     * @param int $from In the history, only those from this time.
     * @return array
     */
    public static function execute(
        int $before = 0,
        int $id = 0,
        string $query = '',
        bool $all = false,
        bool $mine = false,
        int $team = 0,
        int $from = 0
    ): array {
        global $DB;
        $params = self::validate_parameters(self::execute_parameters(), ['before' => $before, 'id' => $id,
            'query' => $query, 'all' => $all, 'mine' => $mine, 'team' => $team, 'from' => $from]);
        $viewer = self::viewer();
        $now = time();
        if ($params['id']) {
            $row = $DB->get_record(mirror::TABLE, ['cwid' => $params['id']]);
            $list = $row && $viewer->sees($row) ? [self::clean($viewer->export($row, $viewer->seen([(int)$row->cwid]), $now))] : [];
            return ['conversations' => $list, 'more' => false, 'days' => self::DAYS, 'hits' => []];
        }
        [$where, $wparams] = $viewer->where();
        $query = trim($params['query']);
        if (\core_text::strlen($query) >= 2) {
            [$list, $hits] = self::found($viewer, $where, $wparams, $query, $now);
            return ['conversations' => $list, 'more' => false, 'days' => self::DAYS, 'hits' => $hits];
        }
        $sql = "c.status = 'resolved' AND c.lastactivity >= :since";
        $wparams['since'] = $params['all'] ? $params['from'] : $now - self::DAYS * DAYSECS;
        $limit = $params['all'] ? self::PAGE : self::LIMIT;
        if ($params['before'] > 0) {
            $sql .= ' AND c.lastactivity < :before';
            $wparams['before'] = $params['before'];
        }
        if ($params['all'] && $params['mine']) {
            $sql .= ' AND c.assigneeid = :hadit';
            $wparams['hadit'] = (int)$viewer->agent['id'];
        }
        if ($params['all'] && $params['team']) {
            $sql .= ' AND c.teamid = :ofteam';
            $wparams['ofteam'] = $params['team'];
        }
        $rows = $DB->get_records_sql(
            "SELECT c.* FROM {" . mirror::TABLE . "} c WHERE $where AND $sql ORDER BY c.lastactivity DESC, c.cwid DESC",
            $wparams,
            0,
            $limit + 1
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        return ['conversations' => self::exported($viewer, $rows, $now), 'more' => $more, 'days' => self::DAYS, 'hits' => []];
    }

    /**
     * Every conversation the person sees of whom a search finds, whenever it was: by the name of the contact, by
     * their phone, by any name of their family in the common record (a child, the other adult), or by what was said
     * in it, messages and notes (the search of messages of Chatwoot), with the sentence where it is.
     *
     * @param \local_oksigeniamarandu\local\chat\viewer $viewer Who looks.
     * @param string $where What they see, as SQL.
     * @param array $wparams Its parameters.
     * @param string $query What was typed.
     * @param int $now The time.
     * @return array [the conversations, the newest first; the hits in what was said, by conversation].
     */
    protected static function found($viewer, string $where, array $wparams, string $query, int $now): array {
        global $DB;
        $conditions = [$DB->sql_like('c.name', ':qname', false, false)];
        $params = ['qname' => '%' . $DB->sql_like_escape($query) . '%'];
        $digits = preg_replace('/\D/', '', $query);
        if (strlen($digits) >= 3) {
            $conditions[] = $DB->sql_like('c.phone', ':qphone');
            $params['qphone'] = '%' . $digits . '%';
        }
        $phones = [];
        foreach (family::search($query, 10) as $household) {
            foreach ($household['adults'] as $adult) {
                if ($adult['phone'] !== '') {
                    $phones[] = $adult['phone'];
                }
            }
        }
        if ($phones) {
            [$in, $inparams] = $DB->get_in_or_equal(array_values(array_unique($phones)), SQL_PARAMS_NAMED, 'fphone');
            $conditions[] = "c.phone $in";
            $params += $inparams;
        }
        // What was said: the newest message of each conversation that has the words.
        $hits = [];
        foreach (self::said($query) as $message) {
            $cwid = (int)($message['conversation_id'] ?? 0);
            if ($cwid && !isset($hits[$cwid])) {
                $hits[$cwid] = ['conversation' => $cwid, 'text' => self::snippet((string)($message['content'] ?? ''), $query),
                    'at' => (int)($message['created_at'] ?? 0), 'note' => !empty($message['private'])];
            }
        }
        if ($hits) {
            [$in, $inparams] = $DB->get_in_or_equal(array_keys($hits), SQL_PARAMS_NAMED, 'said');
            $conditions[] = "c.cwid $in";
            $params += $inparams;
        }
        $rows = $DB->get_records_sql(
            "SELECT c.* FROM {" . mirror::TABLE . "} c WHERE $where AND (" . implode(' OR ', $conditions) . ")
          ORDER BY c.lastactivity DESC, c.cwid DESC",
            $wparams + $params,
            0,
            self::LIMIT
        );
        $seen = array_flip(array_map('intval', array_column($rows, 'cwid')));
        return [self::exported($viewer, $rows, $now), array_values(array_intersect_key($hits, $seen))];
    }

    /**
     * The messages and notes of the account with these words, from the search of Chatwoot (a few pages, the newest
     * first); none if it does not answer.
     *
     * @param string $query The words.
     * @return array The messages, as Chatwoot gives them.
     */
    protected static function said(string $query): array {
        $found = [];
        try {
            for ($page = 1; $page <= 3; $page++) {
                $answer = directory::client()->call('GET', '/search/messages', ['q' => $query, 'page' => $page]);
                $messages = $answer['payload']['messages'] ?? [];
                $found = array_merge($found, $messages);
                if (count($messages) < 15) {
                    break;
                }
            }
        } catch (\moodle_exception $e) {
            return $found;
        }
        usort($found, function ($a, $b) {
            return (int)($b['created_at'] ?? 0) <=> (int)($a['created_at'] ?? 0);
        });
        return $found;
    }

    /**
     * The sentence of a message where the words are: around the first of them, cut with ellipses.
     *
     * @param string $text The message.
     * @param string $query The words.
     * @return string
     */
    public static function snippet(string $text, string $query): string {
        $text = trim(preg_replace('/\s+/u', ' ', message::plain_text($text)));
        $word = family::words($query)[0] ?? '';
        $at = $word === '' ? false : mb_strpos(self::fold(\core_text::strtolower($text)), $word);
        if ($at === false) {
            return \core_text::strlen($text) > 120 ? \core_text::substr($text, 0, 117) . '…' : $text;
        }
        // Close before the words, so that they always show, and whole words at both ends.
        $start = max(0, $at - 24);
        if ($start > 0) {
            $space = mb_strpos($text, ' ', $start);
            $start = $space !== false && $space < $at ? $space + 1 : $start;
        }
        $piece = \core_text::substr($text, $start, 130);
        $cut = $start + 130 < \core_text::strlen($text);
        if ($cut) {
            $space = mb_strrpos($piece, ' ');
            $piece = $space !== false && $space > $at - $start ? mb_substr($piece, 0, $space) : $piece;
        }
        return ($start > 0 ? '…' : '') . trim($piece) . ($cut ? '…' : '');
    }

    /**
     * Small letters without accents, keeping each letter where it was.
     *
     * @param string $text The text, in small letters.
     * @return string
     */
    protected static function fold(string $text): string {
        if (!class_exists('\Normalizer')) {
            return $text;
        }
        $out = '';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) as $letter) {
            $bare = preg_replace('/\p{Mn}+/u', '', \Normalizer::normalize($letter, \Normalizer::FORM_D));
            $out .= mb_substr($bare, 0, 1) ?: $letter;
        }
        return $out;
    }

    /**
     * Conversations of the index as the page takes them.
     *
     * @param \local_oksigeniamarandu\local\chat\viewer $viewer Who looks.
     * @param array $rows The rows.
     * @param int $now The time.
     * @return array
     */
    protected static function exported($viewer, array $rows, int $now): array {
        $seen = $viewer->seen(array_map('intval', array_column($rows, 'cwid')));
        $list = [];
        foreach ($rows as $row) {
            $list[] = self::clean($viewer->export($row, $seen, $now));
        }
        return $list;
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'conversations' => new external_multiple_structure(self::conversation_structure()),
            'more' => new external_value(PARAM_BOOL, 'Whether there are older ones'),
            'days' => new external_value(PARAM_INT, 'How many days back the resolved ones of the trays look'),
            'hits' => new external_multiple_structure(new external_single_structure([
                'conversation' => new external_value(PARAM_INT, 'The conversation'),
                'text' => new external_value(PARAM_TEXT, 'The sentence where the words are'),
                'at' => new external_value(PARAM_INT, 'When it was said'),
                'note' => new external_value(PARAM_BOOL, 'Whether it was a note of the team'),
            ]), 'Where a search found the words in what was said', VALUE_DEFAULT, []),
        ]);
    }
}
