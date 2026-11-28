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

namespace local_oksigeniamarandu\local\mail;

/**
 * Whether Mailjet is ready to send for the site, asked to Mailjet: the keys open the account, the sender is active (the
 * address, or the whole domain), and the domain is signed with DKIM; SPF is said, but does not stop it, since DKIM alone
 * aligns the mail with DMARC. The last answer is kept, so that the page does not ask Mailjet each time it is seen.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class check {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /** What is looked at, in order. */
    public const PARTS = ['keys', 'sender', 'dkim', 'spf'];

    /**
     * Asks Mailjet, and keeps what it said.
     *
     * @param mailjet_api|null $api
     * @param int|null $now
     * @return array keys, sender, dkim and spf (ok, bad or unknown), error and time.
     */
    public static function run(?mailjet_api $api = null, ?int $now = null): array {
        $state = ['keys' => 'unknown', 'sender' => 'unknown', 'dkim' => 'unknown', 'spf' => 'unknown', 'error' => '',
            'time' => $now ?? time()];
        $api = $api ?? mailjet_api::from_config();
        [$from] = settings::from();
        if (!$api) {
            $state['keys'] = 'bad';
            return self::keep($state);
        }
        $domain = $from !== '' ? substr($from, strrpos($from, '@') + 1) : '';
        [$status, $answer] = $api->sender($from !== '' ? $from : '*@' . $domain);
        if ($status === 401 || $status === 403) {
            $state['keys'] = 'bad';
            $state['error'] = (string)($answer['ErrorMessage'] ?? 'http ' . $status);
            return self::keep($state);
        }
        if ($status !== 200) {
            $state['error'] = (string)($answer['ErrorMessage'] ?? ($status ? 'http ' . $status : 'network'));
            return self::keep($state);
        }
        $state['keys'] = 'ok';
        if ($from === '') {
            $state['sender'] = 'bad';
            return self::keep($state);
        }
        $state['sender'] = self::active($answer, $from) ? 'ok' : 'bad';
        if ($state['sender'] === 'bad') {
            // The whole domain may be the sender.
            [$status, $answer] = $api->sender('*@' . $domain);
            if ($status === 200 && self::active($answer, '*@' . $domain)) {
                $state['sender'] = 'ok';
            }
        }
        [$status, $answer] = $api->dns($domain);
        $dns = $status === 200 ? ($answer['Data'][0] ?? []) : [];
        foreach (['dkim' => 'DKIMStatus', 'spf' => 'SPFStatus'] as $part => $field) {
            $value = (string)($dns[$field] ?? '');
            $state[$part] = $value === 'OK' ? 'ok' : ($value === 'Error' ? 'bad' : 'unknown');
        }
        return self::keep($state);
    }

    /**
     * What Mailjet said the last time, if the keys and the sender are the same since.
     *
     * @return array|null
     */
    public static function last(): ?array {
        $state = json_decode((string)get_config(self::C, 'mjchecked'), true);
        return is_array($state) && ($state['from'] ?? null) === settings::from()[0] ? $state : null;
    }

    /**
     * Whether Mailjet can be the way: the keys open the account, the sender is active and the domain signs with DKIM.
     *
     * @return bool
     */
    public static function ready(): bool {
        $state = self::last();
        return $state && $state['keys'] === 'ok' && $state['sender'] === 'ok' && $state['dkim'] === 'ok';
    }

    /**
     * Sends a test by Mailjet, whatever the way of the site is now, and says how it went.
     *
     * @param string $email
     * @param int|null $now
     * @param bool $routed By the way that address takes now, not always by Mailjet.
     * @return array ok, way and error.
     */
    public static function test(string $email, ?int $now = null, bool $routed = false): array {
        $now = $now ?? time();
        $a = (object)['site' => settings::from()[1], 'time' => userdate($now, get_string('strftimedatetimeshort', 'langconfig'))];
        $subject = get_string('mail:test:subject', self::C, $a);
        $built = compose::build(compose::from_text(
            $subject,
            get_string($routed ? 'mail:test:bodyroute' : 'mail:test:body', self::C, $a),
            get_string('mail:letter:reason:test', self::C)
        ));
        $letter = [
            'email' => $email,
            'subject' => $subject,
            'html' => $built['html'],
            'text' => $built['text'],
            'images' => $built['images'],
            'component' => self::C,
        ];
        if ($routed) {
            // By the way a mail to that address goes now (the mail of the site for Microsoft, Mailjet for the rest).
            $way = mailer::send($letter, $now);
            return ['ok' => in_array($way, ['site', 'mailjet'], true), 'way' => $way, 'error' => in_array(
                $way,
                ['site', 'mailjet'],
                true
            ) ? '' : $way];
        }
        $result = mailer::test($letter, $now);
        set_config('mailtest', json_encode(['to' => $email, 'time' => $now] + $result), self::C);
        return $result;
    }

    /**
     * Whether a sender is active among the senders Mailjet gave.
     *
     * @param array $answer
     * @param string $email
     * @return bool
     */
    private static function active(array $answer, string $email): bool {
        foreach ((array)($answer['Data'] ?? []) as $sender) {
            if (
                \core_text::strtolower((string)($sender['Email'] ?? '')) === \core_text::strtolower($email)
                    && ($sender['Status'] ?? '') === 'Active'
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keeps what Mailjet said, with the sender it was for.
     *
     * @param array $state
     * @return array
     */
    private static function keep(array $state): array {
        $state['from'] = settings::from()[0];
        set_config('mjchecked', json_encode($state), self::C);
        return $state;
    }
}
