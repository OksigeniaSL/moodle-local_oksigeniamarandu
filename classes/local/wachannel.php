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
 * The channel of WhatsApp of the site, with the same posts as its channel of Telegram, published by hand with help.
 *
 * Meta has no official way to post in a channel of WhatsApp, and Marandu only uses the official ways. So, when a post
 * goes out in the channel of Telegram, the people who publish in WhatsApp get the notice of Marandu «Post for the
 * channel of WhatsApp» (channel_whatsapp) on what they joined to their user. On WhatsApp, inside the 24 hours, the post
 * itself (its video or its image, with its text in the marks of WhatsApp below), ready to forward to the channel, and
 * the notice with the link to its page after it; outside them, the template, with a button to the page. On Telegram, a
 * card with the image and the button. Whoever has none of them gets it in the bell and on the app.
 *
 * The page (wachannel.php), for the phone, has the post ready for WhatsApp: its image or its animation, its text, its
 * button as a link measured with utm_source=whatsapp, «Share on WhatsApp», «Copy the text», «Save the image» and «It is
 * published», which writes down who and when and stops the notices. If nobody marks it in a while, one reminder, in the
 * hours of the channel, to the same people.
 *
 * It is the same post: what is not published in WhatsApp holds nothing of Telegram back, and the pace of the channel
 * is already that of Telegram. A post taken out of Telegram no longer waits for WhatsApp, and whoever published it
 * there is told to take it out too.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wachannel {
    /** What waits for the channel of WhatsApp, or went there. */
    public const TABLE = 'local_oksigeniamarandu_wapost';

    /** How long after the notice the reminder goes, if nobody marked it. */
    public const REMIND = 3 * HOURSECS;

    /** How long a post waits for WhatsApp in «Your work» and for its reminder: after that, its moment is gone. */
    public const RECENT = 3 * DAYSECS;

    /** The shape of the link of a channel of WhatsApp, as WhatsApp gives it to share the channel. */
    public const LINK = '~^https://(?:www\.)?whatsapp\.com/channel/[A-Za-z0-9]{6,}/?$~';

    /** The notice of Marandu that carries it to who publishes. */
    public const NOTICE = 'channel_whatsapp';

    /** The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /**
     * Whether the site posts in its channel of WhatsApp too.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)get_config(self::COMPONENT, 'wachannel');
    }

    /**
     * Switches the channel of WhatsApp on or off. On, its notice is switched on for WhatsApp and Telegram, so that it
     * reaches who publishes; the site can switch it off there afterwards, as any other.
     *
     * @param bool $on Whether the site posts there.
     */
    public static function set_enabled(bool $on): void {
        if ($on && !self::enabled()) {
            foreach (array_keys(notices::SETTING) as $channel) {
                notices::set(self::COMPONENT . '/' . self::NOTICE, $channel, true);
            }
        }
        set_config('wachannel', $on ? 1 : 0, self::COMPONENT);
        work::changed();
    }

    /**
     * The name of the channel of WhatsApp, as the site wrote it; empty if it did not.
     *
     * @return string
     */
    public static function name(): string {
        return trim((string)get_config(self::COMPONENT, 'wachannelname'));
    }

    /**
     * The link that opens the channel of WhatsApp, as the site wrote it; empty if it did not.
     *
     * @return string
     */
    public static function channel_url(): string {
        $url = trim((string)get_config(self::COMPONENT, 'wachannelurl'));
        return preg_match(self::LINK, $url) ? $url : '';
    }

    /**
     * Saves the link of the channel of WhatsApp, if it is one (https://whatsapp.com/channel/…); empty takes it away.
     *
     * @param string $url The link.
     * @return bool False if it was not a link of a channel of WhatsApp: nothing changed.
     */
    public static function set_url(string $url): bool {
        $url = trim($url);
        if ($url !== '' && !preg_match(self::LINK, $url)) {
            return false;
        }
        set_config('wachannelurl', $url, self::COMPONENT);
        return true;
    }

    /**
     * The channel, inside a sentence: its name in quotes, or «the channel of WhatsApp».
     *
     * @return string
     */
    public static function phrase(): string {
        $name = self::name();
        if ($name === '') {
            return get_string('wachannel:thechannel', self::COMPONENT);
        }
        return get_string('wachannel:named', self::COMPONENT, $name);
    }

    /**
     * Who the site chose to publish in WhatsApp.
     *
     * @return int[] Their ids, in the order the site gave them.
     */
    public static function chosen(): array {
        return array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)get_config(self::COMPONENT, 'wachannelpeople'))
        ))));
    }

    /**
     * Saves who publishes in WhatsApp; nobody, for whoever reviews.
     *
     * @param int[] $userids The users.
     */
    public static function set_people(array $userids): void {
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        set_config('wachannelpeople', implode(',', $userids), self::COMPONENT);
        work::changed();
    }

    /**
     * Who publishes in WhatsApp: the people the site chose; until it chooses, whoever reviews the channel on Telegram;
     * and, without anybody there, whoever may review it on the page.
     *
     * @return \stdClass[] The users, active.
     */
    public static function people(): array {
        global $DB;
        $ids = self::chosen() ?: array_keys(channel::reviewers());
        if (!$ids) {
            return array_values(array_filter(get_users_by_capability(
                \context_system::instance(),
                'local/oksigeniamarandu:channel',
                'u.*',
                'u.lastname ASC, u.firstname ASC'
            ), function (\stdClass $user): bool {
                return !$user->deleted && !$user->suspended;
            }));
        }
        $users = $DB->get_records_list('user', 'id', $ids);
        $list = [];
        foreach ($ids as $id) {
            if (isset($users[$id]) && !$users[$id]->deleted && !$users[$id]->suspended) {
                $list[] = $users[$id];
            }
        }
        return $list;
    }

    /**
     * Whether a user may open the page of a post for WhatsApp and mark it published: who publishes there, whoever may
     * review the channel and whoever runs the site.
     *
     * @param int $userid The user.
     * @return bool
     */
    public static function may_publish(int $userid): bool {
        $system = \context_system::instance();
        if (
            has_capability('local/oksigeniamarandu:channel', $system, $userid)
                || has_capability('moodle/site:config', $system, $userid)
        ) {
            return true;
        }
        foreach (self::people() as $user) {
            if ((int)$user->id === $userid) {
                return true;
            }
        }
        return false;
    }

    /**
     * A post has just gone out in the channel of Telegram: it waits for WhatsApp too, and who publishes there is told.
     * Nothing of this holds the channel of Telegram back.
     *
     * @param \stdClass $post The post, as it went out.
     * @param int $now The time.
     * @return int The id of what waits for WhatsApp; 0 if the site has no channel of WhatsApp.
     */
    public static function published(\stdClass $post, int $now): int {
        global $DB;
        if (!self::enabled()) {
            return 0;
        }
        $id = $DB->get_field(self::TABLE, 'id', ['postid' => $post->id]);
        if ($id) {
            return (int)$id;
        }
        $wa = (object)[
            'postid' => $post->id,
            'token' => self::new_token(),
            'status' => 'waiting',
            'clicks' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $wa->id = (int)$DB->insert_record(self::TABLE, $wa);
        $content = json_decode($post->content, true) ?: [];
        if (!empty($content['url'])) {
            // As in Telegram: through the link of Marandu, which counts the visits, shortened if the site has a shortener.
            $wa->link = links::shorten(self::go($wa)) ?? self::go($wa);
            $DB->set_field(self::TABLE, 'link', $wa->link, ['id' => $wa->id]);
        }
        self::tell($wa, $post, false);
        work::changed();
        return $wa->id;
    }

    /**
     * The reminder, once, of what nobody marked published in a while: in the hours of the channel, to the same people.
     *
     * @param int|null $now The time, for the tests.
     * @return int How many were reminded.
     */
    public static function remind(?int $now = null): int {
        global $DB;
        $now = $now ?? time();
        if (!self::enabled() || !channel::open('post', $now)) {
            return 0;
        }
        $due = $DB->get_records_select(
            self::TABLE,
            "status = 'waiting' AND timereminded IS NULL AND timecreated <= :due AND timecreated >= :since",
            ['due' => $now - self::REMIND, 'since' => $now - self::RECENT],
            'timecreated, id'
        );
        $count = 0;
        foreach ($due as $wa) {
            $post = $DB->get_record(channel::TABLE, ['id' => $wa->postid]);
            // Written first: a reminder never goes twice, even if the next run comes while this one sends.
            $DB->update_record(self::TABLE, (object)['id' => $wa->id, 'timereminded' => $now, 'timemodified' => $now]);
            if ($post) {
                self::tell($wa, $post, true);
                $count++;
            }
        }
        return $count;
    }

    /**
     * «It is published»: who and when, and no more notices; the messages of Telegram say who did it.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @param int $userid Who published it.
     * @param telegram_api|null $api The bot, to change its messages.
     * @param int|null $now The time, for the tests.
     * @return bool False if it was not waiting any more.
     */
    public static function done(\stdClass $wa, int $userid, ?telegram_api $api = null, ?int $now = null): bool {
        global $DB;
        $wa = $DB->get_record(self::TABLE, ['id' => $wa->id]);
        if (!$wa || $wa->status !== 'waiting') {
            return false;
        }
        $now = $now ?? time();
        $DB->update_record(self::TABLE, (object)['id' => $wa->id, 'status' => 'published', 'doneby' => $userid,
            'timedone' => $now, 'timemodified' => $now]);
        $post = $DB->get_record(channel::TABLE, ['id' => $wa->postid]);
        $user = \core_user::get_user($userid);
        self::settle($api, $wa, get_string('wachannel:tg:done', self::COMPONENT, (object)[
            'title' => self::title($post),
            'name' => $user ? fullname($user) : '',
        ]));
        work::changed();
        return true;
    }

    /**
     * A post taken out of the channel of Telegram: it no longer waits for WhatsApp; if somebody published it there
     * already, they are told to take it out too.
     *
     * @param \stdClass $post The post.
     * @param telegram_api|null $api The bot, to change its messages.
     * @param int $now The time.
     */
    public static function withdraw(\stdClass $post, ?telegram_api $api, int $now): void {
        global $DB;
        $wa = $DB->get_record(self::TABLE, ['postid' => $post->id]);
        if (!$wa || $wa->status === 'withdrawn') {
            return;
        }
        $DB->update_record(self::TABLE, (object)['id' => $wa->id, 'status' => 'withdrawn', 'timemodified' => $now]);
        $title = self::title($post);
        $user = $wa->doneby ? \core_user::get_user((int)$wa->doneby) : false;
        if ($wa->status === 'waiting') {
            self::settle($api, $wa, get_string('wachannel:tg:withdrawn', self::COMPONENT, $title));
        } else if ($user && !$user->deleted && !$user->suspended) {
            self::message(
                $user,
                get_string('wachannel:notify:withdrawn', self::COMPONENT, $title),
                get_string('wachannel:notify:withdrawntext', self::COMPONENT, (object)['title' => $title,
                    'channel' => self::phrase()]),
                self::url($wa)
            );
        }
        work::changed();
    }

    /**
     * The visit through the link of a post in the channel of WhatsApp: it counts apart from that of Telegram, and goes on
     * to the page of the post with utm_source=whatsapp.
     *
     * @param string $token The token of what waits for WhatsApp.
     * @return string|null Where it goes; null if it or its link are not known.
     */
    public static function click(string $token): ?string {
        global $DB;
        $wa = $token === '' ? false : $DB->get_record(self::TABLE, ['token' => $token], 'id, postid');
        $post = $wa ? $DB->get_record(channel::TABLE, ['id' => $wa->postid], 'id, kind, content') : false;
        $content = $post ? (json_decode($post->content, true) ?: []) : [];
        $url = (string)($content['url'] ?? '');
        if (!preg_match('~^https?://~i', $url)) {
            return null;
        }
        $DB->execute('UPDATE {' . self::TABLE . '} SET clicks = clicks + 1 WHERE id = ?', [$wa->id]);
        return isset($content['utm']) && !$content['utm'] ? $url :
            links::utm(links::for_whatsapp($url), $post->kind, 'whatsapp');
    }

    /**
     * The text of a post for WhatsApp, with its button as a link after its label.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @param \stdClass $post The post.
     * @return string
     */
    public static function text(\stdClass $wa, \stdClass $post): string {
        $content = json_decode($post->content, true) ?: [];
        $link = empty($content['url']) ? '' : ((string)($wa->link ?? '') ?: self::go($wa));
        return watext::post($content, $link, $link === '' ? '' : channel::label($content));
    }

    /**
     * What a post shows: its image (its card, or the one of its plugin) and its animation, an MP4.
     *
     * @param \stdClass $post The post.
     * @return array With image and video, URLs of this site; empty without one.
     */
    public static function media(\stdClass $post): array {
        $content = json_decode($post->content, true) ?: [];
        $video = (string)($content['animation'] ?? '');
        return [
            'image' => (string)($content['image'] ?? ''),
            'video' => preg_match('~\.mp4(?:$|[?#])~i', $video) ? $video : '',
        ];
    }

    /**
     * Whether the image or the animation of a post has something made or edited with artificial intelligence, which
     * WhatsApp asks to label in a channel: its plugin said so ('ai' => true), or the template of its card does.
     *
     * @param \stdClass $post The post.
     * @return bool
     */
    public static function ai(\stdClass $post): bool {
        $content = json_decode($post->content, true) ?: [];
        return !empty($content['ai']) || cards::with_ai((string)($content['card']['type'] ?? ''));
    }

    /**
     * The name of the file of a post when it is saved or shared: its title, in plain letters.
     *
     * @param \stdClass $post The post.
     * @param string $extension jpg, png or mp4.
     * @return string
     */
    public static function filename(\stdClass $post, string $extension): string {
        $name = \core_text::strtolower(\core_text::specialtoascii(self::title($post)));
        $name = trim((string)preg_replace('/[^a-z0-9]+/', '-', $name), '-');
        $name = trim(substr($name, 0, 48), '-');
        return ($name === '' ? 'post' : $name) . '.' . $extension;
    }

    /**
     * The title of a post, short, as the notices say it.
     *
     * @param \stdClass|false|null $post The post.
     * @return string
     */
    public static function title($post): string {
        $content = $post ? (json_decode($post->content, true) ?: []) : [];
        return shorten_text((string)($content['title'] ?? ($content['text'][0] ?? '')), 80);
    }

    /**
     * The page of a post for WhatsApp.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @return \moodle_url
     */
    public static function url(\stdClass $wa): \moodle_url {
        return new \moodle_url('/local/oksigeniamarandu/wachannel.php', ['t' => $wa->token]);
    }

    /**
     * How the posts did in the channel of WhatsApp, for the figures.
     *
     * @param int[] $postids The posts.
     * @return array By post: status (waiting, published or withdrawn), timepublished (when it was marked; null if it
     *               was not) and clicks.
     */
    public static function stats(array $postids): array {
        global $DB;
        if (!$postids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_values($postids));
        $stats = [];
        foreach ($DB->get_records_select(self::TABLE, "postid $in", $params) as $wa) {
            $stats[(int)$wa->postid] = ['status' => (string)$wa->status,
                'timepublished' => $wa->timedone ? (int)$wa->timedone : null, 'clicks' => (int)$wa->clicks];
        }
        return $stats;
    }

    /**
     * What waits for WhatsApp and is still in its moment, the oldest first.
     *
     * @param int $now The time.
     * @return \stdClass[] Each with its post: id, token, timecreated, postid and content.
     */
    public static function waiting(int $now): array {
        global $DB;
        return array_values($DB->get_records_sql(
            "SELECT w.id, w.token, w.timecreated, w.postid, p.content
               FROM {" . self::TABLE . "} w
               JOIN {" . channel::TABLE . "} p ON p.id = w.postid
              WHERE w.status = 'waiting' AND w.timecreated >= :since
           ORDER BY w.timecreated, w.id",
            ['since' => $now - self::RECENT]
        ));
    }

    /**
     * Tells who publishes in WhatsApp that a post waits there, with the notice of Marandu on what each one joined to
     * their user; whoever it would not reach, in the bell and on the app. The first time it carries the post itself, for
     * WhatsApp inside the 24 hours and as a card on Telegram; the reminder, only the notice.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @param \stdClass $post The post.
     * @param bool $again Whether it is the reminder.
     */
    private static function tell(\stdClass $wa, \stdClass $post, bool $again): void {
        $people = self::people();
        if (!$people) {
            return;
        }
        $url = self::url($wa);
        $title = self::title($post);
        $site = format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]);
        $a = (object)['title' => $title, 'channel' => self::phrase()];
        $rich = $again ? [] : self::rich($wa, $post, $a);
        foreach ($people as $user) {
            if (\local_oksigeniamarandu\api::reaches(self::COMPONENT, self::NOTICE, (int)$user->id)) {
                try {
                    \local_oksigeniamarandu\api::notify(
                        self::COMPONENT,
                        self::NOTICE,
                        [$site, $title],
                        $url,
                        (int)$wa->id,
                        (int)$user->id,
                        false,
                        DAYSECS,
                        $rich
                    );
                    continue;
                } catch (\Throwable $e) {
                    debugging('Marandu: the notice for the WhatsApp channel was not queued: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
            self::message(
                $user,
                get_string($again ? 'wachannel:notify:remind' : 'wachannel:notify:subject', self::COMPONENT, $title),
                get_string($again ? 'wachannel:notify:remindtext' : 'wachannel:notify:text', self::COMPONENT, $a),
                $url
            );
        }
    }

    /**
     * What the notice carries the first time: for WhatsApp, inside the 24 hours, the post itself to forward (its text
     * with the marks of WhatsApp, under its video or its image); for Telegram, a card with its image that says what it is.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @param \stdClass $post The post.
     * @param \stdClass $a The title of the post and the channel, for the texts.
     * @return array As api::notify() takes it.
     */
    private static function rich(\stdClass $wa, \stdClass $post, \stdClass $a): array {
        $media = self::media($post);
        return array_filter([
            'title' => get_string('wachannel:title', self::COMPONENT),
            'image' => $media['image'],
            'text' => [get_string('wachannel:tg:text', self::COMPONENT, $a)],
            'caption' => self::text($wa, $post),
            'video' => $media['video'],
        ]);
    }

    /**
     * What became of a post, for its notices: what has not gone does not go, and on Telegram the messages say it,
     * without their button. WhatsApp cannot change a message that went.
     *
     * @param telegram_api|null $api The bot, to change its messages.
     * @param \stdClass $wa What waits for WhatsApp.
     * @param string $text What became of it.
     */
    private static function settle(?telegram_api $api, \stdClass $wa, string $text): void {
        global $DB;
        $rows = $DB->get_records(queue::TABLE, ['component' => self::COMPONENT, 'notice' => self::NOTICE,
            'itemid' => $wa->id]);
        foreach ($rows as $row) {
            if ($row->status === 'queued') {
                $DB->update_record(queue::TABLE, (object)['id' => $row->id, 'status' => 'skipped', 'error' => 'settled']);
                continue;
            }
            if (!$api || strpos((string)$row->wamid, 'tg.') !== 0) {
                continue;
            }
            $phone = (string)$DB->get_field(consent::CONTACTS, 'phone', ['id' => $row->contactid]);
            if (!telegram::is_chat($phone)) {
                continue;
            }
            try {
                $api->edit_text(telegram::chat($phone), (int)substr((string)$row->wamid, 3), $text);
            } catch (api_exception $e) {
                // An old message, or one the person deleted.
                debugging('Marandu: a notice for the channel of WhatsApp was not changed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * A notification of Moodle: in the bell and on the app, with the icon of Marandu and its link.
     *
     * @param \stdClass $user To whom.
     * @param string $subject Its subject.
     * @param string $text Its text.
     * @param \moodle_url $url Where it takes.
     */
    private static function message(\stdClass $user, string $subject, string $text, \moodle_url $url): void {
        $message = new \core\message\message();
        $message->component = self::COMPONENT;
        $message->name = 'wachannel';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->customdata = message_icon::customdata();
        $message->contexturl = $url->out(false);
        $message->contexturlname = get_string('wachannel:open', self::COMPONENT);
        try {
            message_send($message);
        } catch (\Throwable $e) {
            debugging('Marandu: a notification for the channel of WhatsApp did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * The link of a post in the channel of WhatsApp, which counts its visits and goes on to the page of the post.
     *
     * @param \stdClass $wa What waits for WhatsApp.
     * @return string
     */
    private static function go(\stdClass $wa): string {
        return (new \moodle_url('/local/oksigeniamarandu/go.php', ['w' => $wa->token]))->out(false);
    }

    /**
     * A token nothing else has.
     *
     * @return string
     */
    private static function new_token(): string {
        global $DB;
        do {
            $token = random_string(12);
        } while ($DB->record_exists(self::TABLE, ['token' => $token]));
        return $token;
    }
}
