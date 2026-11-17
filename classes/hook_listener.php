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
use stdClass;

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
            $exported = null;
            $html = '';
            try {
                $exported = $navigation->export_for_template($hook->renderer);
            } catch (\Throwable $e) {
                $exported = null;
            }
            try {
                $html = $hook->renderer->render($navigation);
            } catch (\Throwable $e) {
                $html = '';
            }
            // The page renderer looks for render_navigation() before the
            // Mustache template. A theme method of that name can return an
            // empty string, the navigation block, or a bar that has Back to
            // course and no diagnostic. Keep a result only when the diagnostic
            // element is in that same nav.
            if (!self::html_has_debug($html)) {
                try {
                    if (!$exported instanceof stdClass) {
                        $exported = $navigation->export_for_template($hook->renderer);
                    }
                    $html = $hook->renderer->render_from_template(
                        'local_activitynavbuttons/navigation',
                        $exported
                    );
                } catch (\Throwable $e) {
                    $html = is_string($html) ? $html : '';
                }
            }
            if (!self::html_has_debug($html)) {
                $html = self::inject_debug(self::strip_debug(is_string($html) ? $html : ''), $exported);
            }
            if (!is_string($html) || !str_contains($html, 'local-activitynavbuttons-debug')) {
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
     * Whether this HTML is our bar and contains the diagnostic element.
     *
     * @param mixed $html Renderer output.
     * @return bool
     */
    private static function html_has_debug($html): bool {
        if (!is_string($html) || !str_contains($html, 'local-activitynavbuttons-debug')) {
            return false;
        }
        $course = strpos($html, 'local-activitynavbuttons-course');
        $debug = strpos($html, 'local-activitynavbuttons-debug');
        if ($course === false || $debug === false || $debug < $course) {
            return false;
        }
        $next = strpos($html, 'local-activitynavbuttons-next', $course);
        if ($next !== false && $debug > $next) {
            return false;
        }
        return true;
    }

    /**
     * Plain diagnostic from the same export that produced Back to course.
     *
     * @param stdClass|null $exported Template export.
     * @return string
     */
    private static function debug_plain_text(?stdClass $exported): string {
        if ($exported !== null && isset($exported->backtocourse) && is_object($exported->backtocourse)
                && isset($exported->backtocourse->debugtext) && is_string($exported->backtocourse->debugtext)
                && $exported->backtocourse->debugtext !== '') {
            return $exported->backtocourse->debugtext;
        }
        if ($exported !== null && isset($exported->debugtext) && is_string($exported->debugtext)
                && $exported->debugtext !== '') {
            return $exported->debugtext;
        }
        return 'plugin version: ' . navigation_builder::VERSION;
    }

    /**
     * Remove a diagnostic block that was rendered outside the course slot.
     *
     * @param string $html Rendered bar.
     * @return string
     */
    private static function strip_debug(string $html): string {
        $stripped = preg_replace(
            '/<div class="local-activitynavbuttons-debug">.*?<\/div>/s',
            '',
            $html
        );
        return is_string($stripped) ? $stripped : $html;
    }

    /**
     * Put the diagnostic in the course slot when a render omitted it.
     *
     * The block is inserted immediately after the Back to course link. The text
     * is escaped. Nothing is written to the error log.
     *
     * @param string $html Rendered bar, possibly empty.
     * @param stdClass|null $exported Template export from the same build.
     * @return string
     */
    private static function inject_debug(string $html, ?stdClass $exported): string {
        $block = '<div class="local-activitynavbuttons-debug">'
            . htmlspecialchars(self::debug_plain_text($exported), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';

        $slot = 'local-activitynavbuttons-course';
        $pos = strpos($html, $slot);
        if ($pos !== false) {
            $close = strpos($html, '</div>', $pos);
            $anchor = strpos($html, '</a>', $pos);
            if ($anchor !== false && $close !== false && $anchor < $close) {
                $insertat = $anchor + strlen('</a>');
                return substr($html, 0, $insertat) . $block . substr($html, $insertat);
            }
            if ($close !== false) {
                return substr($html, 0, $close) . $block . substr($html, $close);
            }
        }
        $navclose = strripos($html, '</nav>');
        if ($navclose !== false) {
            return substr($html, 0, $navclose) . $block . substr($html, $navclose);
        }
        if ($html !== '' && str_contains($html, 'local-activitynavbuttons')) {
            return $html . $block;
        }
        return '<nav class="local-activitynavbuttons" role="navigation">' . $block . '</nav>';
    }

    /**
     * Diagnostic nav used when rendering the bar throws.
     *
     * Shown on an activity page for this release even when the debug setting is
     * off. The text is escaped. Nothing is written to the error log.
     *
     * @param \Throwable|null $e Failure, when one was caught.
     * @return string
     */
    private static function failure_markup(?\Throwable $e): string {
        global $PAGE;

        if (empty($PAGE->cm) || empty($PAGE->cm->id)) {
            return '';
        }

        $modname = '';
        if (isset($PAGE->cm->modname)) {
            $modname = (string)$PAGE->cm->modname;
        }
        $lines = [
            'plugin version: ' . navigation_builder::VERSION,
            'pagetype: ' . (string)$PAGE->pagetype,
            'cmid: ' . (int)$PAGE->cm->id,
            'modname: ' . $modname,
            'sequence count: unknown',
            'current found: unknown',
            'previous: omitted: render failed',
            'next: omitted: render failed',
            'showprevious: unknown',
            'shownext: unknown',
            'previousurl: unknown',
            'nexturl: unknown',
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
