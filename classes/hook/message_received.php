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

namespace local_oksigeniamarandu\hook;

/**
 * Somebody who joined with a code of a plugin wrote to the number or the bot of the site something of their own: not the
 * opt-in or opt-out word, not a command of the menu, not a button of a question.
 *
 * It comes once for each link of that number or chat to a plugin (the code of invite() it joined with), so a listener
 * looks at get_component() first. Marandu does not keep what was written: the plugin that wants it keeps it. Only active
 * numbers and chats count, and a message that a webhook brings twice comes once.
 *
 * With set_reply(), the listener says what Marandu answers to the person at once (inside the 24 hours on WhatsApp, so it
 * is free). With set_handled(), the plugin took the message and answers later, with api::send_text(), or not at all. If
 * no listener does either, Marandu does what it did before: on Telegram, the bot says that nobody reads what is written
 * to it; on WhatsApp, nothing.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Somebody who joined with a code of a plugin wrote, on WhatsApp or Telegram, a message of their own.')]
#[\core\attribute\tags('message')]
final class message_received {
    /** @var string|null What Marandu answers to the person. */
    private $reply = null;

    /** @var bool Whether a plugin took the message. */
    private $handled = false;

    /**
     * The message.
     *
     * @param string $component The plugin of the code the person joined with.
     * @param int $itemid Its item, such as a household; 0 if the code was only for a user.
     * @param int|null $userid The user of the code, if it had one.
     * @param string $channel whatsapp or telegram.
     * @param string $address The number in E.164, or tg:<chat>.
     * @param string $kind What was sent: text, image, audio, video, document, sticker, location or contact.
     * @param string $text The text, or the caption of an image, a video or a document; empty if there is none.
     * @param int $time When it was written: Meta's time on WhatsApp, Telegram's on Telegram.
     * @param string $messageid The id of the message on its channel: the wamid, or tg.<chat>.<message>.
     * @param string|null $name The name the person has on WhatsApp or Telegram, if it came.
     */
    public function __construct(
        /** @var string The plugin. */
        private readonly string $component,
        /** @var int The item. */
        private readonly int $itemid,
        /** @var int|null The user. */
        private readonly ?int $userid,
        /** @var string The channel. */
        private readonly string $channel,
        /** @var string The number or chat. */
        private readonly string $address,
        /** @var string What was sent. */
        private readonly string $kind,
        /** @var string The text. */
        private readonly string $text,
        /** @var int When it was written. */
        private readonly int $time,
        /** @var string The id of the message. */
        private readonly string $messageid,
        /** @var string|null The name of the person. */
        private readonly ?string $name
    ) {
    }

    /**
     * The plugin of the code the person joined with.
     *
     * @return string
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * The item of the plugin, such as a household; 0 if the code was only for a user.
     *
     * @return int
     */
    public function get_itemid(): int {
        return $this->itemid;
    }

    /**
     * The user of the code, if it had one.
     *
     * @return int|null
     */
    public function get_userid(): ?int {
        return $this->userid;
    }

    /**
     * The channel: whatsapp or telegram.
     *
     * @return string
     */
    public function get_channel(): string {
        return $this->channel;
    }

    /**
     * Who wrote: the number in E.164, or tg:<chat>. It is what api::send_text() takes to answer only them.
     *
     * @return string
     */
    public function get_address(): string {
        return $this->address;
    }

    /**
     * What was sent: text, image, audio, video, document, sticker, location or contact. Marandu passes only the text; a
     * plugin that cannot take the rest can answer where to send it.
     *
     * @return string
     */
    public function get_kind(): string {
        return $this->kind;
    }

    /**
     * The text, or the caption of an image, a video or a document; empty if there is none. Plain text, as it was written.
     *
     * @return string
     */
    public function get_text(): string {
        return $this->text;
    }

    /**
     * When it was written.
     *
     * @return int
     */
    public function get_time(): int {
        return $this->time;
    }

    /**
     * The id of the message on its channel.
     *
     * @return string
     */
    public function get_messageid(): string {
        return $this->messageid;
    }

    /**
     * The name the person has on WhatsApp or Telegram, if it came. It is whatever they chose, not a checked name.
     *
     * @return string|null
     */
    public function get_name(): ?string {
        return $this->name;
    }

    /**
     * What Marandu answers to the person at once, plain text. The first plugin that answers is the one heard.
     *
     * @param string $reply The text.
     */
    public function set_reply(string $reply): void {
        $this->reply = $reply;
        $this->handled = true;
    }

    /**
     * What a listener said to answer, if any.
     *
     * @return string|null
     */
    public function get_reply(): ?string {
        return $this->reply;
    }

    /**
     * The plugin took the message: Marandu answers nothing of its own.
     */
    public function set_handled(): void {
        $this->handled = true;
    }

    /**
     * Whether a plugin took the message.
     *
     * @return bool
     */
    public function is_handled(): bool {
        return $this->handled;
    }
}
