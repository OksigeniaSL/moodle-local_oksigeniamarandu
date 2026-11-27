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

use local_oksigeniamarandu\api;
use local_oksigeniamarandu\local\listening_plugin;

/**
 * Tests of the mails of the tools: the way of each one (home by the mail of the site, the rest by Mailjet when the site
 * chose it), what goes when Mailjet fails or stops, the line of a send to many, the quota of the month, the events of
 * Mailjet, the notices of Moodle marked for this way, and what the page asks Mailjet. Mailjet is never called: a fake
 * keeps what would be sent. Addresses are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\mail\mailer
 * @covers     \local_oksigeniamarandu\local\mail\quota
 * @covers     \local_oksigeniamarandu\local\mail\events
 * @covers     \local_oksigeniamarandu\local\mail\hook
 * @covers     \local_oksigeniamarandu\local\mail\check
 * @covers     \local_oksigeniamarandu\local\mail\settings
 * @covers     \local_oksigeniamarandu\local\mail\page
 */
#[\PHPUnit\Framework\Attributes\CoversClass(mailer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(quota::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(events::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(hook::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(check::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(settings::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(page::class)]
final class mail_test extends \advanced_testcase {
    /** The plugin. */
    private const C = 'local_oksigeniamarandu';

    /** A Wednesday of November at 11:00, in Madrid. */
    private const NOW = 1795428000;

    /** @var array The calls to Mailjet: method, path and body. */
    private array $calls = [];

    /** @var callable|null What Mailjet answers instead of the usual, when it returns something. */
    private $answer = null;

    /** @var int The last id Mailjet gave a mail. */
    private int $next = 1000;

