<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Footer hook listener.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_activitynavbuttons;

defined('MOODLE_INTERNAL') || die();

use core\hook\output\before_footer_html_generation;
use local_activitynavbuttons\local\navigation as navigation_builder;
use local_activitynavbuttons\output\navigation as navigation_output;

/**
 * Adds the activity button bar at the end of the page body.
 *
 * before_footer_html_generation runs inside core_renderer::footer(), after the
 * activity script has printed and before open containers are closed, so the
 * HTML sits at the bottom of the main content.
 */
class hook_listener {
    /**
     * Render the button bar when the current page should show it.
     *
     * @param before_footer_html_generation $hook Footer output hook.
     * @return void
     */
    public static function before_footer(before_footer_html_generation $hook): void {
        global $CFG;

        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return;
        }

        try {
            $data = (new navigation_builder())->build();
            if ($data === null) {
                return;
            }

            $navigation = new navigation_output($data);
            $html = '';
            try {
                $html = $hook->renderer->render($navigation);
            } catch (\Throwable $e) {
                $html = '';
            }
            // The page renderer looks for render_navigation() before the
            // Mustache template. A theme method of that name can return an
            // empty string, or the navigation block, for this widget. Render
            // the plugin template when the result is not our bar.
            if (!is_string($html) || !str_contains($html, 'local-activitynavbuttons')) {
                $html = $hook->renderer->render_from_template(
                    'local_activitynavbuttons/navigation',
                    $navigation->export_for_template($hook->renderer)
                );
            }
            if (!is_string($html) || !str_contains($html, 'local-activitynavbuttons')) {
                $hook->add_html(self::failure_markup(null));
                return;
            }
            $hook->add_html(self::styles_markup() . $html);
        } catch (\Throwable $e) {
            $fallback = self::failure_markup($e);
            if ($fallback !== '') {
                $hook->add_html($fallback);
            }
        }
    }

    /**
     * Diagnostic nav used when rendering the bar throws.
     *
     * Shown on an activity page when the debug setting is on or has never been
     * saved. The text is escaped. Nothing is written to the error log.
     *
     * @param \Throwable|null $e Failure, when one was caught.
     * @return string
     */
    private static function failure_markup(?\Throwable $e): string {
        global $PAGE;

        if (empty($PAGE->cm) || empty($PAGE->cm->id)) {
            return '';
        }
        $debug = get_config(navigation_builder::COMPONENT, 'debug');
        if ($debug !== false && trim((string)$debug) !== '1') {
            return '';
        }

        $lines = [
            'plugin version: ' . navigation_builder::VERSION,
            'pagetype: ' . (string)$PAGE->pagetype,
            'cmid: ' . (int)$PAGE->cm->id,
            'sequence count: unknown',
            'current index: unknown',
        ];
        if ($e !== null) {
            $lines[] = 'render failed: ' . $e->getMessage();
        } else {
            $lines[] = 'render failed: template produced no activity navigation markup';
        }
        $text = htmlspecialchars(implode("\n", $lines), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<nav class="local-activitynavbuttons" role="navigation">'
            . '<div class="local-activitynavbuttons-debug">' . $text . '</div></nav>';
    }

    /**
     * Link the plugin stylesheet only when the bar is rendered.
     *
     * $PAGE->requires->css() throws once the head has been printed. This hook
     * runs from footer(), so the link element is emitted with the bar. When
     * the head is still open (a caller used before output started), the normal
     * requirements API is used instead.
     *
     * @return string Stylesheet markup, or an empty string when queued in the head.
     */
    private static function styles_markup(): string {
        global $PAGE;

        $version = get_config('local_activitynavbuttons', 'version');
        $url = new \moodle_url('/local/activitynavbuttons/styles.css', [
            'v' => $version ? $version : '1',
        ]);

        if (!$PAGE->requires->is_head_done()) {
            $PAGE->requires->css($url);
            return '';
        }

        return \html_writer::empty_tag('link', [
            'rel' => 'stylesheet',
            'type' => 'text/css',
            'href' => $url->out(false),
        ]);
    }
}
