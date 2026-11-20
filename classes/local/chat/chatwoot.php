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

namespace local_oksigeniamarandu\local\chat;

use local_oksigeniamarandu\local\api_exception;

/**
 * The account of Chatwoot under the conversations: its account API, with the access token of an administrator.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chatwoot {
    /** @var string The address of Chatwoot, without the slash at the end. */
    protected $url;

    /** @var int The number of the account. */
    protected $account;

    /** @var string The access token, already decrypted. */
    protected $token;

    /**
     * A client for one account.
     *
     * @param string $url The address of Chatwoot.
     * @param int $account The number of the account.
     * @param string $token The access token of an administrator of the account.
     */
    public function __construct(string $url, int $account, string $token) {
        $this->url = rtrim(trim($url), '/');
        $this->account = $account;
        $this->token = $token;
    }

    /**
     * Whether the site has a Chatwoot connected.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $config = get_config('local_oksigeniamarandu');
        return !empty($config->chatwooturl) && !empty($config->chatwootaccount) && !empty($config->chatwoottoken);
    }

    /**
     * The client with the account of the site.
     *
     * @return static
     * @throws api_exception If there is no account or its token cannot be read.
     */
    public static function from_config(): self {
        if (!self::is_configured()) {
            throw new api_exception('error:chatnotconfigured');
        }
        $config = get_config('local_oksigeniamarandu');
        try {
            $token = \core\encryption::decrypt($config->chatwoottoken);
        } catch (\moodle_exception $e) {
            throw new api_exception('error:token');
        }
        return new static((string)$config->chatwooturl, (int)$config->chatwootaccount, $token);
    }

    /**
     * The client of one person of the account, with their own access token, so that what they do is theirs in
     * Chatwoot too (the answers, the assignments, the reports). The token comes from the Platform API, if the site has
     * a platform app; without it, the client of the site.
     *
     * @param array $agent The person: id, name and email.
     * @param bool $renew Whether to ask for the token again, because the one kept did not work.
     * @return static
     */
    public static function for_agent(array $agent, bool $renew = false): self {
        $site = static::from_config();
        $config = get_config('local_oksigeniamarandu');
        if (empty($config->chatwootplatform) || empty($agent['id']) || empty($agent['email'])) {
            return $site;
        }
        $tokens = json_decode((string)($config->chatagenttokens ?? ''), true) ?: [];
        $kept = $tokens[$agent['id']] ?? '';
        if ($kept !== '' && !$renew) {
            try {
                return new static($site->url, $site->account, \core\encryption::decrypt($kept));
            } catch (\moodle_exception $e) {
                // Kept with another key of the site: asked for again.
                debugging('Marandu: a token of a person could not be read', DEBUG_DEVELOPER);
            }
        }
        try {
            $platform = \core\encryption::decrypt($config->chatwootplatform);
            // For a person who exists, Chatwoot does not change them: it lets the platform app reach them and gives
            // their token.
            [$code, $body] = $site->request('POST', $site->url . '/platform/api/v1/users', json_encode([
                'name' => (string)$agent['name'], 'email' => (string)$agent['email'],
            ]), $platform);
            $user = json_decode($body, true);
            $token = (string)($user['access_token'] ?? '');
            if ($code < 200 || $code >= 300 || $token === '' || (int)($user['id'] ?? 0) !== (int)$agent['id']) {
                return $site;
            }
        } catch (\moodle_exception $e) {
            return $site;
        }
        $tokens[$agent['id']] = \core\encryption::encrypt($token);
        set_config('chatagenttokens', json_encode($tokens), 'local_oksigeniamarandu');
        return new static($site->url, $site->account, $token);
    }

    /**
     * The number of the account.
     *
     * @return int
     */
    public function account(): int {
        return $this->account;
    }

    /**
     * A request to the account API.
     *
     * @param string $method GET, POST, PUT, PATCH or DELETE.
     * @param string $path The part after /api/v1/accounts/{id}, starting with a slash; /profile is the holder of the token.
     * @param array $data The query of a GET, or the JSON body of the rest.
     * @return array The answer, decoded.
     * @throws api_exception If Chatwoot does not answer or says no.
     */
    public function call(string $method, string $path, array $data = []): array {
        // The profile of whoever holds the token is outside the account.
        $url = $this->url . ($path === '/profile' ? '/api/v1' : '/api/v1/accounts/' . $this->account) . $path;
        if ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data, '', '&');
        }
        [$code, $body] = $this->request($method, $url, $method === 'GET' ? null : json_encode((object)$data));
        $answer = json_decode($body, true);
        if ($code < 200 || $code >= 300) {
            $why = is_array($answer) ? (string)($answer['message'] ?? $answer['error'] ?? '') : '';
            throw new api_exception('error:chatwoot', $code, null, $why);
        }
        return is_array($answer) ? $answer : [];
    }

    /**
     * Writes a message with a file in a conversation (multipart, as Chatwoot takes files).
     *
     * @param int $conversation The conversation.
     * @param array $fields content, message_type and private.
     * @param string $path The file on disk.
     * @param string $name Its name.
     * @param string $mimetype Its type.
     * @return array The message.
     * @throws \local_oksigeniamarandu\local\api_exception If Chatwoot does not take it.
     */
    public function upload(int $conversation, array $fields, string $path, string $name, string $mimetype): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl(['ignoresecurity' => true]);
        $curl->setHeader(['api-access-token: ' . $this->token, 'Accept: application/json']);
        $params = [];
        foreach ($fields as $key => $value) {
            $params[$key] = is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
        }
        $params['attachments[]'] = new \CURLFile($path, $mimetype, $name);
        $url = $this->url . '/api/v1/accounts/' . $this->account . '/conversations/' . $conversation . '/messages';
        $body = $curl->post($url, $params, ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 120]);
        if ($curl->get_errno()) {
            throw new api_exception('error:chatnetwork', s($curl->error));
        }
        $code = (int)($curl->get_info()['http_code'] ?? 0);
        $answer = json_decode((string)$body, true);
        if ($code < 200 || $code >= 300 || !is_array($answer)) {
            throw new api_exception('error:chatwoot', $code);
        }
        return $answer;
    }

    /**
     * Downloads a file of Chatwoot (an attachment) to a path, following its redirections.
     *
     * @param string $url The address Chatwoot gave for it.
     * @param string $path Where to keep it.
     * @return array [status, content type].
     */
    public function download(string $url, string $path): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // Only files of this Chatwoot: an attachment never sends the site elsewhere.
        if (strpos($url, $this->url . '/') !== 0) {
            return [0, ''];
        }
        $curl = new \curl(['ignoresecurity' => true]);
        $fp = fopen($path, 'w');
        $curl->download_one($url, null, ['file' => $fp, 'followlocation' => true, 'maxredirs' => 5, 'timeout' => 120]);
        fclose($fp);
        $info = $curl->get_info();
        return [(int)($info['http_code'] ?? 0), (string)($info['content_type'] ?? '')];
    }

    /**
     * Sends a request and gives its status and body. The tests replace it.
     *
     * @param string $method The method.
     * @param string $url The whole address.
     * @param string|null $body The JSON body, or null.
     * @param string|null $token Another token, such as the one of the platform app.
     * @return array [status, body].
     * @throws api_exception If Chatwoot does not answer.
     */
    protected function request(string $method, string $url, ?string $body, ?string $token = null): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        // The address is the one the administrator connected: it may be a machine of the same network.
        $curl = new \curl(['ignoresecurity' => true]);
        // With hyphens: a proxy in front may drop headers with underscores, and Chatwoot takes both.
        $curl->setHeader(['api-access-token: ' . ($token ?? $this->token), 'Content-Type: application/json',
            'Accept: application/json']);
        $options = ['CURLOPT_CONNECTTIMEOUT' => 5, 'CURLOPT_TIMEOUT' => 20];
        if ($method === 'GET') {
            $answer = $curl->get($url, [], $options);
        } else if ($method === 'POST') {
            $answer = $curl->post($url, $body, $options);
        } else {
            $answer = $curl->post($url, $body, $options + ['CURLOPT_CUSTOMREQUEST' => $method]);
        }
        if ($curl->get_errno()) {
            throw new api_exception('error:chatnetwork', s($curl->error));
        }
        return [(int)($curl->get_info()['http_code'] ?? 0), (string)$answer];
    }
}
