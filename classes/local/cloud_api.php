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
 * Client of the WhatsApp Cloud API (WhatsApp Business Platform, by Meta), with the credentials of the site.
 *
 * Only the official API. The account and the number belong to the site, which pastes its own credentials.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class cloud_api {
    /** The Graph API version used when the site does not set one. */
    public const DEFAULT_GRAPH_VERSION = 'v23.0';

    /** Meta error codes with a message of their own; the rest get the general one, with the text of Meta. */
    private const KNOWN_ERRORS = [
        100 => 'error:parameter',
        190 => 'error:token',
        131030 => 'error:recipientnotallowed',
        132001 => 'error:templatenotfound',
    ];

    /** @var string The id Meta gives to the phone number of the site (not the number itself). */
    private $phonenumberid;

    /** @var string The access token, already decrypted. */
    private $accesstoken;

    /** @var string Graph API version, such as v23.0. */
    private $graphversion;

    /**
     * Builds a client for one phone number of the site.
     *
     * @param string $phonenumberid The id Meta gives to the phone number.
     * @param string $accesstoken The access token, in clear.
     * @param string $graphversion Graph API version.
     */
    public function __construct(
        string $phonenumberid,
        string $accesstoken,
        string $graphversion = self::DEFAULT_GRAPH_VERSION
    ) {
        $this->phonenumberid = $phonenumberid;
        $this->accesstoken = $accesstoken;
        $this->graphversion = $graphversion;
    }

    /**
     * Whether the site has pasted the credentials of its account.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $config = get_config('local_oksigeniamarandu');
        return !empty($config->phonenumberid) && !empty($config->accesstoken);
    }

    /**
     * Builds the client with the credentials of the site.
     *
     * @return self
     * @throws api_exception If the account is not connected or the stored token cannot be decrypted.
     */
    public static function from_config(): self {
        if (!self::is_configured()) {
            throw new api_exception('error:notconfigured');
        }
        $config = get_config('local_oksigeniamarandu');
        try {
            $token = \core\encryption::decrypt($config->accesstoken);
        } catch (\moodle_exception $e) {
            throw new api_exception('error:token');
        }
        $version = empty($config->graphversion) ? self::DEFAULT_GRAPH_VERSION : $config->graphversion;
        return new static($config->phonenumberid, $token, $version);
    }

    /**
     * Sends an approved template to one number.
     *
     * @param string $to Digits with the country code (see phone::normalise()).
     * @param string $template Name of the template in Meta.
     * @param string $language Language code of the template, such as es or en_US.
     * @param array $components Parameters of the template, in the shape the API takes.
     * @return string The id Meta gives to the message (wamid), to follow its status.
     * @throws api_exception If Meta does not take the message.
     */
    public function send_template(string $to, string $template, string $language, array $components = []): string {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $template,
                'language' => ['code' => $language],
            ],
        ];
        if ($components) {
            $payload['template']['components'] = $components;
        }
        return $this->send_message($payload);
    }

    /**
     * Sends plain text to one number. Only inside the 24 hours after the number last wrote; outside, Meta refuses it.
     *
     * @param string $to Digits with the country code (see phone::normalise()).
     * @param string $text The text, up to 4096 characters.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    public function send_text(string $to, string $text): string {
        return $this->send_message([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['preview_url' => false, 'body' => \core_text::substr($text, 0, 4096)],
        ]);
    }

    /**
     * Posts a message and returns its id.
     *
     * @param array $payload The message, in the shape of the API.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    private function send_message(array $payload): string {
        $response = $this->post($this->phonenumberid . '/messages', $payload);
        if (empty($response['messages'][0]['id'])) {
            throw new api_exception('error:unexpected');
        }
        return $response['messages'][0]['id'];
    }

    /**
     * Posts to the Graph API and turns its errors into exceptions.
     *
     * @param string $path Path after the version, such as <phone number id>/messages.
     * @param array $payload Body, sent as JSON.
     * @return array The decoded answer.
     * @throws api_exception If Meta returns an error or something that is not JSON.
     */
    protected function post(string $path, array $payload): array {
        $url = 'https://graph.facebook.com/' . $this->graphversion . '/' . $path;
        $response = json_decode($this->request($url, json_encode($payload)), true);
        if (!is_array($response)) {
            throw new api_exception('error:unexpected');
        }
        if (isset($response['error'])) {
            $code = (int)($response['error']['code'] ?? 0);
            $a = (object)[
                'code' => $code,
                'message' => s((string)($response['error']['message'] ?? '')),
            ];
            throw new api_exception(self::KNOWN_ERRORS[$code] ?? 'error:meta', $a, $code);
        }
        return $response;
    }

    /**
     * One HTTP POST with the token. Kept apart so that the tests can answer for Meta.
     *
     * @param string $url Full URL.
     * @param string $body JSON body.
     * @return string What Meta answered.
     * @throws api_exception If Meta cannot be reached.
     */
    protected function request(string $url, string $body): string {
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->accesstoken,
            'Content-Type: application/json',
        ]);
        $answer = $curl->post($url, $body, ['CURLOPT_TIMEOUT' => 20]);
        if ($curl->get_errno()) {
            throw new api_exception('error:network', s($curl->error));
        }
        return (string)$answer;
    }
}
