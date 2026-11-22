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

/**
 * A post of the channel of Telegram, ready for the channel of WhatsApp, for whoever publishes there: made for the phone.
 * Its image or its animation, its text with the marks of WhatsApp, «Share on WhatsApp» (where the browser shares
 * files), «Copy the text», «Save the image» and «It is published». On a computer, the same by hand: save, copy and paste
 * in WhatsApp Web.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_oksigeniamarandu\local\api_exception;
use local_oksigeniamarandu\local\channel;
use local_oksigeniamarandu\local\rich;
use local_oksigeniamarandu\local\telegram_api;
use local_oksigeniamarandu\local\wachannel;
use local_oksigeniamarandu\local\watext;
use local_oksigeniamarandu\local\work;

$component = 'local_oksigeniamarandu';
$token = required_param('t', PARAM_ALPHANUM);
$url = new moodle_url('/local/oksigeniamarandu/wachannel.php', ['t' => $token]);
require_login(null, false);
$system = context_system::instance();
$PAGE->set_context($system);
$PAGE->set_url($url);
// Nothing of the site around what is done here, on a phone.
$PAGE->set_pagelayout('embedded');
$PAGE->blocks->show_only_fake_blocks(true);
$PAGE->set_title(get_string('wachannel:title', $component));

$wa = $DB->get_record(wachannel::TABLE, ['token' => $token]);
$post = $wa ? $DB->get_record(channel::TABLE, ['id' => $wa->postid]) : false;
if (!$wa || !$post) {
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_oksigeniamarandu/wachannel', ['gone' => true]);
    echo $OUTPUT->footer();
    die();
}
if (!wachannel::may_publish((int)$USER->id)) {
    throw new moodle_exception('wachannel:notyours', $component);
}

// The image or the video of the post as a file with the name of the post: to save it, or for the browser to share it.
$media = optional_param('media', '', PARAM_ALPHA);
if (in_array($media, ['image', 'video'], true)) {
    $files = wachannel::media($post);
    $bytes = null;
    if ($files[$media] !== '') {
        $bytes = $media === 'video' ? rich::video($files['video']) : rich::image($files['image']);
    }
    if ($bytes === null) {
        send_header_404();
        die();
    }
    $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    $extension = ['image/png' => 'png', 'video/mp4' => 'mp4'][$type] ?? 'jpg';
    \core\session\manager::write_close();
    send_file($bytes, wachannel::filename($post, $extension), 0, 0, true, optional_param('download', 0, PARAM_BOOL), $type);
}

// Marked published: who and when; the notices stop, and on Telegram they say who did it.
if (optional_param('action', '', PARAM_ALPHA) === 'done' && confirm_sesskey()) {
    $bot = null;
    try {
        $bot = telegram_api::is_configured() ? telegram_api::from_config() : null;
    } catch (api_exception $e) {
        // Without the bot, its messages stay as they were; the rest is done all the same.
        $bot = null;
    }
    wachannel::done($wa, (int)$USER->id, $bot);
    redirect(new moodle_url($url, ['done' => 1]));
}

$now = time();
$files = wachannel::media($post);
$text = wachannel::text($wa, $post);
$mediaurl = function (string $kind, bool $download) use ($url): string {
    return (new moodle_url($url, ['media' => $kind] + ($download ? ['download' => 1] : [])))->out(false);
};
$name = wachannel::name();
// The name of the file the browser shares, without its extension, which comes from what the file is.
$base = substr(wachannel::filename($post, 'x'), 0, -2);
$context = [
    'base' => $base,
    'title' => wachannel::title($post),
    'channel' => $name,
    'intelegram' => get_string(
        'wachannel:intelegram',
        $component,
        work::when((int)($post->timepublished ?: $wa->timecreated), $now)
    ),
    'text' => $text,
    'html' => watext::html($text),
    'waiting' => $wa->status === 'waiting',
    'formurl' => $url->out(false),
    'sesskey' => sesskey(),
    'justdone' => optional_param('done', 0, PARAM_BOOL) && $wa->status === 'published',
    'channelurl' => wachannel::channel_url(),
    // WhatsApp asks to label in a channel what has an image or a video made or edited with artificial intelligence.
    'ai' => wachannel::ai($post),
    'paste' => $name === '' ? get_string('wachannel:step3noname', $component) : get_string('wachannel:step3', $component, $name),
    'backurl' => has_capability('local/oksigeniamarandu:channel', $system)
        ? (new moodle_url('/local/oksigeniamarandu/channel.php', null, 'mrd-published'))->out(false) : '',
];
if ($files['video'] !== '') {
    $context['video'] = ['src' => $files['video'], 'fetch' => $mediaurl('video', false), 'save' => $mediaurl('video', true)];
}
if ($files['image'] !== '') {
    $context['image'] = ['src' => $files['image'], 'fetch' => $mediaurl('image', false), 'save' => $mediaurl('image', true)];
}
$context['both'] = isset($context['video'], $context['image']);
$context['step1'] = get_string($context['both'] ? 'wachannel:step1both' : (isset($context['video']) ? 'wachannel:step1video'
    : 'wachannel:step1'), $component);
$context['nomedia'] = !isset($context['video']) && !isset($context['image']);
if ($wa->status !== 'waiting' && $wa->doneby) {
    $user = core_user::get_user((int)$wa->doneby);
    $context['done'] = get_string('wachannel:donestate', $component, (object)['name' => $user ? fullname($user) : '',
        'when' => work::when((int)$wa->timedone, $now)]);
}
if ($wa->status === 'withdrawn') {
    $context['withdrawn'] = get_string($wa->doneby ? 'wachannel:withdrawnpublished' : 'wachannel:withdrawn', $component);
    $context['waiting'] = false;
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_oksigeniamarandu/wachannel', $context);
echo $OUTPUT->footer();
