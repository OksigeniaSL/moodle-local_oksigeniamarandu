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
 * What Marandu asks of Mailjet: to send (the Send API v3.1, up to 50 mails a call), whether the sender and its domain
 * are ready, how many mails the account sent between two days, and where it tells its events.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mailjet_api {
    /** Where the API is. */
    public const BASE = 'https://api.mailjet.com';

    /** The most mails in a call. */
    public const BATCH = 50;

    /** The events Marandu listens to. */
    public const EVENTS = ['bounce', 'blocked', 'spam'];

    /** @var callable|null What answers instead of Mailjet in the tests: (method, path, body) to [status, answer]. */
    private static $fortests = null;

    /**
     * A client with the keys of an account.
     *
     * @param string $key
     * @param string $secret
     */
    public function __construct(
        /** @var string The key of the API. */
        private string $key,
        /** @var string Its secret. */
        private string $secret
    ) {
    }

    /**
     * The client with the keys of the site, if it has them.
     *
     * @return self|null
     */
    public static function from_config(): ?self {
        $keys = settings::keys();
        return $keys ? new self($keys[0], $keys[1]) : null;
    }

    /**
     * Answers instead of Mailjet in the tests, or stops doing it.
     *
     * @param callable|null $fake (string $method, string $path, ?array $body): array [int status, array answer].
     */
    public static function fake_for_tests(?callable $fake): void {
        self::$fortests = $fake;
    }

    /**
     * Sends mails, as the Send API v3.1 takes them.
     *
     * @param array[] $messages Up to 50.
     * @param bool $sandbox Only checked by Mailjet, never sent.
     * @return array [int status, array answer]: 201 or 200 with each mail in Messages, with its Status.
     */
    public function send(array $messages, bool $sandbox = false): array {
        $body = ['Messages' => array_values($messages)];
        if ($sandbox) {
            $body['SandboxMode'] = true;
        }
        return $this->request('POST', '/v3.1/send', $body);
    }

    /**
     * What Mailjet knows of a sender address: its Status (Active when it may send).
     *
     * @param string $email
     * @return array [int status, array answer]
     */
    public function sender(string $email): array {
        return $this->request('GET', '/v3/REST/sender?' . http_build_query(['Email' => $email], '', '&'));
    }

    /**
     * What Mailjet knows of a domain: SPFStatus and DKIMStatus (OK, Error or Not checked).
     *
     * @param string $domain
     * @return array [int status, array answer]
     */
    public function dns(string $domain): array {
        return $this->request('GET', '/v3/REST/dns/' . rawurlencode($domain));
    }

    /**
     * How many mails the account sent between two times, day by day (statcounters, at most 100 days).
     *
     * @param int $from
     * @param int $to
     * @return int|null Null if Mailjet did not say.
     */
    public function sent_between(int $from, int $to): ?int {
        [$status, $answer] = $this->request('GET', '/v3/REST/statcounters?' . http_build_query([
            'CounterSource' => 'APIKey',
            'CounterTiming' => 'Message',
            'CounterResolution' => 'Day',
            'FromTS' => gmdate('Y-m-d\TH:i:s', $from),
            'ToTS' => gmdate('Y-m-d\TH:i:s', $to),
        ], '', '&'));
        if ($status !== 200 || !isset($answer['Data']) || !is_array($answer['Data'])) {
            return null;
        }
        $sent = 0;
        foreach ($answer['Data'] as $row) {
            $sent += (int)($row['MessageSentCount'] ?? 0);
        }
        return $sent;
    }

    /**
     * Where the account tells its events now.
     *
     * @return array[]|null Each with ID, EventType, Url and Status; null if Mailjet did not say.
     */
    public function callbacks(): ?array {
        [$status, $answer] = $this->request('GET', '/v3/REST/eventcallbackurl');
        return $status === 200 && isset($answer['Data']) && is_array($answer['Data']) ? $answer['Data'] : null;
    }

    /**
     * Tells an event to an address: the one there was for it changes, or a new one is made.
     *
     * @param string $event One of EVENTS.
     * @param string $url
     * @param int $id The callback there was for that event, or 0.
     * @return bool Whether Mailjet took it.
     */
    public function set_callback(string $event, string $url, int $id = 0): bool {
        $body = ['EventType' => $event, 'Url' => $url, 'Version' => 2, 'Status' => 'alive', 'IsBackup' => false];
        [$status] = $id ? $this->request('PUT', '/v3/REST/eventcallbackurl/' . $id, $body)
            : $this->request('POST', '/v3/REST/eventcallbackurl', $body);
        return in_array($status, [200, 201], true);
    }

    /**
     * A call to Mailjet.
     *
     * @param string $method GET, POST or PUT.
     * @param string $path
     * @param array|null $body
     * @return array [int status, array answer]: status 0 if it did not get there.
     */
    protected function request(string $method, string $path, ?array $body = null): array {
        global $CFG;
        if (self::$fortests) {
            return (self::$fortests)($method, $path, $body);
        }
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->key . ':' . $this->secret),
        ]);
        $options = ['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CONNECTTIMEOUT' => 8];
        $json = $body === null ? null : json_encode($body);
        if ($method === 'POST') {
            $raw = $curl->post(self::BASE . $path, $json, $options);
        } else if ($method === 'PUT') {
            $raw = $curl->get(self::BASE . $path, [], $options + ['CURLOPT_CUSTOMREQUEST' => 'PUT', 'CURLOPT_POSTFIELDS' => $json]);
        } else {
            $raw = $curl->get(self::BASE . $path, [], $options);
        }
        if ($curl->get_errno()) {
            return [0, ['ErrorMessage' => (string)$curl->error]];
        }
        $info = $curl->get_info();
        $answer = json_decode((string)$raw, true);
        if (!is_array($answer)) {
            $answer = ['ErrorMessage' => \core_text::substr((string)$raw, 0, 200)];
        }
        return [(int)($info['http_code'] ?? 0), $answer];
    }
}
