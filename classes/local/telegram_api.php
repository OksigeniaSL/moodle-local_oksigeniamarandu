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
 * Client of the Bot API of Telegram, with the bot of the site.
 *
 * Its errors carry the HTTP code of Telegram, negative, so that the queue tells them from the codes of Meta: -429 is
 * too many messages, -403 a person who blocked the bot, -401 a token that is no longer valid.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class telegram_api {
    /** @var string The token of the bot, already decrypted. */
    private $token;

    /**
     * Builds the client.
     *
     * @param string $token The token of the bot.
     */
    public function __construct(string $token) {
        $this->token = $token;
    }

    /**
     * Whether the site has a bot.
     *
     * @return bool
     */
    public static function is_configured(): bool {
        $config = get_config('local_oksigeniamarandu');
        return !empty($config->telegramtoken) && !empty($config->telegrambot);
    }

    /**
     * The client with the bot of the site.
     *
     * @return static
     * @throws api_exception If the site has no bot.
     */
    public static function from_config(): self {
        $token = (string)get_config('local_oksigeniamarandu', 'telegramtoken');
        if ($token === '') {
            throw new api_exception('error:notconfigured');
        }
        try {
            return new static(\core\encryption::decrypt($token));
        } catch (\moodle_exception $e) {
            throw new api_exception('error:token');
        }
    }

    /**
     * The bot: its name and username, which also proves the token.
     *
     * @return array With first_name and username.
     */
    public function get_me(): array {
        return $this->call('getMe');
    }

    /**
     * Tells Telegram where to send what reaches the bot, with the secret it will send back in a header.
     *
     * @param string $url The address of the webhook, on this site.
     * @param string $secret The secret.
     */
    public function set_webhook(string $url, string $secret): void {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member', 'callback_query'],
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * Sends a text to a chat, with a button to a link if there is one.
     *
     * @param string $chatid The chat.
     * @param string $text The text, up to 4096 characters.
     * @param string|null $url Where the button goes.
     * @param string $label The label of the button.
     * @param bool $silent Without sound, for what does not hurry.
     * @return string The id of the message.
     */
    public function send_text(string $chatid, string $text, ?string $url = null, string $label = '', bool $silent = false): string {
        $params = [
            'chat_id' => $chatid,
            'text' => \core_text::substr($text, 0, 4096),
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
        ];
        if ($url !== null) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => $label, 'url' => $url]]]];
        }
        $answer = $this->call('sendMessage', $params);
        return (string)($answer['message_id'] ?? '');
    }

    /**
     * The menu of the bot, for the phones in a language or for all the rest; only in private chats.
     *
     * @param array $commands Each with command and description.
     * @param string|null $lang Two letters; null for the phones of any other language.
     */
    public function set_commands(array $commands, ?string $lang = null): void {
        $params = ['commands' => $commands, 'scope' => ['type' => 'all_private_chats']];
        if ($lang !== null) {
            $params['language_code'] = $lang;
        }
        $this->call('setMyCommands', $params);
    }

    /**
     * Stops the wait of a button that was pressed.
     *
     * @param string $id The callback query.
     */
    public function answer_callback(string $id): void {
        $this->call('answerCallbackQuery', ['callback_query_id' => $id]);
    }

    /**
     * Calls a method of the Bot API.
     *
     * @param string $method The method.
     * @param array $params Its parameters.
     * @return array The result.
     * @throws api_exception If Telegram refuses it or cannot be reached.
     */
    protected function call(string $method, array $params = []): array {
        $answer = json_decode($this->request($method, json_encode($params)), true);
        if (!is_array($answer)) {
            throw new api_exception('error:unexpected');
        }
        if (empty($answer['ok'])) {
            $code = (int)($answer['error_code'] ?? 0);
            $a = (object)['code' => $code, 'message' => s((string)($answer['description'] ?? ''))];
            throw new api_exception('error:telegram', $a, -$code);
        }
        return is_array($answer['result'] ?? null) ? $answer['result'] : [];
    }

    /**
     * Sends a request to Telegram. Kept apart so that the tests can answer for Telegram.
     *
     * @param string $method The method.
     * @param string $body The parameters, as JSON.
     * @return string What Telegram answered.
     * @throws api_exception If Telegram cannot be reached.
     */
    protected function request(string $method, string $body): string {
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $answer = $curl->post('https://api.telegram.org/bot' . $this->token . '/' . $method, $body, ['CURLOPT_TIMEOUT' => 20]);
        if ($curl->get_errno()) {
            throw new api_exception('error:telegramnetwork', s($curl->error));
        }
        return (string)$answer;
    }
}
