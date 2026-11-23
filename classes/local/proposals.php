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

use local_oksigeniamarandu\local\chat\draft;

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

    /** @var string The plugin. */
    private const COMPONENT = 'local_oksigeniamarandu';

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
     * @return int Its id.
     * @throws \invalid_parameter_exception If it says nothing, or the picture is not a picture.
     */
    public static function save(int $userid, string $component, string $screen, string $url, string $body, string $image): int {
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
        if (!draft::available()) {
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
        $rich = ['title' => get_string('proposal:card', self::COMPONENT, $tool),
            'text' => [$record->body, get_string('proposal:from', self::COMPONENT, $author ? fullname($author) : '') .
                ((string)$record->screen !== '' ? ' · ' . $record->screen : '')]];
        if ($image) {
            $rich['image'] = $image->out(false);
        }
        $count = 0;
        foreach (self::people() as $person) {
            $count += \local_oksigeniamarandu\api::notify(
                self::COMPONENT,
                'proposal',
                [$tool . ': ' . $line],
                new \moodle_url('/local/oksigeniamarandu/proposals.php', [], 'p' . $record->id),
                0,
                (int)$person->id,
                false,
                3 * DAYSECS,
                $rich,
                '',
                '',
                'telegram'
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
     * Closes a proposal, with what was done.
     *
     * @param int $id The proposal.
     * @param string $note What was done.
     * @param int $userid Who closes it.
     * @return bool Whether it was open.
     */
    public static function done(int $id, string $note, int $userid): bool {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        if (!$record || $record->status === 'done') {
            return false;
        }
        $now = time();
        $DB->update_record(self::TABLE, (object)['id' => $id, 'status' => 'done',
            'note' => \core_text::substr(trim($note), 0, self::MAX_TEXT), 'doneby' => $userid ?: null, 'timedone' => $now,
            'timemodified' => $now]);
        return true;
    }
}
