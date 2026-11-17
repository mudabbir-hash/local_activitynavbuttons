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

        $data = (new navigation_builder())->build();
        if ($data === null) {
            return;
        }

        $navigation = new navigation_output($data);
        $html = $hook->renderer->render($navigation);
        if (!is_string($html) || $html === '') {
            // The page renderer looks for render_navigation() before the
            // Mustache template. A theme method of that name can return an
            // empty string for this widget. Render the plugin template from
            // the exported context so the bar is still injected.
            $html = $hook->renderer->render_from_template(
                'local_activitynavbuttons/navigation',
                $navigation->export_for_template($hook->renderer)
            );
        }
        if (!is_string($html) || $html === '') {
            return;
        }
        $hook->add_html(self::styles_markup() . $html);
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
