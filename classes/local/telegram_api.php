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
            'allowed_updates' => ['message', 'my_chat_member', 'callback_query', 'message_reaction_count'],
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
        $params = self::text_params($chatid, $text, $silent);
        if ($url !== null) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => $label, 'url' => $url]]]];
        }
        $answer = $this->call('sendMessage', $params);
        return (string)($answer['message_id'] ?? '');
    }

    /**
     * Sends a question to a chat: its text, a row with a button for each answer and, below, the button to its link.
     *
     * @param string $chatid The chat.
     * @param string $text The text.
     * @param array $choices Each with data (what comes back, up to 64 bytes) and text (the label).
     * @param string|null $url Where the button of the link goes.
     * @param string $label The label of that button.
     * @param bool $silent Without sound.
     * @return string The id of the message.
     */
    public function send_question(
        string $chatid,
        string $text,
        array $choices,
        ?string $url = null,
        string $label = '',
        bool $silent = false
    ): string {
        $rows = [array_map(function ($choice) {
            return ['text' => $choice['text'], 'callback_data' => $choice['data']];
        }, $choices)];
        if ($url !== null) {
            $rows[] = [['text' => $label, 'url' => $url]];
        }
        $params = self::text_params($chatid, $text, $silent) + ['reply_markup' => ['inline_keyboard' => $rows]];
        $answer = $this->call('sendMessage', $params);
        return (string)($answer['message_id'] ?? '');
    }

    /**
     * The parameters of a text: with several lines, the first one in bold.
     *
     * @param string $chatid The chat.
     * @param string $text The text, cut to 4096 characters.
     * @param bool $silent Without sound.
     * @return array
     */
    private static function text_params(string $chatid, string $text, bool $silent): array {
        $text = \core_text::substr($text, 0, 4096);
        $params = [
            'chat_id' => $chatid,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
            'disable_notification' => $silent,
        ];
        // With several lines, the first one is the title, in bold. Entities need nothing escaped; Telegram measures in UTF-16.
        $first = strstr($text, "\n", true);
        if ($first !== false && trim($first) !== '') {
            $length = intdiv(strlen(mb_convert_encoding($first, 'UTF-16LE', 'UTF-8')), 2);
            $params['entities'] = [['type' => 'bold', 'offset' => 0, 'length' => $length]];
        }
        return $params;
    }

    /**
     * Sends a card to a chat: a rich message with the button inside. An image goes up once for each bot: Telegram gives it
     * an id for the next ones, so a notice for a whole school does not upload it for each person.
     *
     * @param string $chatid The chat.
     * @param array $rich The clean parts of the card (rich::clean()).
     * @param string|null $url Where the button goes.
     * @param string $label The label of the button.
     * @param bool $silent Without sound, for what does not hurry.
     * @return string The id of the message.
     */
    public function send_rich(string $chatid, array $rich, ?string $url = null, string $label = '', bool $silent = false): string {
        $bytes = empty($rich['image']) ? null : rich::image($rich['image']);
        $cache = \cache::make('local_oksigeniamarandu', 'telegramphotos');
        // The id of a file only works for the bot that uploaded it.
        $key = $bytes === null ? null : sha1(strstr($this->token, ':', true) . ':' . sha1($bytes));
        $fileid = $key === null ? false : $cache->get($key);
        $media = $bytes === null ? null : ($fileid ?: 'attach://image');
        $params = [
            'chat_id' => $chatid,
            'rich_message' => ['blocks' => rich::blocks($rich, $media, $url, $label)],
            'disable_notification' => $silent,
        ];
        try {
            $answer = $bytes === null || $fileid ? $this->call('sendRichMessage', $params)
                : $this->answer($this->request_file('sendRichMessage', self::fields($params), 'image', $bytes));
        } catch (api_exception $e) {
            if ($fileid) {
                // Perhaps Telegram no longer has it: the next one uploads it again.
                $cache->delete($key);
            }
            throw $e;
        }
        if ($key !== null && !$fileid && ($new = self::photo_id($answer))) {
            $cache->set($key, $new);
        }
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
     * Sends a text in reply to a message of a chat, without buttons.
     *
     * @param string $chatid The chat.
     * @param string $text The text.
     * @param int $replyto The message it answers.
     */
    public function reply_to(string $chatid, string $text, int $replyto): void {
        $this->call('sendMessage', self::text_params($chatid, $text, false) + [
            'reply_parameters' => ['message_id' => $replyto, 'allow_sending_without_reply' => true],
        ]);
    }

    /**
     * Sends an animation (an MP4 without sound) with its text below and a button to a link, such as a post of the channel.
     * It goes up once for each bot, as the image of a card.
     *
     * @param string $chatid The chat.
     * @param string $url The animation: a URL of this site with an MP4.
     * @param string $caption The text below, up to 1024 characters; the first line, in bold.
     * @param string|null $link Where the button goes.
     * @param string $label The label of the button.
     * @param bool $silent Without sound.
     * @return string The id of the message.
     * @throws api_exception If it is not an MP4 Telegram takes, or Telegram refuses it.
     */
    public function send_animation(
        string $chatid,
        string $url,
        string $caption,
        ?string $link = null,
        string $label = '',
        bool $silent = false
    ): string {
        $text = self::text_params($chatid, \core_text::substr($caption, 0, 1024), $silent);
        $params = ['chat_id' => $chatid, 'caption' => $text['text'], 'disable_notification' => $silent]
            + (isset($text['entities']) ? ['caption_entities' => $text['entities']] : []);
        if ($link !== null) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => $label, 'url' => $link]]]];
        }
        $answer = $this->animation('sendAnimation', $url, function (string $file) use ($params): array {
            return $params + ['animation' => $file];
        });
        return (string)($answer['message_id'] ?? '');
    }

    /**
     * Puts an animation, with its text below and its button, in place of what a message already sent shows: the same
     * message, without a new one.
     *
     * @param string $chatid The chat.
     * @param int $messageid The message.
     * @param string $url The animation: a URL of this site with an MP4.
     * @param string $caption The text below, up to 1024 characters; the first line, in bold.
     * @param string|null $link Where the button goes.
     * @param string $label The label of the button.
     * @throws api_exception If it is not an MP4 Telegram takes, or Telegram refuses it.
     */
    public function edit_animation(
        string $chatid,
        int $messageid,
        string $url,
        string $caption,
        ?string $link = null,
        string $label = ''
    ): void {
        $text = self::text_params($chatid, \core_text::substr($caption, 0, 1024), true);
        $media = ['type' => 'animation', 'caption' => $text['text']]
            + (isset($text['entities']) ? ['caption_entities' => $text['entities']] : []);
        $params = ['chat_id' => $chatid, 'message_id' => $messageid];
        if ($link !== null) {
            $params['reply_markup'] = ['inline_keyboard' => [[['text' => $label, 'url' => $link]]]];
        }
        $this->animation('editMessageMedia', $url, function (string $file) use ($params, $media): array {
            return $params + ['media' => $media + ['media' => $file]];
        });
    }

    /**
     * Calls a method with an animation, which goes up once for each bot, as the image of a card.
     *
     * @param string $method The method.
     * @param string $url The animation: a URL of this site with an MP4.
     * @param callable $params The parameters of the method, given where the animation is: its id, or attach://animation.
     * @return array What Telegram answered.
     * @throws api_exception If it is not an MP4 Telegram takes, or Telegram refuses it.
     */
    private function animation(string $method, string $url, callable $params): array {
        $bytes = rich::video($url);
        if ($bytes === null) {
            throw new api_exception('error:unexpected');
        }
        $cache = \cache::make('local_oksigeniamarandu', 'telegramphotos');
        // Under its own key: the ids kept before 0.30.1 may be of a document, which would go as one again.
        $key = sha1('animation:' . strstr($this->token, ':', true) . ':' . sha1($bytes));
        $fileid = $cache->get($key);
        try {
            $answer = $fileid ? $this->call($method, $params($fileid))
                : $this->answer($this->request_file($method, self::fields($params('attach://animation')), 'animation', $bytes));
        } catch (api_exception $e) {
            if ($fileid) {
                // Perhaps Telegram no longer has it: the next one uploads it again.
                $cache->delete($key);
            }
            throw $e;
        }
        // Only an animation is kept: what Telegram took as a document is uploaded again the next time.
        $new = (string)($answer['animation']['file_id'] ?? '');
        if (!$fileid && $new !== '') {
            $cache->set($key, $new);
        }
        return $answer;
    }

    /**
     * A chat as Telegram has it: a channel, by its id or by its name after an at sign.
     *
     * @param string $chatid The id, or @ and its name.
     * @return array With id, type, title and username.
     */
    public function get_chat(string $chatid): array {
        return $this->call('getChat', ['chat_id' => $chatid]);
    }

    /**
     * What somebody is in a chat: for the bot in a channel, whether it is an administrator that can post.
     *
     * @param string $chatid The chat.
     * @param int $userid The member, such as the bot.
     * @return array With status and, for an administrator, its rights.
     */
    public function get_chat_member(string $chatid, int $userid): array {
        return $this->call('getChatMember', ['chat_id' => $chatid, 'user_id' => $userid]);
    }

    /**
     * How many people a chat has, such as a channel.
     *
     * @param string $chatid The chat.
     * @return int
     */
    public function member_count(string $chatid): int {
        // Its result is a number, not an object: answer() checks the errors, the number is read here.
        $body = $this->request('getChatMemberCount', json_encode(['chat_id' => $chatid]));
        $this->answer($body);
        return (int)(json_decode($body, true)['result'] ?? 0);
    }

    /**
     * Takes a message out of a chat, such as a post of the channel.
     *
     * @param string $chatid The chat.
     * @param int $messageid The message.
     */
    public function delete_message(string $chatid, int $messageid): void {
        $this->call('deleteMessage', ['chat_id' => $chatid, 'message_id' => $messageid]);
    }

    /**
     * Changes the text of a message and takes its buttons away, such as a question already answered.
     *
     * @param string $chatid The chat.
     * @param int $messageid The message.
     * @param string $text The new text.
     */
    public function edit_text(string $chatid, int $messageid, string $text): void {
        $this->call('editMessageText', [
            'chat_id' => $chatid,
            'message_id' => $messageid,
            'text' => \core_text::substr($text, 0, 4096),
            'link_preview_options' => ['is_disabled' => true],
        ]);
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
        return $this->answer($this->request($method, json_encode($params)));
    }

    /**
     * What Telegram answered, or the error it gave.
     *
     * @param string $body The answer.
     * @return array The result.
     * @throws api_exception If Telegram refuses it.
     */
    private function answer(string $body): array {
        $answer = json_decode($body, true);
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
        global $CFG;
        // The class of the requests: not every script has it loaded.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setHeader(['Content-Type: application/json']);
        $answer = $curl->post('https://api.telegram.org/bot' . $this->token . '/' . $method, $body, ['CURLOPT_TIMEOUT' => 20]);
        if ($curl->get_errno()) {
            throw new api_exception('error:telegramnetwork', s($curl->error));
        }
        return (string)$answer;
    }

    /**
     * Sends a request with a file to Telegram. Kept apart so that the tests can answer for Telegram.
     *
     * @param string $method The method.
     * @param array $fields The parameters, each as text.
     * @param string $name The name of the file, which the parameters refer to as attach://name.
     * @param string $bytes The file.
     * @return string What Telegram answered.
     * @throws api_exception If Telegram cannot be reached.
     */
    protected function request_file(string $method, array $fields, string $name, string $bytes): string {
        global $CFG;
        // The class of the requests: not every script has it loaded.
        require_once($CFG->libdir . '/filelib.php');
        $path = make_request_directory() . '/' . $name;
        file_put_contents($path, $bytes);
        $fields[$name] = new \CURLFile($path, (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::filename($name, $bytes));
        $curl = new \curl();
        $answer = $curl->post('https://api.telegram.org/bot' . $this->token . '/' . $method, $fields, ['CURLOPT_TIMEOUT' => 60]);
        if ($curl->get_errno()) {
            throw new api_exception('error:telegramnetwork', s($curl->error));
        }
        return (string)$answer;
    }

    /**
     * The name a file goes up with: its own, with the extension of what it is. Telegram goes by it, not by the type of
     * the request: an MP4 called just "animation" arrives as a document to download, not as an animation.
     *
     * @param string $name The name, without an extension.
     * @param string $bytes The file.
     * @return string
     */
    public static function filename(string $name, string $bytes): string {
        $extensions = ['video/mp4' => 'mp4', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif',
            'image/webp' => 'webp'];
        $extension = $extensions[(new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes)] ?? '';
        return $extension === '' ? $name : $name . '.' . $extension;
    }

    /**
     * The parameters of a method as the fields of a form, where what is not text goes as JSON.
     *
     * @param array $params The parameters.
     * @return string[]
     */
    private static function fields(array $params): array {
        return array_map(function ($value) {
            return is_string($value) ? $value : json_encode($value);
        }, $params);
    }

    /**
     * The id Telegram gave to the photo of a card it has just sent.
     *
     * @param array $message The message.
     * @return string|null The id of its largest size.
     */
    private static function photo_id(array $message): ?string {
        foreach ($message['rich_message']['blocks'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'photo' && !empty($block['photo'])) {
                $largest = end($block['photo']);
                return $largest['file_id'] ?? null;
            }
        }
        return null;
    }
}
