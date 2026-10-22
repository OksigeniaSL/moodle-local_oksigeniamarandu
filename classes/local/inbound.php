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

use local_oksigeniamarandu\task\send_reply;

/**
 * What Meta sends to the webhook of the site: the messages families write to its number.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class inbound {
    /**
     * Whether a request comes from Meta: its body signed with the app secret (header X-Hub-Signature-256).
     *
     * @param string $raw The body, exactly as it arrived.
     * @param string $header The header, as sha256=<hex>.
     * @param string $secret The app secret.
     * @return bool
     */
    public static function verify_signature(string $raw, string $header, string $secret): bool {
        if ($secret === '' || strpos($header, 'sha256=') !== 0) {
            return false;
        }
        return hash_equals(hash_hmac('sha256', $raw, $secret), substr($header, 7));
    }

    /**
     * Handles the messages to the number of the site, and the statuses of the test message; anything else (other
     * numbers, other fields) is left alone.
     *
     * @param array $payload The decoded body.
     * @return int How many messages were handled.
     */
    public static function handle(array $payload): int {
        $mine = (string)get_config('local_oksigeniamarandu', 'phonenumberid');
        if ($mine === '' || ($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return 0;
        }
        $count = 0;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if (($change['field'] ?? '') !== 'messages' || (string)($value['metadata']['phone_number_id'] ?? '') !== $mine) {
                    continue;
                }
                $names = [];
                foreach ($value['contacts'] ?? [] as $contact) {
                    $names[(string)($contact['wa_id'] ?? '')] = $contact['profile']['name'] ?? null;
                }
                foreach ($value['messages'] ?? [] as $message) {
                    if (is_array($message)) {
                        self::message($message, $names);
                        $count++;
                    }
                }
                foreach ($value['statuses'] ?? [] as $status) {
                    if (is_array($status)) {
                        test_message::status(
                            (string)($status['id'] ?? ''),
                            (string)($status['status'] ?? ''),
                            (string)($status['errors'][0]['title'] ?? '')
                        );
                    }
                }
            }
        }
        return $count;
    }

    /**
     * One message: notes the 24-hour window and, if it is an opt-in or an opt-out, does it and queues the reply.
     *
     * @param array $message The message, as Meta sends it.
     * @param array $names Profile names by number.
     */
    private static function message(array $message, array $names): void {
        $from = (string)($message['from'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $from);
        if ($phone === '') {
            return;
        }
        $name = $names[$from] ?? null;
        $wamid = (string)($message['id'] ?? '');
        consent::touch($phone, $name);
        test_message::inbound($phone);

        $type = $message['type'] ?? '';
        if ($type === 'text') {
            $text = (string)($message['text']['body'] ?? '');
        } else if ($type === 'button') {
            $text = (string)($message['button']['text'] ?? '');
        } else {
            return;
        }
        $match = keywords::match($text);
        if ($match === null) {
            return;
        }
        if ($match['action'] === 'optout') {
            consent::optout($phone, 'keyword', $wamid);
            $reply = 'optout';
        } else {
            $reply = consent::optin_from_message($phone, $match['code'], $wamid, $name);
        }
        send_reply::queue($phone, $reply);
    }
}
