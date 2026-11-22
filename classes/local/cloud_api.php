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
    public const DEFAULT_GRAPH_VERSION = 'v26.0';

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

    /** @var string Graph API version, such as v26.0. */
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
     * Sends a text with buttons to answer it, up to three. Only inside the 24 hours after the number last wrote.
     *
     * @param string $to Digits with the country code.
     * @param string $text The text, up to 1024 characters.
     * @param array $buttons Each with id (up to 256 characters) and title (up to 20).
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    public function send_buttons(string $to, string $text, array $buttons): string {
        return $this->send_message([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => ['text' => \core_text::substr($text, 0, 1024)],
                'action' => ['buttons' => array_map(function ($button) {
                    return ['type' => 'reply', 'reply' => ['id' => $button['id'], 'title' => $button['title']]];
                }, array_slice($buttons, 0, 3))],
            ],
        ]);
    }

    /**
     * Sends a text with a list to choose from, for a question of more answers than the three buttons WhatsApp allows.
     * Only inside the 24 hours after the number last wrote.
     *
     * @param string $to Digits with the country code.
     * @param string $text The text, up to 1024 characters.
     * @param string $button The label of the button that opens the list, up to 20 characters.
     * @param array $rows Each with id (up to 200 characters) and title (up to 24), ten at most.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    public function send_list(string $to, string $text, string $button, array $rows): string {
        return $this->send_message([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'list',
                'body' => ['text' => \core_text::substr($text, 0, 1024)],
                'action' => [
                    'button' => \core_text::substr($button, 0, 20),
                    'sections' => [['rows' => array_map(function ($row) {
                        return ['id' => $row['id'], 'title' => \core_text::substr($row['title'], 0, 24)];
                    }, array_slice($rows, 0, 10))]],
                ],
            ],
        ]);
    }

    /**
     * Sends an image with its text below, such as the card of a confirmation. Only inside the 24 hours after the number
     * last wrote.
     *
     * @param string $to Digits with the country code.
     * @param string $mediaid The id Meta gave to the image when it went up (upload_media()).
     * @param string $caption The text, up to 1024 characters; empty for none.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    public function send_image(string $to, string $mediaid, string $caption): string {
        return $this->send_media($to, 'image', $mediaid, $caption);
    }

    /**
     * Sends a video (an MP4 with H.264, as the cards that move are) with its text below, such as a post to forward to a
     * channel. Only inside the 24 hours after the number last wrote.
     *
     * @param string $to Digits with the country code.
     * @param string $mediaid The id Meta gave to the video when it went up (upload_media()).
     * @param string $caption The text, up to 1024 characters; empty for none.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    public function send_video(string $to, string $mediaid, string $caption): string {
        return $this->send_media($to, 'video', $mediaid, $caption);
    }

    /**
     * Sends an image or a video already uploaded, with its text below if it has one.
     *
     * @param string $to Digits with the country code.
     * @param string $type image or video.
     * @param string $mediaid The id Meta gave to it.
     * @param string $caption The text, up to 1024 characters; empty for none.
     * @return string The id Meta gives to the message (wamid).
     * @throws api_exception If Meta does not take the message.
     */
    private function send_media(string $to, string $type, string $mediaid, string $caption): string {
        $media = ['id' => $mediaid];
        if ($caption !== '') {
            $media['caption'] = \core_text::substr($caption, 0, 1024);
        }
        return $this->send_message([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $type,
            $type => $media,
        ]);
    }

    /**
     * Uploads an image or a video to Meta, for the messages of the number: Meta keeps it 30 days.
     *
     * @param string $bytes The image, a JPEG or a PNG of up to 5 MB; or the video, an MP4 of up to 16 MB.
     * @param string $name Its name, with its extension.
     * @return string The id Meta gives to it.
     * @throws api_exception If Meta does not take it.
     */
    public function upload_media(string $bytes, string $name): string {
        $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $answer = $this->decode($this->request_file(
            $this->url($this->phonenumberid . '/media'),
            ['messaging_product' => 'whatsapp', 'type' => $type],
            $bytes,
            $type,
            $name
        ));
        if (empty($answer['id'])) {
            throw new api_exception('error:unexpected');
        }
        return (string)$answer['id'];
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
     * The number and the verified name Meta has for the phone of the site: the check that the details are right.
     *
     * @return array ['number' => '+34 600 00 00 00', 'name' => 'The name of the site'], as Meta writes them.
     * @throws api_exception If Meta does not take the details.
     */
    public function get_phone_number(): array {
        $response = $this->decode($this->request($this->url($this->phonenumberid .
            '?fields=display_phone_number,verified_name')));
        if (empty($response['display_phone_number'])) {
            throw new api_exception('error:unexpected');
        }
        return [
            'number' => (string)$response['display_phone_number'],
            'name' => (string)($response['verified_name'] ?? ''),
        ];
    }

    /**
     * Where Meta sends what reaches the number: the webhook of the number, of its account or of the app, the first one
     * that is set (by verify, «webhook_configuration»). Empty if Meta does not say.
     *
     * @return array application, whatsapp_business_account and phone_number, those there are.
     */
    public function webhook_configuration(): array {
        try {
            $response = $this->decode($this->request($this->url($this->phonenumberid . '?fields=webhook_configuration')));
        } catch (api_exception $e) {
            return [];
        }
        return is_array($response['webhook_configuration'] ?? null) ? $response['webhook_configuration'] : [];
    }

    /**
     * Whether the token reaches the account where the templates are. Meta keeps it apart from the number (the messaging
     * account and the WhatsApp account of a business portfolio): a system user with only the second one reaches the
     * number and not the templates.
     *
     * @param string $wabaid The WhatsApp Business account.
     * @return bool
     */
    public function reaches_account(string $wabaid): bool {
        try {
            $response = $this->decode($this->request($this->url($wabaid . '?fields=id')));
        } catch (api_exception $e) {
            return false;
        }
        return (string)($response['id'] ?? '') === $wabaid;
    }

    /**
     * Sends a template to Meta for review.
     *
     * @param string $wabaid The WhatsApp Business account.
     * @param array $payload Name, language, category and components, in the shape of the API.
     * @return array What Meta answers: id, status and category.
     * @throws api_exception If Meta does not take it.
     */
    public function create_template(string $wabaid, array $payload): array {
        return $this->post($wabaid . '/message_templates', $payload);
    }

    /**
     * Changes the text of a template Meta has already: it goes back to its review. An approved one, once a day and ten
     * times a month at most, as Meta says.
     *
     * @param string $templateid The id of the template in Meta.
     * @param array $components Its components, as when it was created.
     * @return array What Meta answers.
     * @throws api_exception If Meta does not take it.
     */
    public function edit_template(string $templateid, array $components): array {
        return $this->post($templateid, ['components' => $components]);
    }

    /**
     * Deletes one template from Meta, by its id: the others with its name, in other languages, stay.
     *
     * @param string $wabaid The WhatsApp Business account.
     * @param string $templateid The id of the template in Meta.
     * @param string $name Its name, which Meta asks for together with the id.
     * @return array What Meta answers.
     * @throws api_exception If Meta does not take it.
     */
    public function delete_template(string $wabaid, string $templateid, string $name): array {
        $query = http_build_query(['hsm_id' => $templateid, 'name' => $name], '', '&');
        return $this->remove($wabaid . '/message_templates?' . $query);
    }

    /**
     * The templates of the account, with their status, category and why they were rejected.
     *
     * @param string $wabaid The WhatsApp Business account.
     * @return array[]
     * @throws api_exception If Meta does not answer.
     */
    public function list_templates(string $wabaid): array {
        $response = $this->decode($this->request($this->url($wabaid .
            '/message_templates?fields=name,language,status,category,rejected_reason&limit=250')));
        return array_values(array_filter((array)($response['data'] ?? []), 'is_array'));
    }

    /**
     * Asks Meta, once after a number of the app of WhatsApp Business joins the API (coexistence), for its contacts
     * (smb_app_state_sync) or its chats (history): they come afterwards to the webhook.
     *
     * @param string $phonenumberid The number.
     * @param string $type smb_app_state_sync or history.
     * @return string The id of the request, for the support of Meta.
     * @throws api_exception If Meta does not take it.
     */
    public function request_app_data(string $phonenumberid, string $type): string {
        $answer = $this->post(rawurlencode($phonenumberid) . '/smb_app_data', ['messaging_product' => 'whatsapp',
            'sync_type' => $type]);
        return (string)($answer['request_id'] ?? '');
    }

    /**
     * What the messages of an account cost, day by day and by category, as Meta counts them, and its currency.
     *
     * @param string $wabaid The WhatsApp Business account.
     * @param int $start From, a Unix time.
     * @param int $end To, a Unix time.
     * @return array currency and points (each with start, end, pricing_category, pricing_type, volume and cost).
     * @throws api_exception If Meta does not give them.
     */
    public function pricing(string $wabaid, int $start, int $end): array {
        $fields = 'currency,pricing_analytics.start(' . $start . ').end(' . $end . ').granularity(DAILY)'
            . '.dimensions(["PRICING_CATEGORY","PRICING_TYPE"])';
        $answer = $this->decode($this->request($this->url(rawurlencode($wabaid) . '?fields=' . rawurlencode($fields))));
        $points = [];
        foreach ($answer['pricing_analytics']['data'] ?? [] as $block) {
            foreach ($block['data_points'] ?? [] as $point) {
                $points[] = $point;
            }
        }
        return ['currency' => (string)($answer['currency'] ?? ''), 'points' => $points];
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
        return $this->decode($this->request($this->url($path), json_encode($payload)));
    }

    /**
     * Deletes something in the Graph API and turns its errors into exceptions.
     *
     * @param string $path Path after the version, with its query.
     * @return array The decoded answer.
     * @throws api_exception If Meta returns an error or something that is not JSON.
     */
    protected function remove(string $path): array {
        return $this->decode($this->request($this->url($path), null, true));
    }

    /**
     * The full URL of a path of the Graph API.
     *
     * @param string $path Path after the version.
     * @return string
     */
    private function url(string $path): string {
        return 'https://graph.facebook.com/' . $this->graphversion . '/' . $path;
    }

    /**
     * Decodes an answer of Meta and turns its errors into exceptions.
     *
     * @param string $answer What Meta answered.
     * @return array
     * @throws api_exception If it is an error or not JSON.
     */
    private function decode(string $answer): array {
        $response = json_decode($answer, true);
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
     * One request with a file, as a form, with the token. Kept apart so that the tests can answer for Meta.
     *
     * @param string $url Full URL.
     * @param array $fields The fields of the form, as text.
     * @param string $bytes The file.
     * @param string $type Its media type.
     * @param string $name Its name.
     * @return string What Meta answered.
     * @throws api_exception If Meta cannot be reached.
     */
    protected function request_file(string $url, array $fields, string $bytes, string $type, string $name): string {
        global $CFG;
        // The class of the requests: not every script has it loaded.
        require_once($CFG->libdir . '/filelib.php');
        $path = make_request_directory() . '/' . clean_param($name, PARAM_FILE);
        file_put_contents($path, $bytes);
        $fields['file'] = new \CURLFile($path, $type, $name);
        $curl = new \curl();
        $curl->setHeader(['Authorization: Bearer ' . $this->accesstoken]);
        $answer = $curl->post($url, $fields, ['CURLOPT_TIMEOUT' => 60]);
        if ($curl->get_errno()) {
            throw new api_exception('error:network', s($curl->error));
        }
        return (string)$answer;
    }

    /**
     * One HTTP request with the token: POST with a body, GET without, or DELETE. Kept apart so that the tests can answer
     * for Meta.
     *
     * @param string $url Full URL.
     * @param string|null $body JSON body, or null for a GET.
     * @param bool $delete Whether it is a DELETE.
     * @return string What Meta answered.
     * @throws api_exception If Meta cannot be reached.
     */
    protected function request(string $url, ?string $body = null, bool $delete = false): string {
        global $CFG;
        // The class of the requests: not every script has it loaded.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader([
            'Authorization: Bearer ' . $this->accesstoken,
            'Content-Type: application/json',
        ]);
        if ($delete) {
            // The delete() of Moodle's curl adds a user and password of its own, which would clash with the token.
            $answer = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 20, 'CURLOPT_CUSTOMREQUEST' => 'DELETE']);
        } else if ($body === null) {
            $answer = $curl->get($url, [], ['CURLOPT_TIMEOUT' => 20]);
        } else {
            $answer = $curl->post($url, $body, ['CURLOPT_TIMEOUT' => 20]);
        }
        if ($curl->get_errno()) {
            throw new api_exception('error:network', s($curl->error));
        }
        return (string)$answer;
    }
}
