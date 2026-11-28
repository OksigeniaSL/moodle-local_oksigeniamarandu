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
 * Proposals of improvement: a person, on a screen of a tool, marks with a picture of it what they would change and says
 * how. They are kept in the site with who, when, the tool and its version, the screen and its address; the text generation
 * of the site, if there is one, puts each in a line and says which tool it is about; the people the site chose hear of
 * each by Telegram with the picture; and whoever looks after them reads them and closes them, here or from the command
 * line (cli/proposals.php).
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposals {
    /** @var string The table. */
    public const TABLE = 'local_oksigeniamarandu_proposal';

    /** @var string The area of the files of the pictures. */
    public const AREA = 'proposal';

    /** @var int The most bytes of a picture. */
    public const MAX_BYTES = 6 * 1024 * 1024;

    /** @var int The most characters of what the person writes. */
    public const MAX_TEXT = 4000;

    /** @var int Seconds before whoever proposed it hears how it ended, or it goes to Oksigenia: the time to undo. */
    public const UNDO = 10;

    /** @var int The largest picture that goes to Oksigenia, in bytes. */
    public const REMOTE_BYTES = 1000000;

    /** @var string The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

    /** @var string Marandu, written so that a copy of this class in another plugin keeps pointing at it. */
    private const MARANDU = '\\local_oksigenia' . 'marandu';

    /**
     * Keeps a proposal and leaves to a task its line and the notice to the people who receive them.
     *
     * @param int $userid Who proposes it.
     * @param string $component The tool, such as local_oksigeniameals.
     * @param string $screen The screen, as the tool names it.
     * @param string $url The address of the page.
     * @param string $body What they would change.
     * @param string $image The picture of the screen with the marks, a JPEG or a PNG in base64 (a data: URL too); empty
     *                      if the browser could not make it.
     * @param string $kind idea, or issue: something does not work (the people who receive them hear it at once).
     * @param string $browser The browser, its system, the size of its window and its colours, in words.
     * @param int $marks How many marks the picture carries.
     * @return int Its id.
     * @throws \invalid_parameter_exception If it says nothing, or the picture is not a picture.
     */
    public static function save(
        int $userid,
        string $component,
        string $screen,
        string $url,
        string $body,
        string $image,
        string $kind = 'idea',
        string $browser = '',
        int $marks = 0
    ): int {
        global $DB;
        $component = clean_param($component, PARAM_COMPONENT);
        if ($component === '') {
            throw new \invalid_parameter_exception('A proposal needs its tool');
        }
        $body = trim(\core_text::substr(str_replace(["\r\n", "\r"], "\n", clean_param($body, PARAM_TEXT)), 0, self::MAX_TEXT));
        if ($body === '') {
            throw new \invalid_parameter_exception('A proposal says what to change');
        }
        [$bytes, $extension] = self::image($image);
        $now = time();
        $id = (int)$DB->insert_record(self::TABLE, (object)[
            'userid' => $userid,
            'component' => $component,
            'toolrelease' => \core_text::substr(self::release($component), 0, 50),
            'screen' => \core_text::substr(clean_param($screen, PARAM_TEXT), 0, 100),
            'pageurl' => \core_text::substr(clean_param($url, PARAM_URL), 0, 1333),
            'body' => $body,
            'status' => 'new',
            'kind' => $kind === 'issue' ? 'issue' : 'idea',
            'browser' => \core_text::substr(trim(clean_param($browser, PARAM_TEXT)), 0, 255) ?: null,
            'marks' => max(0, min(999, $marks)),
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        if ($bytes !== '') {
            get_file_storage()->create_file_from_string([
                'contextid' => \context_system::instance()->id,
                'component' => self::COMPONENT,
                'filearea' => self::AREA,
                'itemid' => $id,
                'filepath' => '/',
                'filename' => 'captura.' . $extension,
                'userid' => $userid,
            ], $bytes);
        }
        $task = new \local_oksigeniamarandu\task\proposal_received();
        $task->set_custom_data(['id' => $id]);
        \core\task\manager::queue_adhoc_task($task);
        return $id;
    }

    /**
     * The version of a tool, as it says it: its release, or that of the platform.
     *
     * @param string $component The tool.
     * @return string
     */
    public static function release(string $component): string {
        global $CFG;
        if ($component === 'core') {
            return (string)$CFG->release;
        }
        $info = \core_plugin_manager::instance()->get_plugin_info($component);
        return $info ? (string)($info->release ?? '') : '';
    }

    /**
     * The picture as it came: its bytes and its kind, checked.
     *
     * @param string $image Base64, with or without its data: prefix; empty for none.
     * @return array The bytes and the extension; two empty strings for none.
     * @throws \invalid_parameter_exception If it is not a JPEG or a PNG, or it is too big.
     */
    private static function image(string $image): array {
        $image = trim(preg_replace('~^data:image/[a-z]+;base64,~i', '', trim($image)));
        if ($image === '') {
            return ['', ''];
        }
        $bytes = base64_decode($image, true);
        if ($bytes === false || strlen($bytes) > self::MAX_BYTES) {
            throw new \invalid_parameter_exception('The picture of a proposal is a JPEG or a PNG of 6 MB at most');
        }
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return [$bytes, 'jpg'];
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return [$bytes, 'png'];
        }
        throw new \invalid_parameter_exception('The picture of a proposal is a JPEG or a PNG of 6 MB at most');
    }

    /**
     * The picture of a proposal, if it has one.
     *
     * @param int $id The proposal.
     * @return \stored_file|null
     */
    public static function image_file(int $id): ?\stored_file {
        $files = get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::COMPONENT,
            self::AREA,
            $id,
            'id',
            false
        );
        return $files ? reset($files) : null;
    }

    /**
     * The address of the picture of a proposal, for whoever may read it.
     *
     * @param int $id The proposal.
     * @return \moodle_url|null
     */
    public static function image_url(int $id): ?\moodle_url {
        $file = self::image_file($id);
        return $file ? \moodle_url::make_pluginfile_url(
            $file->get_contextid(),
            self::COMPONENT,
            self::AREA,
            $id,
            '/',
            $file->get_filename()
        ) : null;
    }

    /**
     * The people who receive the proposals: those the site chose (proposalpeople), in its order.
     *
     * @return \stdClass[]
     */
    public static function people(): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map(
            'intval',
            explode(',', (string)get_config(self::COMPONENT, 'proposalpeople'))
        ))));
        $users = $ids ? $DB->get_records_list('user', 'id', $ids) : [];
        $list = [];
        foreach ($ids as $id) {
            if (isset($users[$id]) && !$users[$id]->deleted && !$users[$id]->suspended) {
                $list[] = $users[$id];
            }
        }
        return $list;
    }

    /**
     * Chooses the people who receive the proposals.
     *
     * @param int[] $userids The users; none for nobody.
     */
    public static function set_people(array $userids): void {
        $userids = array_values(array_unique(array_filter(array_map('intval', $userids))));
        set_config('proposalpeople', implode(',', $userids), self::COMPONENT);
    }

    /**
     * Whether a user reads and closes the proposals: one of the people chosen, or with the capability.
     *
     * @param int $userid The user.
     * @return bool
     */
    public static function can_read(int $userid): bool {
        foreach (self::people() as $user) {
            if ((int)$user->id === $userid) {
                return true;
            }
        }
        return $userid > 0 && has_capability('local/oksigeniamarandu:proposals', \context_system::instance(), $userid);
    }

    /**
     * The tools of the site a proposal can be about: the plugins of Oksigenia installed, and the platform itself.
     *
     * @return string[] Their names, by component.
     */
    public static function tools(): array {
        $tools = ['core' => get_string('proposal:platform', self::COMPONENT)];
        foreach (\core_plugin_manager::instance()->get_plugins() as $plugins) {
            foreach ($plugins as $plugin) {
                if (strpos((string)$plugin->name, 'oksigenia') === 0 && $plugin->is_installed_and_upgraded()) {
                    $tools[$plugin->component] = format_string($plugin->displayname);
                }
            }
        }
        return $tools;
    }

    /**
     * The name of a tool.
     *
     * @param string $component The tool.
     * @return string
     */
    public static function tool_name(string $component): string {
        $tools = self::tools();
        return $tools[$component] ?? $component;
    }

    /**
     * Puts a proposal in a line and says which tool it is about, with the text generation of the site, if there is one.
     *
     * @param \stdClass $record The proposal.
     * @return bool Whether it did.
     */
    public static function summarise(\stdClass $record): bool {
        global $DB;
        if (!self::ai()) {
            return false;
        }
        $tools = self::tools();
        $list = implode("\n", array_map(fn($component, $name) => '- ' . $component . ': ' . $name, array_keys($tools), $tools));
        $prompt = get_string('proposal:prompt', self::COMPONENT, (object)[
            'tool' => $record->component,
            'screen' => (string)$record->screen,
            'tools' => $list,
            'body' => $record->body,
        ]);
        try {
            $action = new \core_ai\aiactions\generate_text(\context_system::instance()->id, (int)$record->userid, $prompt);
            $response = \core\di::get(\core_ai\manager::class)->process_action($action);
        } catch (\Throwable $e) {
            return false;
        }
        if (!$response->get_success()) {
            return false;
        }
        $text = (string)($response->get_response_data()['generatedcontent'] ?? '');
        $summary = '';
        $tool = '';
        foreach (preg_split('/\R/u', $text) as $line) {
            if (preg_match('/^\W*(?:summary|resumen)\W*:\s*(.+)$/iu', trim($line), $match)) {
                $summary = trim($match[1], " \t\"«»*");
            } else if (preg_match('/^\W*(?:tool|herramienta)\W*:\s*([a-z0-9_]+)/iu', trim($line), $match)) {
                $tool = isset($tools[$match[1]]) ? $match[1] : '';
            }
        }
        if ($summary === '') {
            return false;
        }
        $DB->update_record(self::TABLE, (object)['id' => $record->id, 'summary' => \core_text::substr($summary, 0, 255),
            'tool' => $tool !== '' ? $tool : null, 'timemodified' => time()]);
        return true;
    }

    /**
     * Tells the people who receive the proposals, by Telegram, with the picture.
     *
     * @param \stdClass $record The proposal.
     * @return int How many chats it was queued for.
     */
    public static function notify(\stdClass $record): int {
        global $DB;
        $author = \core_user::get_user((int)$record->userid);
        $tool = self::tool_name((string)($record->tool ?: $record->component));
        $line = $record->summary ?: \core_text::substr(preg_replace('/\s+/u', ' ', $record->body), 0, 150);
        $image = self::image_url((int)$record->id);
        $rich = ['title' => get_string($record->kind === 'issue' ? 'proposal:cardissue' : 'proposal:card', self::COMPONENT, $tool),
            'text' => [$record->body, get_string('proposal:from', self::COMPONENT, $author ? fullname($author) : '') .
                ((string)$record->screen !== '' ? ' · ' . $record->screen : '')]];
        if ($image) {
            $rich['image'] = $image->out(false);
        }
        $count = 0;
        if (!self::marandu()) {
            $DB->set_field(self::TABLE, 'timenotified', time(), ['id' => $record->id]);
            return 0;
        }
        foreach (self::people() as $person) {
            $count += self::telegram(
                [$tool . ': ' . $line],
                new \moodle_url('/local/oksigeniamarandu/proposals.php', [], 'p' . $record->id),
                (int)$person->id,
                3 * DAYSECS,
                $rich
            );
        }
        $DB->set_field(self::TABLE, 'timenotified', time(), ['id' => $record->id]);
        return $count;
    }

    /**
     * The proposals, the newest first.
     *
     * @param string $status new, done or empty for all.
     * @param int $limit How many; 0 for all.
     * @return \stdClass[]
     */
    public static function list(string $status = 'new', int $limit = 0): array {
        global $DB;
        return array_values($DB->get_records(
            self::TABLE,
            $status !== '' ? ['status' => $status] : [],
            'timecreated DESC, id DESC',
            '*',
            0,
            $limit
        ));
    }

    /**
     * A proposal as the command line gives it, with its picture copied to a folder.
     *
     * @param \stdClass $record The proposal.
     * @param string|null $dir Where to copy its picture; null not to copy it.
     * @return array
     */
    public static function export(\stdClass $record, ?string $dir = null): array {
        $author = \core_user::get_user((int)$record->userid);
        $picture = '';
        $file = self::image_file((int)$record->id);
        if ($file && $dir !== null) {
            $picture = rtrim($dir, '/') . '/propuesta-' . $record->id . '.' . pathinfo($file->get_filename(), PATHINFO_EXTENSION);
            $file->copy_content_to($picture);
        }
        return [
            'id' => (int)$record->id,
            'time' => (int)$record->timecreated,
            'date' => userdate((int)$record->timecreated, '%Y-%m-%d %H:%M'),
            'who' => $author ? fullname($author) : '',
            'userid' => (int)$record->userid,
            'tool' => (string)$record->component,
            'toolname' => self::tool_name((string)$record->component),
            'release' => (string)$record->toolrelease,
            'screen' => (string)$record->screen,
            'url' => (string)$record->pageurl,
            'text' => (string)$record->body,
            'summary' => (string)$record->summary,
            'guessedtool' => (string)$record->tool,
            'status' => (string)$record->status,
            'note' => (string)$record->note,
            'picture' => $picture,
        ];
    }

    /**
     * Closes a proposal, with what was done (as before: done, without a version).
     *
     * @param int $id The proposal.
     * @param string $note What was done.
     * @param int $userid Who closes it.
     * @return bool Whether it was open.
     */
    public static function done(int $id, string $note, int $userid): bool {
        return self::close($id, 'done', $note, '', $userid);
    }

    /**
     * What just came: its line by the text generation of the site; and, if something does not work, the people who
     * receive the suggestions hear it now. An idea waits for the summary of the day.
     *
     * @param int $id
     * @return int How many chats were told.
     */
    public static function received(int $id): int {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->timenotified) {
            return 0;
        }
        if (self::summarise($record)) {
            $record = $DB->get_record(self::TABLE, ['id' => $id]);
        }
        if ($record->kind !== 'issue') {
            return 0;
        }
        self::bell($record);
        return self::notify($record);
    }

    /**
     * The ideas of the day, in one notice to each person who receives them: by Telegram, and in the bell.
     *
     * @param int $now
     * @return int How many ideas went.
     */
    public static function digest(int $now): int {
        global $DB;
        $ideas = $DB->get_records_select(
            self::TABLE,
            "status = 'new' AND kind = 'idea' AND timedigest IS NULL",
            null,
            'timecreated ASC, id ASC'
        );
        if (!$ideas) {
            return 0;
        }
        $lines = [];
        foreach ($ideas as $idea) {
            $lines[] = self::tool_name((string)($idea->tool ?: $idea->component)) . ': '
                . ($idea->summary ?: \core_text::substr(preg_replace('/\s+/u', ' ', $idea->body), 0, 120));
        }
        $count = count($ideas);
        $title = get_string($count === 1 ? 'proposal:digest:one' : 'proposal:digest:many', self::COMPONENT, $count);
        $url = new \moodle_url('/local/oksigeniamarandu/proposals.php');
        foreach (self::people() as $person) {
            self::telegram(
                [$title . "\n" . implode("\n", array_map(fn($line) => '• ' . $line, $lines))],
                $url,
                (int)$person->id,
                DAYSECS,
                []
            );
            self::message('proposal', $person, $title, implode("\n", $lines), $url, get_string('proposal:open', self::COMPONENT));
        }
        [$in, $params] = $DB->get_in_or_equal(array_keys($ideas));
        $DB->set_field_select(self::TABLE, 'timedigest', $now, "id $in", $params);
        return $count;
    }

    /**
     * Closes a suggestion with how it ended: done (in a version) or not (and why). Whoever proposed it hears it a few
     * seconds later (UNDO), unless it is undone before; an answer of Oksigenia is told at once.
     *
     * @param int $id
     * @param string $outcome done or no.
     * @param string $note Why not; or what was done.
     * @param string $release The version it is done in.
     * @param int $userid Who closes it; 0 for Oksigenia.
     * @param int|null $now
     * @return bool Whether it was open.
     */
    public static function close(int $id, string $outcome, string $note, string $release, int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status === 'done' || !in_array($outcome, ['done', 'no'], true)) {
            return false;
        }
        $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'done', 'outcome' => $outcome,
            'note' => \core_text::substr(trim($note), 0, self::MAX_TEXT),
            'outcomerelease' => \core_text::substr(trim(clean_param($release, PARAM_TEXT)), 0, 50) ?: null,
            'doneby' => $userid ?: null, 'timedone' => $now, 'timetold' => null, 'timemodified' => $now]);
        $task = new \local_oksigeniamarandu\task\proposal_tell();
        $task->set_custom_data(['id' => $id, 'time' => $now]);
        $task->set_next_run_time($now + ($userid ? self::UNDO : 0));
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * Undoes the last gesture on a suggestion, while whoever proposed it has not heard it: a close, or the pass to
     * Oksigenia before it went.
     *
     * @param int $id
     * @return bool Whether it was undone.
     */
    public static function undo(int $id): bool {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if ($record && $record->status === 'done' && !$record->timetold) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => $record->remotestate === 'received' ? 'away' : 'new',
                'outcome' => null, 'outcomerelease' => null, 'note' => null, 'doneby' => null, 'timedone' => null,
                'timemodified' => time()]);
            return true;
        }
        if ($record && $record->status === 'away' && $record->remotestate === 'sending') {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'new', 'remotestate' => null, 'remoteby' => null,
                'timeremote' => null, 'timeasked' => null, 'timemodified' => time()]);
            return true;
        }
        return false;
    }

    /**
     * Passes a suggestion to Oksigenia: it is about the tool, not about the organization. It goes a few seconds later
     * (UNDO), and then the hourly task tries again while the receiver does not take it, for a day.
     *
     * @param int $id
     * @param int $userid Who passes it.
     * @param int|null $now
     * @return bool Whether it was waiting.
     */
    public static function forward(int $id, int $userid, ?int $now = null): bool {
        global $DB;
        $now = $now ?? time();
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status !== 'new') {
            return false;
        }
        $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'away', 'remotestate' => 'sending',
            'remoteby' => $userid ?: null, 'timeremote' => $now, 'timeasked' => null, 'timemodified' => $now]);
        $task = new \local_oksigeniamarandu\task\proposal_forward();
        $task->set_custom_data(['id' => $id]);
        $task->set_next_run_time($now + self::UNDO);
        \core\task\manager::queue_adhoc_task($task);
        return true;
    }

    /**
     * Sends a suggestion passed to Oksigenia to its receiver, without the name or the mail of the person.
     *
     * @param int $id
     * @param int|null $now
     * @return string received, refused (the receiver will not take it), failed (a day without taking it), retry, or
     *     empty if it was not waiting to go.
     */
    public static function push(int $id, ?int $now = null): string {
        global $CFG, $DB;
        $now = $now ?? time();
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status !== 'away' || $record->remotestate !== 'sending') {
            return '';
        }
        $author = \core_user::get_user((int)$record->userid);
        $body = [
            'producto' => (string)$record->component,
            'version' => (string)$record->toolrelease,
            'tipo' => $record->kind === 'issue' ? 'incidencia' : 'idea',
            'texto' => (string)$record->body,
            'pantalla' => (string)$record->screen,
            'pagina' => (string)$record->pageurl,
            'navegador' => (string)$record->browser,
            'sitio' => (string)parse_url($CFG->wwwroot, PHP_URL_HOST),
            'idioma' => $author && !empty($author->lang) ? (string)$author->lang : (string)$CFG->lang,
            'ref' => (string)$record->id,
        ];
        $picture = self::jpeg((int)$record->id, 1600, self::REMOTE_BYTES);
        if ($picture !== '') {
            $body['imagen'] = base64_encode($picture);
        }
        [$status, $answer] = proposal_receiver::send($body);
        if (in_array($status, [200, 201], true) && !empty($answer['id']) && !empty($answer['clave'])) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'remotestate' => 'received',
                'remoteid' => \core_text::substr((string)$answer['id'], 0, 64),
                'remotekey' => \core_text::substr((string)$answer['clave'], 0, 128), 'timeasked' => $now,
                'timemodified' => $now]);
            return 'received';
        }
        if (in_array($status, [400, 413], true)) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'new', 'remotestate' => 'refused',
                'timeasked' => $now, 'timemodified' => $now]);
            return 'refused';
        }
        if ($now - (int)$record->timeremote >= DAYSECS) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'new', 'remotestate' => 'failed',
                'timeasked' => $now, 'timemodified' => $now]);
            return 'failed';
        }
        $DB->set_field(self::TABLE, 'timeasked', $now, ['id' => $id]);
        return 'retry';
    }

    /**
     * Asks the receiver of Oksigenia how a suggestion goes; when it is done or turned down, closes it with its answer
     * and whoever proposed it hears it.
     *
     * @param int $id
     * @param int|null $now
     * @return string received, done, no, lost (the receiver does not know it) or retry; empty if it was not there.
     */
    public static function ask(int $id, ?int $now = null): string {
        global $DB;
        $now = $now ?? time();
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status !== 'away' || $record->remotestate !== 'received') {
            return '';
        }
        [$status, $answer] = proposal_receiver::ask((string)$record->remoteid, (string)$record->remotekey);
        if ($status === 404) {
            $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'new', 'remotestate' => 'lost',
                'timeasked' => $now, 'timemodified' => $now]);
            return 'lost';
        }
        $DB->set_field(self::TABLE, 'timeasked', $now, ['id' => $id]);
        if ($status !== 200) {
            return 'retry';
        }
        $state = (string)($answer['estado'] ?? '');
        if ($state === 'hecha' || $state === 'descartada') {
            $outcome = $state === 'hecha' ? 'done' : 'no';
            self::close($id, $outcome, (string)($answer['respuesta'] ?? ''), (string)($answer['version'] ?? ''), 0, $now);
            return $outcome;
        }
        return 'received';
    }

    /**
     * The hourly round: what waits to go to Oksigenia (a try an hour, for a day), what Oksigenia has (asked once a day),
     * and, from 8:00 once a day, the summary of the ideas.
     *
     * @param int|null $now
     * @return array How many of each.
     */
    public static function round(?int $now = null): array {
        global $DB;
        $now = $now ?? time();
        $counts = ['sent' => 0, 'asked' => 0, 'ideas' => 0];
        $waiting = $DB->get_records_select(
            self::TABLE,
            "status = 'away' AND remotestate = 'sending' AND timeremote <= :ready AND (timeasked IS NULL OR timeasked <= :hour)",
            ['ready' => $now - self::UNDO, 'hour' => $now - HOURSECS + 60],
            'id ASC',
            'id'
        );
        foreach ($waiting as $record) {
            $counts['sent'] += self::push((int)$record->id, $now) === 'received' ? 1 : 0;
        }
        $away = $DB->get_records_select(
            self::TABLE,
            "status = 'away' AND remotestate = 'received' AND (timeasked IS NULL OR timeasked <= :day)",
            ['day' => $now - DAYSECS + 300],
            'id ASC',
            'id'
        );
        foreach ($away as $record) {
            self::ask((int)$record->id, $now);
            $counts['asked']++;
        }
        $today = userdate($now, '%Y%m%d', \core_date::get_server_timezone());
        $hour = (int)userdate($now, '%H', \core_date::get_server_timezone());
        if ($hour >= 8 && (string)get_config(self::COMPONENT, 'proposaldigest') !== $today) {
            set_config('proposaldigest', $today, self::COMPONENT);
            $counts['ideas'] = self::digest($now);
        }
        return $counts;
    }

    /**
     * Tells whoever proposed it how it ended: in the bell and by mail, in the common look, with what they proposed and
     * their picture, signed by whoever closed it (or the team of the tool, when Oksigenia answered).
     *
     * @param int $id
     * @param int $time When it was closed: if it was undone and closed again since, the later one tells.
     * @return bool Whether it told.
     */
    public static function tell(int $id, int $time = 0): bool {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status !== 'done' || $record->timetold || ($time && (int)$record->timedone !== $time)) {
            return false;
        }
        $to = \core_user::get_user((int)$record->userid);
        if (!$to || $to->deleted || $to->suspended) {
            $DB->set_field(self::TABLE, 'timetold', time(), ['id' => $id]);
            return false;
        }
        [$subject, $text, $url, $see, $pieces] = self::letter($record, $to);
        $message = self::message('proposalanswer', $to, $subject, $text, $url, $see, $pieces);
        $DB->set_field(self::TABLE, 'timetold', time(), ['id' => $id]);
        return $message;
    }

    /**
     * What whoever proposed a closed suggestion gets: its subject, its text, the address and the words of its button, and
     * the pieces of its mail in the common look.
     *
     * @param \stdClass $record
     * @param \stdClass $to Whoever proposed it.
     * @return array
     */
    public static function letter(\stdClass $record, \stdClass $to): array {
        $lang = !empty($to->lang) ? (string)$to->lang : '';
        $sm = get_string_manager();
        $say = fn(string $key, $a = null) => $sm->get_string($key, self::COMPONENT, $a, $lang ?: null);
        $issue = $record->kind === 'issue';
        $done = $record->outcome === 'done';
        $release = (string)$record->outcomerelease;
        $tool = self::tool_name((string)$record->component);
        $what = $issue ? 'issue' : 'idea';
        $subject = $done
            ? $say('proposal:tell:' . $what . ':done' . ($release !== '' ? ':release' : ''), $release)
            : $say('proposal:tell:' . $what . ':no');
        $heading = $done
            ? $say('proposal:tell:' . $what . ':heading' . ($release !== '' ? ':release' : ''), $release)
            : $say('proposal:tell:no:heading');
        $when = userdate((int)$record->timecreated, $say('proposal:tell:dateformat'), \core_date::get_user_timezone($to));
        $proposed = $say('proposal:tell:' . $what . ':proposed', $when);
        $oksigenia = !$record->doneby && $record->remotestate === 'received';
        $closer = $record->doneby ? \core_user::get_user((int)$record->doneby) : null;
        $blocks = [];
        // Where the person proposed it, as they know it («Panel de avisos»); the tool, if the screen has no name.
        $place = (string)$record->screen !== '' ? (string)$record->screen : $tool;
        if ($done) {
            $blocks[] = ['paragraph' => $say('proposal:tell:' . $what . ':line', $place)];
            if (trim((string)$record->note) !== '' && $oksigenia) {
                $blocks[] = ['paragraph' => trim((string)$record->note)];
            }
        } else {
            $blocks[] = ['paragraph' => $say('proposal:tell:' . $what . ':noline')];
            $blocks[] = ['rich' => [['run' => trim((string)$record->note) ?: $say('proposal:tell:no:noreason'), 'bold' => true]]];
        }
        if ($done && (string)$record->pageurl !== '') {
            $blocks[] = ['action' => ['actionurl' => (string)$record->pageurl, 'actiontext' => $say('proposal:tell:see')]];
        }
        $blocks[] = ['note' => ['notetitle' => $proposed, 'notetext' => (string)$record->body]];
        $picture = self::jpeg((int)$record->id, 1040, 200000);
        if ($picture !== '') {
            $size = @getimagesizefromstring($picture);
            // A picture of a phone, taller than wide: narrower, so that the mail does not become a long scroll.
            $narrow = $size && $size[1] > $size[0];
            $caption = $say((int)($record->marks ?? 0) > 0 ? 'proposal:tell:picture:marked' : 'proposal:tell:picture');
            $blocks[] = ['picture' => ['picturebytes' => $picture, 'picturealt' => $caption, 'picturecaption' => $caption,
                'pictureframe' => true] + ($narrow ? ['picturewidth' => 280] : [])];
        }
        if (!$done) {
            $blocks[] = ['small' => $say('proposal:tell:another')];
        }
        $pieces = [
            'subject' => $subject,
            'preheader' => $done ? \core_text::substr(preg_replace('/\s+/u', ' ', (string)$record->body), 0, 120)
                : trim((string)$record->note),
            'heading' => $heading,
            'hello' => $say('proposal:tell:hello', $to->firstname),
            'blocks' => $blocks,
            'signature' => [
                'signoff' => $say($issue ? 'proposal:tell:signoff:issue' : 'proposal:tell:signoff:idea'),
                'signer' => $oksigenia ? $say('proposal:tell:team', $tool) : ($closer ? fullname($closer) : self::org()),
                'signrole' => $oksigenia ? $say('proposal:tell:makers', $tool) : $say('proposal:tell:role'),
            ],
            'reason' => $say('proposal:tell:reason'),
        ];
        $text = $subject . "\n\n" . $proposed . ":\n" . $record->body;
        $url = (string)$record->pageurl !== '' ? new \moodle_url((string)$record->pageurl) : null;
        return [$subject, $text, $url, $say('proposal:tell:see'), $pieces];
    }

    /**
     * Loads on this page «Propose an improvement», the piece the tools of the site share: any element with
     * data-marandu-proposal="<component>" (and data-screen="<screen>", as the tool names it) opens it, and the script
     * window.MaranduProposal.open({component, screen}) too. The person marks on a picture of what they see what they
     * would change and says how; the people the site chose hear of it by Telegram, and cli/proposals.php gives them.
     * Only for an account that may propose; for others it loads nothing.
     *
     * @return bool Whether it is on this page.
     */
    public static function js(): bool {
        global $PAGE;
        static $loaded = false;
        $assets = self::assets();
        if (!$assets) {
            return false;
        }
        if ($loaded) {
            return true;
        }
        $loaded = true;
        $PAGE->requires->js_init_code('window.MaranduProposalStrings = ' . json_encode($assets['strings'], JSON_HEX_TAG | JSON_HEX_AMP)
            . '; window.ProponerConfig = window.ProponerConfig || ' . json_encode(['service' => self::COMPONENT . '_proposal_send'])
            . ';');
        $PAGE->requires->js(new \moodle_url($assets['js']));
        return true;
    }

    /**
     * What «Propose an improvement» needs, for a page that prints itself without the header of Moodle (and perhaps with a
     * policy of content that allows no scripts in the page): the script and the styles to link, and the data to print
     * as is inside <script type="application/json" id="marandu-proposal">…</script>, which proposal.js reads (the texts,
     * the address of the site and the key of the session). Then any element with data-marandu-proposal opens it.
     *
     * @return array|null js and css (addresses), strings, config (wwwroot, sesskey) and data (the JSON to print); null for
     *                    an account that may not propose.
     */
    public static function assets(): ?array {
        global $CFG;
        if (!isloggedin() || isguestuser() || !has_capability('local/oksigeniamarandu:propose', \context_system::instance())) {
            return null;
        }
        $strings = [];
        $keys = ['title', 'taking', 'nopicture', 'clear', 'sending', 'needtext', 'close', 'failed', 'back', 'intro', 'idea',
            'ideadesc', 'issue', 'issuedesc', 'goes', 'ideashort', 'issueshort', 'markidea', 'markideatouch', 'markissue',
            'markissuetouch', 'sideidea', 'sideissue', 'marks0', 'marks1', 'marksn', 'again', 'next', 'whatidea', 'whatissue',
            'alsogoes', 'sendidea', 'sendissue', 'whoidea', 'whoissue', 'thanksidea', 'thanksideatext', 'thanksissue',
            'thanksissuetext', 'doneidea', 'doneissue', 'today', 'nextidea', 'nextideahint', 'nextissue', 'nextissuehint',
            'backtopage', 'captured', 'sentwithidea', 'sentwithissue', 'browserin', 'dark', 'light'];
        foreach ($keys as $key) {
            $strings[$key] = get_string('proposal:js:' . $key, self::COMPONENT);
        }
        $version = ['v' => (string)get_config(self::COMPONENT, 'version')];
        $config = ['wwwroot' => $CFG->wwwroot, 'sesskey' => sesskey()];
        return [
            'js' => (new \moodle_url('/local/oksigeniamarandu/proposal/proposal.js', $version))->out(false),
            'css' => (new \moodle_url('/local/oksigeniamarandu/proposal/proposal.css', $version))->out(false),
            'strings' => $strings,
            'config' => $config,
            'data' => json_encode(['strings' => $strings, 'config' => $config, 'version' => $version['v'],
                'service' => self::COMPONENT . '_proposal_send'], JSON_HEX_TAG | JSON_HEX_AMP),
        ];
    }

    /**
     * The button «Sugerencias» for a screen of a tool, with what it needs loaded on the page: a pill with its icon and its
     * word in the header; on a phone, the icon alone. It carries the name and the version of the tool, which go with what
     * does not work.
     *
     * @param string $component The tool, such as local_oksigeniameals.
     * @param string $screen The screen, as the tool names it (such as «the week of a diner»).
     * @param string $class More classes for the button.
     * @return string Its HTML; empty for an account that may not propose.
     */
    public static function button(string $component, string $screen = '', string $class = ''): string {
        if (!self::js()) {
            return '';
        }
        $component = clean_param($component, PARAM_COMPONENT);
        $word = get_string('proposal:button', self::COMPONENT);
        $icon = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" '
            . 'stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 3.5h7.5l4.5 4.5v12.5h-12z"/>'
            . '<path d="M14 3.5v4.5h4.5"/><path d="M9 14.2c1.1-1.2 2.1-1.2 3 0s1.9 1.2 3 0"/></svg>';
        return \html_writer::tag('button', $icon . \html_writer::span(s($word)), [
            'type' => 'button',
            'class' => trim('sg-boton ' . $class),
            'title' => $word,
            'data-marandu-proposal' => $component,
            'data-screen' => clean_param($screen, PARAM_TEXT),
            'data-tool' => self::tool_name($component),
            'data-release' => self::release($component),
        ]);
    }

    /**
     * The notice «proposal» by Telegram, through Marandu, if it is on the site and the plugin declares the notice (Marandu
     * does; a copy of this class in another plugin declares it in its <plugin>_marandu_notices(), or goes without it).
     *
     * @param array $values
     * @param \moodle_url $url
     * @param int $userid
     * @param int $life
     * @param array $rich
     * @return int How many chats it went to.
     */
    private static function telegram(array $values, \moodle_url $url, int $userid, int $life, array $rich): int {
        if (!self::marandu()) {
            return 0;
        }
        $api = self::MARANDU . '\\api';
        try {
            return (int)$api::notify(self::COMPONENT, 'proposal', $values, $url, 0, $userid, false, $life, $rich, '', '', 'telegram');
        } catch (\Throwable $e) {
            debugging('The suggestions of ' . self::COMPONENT . ' do not go by Telegram: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return 0;
        }
    }

    /**
     * Whether Marandu is on the site: its notices by Telegram, and the mail in the common look. This class goes copied
     * into other plugins (kit/proponer of hq), and works without it.
     *
     * @return bool
     */
    private static function marandu(): bool {
        return class_exists(self::MARANDU . '\\api');
    }

    /**
     * Whether the site has text generation (core_ai) for the line of each suggestion.
     *
     * @return bool
     */
    private static function ai(): bool {
        if (!class_exists('\\core_ai\\manager') || !class_exists('\\core_ai\\aiactions\\generate_text')) {
            return false;
        }
        try {
            return (bool)\core\di::get(\core_ai\manager::class)->is_action_available(\core_ai\aiactions\generate_text::class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The name of the organization, as the mails sign it.
     *
     * @return string
     */
    private static function org(): string {
        global $SITE;
        $cards = self::MARANDU . '\\local\\cards';
        if (class_exists($cards)) {
            return $cards::org();
        }
        return html_entity_decode(
            format_string($SITE->fullname, true, ['context' => \context_system::instance()]),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
    }

    /**
     * The custom data of a notice with the icon of the tool, in colour (the app reads notificationiconurl).
     *
     * @return array
     */
    private static function icon(): array {
        global $OUTPUT;
        return ['notificationiconurl' => $OUTPUT->image_url('icon', self::COMPONENT)->out(false)];
    }

    /**
     * A moment in words, as the pages of the site say it: «hoy a las 10:42», «ayer a las 17:40», «el 26 de noviembre a las…».
     *
     * @param int $time
     * @param int $now
     * @return string
     */
    public static function when(int $time, int $now): string {
        $hour = userdate($time, get_string('strftimetime', 'langconfig'));
        $midnight = usergetmidnight($now);
        if ($time >= $midnight) {
            return get_string('proposals:when:today', self::COMPONENT, $hour);
        }
        if ($time >= usergetmidnight($midnight - HOURSECS)) {
            return get_string('proposals:when:yesterday', self::COMPONENT, $hour);
        }
        return get_string('proposals:when:day', self::COMPONENT, (object)['day' => userdate(
            $time,
            get_string('strftimedaydate', 'langconfig')
        ), 'hour' => $hour]);
    }

    /**
     * A suggestion as the page of whoever receives them shows it (local_oksigeniamarandu/proposal_card).
     *
     * @param \stdClass $record
     * @param int $now
     * @return array
     */
    public static function card(\stdClass $record, int $now): array {
        $c = self::COMPONENT;
        $author = \core_user::get_user((int)$record->userid);
        $first = $author ? (string)$author->firstname : '';
        $issue = $record->kind === 'issue';
        $where = self::tool_name((string)$record->component) . ((string)$record->screen !== '' ? ', «' . $record->screen . '»' : '');
        $extra = '';
        if ($issue) {
            $browser = array_slice(explode(' · ', (string)$record->browser), 0, 2);
            $browser = count($browser) === 2 && $browser[0] !== '' && $browser[1] !== ''
                ? str_replace(['{browser}', '{system}'], $browser, get_string('proposal:js:browserin', $c)) : implode(' ', $browser);
            $parts = array_filter([trim(self::tool_name((string)$record->component) . ' ' . $record->toolrelease), $browser]);
            $extra = implode(' · ', $parts);
        }
        $image = self::image_url((int)$record->id);
        $portrait = false;
        if ($image) {
            $info = self::image_file((int)$record->id)->get_imageinfo();
            $portrait = $info && $info['height'] > $info['width'];
        }
        $card = [
            'id' => (int)$record->id,
            'kind' => $issue ? 'fallo' : 'idea',
            'text' => (string)$record->body,
            'who' => $author ? fullname($author) : '',
            'when' => self::when((int)$record->timecreated, $now),
            'where' => $where,
            'url' => (string)$record->pageurl,
            'extra' => $extra,
            'image' => $image ? $image->out(false) : '',
            'portrait' => $portrait,
            'formurl' => (new \moodle_url('/local/oksigeniamarandu/proposals.php'))->out(false),
            'sesskey' => sesskey(),
            'open' => false,
            'seal' => false,
        ];
        if ($record->status === 'new') {
            $remote = in_array((string)$record->remotestate, ['refused', 'failed', 'lost'], true)
                ? get_string('proposals:remote:' . $record->remotestate, $c) : '';
            $card['open'] = [
                'release' => self::release((string)$record->component),
                'donelabel' => get_string($issue ? 'proposals:done:issue' : 'proposals:done:idea', $c),
                'whyhint' => get_string('proposals:whyhint', $c, $first),
                'remote' => $remote,
            ];
        } else if ($record->status === 'away') {
            $card['seal'] = ['kindseal' => 'oks', 'isoks' => true,
                'title' => get_string('proposals:sealoks', $c, userdate((int)$record->timeremote, get_string('strftimedaydate', 'langconfig'))),
                'tail' => get_string('proposals:sealoks:tail', $c, $first),
                'undo' => $record->remotestate === 'sending'];
        } else {
            $done = $record->outcome !== 'no';
            $release = (string)$record->outcomerelease;
            $title = $done
                ? get_string('proposals:seal:' . ($issue ? 'issue' : 'idea') . ($release !== '' ? ':release' : ''), $c, $release)
                : get_string('proposals:seal:no', $c, trim((string)$record->note));
            $card['seal'] = ['kindseal' => $done ? 'hecho' : 'no', 'ishecho' => $done, 'isno' => !$done, 'title' => $title,
                'tail' => $record->timetold ? get_string('proposals:seal:told', $c, $first)
                    : get_string('proposals:seal:telling', $c, $first),
                'undo' => !$record->timetold];
        }
        return $card;
    }

    /**
     * The people who receive the suggestions hear in the bell, now, that something does not work.
     *
     * @param \stdClass $record
     */
    private static function bell(\stdClass $record): void {
        $tool = self::tool_name((string)($record->tool ?: $record->component));
        $line = $record->summary ?: \core_text::substr(preg_replace('/\s+/u', ' ', $record->body), 0, 150);
        $url = new \moodle_url('/local/oksigeniamarandu/proposals.php', [], 'p' . $record->id);
        foreach (self::people() as $person) {
            self::message(
                'proposal',
                $person,
                get_string('proposal:issue:subject', self::COMPONENT, (object)['tool' => $tool, 'line' => $line]),
                $record->body,
                $url,
                get_string('proposal:open', self::COMPONENT)
            );
        }
    }

    /**
     * A notice of the site to one person, in the bell and by mail (in the common look, when there are pieces).
     *
     * @param string $name The provider: proposal or proposalanswer.
     * @param \stdClass $to
     * @param string $subject
     * @param string $text
     * @param \moodle_url|null $url
     * @param string $urlname
     * @param array $pieces The pieces of its mail; none for a mail made from the text.
     * @return bool Whether it went.
     */
    private static function message(
        string $name,
        \stdClass $to,
        string $subject,
        string $text,
        ?\moodle_url $url,
        string $urlname,
        array $pieces = []
    ): bool {
        $message = new \core\message\message();
        $message->component = self::COMPONENT;
        $message->name = $name;
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $to;
        $message->subject = $subject;
        $message->fullmessage = $text;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '';
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->customdata = self::icon();
        if ($url) {
            $message->contexturl = $url;
            $message->contexturlname = $urlname;
        }
        if (self::marandu()) {
            // The mail in the common look of the tools, by the way of the site.
            $api = self::MARANDU . '\\api';
            $api::mail_notice($message, $pieces);
        }
        try {
            return (bool)message_send($message);
        } catch (\Throwable $e) {
            debugging('Marandu: a notice of the suggestions did not go: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * The picture of a suggestion as a JPEG of a width at most and a size at most, for a mail or for Oksigenia.
     *
     * @param int $id
     * @param int $width
     * @param int $bytes
     * @return string Its bytes; empty if it has none or it cannot be made.
     */
    public static function jpeg(int $id, int $width, int $bytes): string {
        $file = self::image_file($id);
        if (!$file || !function_exists('imagecreatefromstring')) {
            return '';
        }
        $source = @imagecreatefromstring($file->get_content());
        if (!$source) {
            return '';
        }
        $w = imagesx($source);
        $h = imagesy($source);
        foreach ([$width, (int)round($width * 0.75), (int)round($width * 0.5)] as $max) {
            $scale = min(1, $max / max(1, $w));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $image = imagecreatetruecolor($nw, $nh);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagecopyresampled($image, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);
            foreach ([82, 70, 60] as $quality) {
                ob_start();
                imagejpeg($image, null, $quality);
                $out = (string)ob_get_clean();
                if (strlen($out) <= $bytes) {
                    return $out;
                }
            }
        }
        return '';
    }
}
