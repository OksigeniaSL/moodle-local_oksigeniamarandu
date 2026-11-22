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
 * Tests of the text of a post as WhatsApp writes it, from the entities of Telegram. Texts and links are made up.
 *
 * @package    local_oksigeniamarandu
 * @category   test
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_oksigeniamarandu\local\watext
 */
#[\PHPUnit\Framework\Attributes\CoversClass(watext::class)]
final class watext_test extends \basic_testcase {
    /**
     * An entity, as the Bot API gives it.
     *
     * @param string $type Its type.
     * @param int $offset Where it starts, in code units of UTF-16.
     * @param int $length How long it is.
     * @param string $url The link of a text_link.
     * @return array
     */
    private static function entity(string $type, int $offset, int $length, string $url = ''): array {
        return ['type' => $type, 'offset' => $offset, 'length' => $length] + ($url === '' ? [] : ['url' => $url]);
    }

    /**
     * The formats WhatsApp has take their marks; the rest of Telegram stays as plain text.
     */
    public function test_formats(): void {
        $this->assertSame('*Hola* mundo', watext::from_entities('Hola mundo', [self::entity('bold', 0, 4)]));
        $this->assertSame('_Hola_ ~mundo~', watext::from_entities('Hola mundo', [self::entity('italic', 0, 4),
            self::entity('strikethrough', 5, 5)]));
        $quote = "\x60";
        $this->assertSame("Ejecuta {$quote}npm install{$quote}", watext::from_entities('Ejecuta npm install', [
            self::entity('code', 8, 11)]));
        $this->assertSame(str_repeat($quote, 3) . "uno\ndos" . str_repeat($quote, 3), watext::from_entities("uno\ndos", [
            self::entity('pre', 0, 7)]));
        $this->assertSame("> cita\n> otra", watext::from_entities("cita\notra", [self::entity('blockquote', 0, 9)]));
        // Underline, spoiler and the like: WhatsApp has nothing of them.
        $this->assertSame('un secreto', watext::from_entities('un secreto', [self::entity('spoiler', 3, 7),
            self::entity('underline', 0, 2)]));
        $this->assertSame('Sin formato.', watext::from_entities('Sin formato.', []));
    }

    /**
     * Offsets count in UTF-16, as Telegram measures them: an emoji takes two.
     */
    public function test_utf16(): void {
        $this->assertSame("\u{1F389} *Fiesta* hoy", watext::from_entities("\u{1F389} Fiesta hoy", [self::entity('bold', 3, 6)]));
        $this->assertSame(3, watext::units("\u{1F389} "));
        $this->assertSame('*Año* nuevo', watext::from_entities('Año nuevo', [self::entity('bold', 0, 3)]));
    }

    /**
     * A format over several lines is marked line by line, the spaces at its edges outside the marks; one inside another
     * nests, the same one twice does not repeat its marks, and two that overlap without one inside the other leave out
     * the second.
     */
    public function test_nesting_and_edges(): void {
        $this->assertSame("*Uno* \n*Dos*", watext::from_entities("Uno \nDos", [self::entity('bold', 0, 8)]));
        $this->assertSame(' *dentro* ', watext::from_entities(' dentro ', [self::entity('bold', 0, 8)]));
        $this->assertSame('*negrita y _cursiva_*', watext::from_entities('negrita y cursiva', [self::entity('bold', 0, 17),
            self::entity('italic', 10, 7)]));
        $this->assertSame('*todo junto*', watext::from_entities('todo junto', [self::entity('bold', 0, 10),
            self::entity('bold', 5, 5)]));
        $this->assertSame('*uno dos* tres', watext::from_entities('uno dos tres', [self::entity('bold', 0, 7),
            self::entity('italic', 4, 8)]));
    }

    /**
     * A mark in the text itself never breaks the formats: at the edge of a word it changes for the character that looks
     * like it; between letters or digits it stays; links, addresses and names stay as they are.
     */
    public function test_escape(): void {
        $star = watext::SAFE['*'];
        $low = watext::SAFE['_'];
        $this->assertSame('*Oferta 2x1' . $star . '*', watext::from_entities('Oferta 2x1*', [self::entity('bold', 0, 11)]));
        $this->assertSame('precio ' . $star . 'especial' . $star . ' hoy', watext::escape('precio *especial* hoy'));
        $this->assertSame('archivo_final y 2*3', watext::escape('archivo_final y 2*3'));
        $this->assertSame(
            'Mira https://example.org/a_b_ y ' . $low . 'ya' . $low,
            watext::escape('Mira https://example.org/a_b_ y _ya_')
        );
        $this->assertSame('ana_perez@example.org y @canal_ejemplo', watext::escape('ana_perez@example.org y @canal_ejemplo'));
        $this->assertSame(watext::SAFE['~'] . ' y ' . watext::SAFE["\x60"], watext::escape("~ y \x60"));
        // A link Telegram marked stays whole, underscores and all.
        $this->assertSame('Ver https://example.org/x_y_', watext::from_entities('Ver https://example.org/x_y_', [
            self::entity('url', 4, 24)]));
    }

    /**
     * A link in a word goes after it, in brackets; a word that is the link itself, once.
     */
    public function test_links(): void {
        $this->assertSame(
            'Más información aquí (https://example.org/info)',
            watext::from_entities('Más información aquí', [self::entity('text_link', 16, 4, 'https://example.org/info')])
        );
        $this->assertSame(
            'https://example.org',
            watext::from_entities('https://example.org', [self::entity('text_link', 0, 19, 'https://example.org')])
        );
        $this->assertSame(
            '*Más (https://example.org)*',
            watext::from_entities('Más', [self::entity('bold', 0, 3), self::entity('text_link', 0, 3, 'https://example.org')])
        );
    }

    /**
     * A post of the channel: the title in bold, the lines as a list, the text, and the button as its link after its label.
     */
    public function test_post(): void {
        $star = watext::SAFE['*'];
        $content = ['title' => 'Jornada *especial*', 'lines' => ['Sábado 14', 'De 10:00 a 13:00'],
            'text' => ['Con visita guiada.', 'Trae a tu familia.']];
        $this->assertSame(
            "*Jornada {$star}especial{$star}*\n\n\u{2022} Sábado 14\n\u{2022} De 10:00 a 13:00\n\nCon visita guiada.\n"
                . "Trae a tu familia.\n\n\u{1F449} Reservar visita: https://sho.rt/abc_",
            watext::post($content, 'https://sho.rt/abc_', 'Reservar visita')
        );
        $this->assertSame('*Solo el título*', watext::post(['title' => 'Solo el título']));
        $this->assertSame("Solo texto.\n\n\u{1F449} https://example.org/a", watext::post(
            ['text' => ['Solo texto.']],
            'https://example.org/a'
        ));
    }

    /**
     * What the page shows before sharing: escaped, with its formats in their tags and its links that can be followed.
     */
    public function test_html(): void {
        $html = watext::html("*Hola* _mundo_ <b>x</b>\nhttps://example.org/a?b=1&c=2 y 2*3");
        $this->assertStringContainsString('<strong>Hola</strong> <em>mundo</em> &lt;b&gt;x&lt;/b&gt;<br>', $html);
        $this->assertStringContainsString('<a href="https://example.org/a?b=1&amp;c=2" target="_blank" rel="noopener">'
            . 'https://example.org/a?b=1&amp;c=2</a>', $html);
        $this->assertStringContainsString('y 2*3', $html);
        $this->assertSame('<s>no</s> es <s>esto</s>', watext::html('~no~ es ~esto~'));
        $this->assertSame('<code>código</code>', watext::html("\x60código\x60"));
    }
}
