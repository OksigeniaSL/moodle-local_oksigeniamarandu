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

namespace local_oksigeniamarandu\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_oksigeniamarandu\local\proposals;

/**
 * A proposal of improvement from the screen of a tool: what the person would change and the picture of the screen
 * with their marks.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class proposal_send extends external_api {
    /**
     * The parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'The tool, such as local_oksigeniameals'),
            'screen' => new external_value(PARAM_TEXT, 'The screen, as the tool names it', VALUE_DEFAULT, ''),
            'url' => new external_value(PARAM_URL, 'The address of the page', VALUE_DEFAULT, ''),
            'text' => new external_value(PARAM_TEXT, 'What the person would change'),
            'image' => new external_value(PARAM_RAW, 'The picture with the marks: a JPEG or a PNG in base64', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Keeps it.
     *
     * @param string $component The tool.
     * @param string $screen The screen.
     * @param string $url The address.
     * @param string $text What they would change.
     * @param string $image The picture.
     * @return array Its id.
     */
    public static function execute(
        string $component,
        string $screen = '',
        string $url = '',
        string $text = '',
        string $image = ''
    ): array {
        global $USER;
        $params = self::validate_parameters(self::execute_parameters(), ['component' => $component, 'screen' => $screen,
            'url' => $url, 'text' => $text, 'image' => $image]);
        $context = \context_system::instance();
        self::validate_context($context);
        if (isguestuser()) {
            throw new \require_login_exception('A guest does not propose');
        }
        require_capability('local/oksigeniamarandu:propose', $context);
        return ['id' => proposals::save(
            (int)$USER->id,
            $params['component'],
            $params['screen'],
            $params['url'],
            $params['text'],
            $params['image']
        )];
    }

    /**
     * What it returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'id' => new external_value(PARAM_INT, 'The proposal'),
        ]);
    }
}
