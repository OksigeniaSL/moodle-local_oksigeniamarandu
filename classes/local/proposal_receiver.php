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
 * The receiver of Oksigenia for the suggestions that are about a tool and not about the organization: a POST with the
 * suggestion (without the name or the mail of the person) answers its id and a key, 201 when new and 200 when it already
 * had it (the same site and ref); a GET of its id with the key says how it goes (received, done or turned down) and its
 * answer. 400 and 413 are final; 429 and 5xx, to try again.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_receiver {
    /** The address of the receiver, unless the site says another (proposalreceiver). */
    public const URL = 'https://aravo.oksigenia.com/v1/propuestas';

    /** @var callable|null What answers instead of the receiver, in the tests. */
    private static $fake = null;

    /**
     * Answers instead of the receiver in the tests, or stops doing it.
     *
     * @param callable|null $fake fn(string $method, string $url, ?array $body): array [status, answer].
     */
    public static function fake_for_tests(?callable $fake): void {
        self::$fake = $fake;
    }

    /**
     * The address of the receiver.
     *
     * @return string
     */
    public static function url(): string {
        $url = trim((string)get_config('local_oksigeniamarandu', 'proposalreceiver'));
        return preg_match('~^https://~i', $url) ? rtrim($url, '/') : self::URL;
    }

    /**
     * Sends a suggestion.
     *
     * @param array $body As the receiver takes it.
     * @return array The status (0 if it did not answer) and its answer.
     */
    public static function send(array $body): array {
        return self::request('POST', self::url(), $body);
    }

    /**
     * How a suggestion goes.
     *
     * @param string $id
     * @param string $key
     * @return array The status (0 if it did not answer) and its answer.
     */
    public static function ask(string $id, string $key): array {
        return self::request('GET', self::url() . '/' . rawurlencode($id) . '?k=' . rawurlencode($key), null);
    }

    /**
     * A call to the receiver.
     *
     * @param string $method
     * @param string $url
     * @param array|null $body
     * @return array The status and the answer.
     */
    private static function request(string $method, string $url, ?array $body): array {
        global $CFG;
        if (self::$fake) {
            return (self::$fake)($method, $url, $body);
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader(['Accept: application/json']);
        $options = ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 10];
        if ($method === 'POST') {
            $curl->setHeader(['Content-Type: application/json']);
            $raw = $curl->post($url, json_encode($body), $options);
        } else {
            $raw = $curl->get($url, [], $options);
        }
        $info = $curl->get_info();
        $status = $curl->get_errno() ? 0 : (int)($info['http_code'] ?? 0);
        $answer = json_decode((string)$raw, true);
        return [$status, is_array($answer) ? $answer : []];
    }
}
