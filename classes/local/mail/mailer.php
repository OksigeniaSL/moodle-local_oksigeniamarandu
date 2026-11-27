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

use local_oksigeniamarandu\local\limits;

/**
 * The mails of the plugins: each one by its way, with its pictures inside and its text version, never with tracking.
 *
 * - An address of home goes by the mail of the site.
 * - One out of home goes by Mailjet when the site chose it; if not, by the mail of the site, as always.
 * - A mail to one person goes at once; if Mailjet fails or is stopped, it goes by the mail of the site, so that it never
 *   waits. A send to many (bulk) waits instead, and never falls to the mail of the site: its reputation and its limits a
 *   day are for the mail of every day.
 * - Past the share of the quota of the month the site says, what is routine (daily and repeated) goes by the mail of the
 *   site; past the quota, a send to many waits for the next month or for a person who lets it go, and one to a person
 *   goes on.
 * - An address that came back for good, or was blocked, receives nothing more; one that marked a mail as spam, no more
 *   sends to many nor routine.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mailer {
    /** The mails. */
    public const TABLE = 'local_oksigeniamarandu_mail';

    /** The addresses that do not receive. */
    public const BAD = 'local_oksigeniamarandu_mailbad';

    /** What a plugin has in its lib.php to hear what became of its mails. */
    public const CALLBACK = 'marandu_mail_event';

    /** How long Mailjet stays aside after it refused the keys or the sender, before it is tried again. */
    public const PAUSE = 15 * MINSECS;

    /** When a mail that failed for a while gives up. */
    public const GIVEUP = DAYSECS;

    /** The files of the pictures of what waits. */
    public const FILEAREA = 'mailimage';

    /** The most bytes of the pictures of one mail, so that it is not cut nor taken for spam. */
    public const MAXIMAGES = 1048576;

    /**
     * The most bytes of the attachments of one mail: Mailjet takes up to 15 MB a message with everything in it, and an
     * attachment grows a third in base64 (9 MB are 12 MB, with room for the pictures and the text).
     */
    public const MAXFILES = 9437184;

    /** The waits between tries of what failed for a while; then each hour, until a day. */
    private const RETRIES = [MINSECS, 5 * MINSECS, 15 * MINSECS, HOURSECS];

    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /**
     * Sends a mail, or leaves it waiting.
     *
     * @param array $letter email, name, userid, subject, html, text, images (each with cid, name, mime and data), files
     *     (attachments, each with name, mime and data), component, ref, replyto, bulk, routine, unsubscribe (the address
     *     to leave, for a send to many) and later.
     * @param int|null $now
     * @param bool $onlymailjet Only if it goes by Mailjet, now; if not, nothing (the mail of Moodle sends it).
     * @return string site or mailjet (the way it went), queued, bad (the address does not receive), invalid, toobig (the
     *     attachments pass MAXFILES), failed, or, with $onlymailjet, no.
     */
    public static function send(array $letter, ?int $now = null, bool $onlymailjet = false): string {
        $now = $now ?? time();
        $letter = self::normal($letter);
        if (!validate_email($letter['email'])) {
            return 'invalid';
        }
        if (self::refused($letter)) {
            return $onlymailjet ? 'no' : 'bad';
        }
        $size = array_sum(array_map(fn($file) => strlen($file['data']), $letter['files']));
        if ($size > self::MAXFILES) {
            debugging('Marandu: the attachments of a mail weigh ' . $size . ' bytes, more than ' . self::MAXFILES
                . '; it does not go.', DEBUG_DEVELOPER);
            self::tell($letter['component'], $letter['email'], 'failed', 'toobig', $letter['ref'], $now);
            return 'toobig';
        }
        $bulk = $letter['bulk'] || $letter['later'];
        $way = self::route($letter, $now, quota::used($now), $why);
        if ($onlymailjet && $way !== 'mailjet') {
            return 'no';
        }
        if ($bulk || $way === 'wait') {
            self::queue($letter, $now, $way === 'wait' ? $why : null);
            return 'queued';
        }
        $id = self::record($letter, $now);
        if ($way === 'site') {
            return self::by_site($letter, $id, $now) ? 'site' : 'failed';
        }
        $result = self::by_mailjet([$id => $letter], $now)[$id];
        if ($result['ok']) {
            return 'mailjet';
        }
        if ($onlymailjet) {
            // The mail of Moodle sends it, as it would have.
            self::finish($id, $now, 'sent', 'site', 'mailjet: ' . $result['error']);
            return 'no';
        }
        if ($result['kind'] === 'bad') {
            return 'failed';
        }
        // For a while, or Mailjet stopped: to one person, at once by the mail of the site.
        return self::by_site($letter, $id, $now, 'mailjet: ' . $result['error']) ? 'site' : 'failed';
    }

    /**
     * Tells whoever is told of the limits something of a few lines, in the common look: a channel stopped, the cap or
     * the quota of the month, Mailjet aside.
     *
     * @param string $email
     * @param string $subject
     * @param string $text Paragraphs; a line that ends in an address of the site becomes its button.
     * @param string $action What the button says, naming what it does.
     * @param int|null $now
     * @return string As send() says.
     */
    public static function warn(string $email, string $subject, string $text, string $action = '', ?int $now = null): string {
        $reason = get_string('mail:letter:reason:limits', self::C);
        $built = compose::build(compose::from_text($subject, $text, $reason, '', $action));
        return self::send(['email' => $email, 'subject' => $subject, 'html' => $built['html'], 'text' => $built['text'],
            'images' => $built['images'], 'component' => self::C], $now);
    }

    /**
     * Sends a mail by Mailjet, whatever the way of the site is, and never by the mail of the site: a test of the page.
     *
     * @param array $letter
     * @param int $now
     * @return array ok, way (mailjet, or empty) and error.
     */
    public static function test(array $letter, int $now): array {
        $letter = self::normal($letter);
        if (!validate_email($letter['email'])) {
            return ['ok' => false, 'way' => '', 'error' => 'invalid'];
        }
        $id = self::record($letter, $now);
        $result = self::by_mailjet([$id => $letter], $now)[$id];
        if (!$result['ok'] && $result['kind'] !== 'bad') {
            self::finish($id, $now, 'failed', 'mailjet', $result['error']);
        }
        return ['ok' => $result['ok'], 'way' => $result['ok'] ? 'mailjet' : '', 'error' => $result['error']];
    }

    /**
     * The way a mail goes now.
     *
     * @param array $letter
     * @param int $now
     * @param int $used The mails of the month by Mailjet so far.
     * @param string|null $why Why it waits: paused or quota.
     * @return string site, mailjet or wait.
     */
    public static function route(array $letter, int $now, int $used, ?string &$why = null): string {
        $why = null;
        if (settings::is_home($letter['email']) || !settings::mailjet_chosen()) {
            return 'site';
        }
        $many = $letter['bulk'] || $letter['later'];
        if (self::paused($now)) {
            $why = 'paused';
            return $many ? 'wait' : 'site';
        }
        $quota = settings::quota();
        if ($quota > 0) {
            if ($letter['routine'] && $used * 100 >= $quota * settings::routine_share()) {
                return 'site';
            }
            if ($used >= $quota && $many && empty($letter['released'])) {
                $why = 'quota';
                return 'wait';
            }
        }
        return 'mailjet';
    }

    /**
     * Sends what waits and can go now: the minute task.
     *
     * @param int|null $now
     * @return array How many went by each way, waited and failed.
     */
    public static function process(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['site' => 0, 'mailjet' => 0, 'waiting' => 0, 'failed' => 0];
        $rows = $DB->get_records_select(
            self::TABLE,
            "status = 'queued' AND (timeretry IS NULL OR timeretry <= :now)",
            ['now' => $now],
            'id ASC',
            '*',
            0,
            500
        );
        $used = quota::used($now);
        $batch = [];
        foreach ($rows as $row) {
            $letter = self::unpack($row);
            if ($letter === null) {
                self::finish((int)$row->id, $now, 'failed', null, 'unreadable');
                $counts['failed']++;
                continue;
            }
            if (self::refused($letter)) {
                self::finish((int)$row->id, $now, 'failed', null, 'bad');
                self::tell($letter['component'], $letter['email'], 'failed', 'bad', $letter['ref'], $now);
                $counts['failed']++;
                continue;
            }
            $way = self::route($letter, $now, $used + count($batch), $why);
            if ($way === 'wait') {
                self::wait($row, $now, $why === 'paused' ? self::PAUSE : HOURSECS, $why);
                $counts['waiting']++;
            } else if ($way === 'site') {
                $counts[self::by_site($letter, (int)$row->id, $now) ? 'site' : 'failed']++;
            } else {
                $batch[(int)$row->id] = $letter;
                if (count($batch) % mailjet_api::BATCH === 0) {
                    $counts = self::flush($batch, $rows, $now, $counts);
                    $used += count($batch);
                    $batch = [];
                }
            }
        }
        if ($batch) {
            $counts = self::flush($batch, $rows, $now, $counts);
        }
        if (!$DB->record_exists(self::TABLE, ['status' => 'queued'])) {
            self::forget_images($now);
        }
        return $counts;
    }

    /**
     * Lets go by Mailjet what waits for the quota of the month, as a person decided.
     *
     * @param string $component Only of this plugin; empty for all.
     * @return int How many.
     */
    public static function release(string $component = ''): int {
        global $DB;
        $where = "status = 'queued' AND waiting = 'quota'" . ($component !== '' ? ' AND component = :component' : '');
        $rows = $DB->get_records_select(self::TABLE, $where, ['component' => $component], '', 'id');
        foreach ($rows as $row) {
            $DB->update_record(self::TABLE, (object)['id' => $row->id, 'released' => 1, 'timeretry' => null]);
        }
        return count($rows);
    }

    /**
     * Whether Mailjet is aside now, after it refused the keys or the sender.
     *
     * @param int $now
     * @return array|null time and error, while it lasts.
     */
    public static function paused(int $now): ?array {
        $state = json_decode((string)get_config(self::C, 'mailpaused'), true);
        return is_array($state) && (int)($state['time'] ?? 0) > $now - self::PAUSE ? $state : null;
    }

    /**
     * What a plugin hears of its mails, and all those that listen when the mail is not known.
     *
     * @param string $component The plugin; empty for all.
     * @param string $email
     * @param string $event bounce, blocked, spam or failed.
     * @param string $why What the other side said.
     * @param string $ref What the plugin calls the mail.
     * @param int $time
     */
    public static function tell(string $component, string $email, string $event, string $why, string $ref, int $time): void {
        foreach (get_plugins_with_function(self::CALLBACK) as $type => $plugins) {
            foreach ($plugins as $name => $function) {
                if ($component !== '' && $component !== $type . '_' . $name) {
                    continue;
                }
                try {
                    $function($email, $event, $why, $ref, $time);
                } catch (\Throwable $e) {
                    $said = 'Marandu: ' . $type . '_' . $name . ' did not take an event of a mail: ' . $e->getMessage();
                    debugging($said, DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * A mail with every field, its text version and its pictures checked.
     *
     * @param array $letter
     * @return array
     */
    private static function normal(array $letter): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $letter += ['email' => '', 'name' => '', 'userid' => 0, 'subject' => '', 'html' => '', 'text' => '', 'images' => [],
            'files' => [],
            'component' => '', 'ref' => '', 'replyto' => '', 'bulk' => false, 'routine' => false, 'unsubscribe' => '',
            'later' => false, 'released' => false];
        $letter['email'] = \core_text::strtolower(trim((string)$letter['email']));
        $letter['subject'] = \core_text::substr(trim(str_replace(["\r", "\n"], ' ', (string)$letter['subject'])), 0, 250);
        $letter['name'] = trim(str_replace(["\r", "\n", '"'], ' ', (string)$letter['name']));
        if (trim((string)$letter['text']) === '' && $letter['html'] !== '') {
            $letter['text'] = html_to_text($letter['html'], 0, false);
        }
        $images = [];
        $bytes = 0;
        foreach ((array)$letter['images'] as $image) {
            $data = (string)($image['data'] ?? '');
            $cid = preg_replace('/[^A-Za-z0-9._@-]/', '', (string)($image['cid'] ?? ''));
            // Only the pictures the mail shows, and not past the size that makes it look like spam.
            if ($data === '' || $cid === '' || strpos((string)$letter['html'], 'cid:' . $cid) === false) {
                continue;
            }
            $bytes += strlen($data);
            if ($bytes > self::MAXIMAGES) {
                debugging('Marandu: the pictures of a mail pass ' . self::MAXIMAGES . ' bytes; the rest go out.', DEBUG_DEVELOPER);
                break;
            }
            $images[] = ['cid' => $cid, 'name' => clean_param((string)($image['name'] ?? $cid), PARAM_FILE) ?: $cid,
                'mime' => (string)($image['mime'] ?? 'image/png'), 'data' => $data];
        }
        $letter['images'] = $images;
        $files = [];
        foreach ((array)$letter['files'] as $file) {
            $data = (string)($file['data'] ?? '');
            if ($data === '') {
                continue;
            }
            $name = clean_param((string)($file['name'] ?? ''), PARAM_FILE) ?: 'file';
            $files[] = ['name' => $name, 'mime' => (string)($file['mime'] ?? '') ?: mimeinfo('type', $name), 'data' => $data];
        }
        $letter['files'] = $files;
        foreach (['bulk', 'routine', 'later', 'released'] as $flag) {
            $letter[$flag] = !empty($letter[$flag]);
        }
        return $letter;
    }

    /**
     * Whether the address does not receive this mail: it came back for good or was blocked; or it marked one as spam
     * and this is a send to many or routine.
     *
     * @param array $letter
     * @return bool
     */
    private static function refused(array $letter): bool {
        global $DB;
        $event = $DB->get_field(self::BAD, 'event', ['email' => $letter['email']]);
        return $event !== false && ($event !== 'spam' || $letter['bulk'] || $letter['routine']);
    }

    /**
     * Notes a mail that goes now.
     *
     * @param array $letter
     * @param int $now
     * @return int Its id.
     */
    private static function record(array $letter, int $now): int {
        global $DB;
        return (int)$DB->insert_record(self::TABLE, (object)[
            'component' => $letter['component'] === '' ? null : \core_text::substr((string)$letter['component'], 0, 100),
            'ref' => $letter['ref'] === '' ? null : \core_text::substr((string)$letter['ref'], 0, 100),
            'userid' => (int)$letter['userid'] ?: null,
            'email' => $letter['email'],
            'subject' => $letter['subject'],
            'status' => 'queued',
            'bulk' => (int)$letter['bulk'],
            'routine' => (int)$letter['routine'],
            'timequeued' => $now,
        ]);
    }

    /**
     * Leaves a mail waiting, with its pictures in the files of the site.
     *
     * @param array $letter
     * @param int $now
     * @param string|null $why paused or quota, if it cannot go yet.
     * @return int Its id.
     */
    private static function queue(array $letter, int $now, ?string $why): int {
        global $DB;
        $id = self::record($letter, $now);
        $images = [];
        foreach ($letter['images'] as $image) {
            $images[] = ['cid' => $image['cid'], 'name' => $image['name'], 'mime' => $image['mime'],
                'file' => self::keep_file($image['data'], $image['name'])];
        }
        $files = [];
        foreach ($letter['files'] as $file) {
            $files[] = ['name' => $file['name'], 'mime' => $file['mime'], 'file' => self::keep_file($file['data'], $file['name'])];
        }
        $payload = array_diff_key($letter, ['images' => 1, 'files' => 1]) + ['images' => $images, 'files' => $files];
        $DB->update_record(self::TABLE, (object)['id' => $id, 'payload' => json_encode($payload),
            'waiting' => $why, 'timeretry' => $why === null ? null : $now + ($why === 'paused' ? self::PAUSE : HOURSECS)]);
        return $id;
    }

    /**
     * A mail that waits, as it was left.
     *
     * @param \stdClass $row
     * @return array|null
     */
    private static function unpack(\stdClass $row): ?array {
        $letter = json_decode((string)$row->payload, true);
        if (!is_array($letter)) {
            return null;
        }
        $images = [];
        foreach ((array)($letter['images'] ?? []) as $image) {
            $file = self::find_file((string)($image['file'] ?? ''));
            if ($file !== null) {
                $images[] = ['cid' => $image['cid'], 'name' => $image['name'], 'mime' => $image['mime'], 'data' => $file['data']];
            }
        }
        $letter['images'] = $images;
        $files = [];
        foreach ((array)($letter['files'] ?? []) as $file) {
            $kept = self::find_file((string)($file['file'] ?? ''));
            if ($kept !== null) {
                $files[] = ['name' => $file['name'], 'mime' => $file['mime'], 'data' => $kept['data']];
            }
        }
        $letter['files'] = $files;
        $letter['released'] = !empty($row->released);
        return self::normal($letter);
    }

    /**
     * Keeps a picture or a part of a mail, once for all the mails that carry it: for what waits in the line, and for
     * the notices of Moodle marked for this way, which come with their HTML only.
     *
     * @param string $data
     * @param string $name Its name, for its extension; or the whole name to keep it with, with $asnamed.
     * @param bool $asnamed Kept with that very name (a part found again by another, such as the text of a kept mail by
     *     the hash of its HTML), not by what it is.
     * @return string What finds it again: the name it is kept with.
     */
    public static function keep_file(string $data, string $name, bool $asnamed = false): string {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($name, PATHINFO_EXTENSION))) ?: 'png';
        $filename = $asnamed ? clean_param($name, PARAM_FILE) : sha1($data) . '.' . $ext;
        if (!$fs->file_exists($context->id, self::C, self::FILEAREA, 0, '/', $filename)) {
            $fs->create_file_from_string(['contextid' => $context->id, 'component' => self::C, 'filearea' => self::FILEAREA,
                'itemid' => 0, 'filepath' => '/', 'filename' => $filename], $data);
        }
        return $filename;
    }

    /**
     * A picture or a part kept with keep_file(), by its name, or by what it is and its kinds.
     *
     * @param string $hash What it is (sha1), or the whole name it is kept with.
     * @param string[] $extensions The kinds it may be, when only the hash is given.
     * @return array|null name, mime and data.
     */
    public static function find_file(string $hash, array $extensions = ['png', 'jpg', 'jpeg', 'gif']): ?array {
        $fs = get_file_storage();
        $context = \context_system::instance();
        $names = strpos($hash, '.') !== false ? [$hash] : array_map(fn($ext) => $hash . '.' . $ext, $extensions);
        foreach ($names as $filename) {
            $file = $fs->get_file($context->id, self::C, self::FILEAREA, 0, '/', $filename);
            if ($file) {
                return ['name' => $filename, 'mime' => $file->get_mimetype(), 'data' => $file->get_content()];
            }
        }
        return null;
    }

    /**
     * Takes away the pictures of the mails that waited, once nothing waits.
     *
     * @param int $now
     */
    private static function forget_images(int $now): void {
        $fs = get_file_storage();
        foreach ($fs->get_area_files(\context_system::instance()->id, self::C, self::FILEAREA, 0, 'id', false) as $file) {
            if ($file->get_timecreated() < $now - HOURSECS) {
                $file->delete();
            }
        }
    }

    /**
     * A mail that cannot go yet, looked at again later.
     *
     * @param \stdClass $row
     * @param int $now
     * @param int $seconds
     * @param string|null $why
     */
    private static function wait(\stdClass $row, int $now, int $seconds, ?string $why): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $row->id, 'timeretry' => $now + $seconds, 'waiting' => $why]);
    }

    /**
     * Closes a mail: how it went, and its content forgotten.
     *
     * @param int $id
     * @param int $now
     * @param string $status sent or failed.
     * @param string|null $way site or mailjet.
     * @param string|null $error
     * @param string|null $mjid
     */
    private static function finish(
        int $id,
        int $now,
        string $status,
        ?string $way,
        ?string $error = null,
        ?string $mjid = null
    ): void {
        global $DB;
        $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => $status, 'way' => $way, 'timesent' => $now,
            'error' => $error === null ? null : \core_text::substr($error, 0, 250), 'mjid' => $mjid, 'payload' => null,
            'waiting' => null, 'timeretry' => null]);
    }

    /**
     * Sends a mail by the mail of the site, as Moodle sends its own (its SMTP, its switches for development sites), with
     * its pictures inside.
     *
     * @param array $letter
     * @param int $id
     * @param int $now
     * @param string|null $note Why it went this way, kept with it.
     * @return bool
     */
    private static function by_site(array $letter, int $id, int $now, ?string $note = null): bool {
        global $CFG;
        if (!empty($CFG->noemailever)) {
            self::finish($id, $now, 'sent', 'site', 'noemailever');
            return true;
        }
        $email = $letter['email'];
        $subject = $letter['subject'];
        if (email_should_be_diverted($email)) {
            $subject = "[DIVERTED {$email}] {$subject}";
            $email = $CFG->divertallemailsto;
        }
        $mail = get_mailer();
        $noreply = !empty($CFG->noreplyaddress) ? $CFG->noreplyaddress : 'noreply@' . get_host_from_url($CFG->wwwroot);
        $mail->Sender = $noreply;
        $mail->From = $noreply;
        $mail->FromName = settings::from()[1];
        $mail->Subject = $subject;
        $mail->addAddress($email, $letter['name']);
        $replyto = $letter['replyto'] ?: settings::replyto();
        if ($replyto !== '' && validate_email($replyto)) {
            $mail->addReplyTo($replyto);
        }
        foreach (self::headers($letter) as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }
        // No tracking, said also to the SMTP of a provider that would add it (Mailjet reads these; any other leaves them).
        $mail->addCustomHeader('X-Mailjet-TrackOpen', '0');
        $mail->addCustomHeader('X-Mailjet-TrackClick', '0');
        $mail->MessageID = generate_email_messageid();
        if (!empty($CFG->emaildkimselector)) {
            // Signed as Moodle signs its own, when the site has the key.
            $domain = substr(strrchr($mail->From, '@'), 1);
            $pempath = "{$CFG->dataroot}/dkim/{$domain}/{$CFG->emaildkimselector}.private";
            if (file_exists($pempath)) {
                $mail->DKIM_domain = $domain;
                $mail->DKIM_private = $pempath;
                $mail->DKIM_selector = $CFG->emaildkimselector;
                $mail->DKIM_identity = $mail->From;
            }
        }
        if ($letter['html'] !== '') {
            $mail->isHTML(true);
            $mail->Encoding = 'quoted-printable';
            $mail->Body = $letter['html'];
            $mail->AltBody = $letter['text'];
            foreach ($letter['images'] as $image) {
                $mail->addStringEmbeddedImage($image['data'], $image['cid'], $image['name'], 'base64', $image['mime']);
            }
        } else {
            $mail->isHTML(false);
            $mail->Body = $letter['text'];
        }
        foreach ($letter['files'] as $file) {
            $mail->addStringAttachment($file['data'], $file['name'], 'base64', $file['mime']);
        }
        $ok = $mail->send();
        $error = $ok ? $note : (string)$mail->ErrorInfo;
        self::finish($id, $now, $ok ? 'sent' : 'failed', 'site', $error);
        if (!$ok) {
            self::tell($letter['component'], $letter['email'], 'failed', $error, $letter['ref'], $now);
        }
        return $ok;
    }

    /**
     * The headers of every mail: automatic, so that no automatic answer comes back; and, for a send to many, how to
     * leave it with one click.
     *
     * @param array $letter
     * @return array By name.
     */
    private static function headers(array $letter): array {
        $headers = ['Auto-Submitted' => 'auto-generated'];
        if ($letter['unsubscribe'] !== '' && preg_match('~^https://~i', $letter['unsubscribe'])) {
            $headers['List-Unsubscribe'] = '<' . $letter['unsubscribe'] . '>';
            $headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }
        return $headers;
    }

    /**
     * Sends mails by Mailjet, the rows of what waited closed as it goes.
     *
     * @param array $batch Letters, by id.
     * @param \stdClass[] $rows What waited, by id.
     * @param int $now
     * @param array $counts
     * @return array The counts.
     */
    private static function flush(array $batch, array $rows, int $now, array $counts): array {
        global $DB;
        foreach (self::by_mailjet($batch, $now) as $id => $result) {
            if ($result['ok']) {
                $counts['mailjet']++;
                continue;
            }
            $row = $rows[$id];
            $letter = $batch[$id];
            if ($result['kind'] === 'bad') {
                $counts['failed']++;
                continue;
            }
            $attempts = (int)$row->attempts + 1;
            if ($now - (int)$row->timequeued >= self::GIVEUP) {
                self::finish($id, $now, 'failed', null, $result['error']);
                self::tell($letter['component'], $letter['email'], 'failed', $result['error'], $letter['ref'], $now);
                $counts['failed']++;
                continue;
            }
            $wait = $result['kind'] === 'pause' ? self::PAUSE : self::RETRIES[min($attempts, count(self::RETRIES)) - 1];
            $DB->update_record(self::TABLE, (object)['id' => $id, 'attempts' => $attempts, 'timeretry' => $now + $wait,
                'waiting' => $result['kind'] === 'pause' ? 'paused' : 'retry',
                'error' => \core_text::substr($result['error'], 0, 250)]);
            $counts['waiting']++;
        }
        return $counts;
    }

    /**
     * Sends mails by Mailjet in one call, and closes those that went or cannot go.
     *
     * @param array $batch Letters, by id; up to 50.
     * @param int $now
     * @return array By id: ok, kind (ok, retry, pause or bad) and error.
     */
    private static function by_mailjet(array $batch, int $now): array {
        $api = mailjet_api::from_config();
        $results = [];
        if (!$api) {
            foreach ($batch as $id => $letter) {
                $results[$id] = ['ok' => false, 'kind' => 'pause', 'error' => 'nokeys'];
            }
            return $results;
        }
        [$from, $fromname] = settings::from();
        $messages = [];
        foreach ($batch as $id => $letter) {
            $message = [
                'From' => ['Email' => $from, 'Name' => $fromname],
                'To' => [array_filter(['Email' => $letter['email'], 'Name' => $letter['name']], 'strlen')],
                'Subject' => $letter['subject'],
                'TextPart' => $letter['text'],
                'TrackOpens' => 'disabled',
                'TrackClicks' => 'disabled',
                'CustomID' => 'mrd-' . $id,
                'Headers' => self::headers($letter),
            ];
            if ($letter['html'] !== '') {
                $message['HTMLPart'] = $letter['html'];
                $inside = [];
                foreach ($letter['images'] as $image) {
                    $inside[] = ['ContentType' => $image['mime'], 'Filename' => $image['name'], 'ContentID' => $image['cid'],
                        'Base64Content' => base64_encode($image['data'])];
                }
                if ($inside) {
                    $message['InlinedAttachments'] = $inside;
                }
            }
            if ($letter['files']) {
                $message['Attachments'] = array_map(fn($file) => ['ContentType' => $file['mime'], 'Filename' => $file['name'],
                    'Base64Content' => base64_encode($file['data'])], $letter['files']);
            }
            $replyto = $letter['replyto'] ?: settings::replyto();
            if ($replyto !== '' && validate_email($replyto)) {
                $message['ReplyTo'] = ['Email' => $replyto];
            }
            $messages[] = $message;
        }
        [$status, $answer] = $api->send($messages, (bool)get_config(self::C, 'mailsandbox'));
        $ids = array_keys($batch);
        $each = isset($answer['Messages']) && is_array($answer['Messages']) ? array_values($answer['Messages']) : [];
        foreach ($ids as $i => $id) {
            $one = $each[$i] ?? null;
            if (in_array($status, [200, 201], true) && $one && ($one['Status'] ?? '') === 'success') {
                $mjid = (string)($one['To'][0]['MessageID'] ?? '');
                self::finish($id, $now, 'sent', 'mailjet', null, $mjid === '' ? null : $mjid);
                $results[$id] = ['ok' => true, 'kind' => 'ok', 'error' => ''];
                continue;
            }
            $error = $one['Errors'][0] ?? $answer;
            $code = (int)($error['StatusCode'] ?? $status);
            // What Mailjet said, with its code, or with the status of the answer when it gave no code.
            $said = trim(($error['ErrorCode'] ?? ($status ? 'http ' . $code : 'network')) . ' ' . ($error['ErrorMessage'] ?? ''));
            if ($status === 0 || $code === 429 || $code >= 500) {
                $kind = 'retry';
            } else if ($code === 401 || $code === 403) {
                $kind = 'pause';
            } else {
                $kind = 'bad';
            }
            $results[$id] = ['ok' => false, 'kind' => $kind, 'error' => $said];
            if ($kind === 'bad') {
                self::finish($id, $now, 'failed', 'mailjet', $said);
                $letter = $batch[$id];
                self::tell($letter['component'], $letter['email'], 'failed', $said, $letter['ref'], $now);
            }
        }
        $refused = array_filter($results, fn($result) => $result['kind'] === 'pause');
        if ($refused && settings::mailjet_chosen()) {
            // Only when it is the way of the site: a test before choosing it does not stop anything.
            self::pause($now, reset($refused)['error']);
        } else if (array_filter($results, fn($result) => $result['ok'])) {
            self::unpause();
        }
        return $results;
    }

    /**
     * Puts Mailjet aside a while, and says it once to whoever is told of the limits.
     *
     * @param int $now
     * @param string $error
     */
    private static function pause(int $now, string $error): void {
        $was = json_decode((string)get_config(self::C, 'mailpaused'), true);
        $told = is_array($was) && !empty($was['told']);
        set_config('mailpaused', json_encode(['time' => $now, 'error' => $error, 'since' => $told ? (int)$was['since'] : $now,
            'told' => 1]), self::C);
        if ($told) {
            return;
        }
        $a = (object)['site' => settings::from()[1], 'error' => $error,
            'url' => (new \moodle_url('/local/oksigeniamarandu/mail.php'))->out(false)];
        foreach (limits::emails() as $email) {
            // Mailjet is aside now: this goes by the mail of the site.
            self::warn(
                $email,
                get_string('mail:paused:subject', self::C, $a),
                get_string('mail:paused:body', self::C, $a),
                get_string('mail:action:mailjet', self::C),
                $now
            );
        }
    }

    /**
     * Mailjet takes mails again.
     */
    private static function unpause(): void {
        if ((string)get_config(self::C, 'mailpaused') !== '') {
            set_config('mailpaused', '', self::C);
        }
    }
}
