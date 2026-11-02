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
 * Somebody answered a question that a plugin sent with api::ask(), with one of its buttons.
 *
 * It comes once for each answer, for any plugin: a listener looks at get_component() first. Pressing again, or the other
 * button, is another answer. Marandu has already checked that it comes from the number or chat the question went to. With
 * set_reply(), the listener says what Marandu answers to the person; without it, a short acknowledgement goes.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Somebody answered, on WhatsApp or Telegram, a question that a plugin sent with Oksigenia Marandu.')]
#[\core\attribute\tags('message')]
final class answer_received {
    /** @var string|null What Marandu answers to the person. */
    private $reply = null;

    /**
     * The answer.
     *
     * @param string $component The plugin that asked.
     * @param string $notice The question, as the plugin declared it.
     * @param string $ref What the plugin gave to know the question again.
     * @param int $itemid The item of the plugin it went to, such as an adult of the family records; 0 for a user.
     * @param string $option The key of the answer, as declared.
     * @param string $channel whatsapp or telegram.
     * @param string $address The number in E.164, or tg:<chat>.
     * @param int $time When it was answered: Meta's time on WhatsApp, the arrival on Telegram.
     */
    public function __construct(
        /** @var string The plugin that asked. */
        private readonly string $component,
        /** @var string The question. */
        private readonly string $notice,
        /** @var string The reference of the plugin. */
        private readonly string $ref,
        /** @var int The item it went to. */
        private readonly int $itemid,
        /** @var string The key of the answer. */
        private readonly string $option,
        /** @var string The channel. */
        private readonly string $channel,
        /** @var string The number or chat. */
        private readonly string $address,
        /** @var int When it was answered. */
        private readonly int $time
    ) {
    }

    /**
     * The plugin that asked.
     *
     * @return string
     */
    public function get_component(): string {
        return $this->component;
    }

    /**
     * The question, as the plugin declared it.
     *
     * @return string
     */
    public function get_notice(): string {
        return $this->notice;
    }

    /**
     * What the plugin gave to know the question again.
     *
     * @return string
     */
    public function get_ref(): string {
        return $this->ref;
    }

    /**
     * The item of the plugin it went to; 0 if it went to a user.
     *
     * @return int
     */
    public function get_itemid(): int {
        return $this->itemid;
    }

    /**
     * The key of the answer, as declared.
     *
     * @return string
     */
    public function get_option(): string {
        return $this->option;
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
     * Who answered: the number in E.164, or tg:<chat>.
     *
     * @return string
     */
    public function get_address(): string {
        return $this->address;
    }

    /**
     * When it was answered.
     *
     * @return int
     */
    public function get_time(): int {
        return $this->time;
    }

    /**
     * What Marandu answers to the person, plain text.
     *
     * @param string $reply The text.
     */
    public function set_reply(string $reply): void {
        $this->reply = $reply;
    }

    /**
     * What a listener said to answer, if any.
     *
     * @return string|null
     */
    public function get_reply(): ?string {
        return $this->reply;
    }
}
