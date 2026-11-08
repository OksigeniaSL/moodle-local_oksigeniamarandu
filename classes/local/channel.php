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
 * The channel of Telegram of the site: what the plugins post there, and whoever reviews it before it goes.
 *
 * The bot is an administrator of the channel that can post. A plugin declares what it posts in its lib.php:
 *
 *     function local_example_oksigeniamarandu_posts(): array {
 *         return ['open_day' => ['review' => true]];
 *     }
 *
 * with its name and when it goes in its language packs (marandu:post_open_day, marandu:post_open_day_desc), and posts
 * with api::channel_post(). The site says, for each kind, whether it goes by itself, after whoever reviews it, or not at
 * all. Whoever reviews (local/oksigeniamarandu:channel, with their chat joined to the bot) gets each post first in their
 * chat with the bot, as it will look, with «Post» and «Skip», only in the hours of review; what is urgent, at any hour.
 * The posts go out in the hours of the channel, without sound; what is urgent, at once and with sound.
 *
 * Marandu posts by itself what is written in the announcements forum of the front page: it is written by a person
 * already, so it goes without review; with «Send now» (mailnow), at once.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class channel {
    /** The posts. */
    public const TABLE = 'local_oksigeniamarandu_post';

    /** How many people the channel had each day. */
    public const STATS = 'local_oksigeniamarandu_chstat';

    /** What the site says of a kind of post: it goes by itself, after a review, or not at all. */
    public const POLICIES = ['auto', 'review', 'off'];

    /** The hours of review (from Monday to Friday) and those of the channel (every day), until the site says others. */
    public const DEFAULTS = ['reviewdays' => '1,2,3,4,5', 'reviewfrom' => '08:00', 'reviewto' => '15:00',
        'postdays' => '0,1,2,3,4,5,6', 'postfrom' => '08:00', 'postto' => '21:00'];

    /** What Marandu posts by itself: the announcements of the front page. */
    public const NEWS = 'news';

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** Tries after an error before giving up on a post. */
    private const ATTEMPTS = 5;

    /** What Telegram says when the channel itself is the problem, not the post. */
    private const CHANNELERRORS = ['chat not found', 'not a member', 'administrator rights', 'not enough rights', 'kicked',
        'have no rights'];

    /** @var array Kinds declared by the tests, by component. */
    private static $fortests = [];

    /**
     * Every kind of post declared on the site, by "component/kind".
     *
     * @return \stdClass[] Each with component, kind, key and review (whether it waits for a review unless the site says).
     */
    public static function kinds(): array {
        $declared = [];
        foreach (get_plugins_with_function('oksigeniamarandu_posts', 'lib.php') as $type => $plugins) {
            foreach ($plugins as $plugin => $function) {
                $declared[$type . '_' . $plugin] = (array)$function();
            }
        }
        foreach (self::$fortests as $component => $kinds) {
            $declared[$component] = $kinds + ($declared[$component] ?? []);
        }
        $all = [];
        foreach ($declared as $component => $kinds) {
            foreach ($kinds as $kind => $definition) {
                if (!is_string($kind) || !preg_match('/^[a-z0-9_]{1,40}$/', $kind)) {
                    continue;
                }
                $definition = (array)$definition;
                $all[$component . '/' . $kind] = (object)[
                    'component' => $component,
                    'kind' => $kind,
                    'key' => $component . '/' . $kind,
                    'review' => !array_key_exists('review', $definition) || !empty($definition['review']),
                ];
            }
        }
        ksort($all);
        return $all;
    }

    /**
     * Kinds declared by the tests, as a plugin would; only while the tests run.
     *
     * @param array $kinds By component, the declarations; [] to forget them.
     */
    public static function declare_for_tests(array $kinds): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for the tests');
        }
        self::$fortests = $kinds;
    }

    /**
     * The name of a kind of post, as its plugin says it.
     *
     * @param \stdClass $kind The kind.
     * @return string
     */
    public static function name(\stdClass $kind): string {
        $id = 'marandu:post_' . $kind->kind;
        return get_string_manager()->string_exists($id, $kind->component) ? get_string($id, $kind->component) : $kind->kind;
    }

    /**
     * When a kind of post goes, as its plugin says it; empty if it does not.
     *
     * @param \stdClass $kind The kind.
     * @return string
     */
    public static function description(\stdClass $kind): string {
        $id = 'marandu:post_' . $kind->kind . '_desc';
        return get_string_manager()->string_exists($id, $kind->component) ? get_string($id, $kind->component) : '';
    }

    /**
     * What the site says of a kind of post: auto, review or off.
     *
     * @param string $key "component/kind".
     * @return string
     */
    public static function policy(string $key): string {
        $kinds = self::kinds();
        if (!isset($kinds[$key])) {
            return 'off';
        }
        $chosen = json_decode((string)get_config(self::COMPONENT, 'channelpolicy'), true);
        $policy = is_array($chosen) ? ($chosen[$key] ?? null) : null;
        return in_array($policy, self::POLICIES, true) ? $policy : ($kinds[$key]->review ? 'review' : 'auto');
    }

    /**
     * Saves what the site says of the kinds of post.
     *
     * @param array $policies By "component/kind", auto, review or off; the rest is left out.
     */
    public static function set_policies(array $policies): void {
        $kinds = self::kinds();
        $chosen = json_decode((string)get_config(self::COMPONENT, 'channelpolicy'), true);
        $chosen = is_array($chosen) ? $chosen : [];
        foreach ($policies as $key => $policy) {
            if (isset($kinds[$key]) && in_array($policy, self::POLICIES, true)) {
                $chosen[$key] = $policy;
            }
        }
        set_config('channelpolicy', json_encode($chosen), self::COMPONENT);
    }

    /**
     * The channel the site posts in.
     *
     * @return \stdClass|null With id, title, username and problem (norights, or empty); null without one.
     */
    public static function current(): ?\stdClass {
        $config = get_config(self::COMPONENT);
        if (empty($config->channelid)) {
            return null;
        }
        return (object)[
            'id' => (string)$config->channelid,
            'title' => (string)($config->channeltitle ?? ''),
            'username' => (string)($config->channelusername ?? ''),
            'problem' => (string)($config->channelproblem ?? ''),
        ];
    }

    /**
     * Whether the site has a bot and a channel to post in.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return telegram_api::is_configured() && self::current() !== null;
    }

    /**
     * The channel where the bot was last made an administrator that can post, while the site has not taken it.
     *
     * @return \stdClass|null With id, title and username.
     */
    public static function candidate(): ?\stdClass {
        $candidate = json_decode((string)get_config(self::COMPONENT, 'channelcandidate'), true);
        return is_array($candidate) && !empty($candidate['id']) ? (object)$candidate : null;
    }

    /**
     * What Telegram says when the rights of the bot change in a channel: it is offered to the site if it can post there;
     * for the channel of the site, it says whether it still can.
     *
     * @param array $member The my_chat_member of the update.
     */
    public static function member_changed(array $member): void {
        $chat = $member['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'channel' || empty($chat['id'])) {
            return;
        }
        $new = $member['new_chat_member'] ?? [];
        $canpost = ($new['status'] ?? '') === 'administrator' && !empty($new['can_post_messages']);
        $id = (string)$chat['id'];
        $current = self::current();
        if ($current && $current->id === $id) {
            if ($canpost) {
                unset_config('channelproblem', self::COMPONENT);
                set_config('channeltitle', (string)($chat['title'] ?? $current->title), self::COMPONENT);
            } else {
                set_config('channelproblem', 'norights', self::COMPONENT);
            }
            work::changed();
            return;
        }
        $candidate = self::candidate();
        if ($canpost) {
            set_config('channelcandidate', json_encode([
                'id' => $id,
                'title' => (string)($chat['title'] ?? ''),
                'username' => (string)($chat['username'] ?? ''),
            ]), self::COMPONENT);
        } else if ($candidate && $candidate->id === $id) {
            unset_config('channelcandidate', self::COMPONENT);
        }
    }

    /**
     * Takes a channel for the site, once Telegram says the bot can post in it.
     *
     * @param telegram_api $api The bot.
     * @param string $chat The id of the channel, its name after an at sign, or its link t.me/name.
     * @return \stdClass The channel, as current() gives it.
     * @throws \moodle_exception If it is not a channel or the bot cannot post in it.
     */
    public static function connect(telegram_api $api, string $chat): \stdClass {
        $chat = trim($chat);
        if (preg_match('~^(?:https?://)?t\.me/([A-Za-z][A-Za-z0-9_]{4,31})/?$~', $chat, $parts)) {
            $chat = '@' . $parts[1];
        } else if (preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $chat)) {
            $chat = '@' . $chat;
        }
        $info = $api->get_chat($chat);
        if (($info['type'] ?? '') !== 'channel' || empty($info['id'])) {
            throw new \moodle_exception('channel:notchannel', self::COMPONENT);
        }
        $botid = (int)get_config(self::COMPONENT, 'telegrambotid') ?: (int)($api->get_me()['id'] ?? 0);
        $member = $api->get_chat_member((string)$info['id'], $botid);
        if (($member['status'] ?? '') !== 'administrator' || empty($member['can_post_messages'])) {
            throw new \moodle_exception('channel:notadmin', self::COMPONENT);
        }
        set_config('channelid', (string)$info['id'], self::COMPONENT);
        set_config('channeltitle', (string)($info['title'] ?? ''), self::COMPONENT);
        set_config('channelusername', (string)($info['username'] ?? ''), self::COMPONENT);
        unset_config('channelproblem', self::COMPONENT);
        unset_config('channelcandidate', self::COMPONENT);
        work::changed();
        return self::current();
    }

    /**
     * Stops posting in the channel. What is waiting keeps waiting, for another channel.
     */
    public static function disconnect(): void {
        foreach (['channelid', 'channeltitle', 'channelusername', 'channelproblem'] as $name) {
            unset_config($name, self::COMPONENT);
        }
        work::changed();
    }

    /**
     * Puts a post on its way: to whoever reviews it, or straight to its time, as the site says of its kind. With a key, a
     * post of the same kind that has not gone out yet is changed instead; one that is already in the channel stays.
     *
     * @param string $component The plugin.
     * @param string $kind The kind of post, as declared.
     * @param array $content Any of title, image (a URL of this site with a PNG or a JPEG), animation (a URL of this site
     *                       with an MP4, which goes instead of the card), card (a card Marandu makes from its templates,
     *                       as cards::clean() takes it, which goes as its image and its animation), lines, text, url (a
     *                       web address, for its button), label (of the button) and utm (false for a link that must go
     *                       as it is); a title or a text at least.
     * @param int|null $when Not before this time; now if null.
     * @param bool $urgent Whether it goes at any hour, and its review too.
     * @param string $key What the plugin knows it by, up to 100 characters, to change it or take it back.
     * @param int|null $expires After this time it is no longer worth posting.
     * @param int|null $now The time, for the tests.
     * @return int The id of the post; 0 if it does not go (no channel, the site switched the kind off, or it is in the
     *             channel already).
     * @throws \coding_exception If the kind is not declared or the content is not valid.
     */
    public static function post(
        string $component,
        string $kind,
        array $content,
        ?int $when = null,
        bool $urgent = false,
        string $key = '',
        ?int $expires = null,
        ?int $now = null
    ): int {
        global $DB;
        $declared = self::kinds()[$component . '/' . $kind] ?? null;
        if (!$declared) {
            throw new \coding_exception("The post {$component}/{$kind} is not declared");
        }
        if (\core_text::strlen($key) > 100) {
            throw new \coding_exception('The key of a post has 100 characters at most');
        }
        $content = self::clean($content);
        $policy = self::policy($declared->key);
        if ($policy === 'off' || !self::is_ready()) {
            return 0;
        }
        $now = $now ?? time();
        $fields = [
            'content' => json_encode($content),
            'urgent' => (int)$urgent,
            'timeplanned' => $when ?? $now,
            'timeexpires' => $expires,
            'timemodified' => $now,
        ];
        if ($key !== '') {
            $source = ['component' => $component, 'kind' => $kind, 'sourcekey' => $key];
            $waiting = $DB->get_record_select(
                self::TABLE,
                "component = :component AND kind = :kind AND sourcekey = :sourcekey AND status IN ('review', 'ready')",
                $source,
                '*',
                IGNORE_MULTIPLE
            );
            if ($waiting) {
                $update = ['id' => $waiting->id] + $fields;
                // The same as before, but for its card already made: it keeps its card.
                $old = json_decode($waiting->content, true) ?: [];
                if (isset($old['cardhash'])) {
                    unset($old['image'], $old['animation'], $old['cardhash']);
                }
                $changed = json_encode($old) !== $fields['content'];
                if (!$changed) {
                    unset($update['content']);
                }
                // Changed after it was reviewed or sent to review: it is reviewed again, and the old buttons do nothing.
                if ($changed && ($waiting->status === 'review' || $policy === 'review')) {
                    $update += ['status' => 'review', 'timereview' => null, 'reviews' => null, 'decidedby' => null,
                        'timedecided' => null, 'token' => self::new_token()];
                }
                $DB->update_record(self::TABLE, (object)$update);
                work::changed();
                return (int)$waiting->id;
            }
            if ($DB->record_exists(self::TABLE, $source + ['status' => 'published'])) {
                return 0;
            }
        }
        $id = (int)$DB->insert_record(self::TABLE, (object)($fields + [
            'component' => $component,
            'kind' => $kind,
            'sourcekey' => $key,
            'status' => $policy === 'auto' ? 'ready' : 'review',
            'token' => self::new_token(),
            'timecreated' => $now,
        ]));
        work::changed();
        return $id;
    }

    /**
     * Takes a post back: if it has not gone out, it does not go; if it is in the channel, it is taken out of it.
     *
     * @param string $component The plugin.
     * @param string $kind The kind of post.
     * @param string $key What the plugin knows it by.
     * @param bool $waitingonly Only if it has not gone out: what is in the channel stays.
     * @return int How many posts it took back.
     */
    public static function cancel(string $component, string $kind, string $key, bool $waitingonly = false): int {
        global $DB;
        $count = 0;
        $posts = $DB->get_records(self::TABLE, ['component' => $component, 'kind' => $kind, 'sourcekey' => $key]);
        foreach ($posts as $post) {
            if (in_array($post->status, ['review', 'ready'], true)) {
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'cancelled', 'timemodified' => time()]);
                $count++;
            } else if ($post->status === 'published' && !$waitingonly) {
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'remove', 'timemodified' => time()]);
                $count++;
            }
        }
        if ($count) {
            work::changed();
        }
        return $count;
    }

    /**
     * The content of a post, checked.
     *
     * @param array $content The content, as api::channel_post() takes it.
     * @return array
     * @throws \coding_exception If it has parts it does not know, nothing to show, or a link of another site.
     */
    public static function clean(array $content): array {
        global $CFG;
        $parts = ['title', 'image', 'animation', 'card', 'lines', 'text', 'url', 'label', 'utm'];
        $unknown = array_diff(array_keys($content), $parts);
        if ($unknown) {
            throw new \coding_exception('A post has no ' . implode(', ', $unknown));
        }
        $clean = rich::clean(array_intersect_key($content, array_flip(['title', 'image', 'lines', 'text'])));
        if (empty($clean['title']) && empty($clean['text'])) {
            throw new \coding_exception('A post needs a title or a text');
        }
        $url = $content['url'] ?? '';
        $url = $url instanceof \moodle_url ? $url->out(false) : trim((string)$url);
        if ($url !== '' && !preg_match('~^https?://[^\s]+$~i', $url)) {
            throw new \coding_exception('The link of a post has to be a web address');
        }
        $animation = $content['animation'] ?? '';
        $animation = $animation instanceof \moodle_url ? $animation->out(false) : (string)$animation;
        if ($animation !== '' && strpos($animation, $CFG->wwwroot . '/') !== 0) {
            throw new \coding_exception('The animation of a post has to be on this site');
        }
        $label = \core_text::substr(trim(strip_tags((string)($content['label'] ?? ''))), 0, 40);
        $clean += array_filter(['animation' => $animation, 'url' => $url, 'label' => $label]);
        if (!empty($content['card'])) {
            $clean['card'] = cards::clean((array)$content['card']);
        }
        if (array_key_exists('utm', $content) && !$content['utm']) {
            $clean['utm'] = false;
        }
        return $clean;
    }

    /**
     * Sends what is due: the posts to review, to whoever reviews them; the posts whose time has come, to the channel; what
     * was taken back, out of it; and, once a day, how many people the channel has.
     *
     * @param telegram_api $api The bot.
     * @param int|null $now The time, for the tests.
     * @return array Counts: reviewed, published, removed, expired and failed.
     */
    public static function process(telegram_api $api, ?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['reviewed' => 0, 'published' => 0, 'removed' => 0, 'expired' => 0, 'failed' => 0];

        $expired = $DB->get_records_select(
            self::TABLE,
            "status IN ('review', 'ready') AND timeexpires IS NOT NULL AND timeexpires <= :now",
            ['now' => $now]
        );
        foreach ($expired as $post) {
            $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'expired', 'timemodified' => $now]);
            self::tell_reviewers($api, $post, get_string('channel:review:expired', self::COMPONENT));
            $counts['expired']++;
        }

        $waiting = $DB->get_records(self::TABLE, ['status' => 'review', 'timereview' => null], 'timecreated, id');
        $reviewers = $waiting ? self::reviewers() : [];
        $open = self::open('review', $now);
        foreach ($waiting as $post) {
            if (!$reviewers) {
                break;
            }
            if (!$post->urgent && !$open) {
                continue;
            }
            // Whoever reviews sees the card as it will go: it is made first, or the post waits for the next run.
            $post = cards::for_post($post);
            if (cards::pending($post)) {
                continue;
            }
            $sent = [];
            foreach ($reviewers as $address) {
                try {
                    $sent[telegram::chat($address)] = self::ask($api, $post, telegram::chat($address), $now);
                } catch (api_exception $e) {
                    debugging('Marandu: a review of the channel did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            if ($sent) {
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'reviews' => json_encode($sent),
                    'timereview' => $now, 'timemodified' => $now]);
                $counts['reviewed']++;
            }
        }

        $channel = self::current();
        if (!$channel || $channel->problem !== '') {
            return self::done($counts);
        }
        $open = self::open('post', $now);
        $ready = $DB->get_records_select(
            self::TABLE,
            "status = 'ready' AND timeplanned <= :now",
            ['now' => $now],
            'timeplanned, id'
        );
        foreach ($ready as $post) {
            if (!$post->urgent && !$open) {
                continue;
            }
            $post = cards::for_post($post);
            if (cards::pending($post)) {
                continue;
            }
            $content = json_decode($post->content, true) ?: [];
            try {
                $button = empty($content['url']) ? null : self::button($post, $content);
                $messageid = empty($content['animation']) || rich::video($content['animation']) === null
                    ? $api->send_rich($channel->id, self::rich($content), $button, self::label($content), !$post->urgent)
                    : $api->send_animation(
                        $channel->id,
                        $content['animation'],
                        self::caption($content),
                        $button,
                        self::label($content),
                        !$post->urgent
                    );
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'published', 'messageid' => $messageid,
                    'error' => null, 'timepublished' => $now, 'timemodified' => $now]);
                $counts['published']++;
            } catch (api_exception $e) {
                if (self::channel_error($e)) {
                    set_config('channelproblem', 'norights', self::COMPONENT);
                    work::changed();
                    return self::done($counts);
                }
                $attempts = (int)$post->attempts + 1;
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'attempts' => $attempts,
                    'status' => $attempts >= self::ATTEMPTS ? 'failed' : 'ready',
                    'error' => \core_text::substr($e->getMessage(), 0, 255), 'timemodified' => $now]);
                $counts['failed'] += $attempts >= self::ATTEMPTS ? 1 : 0;
            }
        }

        foreach ($DB->get_records(self::TABLE, ['status' => 'remove'], 'id') as $post) {
            try {
                $api->delete_message($channel->id, (int)$post->messageid);
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'removed', 'error' => null,
                    'timemodified' => $now]);
                $counts['removed']++;
            } catch (api_exception $e) {
                // It stays in the channel, as Telegram says why; taking it back again tries again.
                $DB->update_record(self::TABLE, (object)['id' => $post->id, 'status' => 'published',
                    'error' => \core_text::substr($e->getMessage(), 0, 255), 'timemodified' => $now]);
            }
        }

        self::count_members($api, $channel->id, $now);
        return self::done($counts);
    }

    /**
     * Whoever reviews a post said to post it or to skip it: it goes, or not, and the others see who said it.
     *
     * @param \stdClass $post The post, waiting for its review.
     * @param bool $publish Whether it goes.
     * @param int $userid Who said it.
     * @param telegram_api|null $api The bot, to tell whoever reviews.
     * @param int|null $now The time, for the tests.
     * @return bool False if it was not waiting any more.
     */
    public static function decide(\stdClass $post, bool $publish, int $userid, ?telegram_api $api = null, ?int $now = null): bool {
        global $DB;
        $post = $DB->get_record(self::TABLE, ['id' => $post->id]);
        if (!$post || $post->status !== 'review') {
            return false;
        }
        $now = $now ?? time();
        $post->status = $publish ? 'ready' : 'skipped';
        $post->decidedby = $userid;
        $post->timedecided = $now;
        $post->timemodified = $now;
        $DB->update_record(self::TABLE, $post);
        work::changed();
        if ($api) {
            $user = \core_user::get_user($userid);
            $a = (object)['name' => $user ? fullname($user) : '', 'when' => self::when($post, $now, true)];
            self::tell_reviewers($api, $post, get_string(
                $publish ? 'channel:review:published' : 'channel:review:skipped',
                self::COMPONENT,
                $a
            ));
        }
        return true;
    }

    /**
     * A button of a review pressed in a chat with the bot: p:, the token of the post, and pub or skip. Only whoever
     * reviews counts.
     *
     * @param array $query The callback_query of the update.
     * @param telegram_api|null $api The bot, to tell whoever reviews.
     * @return array|null The answer, as a method of the Bot API (at least, that the button stops waiting); null if it is
     *                    not a button of a review.
     */
    public static function from_telegram(array $query, ?telegram_api $api = null): ?array {
        global $DB;
        if (!preg_match('/^p:([A-Za-z0-9]{10}):(pub|skip)$/', (string)($query['data'] ?? ''), $parts)) {
            return null;
        }
        $chat = $query['message']['chat'] ?? [];
        if (($chat['type'] ?? '') !== 'private') {
            return null;
        }
        $stop = ['method' => 'answerCallbackQuery', 'callback_query_id' => (string)($query['id'] ?? '')];
        $post = $DB->get_record(self::TABLE, ['token' => $parts[1]]);
        $userid = array_search(telegram::address((string)$chat['id']), self::reviewers(), true);
        if (!$post || $userid === false || self::decide($post, $parts[2] === 'pub', (int)$userid, $api)) {
            return $stop;
        }
        // Somebody decided first: the button stops waiting, and a line says so.
        if ($api && isset($query['id'])) {
            try {
                $api->answer_callback((string)$query['id']);
            } catch (api_exception $e) {
                // It stops waiting by itself after a while.
                debugging('Marandu: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
        return ['method' => 'sendMessage', 'chat_id' => (string)$chat['id'],
            'text' => get_string('channel:review:already', self::COMPONENT)];
    }

    /**
     * Whoever reviews what goes to the channel: users who may, with their chat joined to the bot.
     *
     * @return string[] Their chats (tg:<chat>), by user.
     */
    public static function reviewers(): array {
        global $DB;
        $rows = $DB->get_records_sql("SELECT l.id, l.userid, c.phone
                                        FROM {" . consent::LINKS . "} l
                                        JOIN {" . consent::CONTACTS . "} c ON c.id = l.contactid
                                       WHERE l.userid IS NOT NULL AND c.channel = :telegram AND c.status = :active
                                    ORDER BY l.id", ['telegram' => 'telegram', 'active' => 'active']);
        $context = \context_system::instance();
        $list = [];
        foreach ($rows as $row) {
            if (isset($list[(int)$row->userid])) {
                continue;
            }
            $user = \core_user::get_user((int)$row->userid);
            $active = $user && !$user->deleted && !$user->suspended;
            if ($active && has_capability('local/oksigeniamarandu:channel', $context, $user)) {
                $list[(int)$row->userid] = $row->phone;
            }
        }
        return $list;
    }

    /**
     * Whether a time falls in the hours of review or in those of the channel, in the time zone of the site.
     *
     * @param string $which review or post.
     * @param int $time The time.
     * @return bool
     */
    public static function open(string $which, int $time): bool {
        $config = get_config(self::COMPONENT);
        $setting = function (string $name) use ($config, $which): string {
            return (string)($config->{$which . $name} ?? self::DEFAULTS[$which . $name]);
        };
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        if (!in_array($date->format('w'), array_filter(explode(',', $setting('days')), 'strlen'), true)) {
            return false;
        }
        $from = (int)str_replace(':', '', $setting('from'));
        $to = (int)str_replace(':', '', $setting('to'));
        if ($from === $to) {
            return true;
        }
        $hour = (int)$date->format('Hi');
        return $from < $to ? ($hour >= $from && $hour < $to) : ($hour >= $from || $hour < $to);
    }

    /**
     * How many people the channel has, and how many it had a week before.
     *
     * @param int $now The time.
     * @return \stdClass|null With now and before (null if it was not counted then); null if it was never counted.
     */
    public static function members(int $now): ?\stdClass {
        global $DB;
        $last = $DB->get_records(self::STATS, null, 'timeday DESC', 'id, timeday, members', 0, 1);
        if (!$last) {
            return null;
        }
        $last = reset($last);
        $before = $DB->get_records_select(
            self::STATS,
            'timeday <= :week',
            ['week' => self::day_start($now) - WEEKSECS],
            'timeday DESC',
            'id, members',
            0,
            1
        );
        return (object)['now' => (int)$last->members, 'before' => $before ? (int)reset($before)->members : null];
    }

    /**
     * The visit through the link of a post: it counts, and goes on to the page of the post.
     *
     * @param string $token The token of the post.
     * @return string|null Where it goes; null if the post or its link are not known.
     */
    public static function click(string $token): ?string {
        global $DB;
        $post = $token === '' ? false : $DB->get_record(self::TABLE, ['token' => $token], 'id, kind, content');
        $content = $post ? (json_decode($post->content, true) ?: []) : [];
        $url = (string)($content['url'] ?? '');
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET clicks = clicks + 1 WHERE id = ?', [$post->id]);
        return isset($content['utm']) && !$content['utm'] ? $url : links::utm($url, $post->kind);
    }

    /**
     * What is written in the announcements forum of the front page goes to the channel: a new discussion, the change of
     * its first post before it goes out, and taking it out if it is deleted. With «Send now», at once.
     *
     * @param \core\event\base $event discussion_created, post_updated or discussion_deleted of mod_forum.
     */
    public static function forum_changed(\core\event\base $event): void {
        global $DB, $CFG;
        $forumid = (int)($event->other['forumid'] ?? 0);
        $forum = $forumid ? $DB->get_record('forum', ['id' => $forumid], 'id, course, type') : false;
        if (!$forum || (int)$forum->course !== (int)SITEID || $forum->type !== 'news') {
            return;
        }
        $discussionid = $event->objecttable === 'forum_discussions' ? (int)$event->objectid
            : (int)($event->other['discussionid'] ?? 0);
        $key = 'd' . $discussionid;
        if ($event instanceof \mod_forum\event\discussion_deleted) {
            self::cancel(self::COMPONENT, self::NEWS, $key);
            return;
        }
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid]);
        $other = $event instanceof \mod_forum\event\post_updated && $discussion
            && (int)$event->objectid !== (int)$discussion->firstpost;
        if (!$discussion || $other) {
            return;
        }
        $first = $DB->get_record('forum_posts', ['id' => $discussion->firstpost]);
        if (!$first) {
            return;
        }
        $text = trim(content_to_text((string)$first->message, (int)$first->messageformat));
        $urgent = !empty($first->mailnow);
        // As the mail of the forum: after the time to edit it, unless it is sent now.
        $when = $urgent ? time() : max((int)$discussion->timestart, (int)$first->created + (int)($CFG->maxeditingtime ?? 1800));
        self::post(
            self::COMPONENT,
            self::NEWS,
            ['title' => (string)$discussion->name, 'text' => $text === '' ? (string)$discussion->name : $text],
            $when,
            $urgent,
            $key,
            empty($discussion->timeend) ? null : (int)$discussion->timeend
        );
    }

    /**
     * Sends a post to whoever reviews it: first as it will look, then the question with its buttons.
     *
     * @param telegram_api $api The bot.
     * @param \stdClass $post The post.
     * @param string $chat The chat of whoever reviews.
     * @param int $now The time.
     * @return int[] The ids of the two messages.
     */
    private static function ask(telegram_api $api, \stdClass $post, string $chat, int $now): array {
        $content = json_decode($post->content, true) ?: [];
        $preview = empty($content['animation']) || rich::video($content['animation']) === null
            ? $api->send_rich($chat, self::rich($content), $content['url'] ?? null, self::label($content), true)
            : $api->send_animation(
                $chat,
                $content['animation'],
                self::caption($content),
                $content['url'] ?? null,
                self::label($content),
                true
            );
        $kind = self::kinds()[$post->component . '/' . $post->kind] ?? (object)['component' => $post->component,
            'kind' => $post->kind];
        $channel = self::current();
        $question = $api->send_question(
            $chat,
            get_string('channel:review:ask', self::COMPONENT, (object)[
                'kind' => self::name($kind),
                'channel' => $channel ? $channel->title : '',
                'when' => self::when($post, $now),
            ]),
            [
                ['text' => get_string('channel:review:publish', self::COMPONENT), 'data' => 'p:' . $post->token . ':pub'],
                ['text' => get_string('channel:review:skip', self::COMPONENT), 'data' => 'p:' . $post->token . ':skip'],
            ],
            null,
            '',
            !$post->urgent
        );
        return [(int)$preview, (int)$question];
    }

    /**
     * Changes the question each reviewer got, to say what became of the post.
     *
     * @param telegram_api $api The bot.
     * @param \stdClass $post The post.
     * @param string $text What became of it.
     */
    private static function tell_reviewers(telegram_api $api, \stdClass $post, string $text): void {
        $reviews = json_decode((string)($post->reviews ?? ''), true);
        foreach (is_array($reviews) ? $reviews : [] as $chat => $ids) {
            try {
                $api->edit_text((string)$chat, (int)($ids[1] ?? 0), $text);
            } catch (api_exception $e) {
                debugging('Marandu: a review of the channel was not changed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * When a post goes, in words: as soon as it is said yes, in a moment once it was, or the day and the time, which out
     * of the hours of the channel is when they start again, unless it is urgent.
     *
     * @param \stdClass $post The post.
     * @param int $now The time.
     * @param bool $decided Whether it was said yes already.
     * @return string
     */
    private static function when(\stdClass $post, int $now, bool $decided = false): string {
        $time = self::goes_at($post, $now);
        if ($time <= $now) {
            return get_string($decided ? 'channel:review:soon' : 'channel:review:now', self::COMPONENT);
        }
        return get_string('channel:review:at', self::COMPONENT, userdate($time, get_string('strftimedaydatetime', 'langconfig')));
    }

    /**
     * What process() did, after forgetting what the dashboard kept if it changed anything.
     *
     * @param array $counts The counts of process().
     * @return array The same.
     */
    private static function done(array $counts): array {
        if (array_sum($counts)) {
            work::changed();
        }
        return $counts;
    }

    /**
     * When a post goes out if it is said yes now: at its time, in the hours of the channel unless it is urgent.
     *
     * @param \stdClass $post The post.
     * @param int $now The time.
     * @return int Now, or later.
     */
    public static function goes_at(\stdClass $post, int $now): int {
        $time = max((int)$post->timeplanned, $now);
        return $post->urgent ? $time : self::next_open('post', $time);
    }

    /**
     * The first time from a time on in the hours of review or of the channel, to the minute; the time itself if it is in
     * them.
     *
     * @param string $which review or post.
     * @param int $time The time.
     * @return int
     */
    public static function next_open(string $which, int $time): int {
        $open = $time;
        // A week and a day is longer than any closed time the site can set.
        while (!self::open($which, $open) && $open < $time + 8 * DAYSECS) {
            $open += 5 * MINSECS;
        }
        if ($open === $time) {
            return $time;
        }
        // On the minute they open, not five minutes after it.
        while ($open - MINSECS > $time && self::open($which, $open - MINSECS)) {
            $open -= MINSECS;
        }
        return $open;
    }

    /**
     * The card of a post, as rich::blocks() takes it.
     *
     * @param array $content The content of the post.
     * @return array
     */
    private static function rich(array $content): array {
        return array_intersect_key($content, array_flip(['title', 'image', 'lines', 'text']));
    }

    /**
     * The label of the button of a post.
     *
     * @param array $content The content of the post.
     * @return string
     */
    private static function label(array $content): string {
        return ($content['label'] ?? '') !== '' ? $content['label'] : get_string('channel:open', self::COMPONENT);
    }

    /**
     * Where the button of a post goes in the channel: the short link of the shortener of the site, which counts the
     * visits, of the link with its UTM marks; without a shortener, the link of Marandu, which counts them.
     *
     * @param \stdClass $post The post.
     * @param array $content Its content.
     * @return string
     */
    private static function button(\stdClass $post, array $content): string {
        $target = isset($content['utm']) && !$content['utm'] ? $content['url'] : links::utm($content['url'], $post->kind);
        return links::shorten($target) ?? self::link($post);
    }

    /**
     * The text under an animation: the title, then the lines and the text.
     *
     * @param array $content The content of the post.
     * @return string
     */
    private static function caption(array $content): string {
        $parts = array_filter([
            (string)($content['title'] ?? ''),
            implode("\n", array_map(function ($line) {
                return '• ' . $line;
            }, (array)($content['lines'] ?? []))),
            implode("\n", (array)($content['text'] ?? [])),
        ], 'strlen');
        return implode("\n\n", $parts);
    }

    /**
     * A token no other post has.
     *
     * @return string
     */
    private static function new_token(): string {
        global $DB;
        do {
            $token = random_string(10);
        } while ($DB->record_exists(self::TABLE, ['token' => $token]));
        return $token;
    }

    /**
     * The link of a post in the channel, which counts the visits and goes on to the page of the post.
     *
     * @param \stdClass $post The post.
     * @return string
     */
    private static function link(\stdClass $post): string {
        return (new \moodle_url('/local/oksigeniamarandu/go.php', ['p' => $post->token]))->out(false);
    }

    /**
     * Whether an error of Telegram is of the channel (the bot cannot post there any more), not of the post.
     *
     * @param api_exception $e The error.
     * @return bool
     */
    private static function channel_error(api_exception $e): bool {
        if (!in_array($e->metacode, [-400, -403], true)) {
            return false;
        }
        $message = \core_text::strtolower((string)($e->a->message ?? $e->getMessage()));
        foreach (self::CHANNELERRORS as $words) {
            if (strpos($message, $words) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Counts the people of the channel, once a day.
     *
     * @param telegram_api $api The bot.
     * @param string $channelid The channel.
     * @param int $now The time.
     */
    private static function count_members(telegram_api $api, string $channelid, int $now): void {
        global $DB;
        $day = self::day_start($now);
        if ((int)get_config(self::COMPONENT, 'channelcounted') === $day) {
            return;
        }
        set_config('channelcounted', $day, self::COMPONENT);
        try {
            $members = $api->member_count($channelid);
        } catch (api_exception $e) {
            return;
        }
        $id = $DB->get_field(self::STATS, 'id', ['timeday' => $day]);
        if ($id) {
            $DB->set_field(self::STATS, 'members', $members, ['id' => $id]);
        } else {
            $DB->insert_record(self::STATS, (object)['timeday' => $day, 'members' => $members]);
        }
    }

    /**
     * The start of the day of a time, in the time zone of the site.
     *
     * @param int $time The time.
     * @return int
     */
    private static function day_start(int $time): int {
        $date = new \DateTime('@' . $time);
        $date->setTimezone(\core_date::get_server_timezone_object());
        $date->setTime(0, 0);
        return $date->getTimestamp();
    }
}