    /**
     * A site whose home is example.org, and a Mailjet that takes everything.
     */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setTimezone('Europe/Madrid');
        $CFG->noreplyaddress = 'noreply@example.org';
        set_config('capemail', 'admin@example.org', self::C);
        require_once(__DIR__ . '/../fixtures/listening_plugin.php');
        require_once(__DIR__ . '/../fixtures/listening_plugin_lib.php');
        listening_plugin::forget();
        mailjet_api::fake_for_tests(function (string $method, string $path, ?array $body): array {
            $this->calls[] = [$method, $path, $body];
            $out = $this->answer ? ($this->answer)($method, $path, $body) : null;
            if ($out !== null) {
                return $out;
            }
            if ($path === '/v3.1/send') {
                return [201, ['Messages' => array_map(function (array $message): array {
                    $this->next++;
                    return ['Status' => 'success', 'CustomID' => $message['CustomID'],
                        'To' => [['Email' => $message['To'][0]['Email'], 'MessageID' => $this->next]]];
                }, $body['Messages'])]];
            }
            if (strpos($path, '/v3/REST/sender') === 0) {
                return [200, ['Data' => [['Email' => 'avisos@example.net', 'Status' => 'Active']]]];
            }
            if (strpos($path, '/v3/REST/dns/') === 0) {
                return [200, ['Data' => [['DKIMStatus' => 'OK', 'SPFStatus' => 'Error']]]];
            }
            if (strpos($path, '/v3/REST/statcounters') === 0) {
                return [200, ['Data' => [['MessageSentCount' => 50], ['MessageSentCount' => 30]]]];
            }
            return [200, ['Data' => []]];
        });
    }

    /**
     * Mailjet answers again as it does.
     */
    protected function tearDown(): void {
        mailjet_api::fake_for_tests(null);
        parent::tearDown();
    }

    /**
     * The site chooses Mailjet, with its keys and its sender.
     */
    private function choose_mailjet(): void {
        settings::set_keys('the-key', 'the-secret');
        set_config('mjfrom', 'avisos@example.net', self::C);
        set_config('mjreplyto', 'secretaria@example.org', self::C);
        set_config('mailway', 'mailjet', self::C);
    }

    /**
     * The sends Mailjet got.
     *
     * @return array[] Their bodies.
     */
    private function sends(): array {
        return array_values(array_map(fn($call) => $call[2], array_filter($this->calls, fn($call) => $call[1] === '/v3.1/send')));
    }

    /**
     * A mail as the plugins pass it.
     *
     * @param string $email
     * @param array $more
     * @return array
     */
    private function letter(string $email, array $more = []): array {
        return $more + ['email' => $email, 'subject' => 'La salida del jueves', 'component' => self::C, 'ref' => 'r1',
            'html' => '<p>Salimos a las <b>9:00</b>.</p><img src="cid:logo" alt="Colegio">',
            'images' => [['cid' => 'logo', 'name' => 'logo.png', 'mime' => 'image/png', 'data' => 'PNGDATA']]];
    }

    /**
     * Home by the mail of the site; the rest by the mail of the site too, until the site chooses Mailjet; then, by
     * Mailjet, with the pictures inside, no tracking and the answers to the organization.
     */
    public function test_way(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $this->assertSame('site', mailer::send($this->letter('ana@example.com'), self::NOW));
        $this->assertSame('site', mailer::send($this->letter('pepe@sub.example.org'), self::NOW));
        $this->assertCount(2, $sink->get_messages());
        $this->assertSame([], $this->calls);

        $this->choose_mailjet();
        $this->assertSame('site', mailer::send($this->letter('pepe@example.org'), self::NOW));
        $mail = $sink->get_messages()[2];
        $this->assertStringContainsString('Content-ID: <logo>', $mail->body);
        $this->assertStringContainsString('Auto-Submitted: auto-generated', $mail->header);
        $this->assertStringContainsString('secretaria@example.org', $mail->header);
        $this->assertSame([], $this->calls);

        $this->assertSame('mailjet', mailer::send($this->letter('ana@example.com'), self::NOW));
        $this->assertCount(3, $sink->get_messages());
        $message = $this->sends()[0]['Messages'][0];
        $row = $DB->get_record(mailer::TABLE, ['email' => 'ana@example.com', 'way' => 'mailjet'], '*', MUST_EXIST);
        $this->assertSame(['avisos@example.net', 'disabled', 'disabled', 'mrd-' . $row->id, 'logo', 'secretaria@example.org'], [
            $message['From']['Email'], $message['TrackOpens'], $message['TrackClicks'], $message['CustomID'],
            $message['InlinedAttachments'][0]['ContentID'], $message['ReplyTo']['Email']]);
        $this->assertStringContainsString('9:00', $message['TextPart']);
        $this->assertSame(['sent', '1001', null], [$row->status, $row->mjid, $row->payload]);

        // An address that is not one.
        $this->assertSame('invalid', api::mail('no-es-correo', 'Hola', '<p>Hola</p>'));
        $user = $this->getDataGenerator()->create_user(['email' => 'luis@example.com', 'suspended' => 1]);
        $this->assertSame('invalid', api::mail($user->id, 'Hola', '<p>Hola</p>'));
    }

    /**
     * Mailjet fails for a while: one person, at once by the mail of the site. Mailjet refuses the keys: it stays aside,
     * whoever is told of the limits hears it once, a send to many waits, and it comes back when Mailjet takes mails again.
     */
    public function test_mailjet_fails(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $this->choose_mailjet();
        $this->answer = fn($method, $path) => $path === '/v3.1/send' ? [503, ['ErrorMessage' => 'Busy']] : null;
        $this->assertSame('site', mailer::send($this->letter('ana@example.com'), self::NOW));
        $this->assertCount(1, $sink->get_messages());
        $this->assertStringStartsWith('mailjet: ', $DB->get_field(mailer::TABLE, 'error', ['email' => 'ana@example.com']));
        $this->assertNull(mailer::paused(self::NOW));

        $this->answer = fn($method, $path) => $path === '/v3.1/send' ? [401, ['ErrorMessage' => 'Unauthorized']] : null;
        $this->assertSame('site', mailer::send($this->letter('eva@example.com'), self::NOW));
        $this->assertNotNull(mailer::paused(self::NOW));
        $subjects = array_map(fn($mail) => $mail->subject, $sink->get_messages());
        $this->assertContains(get_string('mail:paused:subject', self::C, (object)['site' => settings::from()[1]]), $subjects);
        $count = count($sink->get_messages());
        $calls = count($this->sends());

        // Aside: one person by the mail of the site without asking Mailjet; a send to many waits; once.
        $this->assertSame('site', mailer::send($this->letter('rosa@example.com'), self::NOW + 60));
        $this->assertSame('queued', mailer::send($this->letter('juan@example.com', ['bulk' => true]), self::NOW + 60));
        $this->assertSame($calls, count($this->sends()));
        $this->assertCount($count + 1, $sink->get_messages());
        // It is looked at again once the pause is over.
        $this->assertSame('paused', $DB->get_field(mailer::TABLE, 'waiting', ['email' => 'juan@example.com']));
        $this->assertSame(['site' => 0, 'mailjet' => 0, 'waiting' => 0, 'failed' => 0], mailer::process(self::NOW + 120));

        // Mailjet takes mails again.
        $this->answer = null;
        $later = self::NOW + mailer::PAUSE + 300;
        $this->assertSame(['site' => 0, 'mailjet' => 1, 'waiting' => 0, 'failed' => 0], mailer::process($later));
        $this->assertNull(mailer::paused($later));
        $this->assertSame('', (string)get_config(self::C, 'mailpaused'));
    }

    /**
     * A send to many waits in the line, with its pictures kept once, and goes in one call; what fails for a while is
     * tried again, and after a day it gives up and the plugin hears it.
     */
    public function test_line(): void {
        global $DB;
        $this->redirectEmails();
        $this->choose_mailjet();
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->assertSame('queued', api::mail($email, 'Menú', '<p>Menú</p><img src="cid:logo" alt="">', '', [
                ['cid' => 'logo', 'name' => 'logo.png', 'mime' => 'image/png', 'data' => 'PNGDATA'],
            ], ['component' => self::C, 'bulk' => true, 'unsubscribe' => 'https://example.org/baja?k=1', 'ref' => 'menu']));
        }
        $this->assertCount(1, get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::C,
            mailer::FILEAREA,
            0,
            'id',
            false
        ));
        $this->assertSame([], $this->calls);
        $this->assertSame(['site' => 0, 'mailjet' => 3, 'waiting' => 0, 'failed' => 0], mailer::process(self::NOW));
        $sends = $this->sends();
        $this->assertCount(1, $sends);
        $this->assertCount(3, $sends[0]['Messages']);
        $message = $sends[0]['Messages'][0];
        $this->assertSame(base64_encode('PNGDATA'), $message['InlinedAttachments'][0]['Base64Content']);
        $this->assertSame('<https://example.org/baja?k=1>', $message['Headers']['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $message['Headers']['List-Unsubscribe-Post']);
        // Nothing waits: the pictures go, an hour after they were kept.
        mailer::process(time() + HOURSECS + 60);
        $this->assertCount(0, get_file_storage()->get_area_files(
            \context_system::instance()->id,
            self::C,
            mailer::FILEAREA,
            0,
            'id',
            false
        ));

        // A while of errors: tried again, then given up.
        $this->answer = fn($method, $path) => $path === '/v3.1/send' ? [500, ['ErrorMessage' => 'Down']] : null;
        api::mail('d@example.com', 'Menú', '<p>Menú</p>', '', [], ['component' => self::C, 'bulk' => true, 'ref' => 'menu']);
        $start = self::NOW + 2 * HOURSECS;
        $id = (int)$DB->get_field(mailer::TABLE, 'id', ['email' => 'd@example.com']);
        $DB->set_field(mailer::TABLE, 'timequeued', $start, ['id' => $id]);
        $this->assertSame(1, mailer::process($start)['waiting']);
        $row = $DB->get_record(mailer::TABLE, ['id' => $id]);
        $this->assertSame(['retry', 1, $start + MINSECS], [$row->waiting, (int)$row->attempts, (int)$row->timeretry]);
        $this->assertSame(1, mailer::process($start + mailer::GIVEUP)['failed']);
        $this->assertSame('failed', $DB->get_field(mailer::TABLE, 'status', ['id' => $id]));
        $this->assertSame([['d@example.com', 'failed', 'http 500 Down', 'menu']], array_map(
            fn($one) => array_slice($one, 0, 4),
            listening_plugin::$mail
        ));
    }

    /**
     * The quota of the month: what Mailjet counts for the whole account, the warnings once each, what is routine by the
     * mail of the site past its share, a send to many waiting at the quota until a person lets it go.
     */
    public function test_quota(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $this->choose_mailjet();
        set_config('mailquota', 100, self::C);
        $this->assertSame(80, quota::refresh(self::NOW));
        $this->assertSame(80, quota::used(self::NOW));
        $this->assertSame(['used' => 80, 'quota' => 100, 'share' => 80, 'routine' => 80], array_slice(quota::state(self::NOW), 0, 4));
        $this->assertSame('near', quota::warn(self::NOW));
        $this->assertNull(quota::warn(self::NOW + 60));
        $this->assertCount(1, $sink->get_messages());

        // Routine, by the mail of the site; the rest, by Mailjet.
        $this->assertSame('site', mailer::send($this->letter('ana@example.com', ['routine' => true]), self::NOW));
        $this->assertSame('mailjet', mailer::send($this->letter('eva@example.com'), self::NOW));

        // At the quota: a send to many waits, one person goes on.
        set_config('mailmonth', json_encode(['month' => '2026-11', 'sent' => 100, 'time' => self::NOW]), self::C);
        $this->assertSame('full', quota::warn(self::NOW));
        $this->assertSame('queued', mailer::send($this->letter('juan@example.com', ['bulk' => true]), self::NOW));
        $this->assertSame('quota', $DB->get_field(mailer::TABLE, 'waiting', ['email' => 'juan@example.com']));
        $this->assertSame(0, mailer::process(self::NOW + HOURSECS)['mailjet']);
        $this->assertSame('mailjet', mailer::send($this->letter('rosa@example.com'), self::NOW));
        $this->assertSame(1, mailer::release());
        $this->assertSame(1, mailer::process(self::NOW)['mailjet']);

        // Next month, nothing counted yet.
        $this->assertSame(0, quota::used(self::NOW + 31 * DAYSECS));
    }

    /**
     * What Mailjet tells: a bounce for good, a block and a mark of spam are noted, the plugin hears of its own mail,
     * every plugin of one Marandu did not send; a bounce that may pass and a repeat in a send are left; the address
     * receives again when a person says so.
     */
    public function test_events(): void {
        global $DB;
        $this->redirectEmails();
        $this->choose_mailjet();
        mailer::send($this->letter('ana@example.com'), self::NOW);
        $id = (int)$DB->get_field(mailer::TABLE, 'id', ['email' => 'ana@example.com']);
        $taken = events::take([
            ['event' => 'bounce', 'email' => 'Ana@Example.com', 'hard_bounce' => true, 'error' => 'user unknown',
                'CustomID' => 'mrd-' . $id, 'time' => self::NOW + 10],
            ['event' => 'bounce', 'email' => 'eva@example.com', 'hard_bounce' => false, 'error' => 'mailbox busy'],
            ['event' => 'blocked', 'email' => 'eva@example.com', 'error' => 'duplicate in campaign'],
            ['event' => 'spam', 'email' => 'rosa@example.com', 'source' => 'JMRPP', 'CustomID' => 'eguahe-1'],
            ['event' => 'open', 'email' => 'luis@example.com'],
        ], self::NOW + 20);
        $this->assertSame(2, $taken);
        $this->assertSame('failed', $DB->get_field(mailer::TABLE, 'status', ['id' => $id]));
        $this->assertSame(['ana@example.com', 'bounce', 'user unknown', 'r1', self::NOW + 10], listening_plugin::$mail[0]);
        $this->assertSame(['rosa@example.com', 'spam', 'JMRPP', ''], array_slice(listening_plugin::$mail[1], 0, 4));
        $this->assertSame('bounce', api::mail_state('ana@example.com')['event']);
        $this->assertNull(api::mail_state('eva@example.com'));

        // Nothing more to a bounce; after spam, one person yes, a send to many and routine no.
        $this->assertSame('bad', mailer::send($this->letter('ana@example.com'), self::NOW + 60));
        $this->assertSame('mailjet', mailer::send($this->letter('rosa@example.com'), self::NOW + 60));
        $this->assertSame('bad', mailer::send($this->letter('rosa@example.com', ['bulk' => true]), self::NOW + 60));
        $this->assertSame('bad', mailer::send($this->letter('rosa@example.com', ['routine' => true]), self::NOW + 60));
        // A spam after a bounce does not make it lighter.
        events::take([['event' => 'spam', 'email' => 'ana@example.com']], self::NOW + 70);
        $this->assertSame('bounce', api::mail_state('ana@example.com')['event']);
        $this->assertTrue(events::forget('ana@example.com'));
        $this->assertSame('mailjet', mailer::send($this->letter('ana@example.com'), self::NOW + 80));
    }

    /**
     * A notice of Moodle marked for this way: out of home, by Mailjet, and Moodle does not send it again; to home, or
     * unmarked, or if Mailjet does not take it, by the mail of Moodle as always.
     */
    public function test_marked_notices(): void {
        global $DB;
        if (!class_exists(\core\hook\email\before_email_to_user::class)) {
            $this->markTestSkipped('The hook before_email_to_user comes with Moodle 5.3.');
        }
        $sink = $this->redirectEmails();
        $this->choose_mailjet();
        $out = $this->getDataGenerator()->create_user(['email' => 'luis@example.com']);
        $home = $this->getDataGenerator()->create_user(['email' => 'marta@example.org']);
        $from = api::mail_from('local_oksigeniamarandu', true);
        $this->assertSame([hook::HEADER . ': local_oksigeniamarandu; routine'], $from->customheaders);
        $this->assertSame(
            ['component' => 'local_oksigeniamarandu', 'routine' => true, 'letter' => ''],
            hook::mark($from->customheaders)
        );

        $this->assertTrue(email_to_user($out, $from, 'Un número nuevo', 'Texto', '<p>Texto</p>'));
        $this->assertCount(0, $sink->get_messages());
        $this->assertCount(1, $this->sends());
        $this->assertSame('Un número nuevo', $this->sends()[0]['Messages'][0]['Subject']);
        $this->assertSame('mailjet', $DB->get_field(mailer::TABLE, 'way', ['email' => 'luis@example.com']));

        $this->assertTrue(email_to_user($home, api::mail_from('local_oksigeniamarandu'), 'Hola', 'Texto', '<p>Texto</p>'));
        $this->assertTrue(email_to_user($out, \core_user::get_noreply_user(), 'Sin marca', 'Texto'));
        $this->assertCount(2, $sink->get_messages());
        $this->assertCount(1, $this->sends());

        $this->answer = fn($method, $path) => $path === '/v3.1/send' ? [500, ['ErrorMessage' => 'Down']] : null;
        $this->assertTrue(email_to_user($out, api::mail_from('local_oksigeniamarandu'), 'Otra vez', 'Texto', '<p>Texto</p>'));
        $this->assertCount(3, $sink->get_messages());
    }

    /**
     * The common look: the brand of the site, the pieces in order, the pictures inside by what they are, the words that
     * are always the same in the language of the reader, and the way out for a send to many.
     */
    public function test_letter(): void {
        global $SITE;
        $picture = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($picture);
        $bytes = ob_get_clean();
        $pieces = ['heading' => 'El menú de mañana', 'hello' => 'Hola, Ana:', 'blocks' => [
            ['paragraph' => 'Esto es lo que comerá Leo.'],
            ['data' => [['label' => 'Día', 'value' => 'jueves']]],
            ['picture' => ['picturebytes' => $bytes, 'picturealt' => 'La bandeja']],
            ['action' => ['actionurl' => 'https://example.org/menu', 'actiontext' => 'Ver el menú']],
        ], 'signature' => ['signer' => 'Comedor'], 'reason' => 'Te llega porque eliges el menú.',
            'unsub' => ['unsuburl' => 'https://example.org/baja?k=1']];
        $built = api::mail_html($pieces, 'es');
        $this->assertSame('El menú de mañana', $built['subject']);
        $this->assertSame('https://example.org/baja?k=1', $built['unsubscribe']);
        $this->assertCount(1, $built['images']);
        $cid = sha1($bytes) . '@marandu';
        $this->assertSame($cid, $built['images'][0]['cid']);
        $this->assertStringContainsString('cid:' . $cid, $built['html']);
        $this->assertStringContainsString(format_string($SITE->fullname), $built['html']);
        $this->assertStringContainsString('https://example.org/menu', $built['html']);
        $this->assertStringContainsString(
            get_string_manager()->get_string('mail:letter:unsubtext', self::C, null, 'es'),
            $built['html']
        );
        $this->assertStringContainsString('Esto es lo que comerá Leo.', $built['text']);
        $this->assertStringContainsString('https://example.org/menu', $built['text']);
        $this->assertDoesNotMatchRegularExpression('/\n[ \t]*\n[ \t]*\n/', $built['text']);
        $this->assertSame('', $built['id']);

        // A name with «&»: escaped once in the mail, as it is in its text.
        set_config('cardorg', 'Colegio Sol & Mar', self::C);
        $built = api::mail_html(['heading' => 'Hola', 'blocks' => [['paragraph' => 'Texto.']]], 'es');
        $this->assertStringContainsString('Colegio Sol &amp; Mar', $built['html']);
        $this->assertStringNotContainsString('&amp;amp;', $built['html']);
        $this->assertStringContainsString('Colegio Sol & Mar', $built['text']);

        // Built and sent at once: a send to many, with its way out.
        $this->choose_mailjet();
        $this->assertSame('queued', api::mail_letter('ana@example.com', $pieces, ['component' => self::C]));
        mailer::process(self::NOW);
        $message = $this->sends()[0]['Messages'][0];
        $this->assertSame('<https://example.org/baja?k=1>', $message['Headers']['List-Unsubscribe']);
        $this->assertSame($cid, $message['InlinedAttachments'][0]['ContentID']);
    }

    /**
     * A notice of Moodle with its mail in the common look: the bell keeps what it had, and the mail that goes out is the
     * one kept, with its pictures; to home by the mail of the site through Marandu, out of home by Mailjet; if Mailjet
     * fails, by the mail of the site through Marandu, never twice.
     */
    public function test_notice_letter(): void {
        global $CFG;
        if (!class_exists(\core\hook\email\before_email_to_user::class)) {
            $this->markTestSkipped('The hook before_email_to_user comes with Moodle 5.3.');
        }
        $sink = $this->redirectEmails();
        $this->choose_mailjet();
        $out = $this->getDataGenerator()->create_user(['email' => 'luis@example.com']);
        $home = $this->getDataGenerator()->create_user(['email' => 'marta@example.org']);
        $notice = function (\stdClass $to): \core\message\message {
            $message = new \core\message\message();
            $message->component = self::C;
            $message->name = 'spend';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $to;
            $message->subject = 'Un envío espera';
            $message->fullmessage = 'Un envío a 20 familias espera tu decisión.';
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>Sencillo</p>';
            $message->contexturl = new \moodle_url('/local/oksigeniamarandu/chat.php');
            $message->contexturlname = 'Conversaciones';
            api::mail_notice($message);
            return $message;
        };
        $message = $notice($out);
        $mark = hook::mark($message->userfrom->customheaders);
        $this->assertSame(self::C, $mark['component']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $mark['letter']);
        $this->assertSame('<p>Sencillo</p>', $message->fullmessagehtml);

        $this->assertTrue(email_to_user(
            $out,
            $message->userfrom,
            $message->subject,
            $message->fullmessage,
            $message->fullmessagehtml
        ));
        $sent = $this->sends()[0]['Messages'][0];
        $this->assertStringContainsString('Un envío a 20 familias espera tu decisión.', $sent['HTMLPart']);
        // With the text version kept with it, not one made again from the HTML: the button keeps its address.
        $this->assertStringContainsString(
            'Conversaciones: https://www.example.com/moodle/local/oksigeniamarandu/chat.php',
            $sent['TextPart']
        );
        $this->assertStringNotContainsString('&amp;', $sent['TextPart']);
        $this->assertStringContainsString('Conversaciones', $sent['HTMLPart']);
        $this->assertStringNotContainsString('Sencillo', $sent['HTMLPart']);
        $this->assertCount(0, $sink->get_messages());

        $message = $notice($home);
        $this->assertTrue(email_to_user(
            $home,
            $message->userfrom,
            $message->subject,
            $message->fullmessage,
            $message->fullmessagehtml
        ));
        $this->assertCount(1, $sink->get_messages());
        $this->assertStringContainsString('Un env', $sink->get_messages()[0]->body);
        $this->assertCount(1, $this->sends());

        // The processor of mail of Moodle takes the notice as sent, so that the whole notice counts as sent.
        require_once($CFG->dirroot . '/message/output/email/message_output_email.php');
        $message = $notice($home);
        $eventdata = (object)['userto' => $home, 'userfrom' => $message->userfrom, 'subject' => $message->subject,
            'fullmessage' => $message->fullmessage, 'fullmessagehtml' => $message->fullmessagehtml, 'notification' => 1,
            'attachment' => null, 'replyto' => null];
        $this->assertTrue((new \message_output_email())->send_message($eventdata));
        $this->assertCount(2, $sink->get_messages());

        $this->answer = fn($method, $path) => $path === '/v3.1/send' ? [500, ['ErrorMessage' => 'Down']] : null;
        $message = $notice($out);
        $this->assertTrue(email_to_user(
            $out,
            $message->userfrom,
            $message->subject,
            $message->fullmessage,
            $message->fullmessagehtml
        ));
        $this->assertCount(3, $sink->get_messages());
    }

    /**
     * What the page asks Mailjet: the keys, the sender (or its whole domain) and DKIM make it ready; SPF is said and
     * does not stop it; keys that do not open the account, not ready.
     */
    public function test_check(): void {
        $this->assertFalse(check::ready());
        settings::set_keys('the-key', 'the-secret');
        set_config('mjfrom', 'avisos@example.net', self::C);
        $state = check::run(null, self::NOW);
        $this->assertSame(['ok', 'ok', 'ok', 'bad'], [$state['keys'], $state['sender'], $state['dkim'], $state['spf']]);
        $this->assertTrue(check::ready());

        // The address is not a sender, but the whole domain is.
        $this->answer = function ($method, $path) {
            if (strpos($path, '/v3/REST/sender') === 0) {
                return [200, ['Data' => strpos($path, '%2A%40') !== false
                    ? [['Email' => '*@example.net', 'Status' => 'Active']] : []]];
            }
            return null;
        };
        $this->assertSame('ok', check::run(null, self::NOW)['sender']);

        // Another sender: what was checked is not for it.
        set_config('mjfrom', 'otra@example.net', self::C);
        $this->assertFalse(check::ready());

        $this->answer = fn($method, $path) => [401, ['ErrorMessage' => 'Unauthorized']];
        $this->assertSame('bad', check::run(null, self::NOW)['keys']);
        $this->assertFalse(check::ready());

        // The test goes by Mailjet whatever the way is, and never by the mail of the site.
        $sink = $this->redirectEmails();
        $this->answer = null;
        $this->assertSame(['ok' => true, 'way' => 'mailjet', 'error' => ''], check::test('ana@example.com', self::NOW));
        $this->answer = fn($method, $path) => [500, ['ErrorMessage' => 'Down']];
        $this->assertFalse(check::test('ana@example.com', self::NOW)['ok']);
        $this->assertCount(0, $sink->get_messages());
        $this->assertSame('site', get_config(self::C, 'mailway') ?: 'site');
    }

    /**
     * Attachments, by both ways and in the line; past their weight the mail does not go, and the plugin hears it.
     */
    public function test_files(): void {
        global $DB;
        $sink = $this->redirectEmails();
        $pdf = ['name' => 'Previsión de compras.pdf', 'mime' => 'application/pdf', 'data' => '%PDF-1.4 ejemplo'];

        // Home, by the mail of the site.
        $this->assertSame('site', api::mail(
            'pepe@example.org',
            'Encargo',
            '<p>Adjunto el encargo.</p>',
            '',
            [],
            ['component' => self::C, 'files' => [$pdf]]
        ));
        $mail = $sink->get_messages()[0];
        $this->assertStringContainsString('Content-Disposition: attachment', $mail->body);
        $this->assertStringContainsString('application/pdf', $mail->body);

        // Out of home, by Mailjet; and in the line, also after it waited.
        $this->choose_mailjet();
        $this->assertSame('mailjet', api::mail(
            'catering@example.com',
            'Previsión',
            '<p>Adjunto la previsión.</p>',
            '',
            [],
            ['component' => self::C, 'files' => [$pdf]]
        ));
        $attachment = $this->sends()[0]['Messages'][0]['Attachments'][0];
        $this->assertSame(
            ['application/pdf', 'Previsión de compras.pdf', base64_encode('%PDF-1.4 ejemplo')],
            [$attachment['ContentType'], $attachment['Filename'], $attachment['Base64Content']]
        );
        $this->assertSame('queued', api::mail(
            'tutor@example.com',
            'Lista',
            '<p>La lista.</p>',
            '',
            [],
            ['component' => self::C, 'files' => [$pdf], 'later' => true, 'ref' => 'lista']
        ));
        mailer::process(self::NOW);
        $this->assertSame(base64_encode('%PDF-1.4 ejemplo'), $this->sends()[1]['Messages'][0]['Attachments'][0]['Base64Content']);

        // Too heavy: it does not go.
        $big = ['name' => 'grande.pdf', 'mime' => 'application/pdf', 'data' => str_repeat('x', mailer::MAXFILES + 1)];
        $this->assertSame('toobig', api::mail(
            'catering@example.com',
            'Grande',
            '<p>Grande.</p>',
            '',
            [],
            ['component' => self::C, 'files' => [$big], 'ref' => 'grande']
        ));
        $this->assertDebuggingCalled();
        $this->assertSame(['catering@example.com', 'failed', 'toobig', 'grande'], array_slice(end(listening_plugin::$mail), 0, 4));
        $this->assertCount(2, $this->sends());
        $this->assertSame(0, $DB->count_records(mailer::TABLE, ['email' => 'catering@example.com', 'subject' => 'Grande']));
    }

    /**
     * Every date of the page in the same way: today, yesterday, or its day, always with its hour.
     */
    public function test_when(): void {
        $hour = fn(int $time) => userdate($time, get_string('strftimetime', 'langconfig'));
        $early = self::NOW - 90 * MINSECS;
        $late = self::NOW - 11 * HOURSECS - 50 * MINSECS;
        $before = self::NOW - 3 * DAYSECS;
        $this->assertSame('today at ' . $hour($early), page::when($early, self::NOW));
        $this->assertSame('yesterday at ' . $hour($late), page::when($late, self::NOW));
        $this->assertSame(
            'on ' . userdate($before, get_string('strftimedateshort', 'langconfig')) . ' at ' . $hour($before),
            page::when($before, self::NOW)
        );
        $this->assertSame('Today at ' . $hour(self::NOW), page::when(self::NOW, self::NOW, true));
    }

    /**
     * The domains of home: written in any way, and the subdomains count.
     */
    public function test_home(): void {
        $this->assertSame(['example.org'], settings::home_domains());
        set_config('mailhome', 'Colegio.example, @otra.example; mal dominio', self::C);
        $this->assertSame(['colegio.example', 'otra.example'], settings::home_domains());
        $this->assertTrue(settings::is_home('x@aula.colegio.example'));
        $this->assertFalse(settings::is_home('x@colegio.example.com'));
        $this->assertFalse(settings::is_home('x@example.org'));
    }
}
