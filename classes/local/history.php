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

namespace local_oksigeniamarandu\local;

/**
 * The chats and contacts a number had in the app of WhatsApp Business when it joins the API too (coexistence). Meta
 * sends them once, in the day after, in pieces and out of order; the site keeps them as they come and gives them in
 * one file, in order and without repeats, for the shared inbox that will show them with their dates.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class history {
    /** The table. */
    public const TABLE = 'local_oksigeniamarandu_hist';

    /** The fields of the webhook it keeps. */
    public const FIELDS = ['history', 'smb_app_state_sync'];

    /** The error Meta gives when the business did not share its chats. */
    public const DECLINED = 2593109;

    /**
     * Keeps a piece as it came.
     *
     * @param string $field history or smb_app_state_sync.
     * @param array $value The value of the change.
     */
    public static function keep(string $field, array $value): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object)[
            'field' => $field,
            'phonenumberid' => substr((string)($value['metadata']['phone_number_id'] ?? ''), 0, 32),
            'payload' => json_encode($value),
            'timecreated' => time(),
        ]);
    }

    /**
     * What came, by number: how many pieces, contacts, chats and messages, how far each phase went, and whether the
     * business did not share its chats.
     *
     * @return array By number.
     */
    public static function status(): array {
        global $DB;
        $numbers = [];
        foreach ($DB->get_recordset(self::TABLE, null, 'id') as $row) {
            $value = json_decode($row->payload, true) ?: [];
            $number = $row->phonenumberid;
            $numbers[$number] = $numbers[$number] ?? ['pieces' => 0, 'contacts' => 0, 'progress' => [], 'declined' => false];
            $numbers[$number]['pieces']++;
            $numbers[$number]['contacts'] += count($value['state_sync'] ?? []);
            foreach ($value['history'] ?? [] as $part) {
                $phase = (int)($part['metadata']['phase'] ?? 0);
                $numbers[$number]['progress'][$phase] = max(
                    $numbers[$number]['progress'][$phase] ?? 0,
                    (int)($part['metadata']['progress'] ?? 0)
                );
                foreach ($part['errors'] ?? [] as $error) {
                    $numbers[$number]['declined'] = $numbers[$number]['declined'] || (int)($error['code'] ?? 0) === self::DECLINED;
                }
            }
            foreach ($value['errors'] ?? [] as $error) {
                $numbers[$number]['declined'] = $numbers[$number]['declined'] || (int)($error['code'] ?? 0) === self::DECLINED;
            }
        }
        foreach (self::export() as $number => $export) {
            $numbers[$number]['threads'] = count($export['threads']);
            $numbers[$number]['messages'] = array_sum(array_map(function ($thread) {
                return count($thread['messages']);
            }, $export['threads']));
        }
        return $numbers;
    }

    /**
     * Everything, in order and without repeats, by number: the business number, the contacts with their names and
     * each chat with its messages (who wrote, when, the text and its status).
     *
     * @param string $phonenumberid Only this number; all if empty.
     * @return array By number: business, contacts (phone and name) and threads (phone, name and messages).
     */
    public static function export(string $phonenumberid = ''): array {
        global $DB;
        $params = $phonenumberid === '' ? null : ['phonenumberid' => $phonenumberid];
        $numbers = [];
        foreach ($DB->get_recordset(self::TABLE, $params, 'id') as $row) {
            $value = json_decode($row->payload, true) ?: [];
            $number = $row->phonenumberid;
            $numbers[$number] = $numbers[$number] ?? ['business' => '', 'contacts' => [], 'threads' => []];
            $business = preg_replace('/[^0-9]/', '', (string)($value['metadata']['display_phone_number'] ?? ''));
            if ($business !== '') {
                $numbers[$number]['business'] = $business;
            }
            foreach ($value['state_sync'] ?? [] as $sync) {
                $phone = preg_replace('/[^0-9]/', '', (string)($sync['contact']['phone_number'] ?? ''));
                if (($sync['type'] ?? '') !== 'contact' || $phone === '') {
                    continue;
                }
                if (($sync['action'] ?? 'add') === 'remove') {
                    unset($numbers[$number]['contacts'][$phone]);
                    continue;
                }
                $numbers[$number]['contacts'][$phone] = trim((string)($sync['contact']['full_name']
                    ?? $sync['contact']['first_name'] ?? ''));
            }
            foreach ($value['history'] ?? [] as $part) {
                foreach ($part['threads'] ?? [] as $thread) {
                    $phone = preg_replace('/[^0-9]/', '', (string)($thread['context']['wa_id'] ?? $thread['id'] ?? ''));
                    if ($phone === '') {
                        continue;
                    }
                    foreach ($thread['messages'] ?? [] as $message) {
                        $id = (string)($message['id'] ?? '');
                        if ($id === '') {
                            continue;
                        }
                        $numbers[$number]['threads'][$phone][$id] = $message;
                    }
                }
            }
        }
        $result = [];
        foreach ($numbers as $number => $data) {
            $threads = [];
            foreach ($data['threads'] as $phone => $messages) {
                $list = [];
                foreach ($messages as $message) {
                    $list[] = self::message($message, $data['business']);
                }
                usort($list, function ($a, $b) {
                    return [$a['at'], $a['id']] <=> [$b['at'], $b['id']];
                });
                $threads[] = ['phone' => (string)$phone, 'name' => (string)($data['contacts'][$phone] ?? ''),
                    'messages' => $list];
            }
            usort($threads, function ($a, $b) {
                return $a['messages'][0]['at'] <=> $b['messages'][0]['at'];
            });
            $contacts = [];
            foreach ($data['contacts'] as $phone => $name) {
                $contacts[] = ['phone' => (string)$phone, 'name' => $name];
            }
            $result[$number] = ['business' => $data['business'], 'contacts' => $contacts, 'threads' => $threads];
        }
        return $result;
    }

    /**
     * One message of a chat, as the inbox will write it.
     *
     * @param array $message As Meta sent it.
     * @param string $business The business number, only digits: what it wrote is from_me.
     * @return array id, at, from_me, type, text and status.
     */
    protected static function message(array $message, string $business): array {
        $type = (string)($message['type'] ?? 'text');
        $from = preg_replace('/[^0-9]/', '', (string)($message['from'] ?? ''));
        $text = (string)($message['text']['body'] ?? $message[$type]['caption'] ?? '');
        if ($type !== 'text') {
            // What was not text did not keep its file: a line says what it was.
            $what = get_string('history:attachment', 'local_oksigeniamarandu');
            $text = trim($what . ($text !== '' ? ': ' . $text : ''));
        }
        return [
            'id' => (string)$message['id'],
            'at' => (int)($message['timestamp'] ?? 0),
            'from_me' => $business !== '' && $from === $business,
            'type' => $type,
            'text' => $text,
            'status' => (string)($message['history_context']['status'] ?? ''),
        ];
    }

    /**
     * Forgets what came for a number, once the inbox has it.
     *
     * @param string $phonenumberid The number.
     * @return int How many pieces it forgot.
     */
    public static function forget(string $phonenumberid): int {
        global $DB;
        $count = $DB->count_records(self::TABLE, ['phonenumberid' => $phonenumberid]);
        $DB->delete_records(self::TABLE, ['phonenumberid' => $phonenumberid]);
        return $count;
    }
}
