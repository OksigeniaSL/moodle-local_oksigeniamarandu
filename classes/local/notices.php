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
 * The notices the plugins of the site declare, and which of them the site sends.
 *
 * A plugin declares its notices in its lib.php:
 *
 *     function local_example_oksigeniamarandu_notices(): array {
 *         return ['menu_missing' => ['examples' => ['Ana', 'lunes 3'], 'perweek' => 0.5]];
 *     }
 *
 * and their texts in its language packs, so that they are translated like any other string:
 *
 *     $string['marandu:menu_missing'] = 'Menu not chosen';                     // Its name, for the site and the families.
 *     $string['marandu:menu_missing_desc'] = 'The day before, to whom has not chosen';  // When it goes out.
 *     // The WhatsApp text; it never starts or ends with a value.
 *     $string['marandu:menu_missing_template'] = 'Notice from the canteen for {{1}}: no menu chosen for {{2}} yet. You
 *         can choose it at the link.';
 *     $string['marandu:menu_missing_button'] = 'Choose the menu';              // The button, up to 25 characters.
 *
 * and, optionally, what the plugin is and what its items are for whoever runs the site, in its own words:
 *
 *     $string['marandu:label'] = 'canteen';                    // Beside the name of each notice, in the panel.
 *     $string['marandu:item'] = 'a family of the canteen';     // Under whom a number stands for, in the list.
 *
 * The examples are the values Meta sees when it reviews the text: made up, one for each {{n}}. Perweek is how many of
 * these notices a family gets in a usual week, to estimate the cost. With 'askedfor' => true, each person asks for the
 * notice at a time they choose: the quiet hours do not hold it back and it does not count for the notices a day. It is
 * not urgent, so it keeps the choice of notices without sound.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notices {
    /**
     * Every notice declared on the site, by "component/notice".
     *
     * @return \stdClass[] Each with component, notice, key, examples, perweek and askedfor.
     */
    public static function all(): array {
        $all = [];
        foreach (get_plugins_with_function('oksigeniamarandu_notices', 'lib.php') as $type => $plugins) {
            foreach ($plugins as $plugin => $function) {
                $component = $type . '_' . $plugin;
                foreach ((array)$function() as $notice => $definition) {
                    if (!is_string($notice) || !preg_match('/^[a-z0-9_]{1,40}$/', $notice)) {
                        continue;
                    }
                    $all[$component . '/' . $notice] = (object)[
                        'component' => $component,
                        'notice' => $notice,
                        'key' => $component . '/' . $notice,
                        'examples' => array_map('strval', array_values((array)($definition['examples'] ?? []))),
                        'perweek' => max(0.0, (float)($definition['perweek'] ?? 1)),
                        'askedfor' => !empty($definition['askedfor']),
                    ];
                }
            }
        }
        ksort($all);
        return $all;
    }

    /**
     * One declared notice.
     *
     * @param string $component The plugin.
     * @param string $notice The notice.
     * @return \stdClass|null
     */
    public static function get(string $component, string $notice): ?\stdClass {
        return self::all()[$component . '/' . $notice] ?? null;
    }

    /**
     * A text of a notice, from the language pack of its plugin.
     *
     * @param \stdClass $notice The notice.
     * @param string $part '' for its name, or desc, template or button.
     * @param string|null $lang The language; the current one if null.
     * @return string
     */
    public static function text(\stdClass $notice, string $part = '', ?string $lang = null): string {
        $id = 'marandu:' . $notice->notice . ($part === '' ? '' : '_' . $part);
        return get_string_manager()->get_string($id, $notice->component, null, $lang);
    }

    /**
     * What a notice is about, for whoever runs the site: its own label, the label of its plugin, or the plugin's name.
     *
     * @param \stdClass $notice The notice.
     * @return string
     */
    public static function label(\stdClass $notice): string {
        $strings = get_string_manager();
        foreach (['marandu:' . $notice->notice . '_label', 'marandu:label'] as $id) {
            if ($strings->string_exists($id, $notice->component)) {
                return get_string($id, $notice->component);
            }
        }
        return self::pluginname($notice->component);
    }

    /**
     * What the items of a plugin are, under whom a number stands for: «a family of the canteen».
     *
     * @param string $component The plugin.
     * @return string
     */
    public static function item(string $component): string {
        $strings = get_string_manager();
        foreach (['marandu:item', 'marandu:label'] as $id) {
            if ($strings->string_exists($id, $component)) {
                return get_string($id, $component);
            }
        }
        return self::pluginname($component);
    }

    /**
     * Whom an item of a plugin stands for, as the plugin names it in <component>_oksigeniamarandu_label(int $itemid) in
     * its lib.php (for example, «Family of Ana»); null if it does not, or if the plugin is not installed any more.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item.
     * @return string|null
     */
    public static function item_label(string $component, int $itemid): ?string {
        if ($component === 'core' || \core_component::get_component_directory($component) === null) {
            return null;
        }
        $label = component_callback($component, 'oksigeniamarandu_label', [$itemid], null);
        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * The name of a plugin, or its component if it is not installed any more.
     *
     * @param string $component The plugin.
     * @return string
     */
    private static function pluginname(string $component): string {
        return get_string_manager()->string_exists('pluginname', $component) ? get_string('pluginname', $component) : $component;
    }

    /**
     * The languages the templates are written in: for now, the language of the site.
     *
     * @return string[] Moodle language codes.
     */
    public static function languages(): array {
        global $CFG;
        return [$CFG->lang];
    }

    /**
     * The language code Meta uses for a Moodle language: es stays es, es_mx becomes es_MX.
     *
     * @param string $lang Moodle language code.
     * @return string
     */
    public static function metalang(string $lang): string {
        $parts = explode('_', $lang, 2);
        return isset($parts[1]) ? $parts[0] . '_' . strtoupper($parts[1]) : $parts[0];
    }

    /**
     * The name of the template of a notice in Meta: lower case, digits and _, unique on the account.
     *
     * @param \stdClass $notice The notice.
     * @return string
     */
    public static function template_name(\stdClass $notice): string {
        return 'mrd_' . $notice->component . '_' . $notice->notice;
    }

    /** The setting with the notices switched on, by channel. */
    public const SETTING = ['whatsapp' => 'enablednotices', 'telegram' => 'telegramnotices'];

    /** The drawing of the plugins of the house; any other plugin gets the bell. */
    private const ICONS = [
        'local_oksigeniameals' => 'comedor',
        'local_oksigeniaayvurenda' => 'biblioteca',
        'local_oksigeniamarandu' => 'general',
    ];

    /**
     * The notices the site has switched on for a channel, as "component/notice" keys.
     *
     * @param string $channel whatsapp or telegram.
     * @return string[]
     */
    public static function enabled(string $channel = 'whatsapp'): array {
        return self::keys(self::SETTING[$channel] ?? 'enablednotices');
    }

    /**
     * Whether a channel has ever been given its notices (an empty choice is a choice).
     *
     * @param string $channel whatsapp or telegram.
     * @return bool
     */
    public static function chosen(string $channel): bool {
        return get_config('local_oksigeniamarandu', self::SETTING[$channel]) !== false;
    }

    /**
     * Switches a notice on or off for a channel.
     *
     * @param string $key "component/notice".
     * @param string $channel whatsapp or telegram.
     * @param bool $on On or off.
     */
    public static function set(string $key, string $channel, bool $on): void {
        if (!isset(self::SETTING[$channel]) || !isset(self::all()[$key])) {
            return;
        }
        $keys = array_diff(self::enabled($channel), [$key]);
        if ($on) {
            $keys[] = $key;
        }
        $keys = array_values(array_intersect(array_keys(self::all()), $keys));
        set_config(self::SETTING[$channel], implode(',', $keys), 'local_oksigeniamarandu');
    }

    /**
     * Whether any notice is on for a channel that is ready: if none, nothing goes out.
     *
     * @return bool
     */
    public static function any_on(): bool {
        foreach (\local_oksigeniamarandu\api::channels() as $channel => $info) {
            if ($info['ready'] && array_intersect(self::enabled($channel), array_keys(self::all()))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The drawing of a plugin, for its notices.
     *
     * @param string $component The plugin.
     * @return string The name of a drawing of {@see drawings}.
     */
    public static function icon(string $component): string {
        return self::ICONS[$component] ?? 'campana';
    }

    /**
     * The heading of the notices of a plugin: what it is, in capitals, and its name.
     *
     * @param string $component The plugin.
     * @return \stdClass With title and name.
     */
    public static function group(string $component): \stdClass {
        $strings = get_string_manager();
        $name = self::pluginname($component);
        $title = $strings->string_exists('marandu:label', $component) ? get_string('marandu:label', $component) : $name;
        $title = \core_text::strtoupper(\core_text::substr($title, 0, 1)) . \core_text::substr($title, 1);
        return (object)['title' => $title, 'name' => $title === $name ? '' : $name];
    }

    /**
     * The notices that go out even in the quiet hours.
     *
     * @return string[]
     */
    public static function urgent(): array {
        return self::keys('urgentnotices');
    }

    /**
     * A setting with a list of "component/notice" keys.
     *
     * @param string $name The setting.
     * @return string[]
     */
    private static function keys(string $name): array {
        $value = (string)get_config('local_oksigeniamarandu', $name);
        return $value === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
