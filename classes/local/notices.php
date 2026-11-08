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
 *     $string['marandu:item_kitchen'] = 'the kitchen of a canteen';  // The same, for the items of an area.
 *
 * The examples are the values Meta sees when it reviews the text: made up, one for each {{n}}. Perweek is how many of
 * these notices a family gets in a usual week, to estimate the cost. With 'askedfor' => true, each person asks for the
 * notice at a time they choose: the quiet hours do not hold it back and it does not count for the notices a day. It is
 * not urgent, so it keeps the choice of notices without sound.
 *
 * A notice that takes over from another of the same plugin, such as a day without canteen that went before as a change
 * of menu, says so with 'follows' => 'menu_changed': the first time the site sees it, it is switched on in each channel
 * where that one is on. After that, it is a notice like any other, which the site switches on or off.
 *
 * A notice can be a question with buttons to answer it, sent with api::ask(): 'options' => ['yes', 'no'] (up to three,
 * short keys), each with its label in the language pack, up to 20 characters:
 *
 *     $string['marandu:consent_option_yes'] = 'I authorise';
 *     $string['marandu:consent_option_no'] = 'I do not authorise';
 *
 * The answer reaches the plugin with the hook \local_oksigeniamarandu\hook\answer_received.
 *
 * @package    local_oksigeniamarandu
 * @copyright  2026 Oksigenia SL <dev@oksigenia.cc>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notices {
    /** @var array Notices declared by the tests, by component. */
    private static $fortests = [];

    /** @var bool Whether this request has already switched on the notices that follow another. */
    private static $followed = false;

    /**
     * Every notice declared on the site, by "component/notice".
     *
     * @return \stdClass[] Each with component, notice, key, examples, perweek, askedfor, options and follows (the notice
     *                     of the same plugin it takes over from, or empty).
     */
    public static function all(): array {
        $all = [];
        $declared = [];
        foreach (get_plugins_with_function('oksigeniamarandu_notices', 'lib.php') as $type => $plugins) {
            foreach ($plugins as $plugin => $function) {
                $declared[$type . '_' . $plugin] = (array)$function();
            }
        }
        foreach (self::$fortests as $component => $notices) {
            $declared[$component] = $notices + ($declared[$component] ?? []);
        }
        foreach ($declared as $component => $notices) {
            foreach ($notices as $notice => $definition) {
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
                    'options' => self::options((array)($definition['options'] ?? [])),
                    'follows' => self::follows((string)($definition['follows'] ?? ''), $notice),
                ];
            }
        }
        ksort($all);
        return $all;
    }

    /**
     * The answers of a question, as declared: short keys, at most three, as WhatsApp takes its buttons.
     *
     * @param array $options The keys.
     * @return string[]
     */
    private static function options(array $options): array {
        $keys = array_values(array_unique(array_filter(array_map('strval', $options), function ($key) {
            return (bool)preg_match('/^[a-z0-9]{1,10}$/', $key);
        })));
        return array_slice($keys, 0, 3);
    }

    /**
     * The notice another takes over from: a name like that of any notice, and not itself.
     *
     * @param string $follows The notice, as declared.
     * @param string $notice The notice that takes over.
     * @return string Empty if it follows none.
     */
    private static function follows(string $follows, string $notice): string {
        return $follows !== $notice && preg_match('/^[a-z0-9_]{1,40}$/', $follows) ? $follows : '';
    }

    /**
     * Notices declared by the tests, as a plugin would; only while the tests run.
     *
     * @param array $notices By component, the declarations; [] to forget them.
     */
    public static function declare_for_tests(array $notices): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Only for the tests');
        }
        self::$fortests = $notices;
        self::$followed = false;
    }

    /**
     * The label of an answer of a question, as its button shows it.
     *
     * @param \stdClass $notice The notice.
     * @param string $option The key of the answer.
     * @param string|null $lang The language; the current one if null.
     * @return string Up to 20 characters.
     */
    public static function option_label(\stdClass $notice, string $option, ?string $lang = null): string {
        return \core_text::substr(self::text($notice, 'option_' . $option, $lang), 0, 20);
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
     * What the items of a plugin are, under whom a number stands for: «a family of the canteen», or «the kitchen of a
     * canteen» for its area kitchen (marandu:item_kitchen).
     *
     * @param string $component The plugin.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return string
     */
    public static function item(string $component, string $area = ''): string {
        $strings = get_string_manager();
        $ids = $area === '' ? ['marandu:item', 'marandu:label'] : ['marandu:item_' . $area, 'marandu:label'];
        foreach ($ids as $id) {
            if ($strings->string_exists($id, $component)) {
                return get_string($id, $component);
            }
        }
        return self::pluginname($component);
    }

    /**
     * Whom an item of a plugin stands for, as the plugin names it in
     * <component>_oksigeniamarandu_label(int $itemid, string $area = '') in its lib.php (for example, «Family of Ana»);
     * null if it does not, or if the plugin is not installed any more.
     *
     * @param string $component The plugin.
     * @param int $itemid Its item.
     * @param string $area The kind of item, if the plugin has more than one.
     * @return string|null
     */
    public static function item_label(string $component, int $itemid, string $area = ''): ?string {
        if ($component === 'core' || \core_component::get_component_directory($component) === null) {
            return null;
        }
        $label = component_callback($component, 'oksigeniamarandu_label', [$itemid, $area], null);
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
        self::follow();
        return self::keys(self::SETTING[$channel] ?? 'enablednotices');
    }

    /**
     * Switches on, once, each notice that takes over from another in the channels where that one is on, so that a
     * plugin that splits a notice in two does not leave the site without it. Only the notices that declare 'follows'
     * are written down, once, in followednotices; the rest never touch the settings.
     */
    private static function follow(): void {
        if (self::$followed) {
            return;
        }
        self::$followed = true;
        $done = self::keys('followednotices');
        $new = array_filter(self::all(), function ($notice) use ($done) {
            return $notice->follows !== '' && !in_array($notice->key, $done, true);
        });
        if (!$new) {
            return;
        }
        foreach (self::SETTING as $setting) {
            $keys = self::keys($setting);
            $add = [];
            foreach ($new as $notice) {
                if (in_array($notice->component . '/' . $notice->follows, $keys, true) && !in_array($notice->key, $keys, true)) {
                    $add[] = $notice->key;
                }
            }
            if ($add) {
                set_config($setting, implode(',', array_merge($keys, $add)), 'local_oksigeniamarandu');
            }
        }
        set_config('followednotices', implode(',', array_merge($done, array_keys($new))), 'local_oksigeniamarandu');
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
