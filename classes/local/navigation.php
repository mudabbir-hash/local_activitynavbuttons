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
 * Decides which activity navigation buttons the current page should show.
 *
 * Ordering. get_fast_modinfo()->get_section_info_all() is sorted by section
 * number and includes delegated subsection sections. Moodle assigns those
 * delegated sections the next free section number, so a walk of that list
 * alone places subsection activities after later top-level sections.
 *
 * Display order is the order a student meets activities on the course page:
 * 1. Walk non-delegated sections in get_section_info_all() order.
 * 2. Within a section, follow get_sections() (section number => cmids in
 *    sequence). That is the modinfo cache, not a fresh database query.
 * 3. When a course module owns a delegated section, get_section_info_by_component('mod_' . modname, instance)
 *    finds it in the same cache. The module is considered in place, then the
 *    delegated section is inserted there, including nested subsections.
 * 4. Delegated sections that were not reached from a parent module are appended
 *    in get_section_info_all() order so an orphaned subsection is not dropped.
 *
 * Visibility. A link target must be uservisible. Modinfo already applies
 * availability, group, and hidden-section rules for the current user, including
 * guests and users without access. Activities with deletioninprogress are not
 * targets. The current activity is kept as an anchor even when it would not
 * itself be a target, so the nearest earlier and later targets can still be found.
 *
 * Custom URL versus automatic. A non-empty custom URL replaces automatic
 * navigation for that button and the activity name is not appended. An empty
 * custom URL uses the neighbouring activity. A custom URL that fails validation
 * is not printed and does not fall through to the automatic neighbour, because
 * the administrator asked for a specific address. Previous and Next are chosen
 * independently. Back to course is independent of both: an empty custom URL
 * links to /course/view.php?id=, and the button is omitted when the course
 * cannot be resolved.
 *
 * A button is omitted when its show setting is off, when a custom URL was set
 * but rejected, or when there is neither a custom URL nor a usable target
 * (first activity has no previous, last activity has no next, empty course,
 * or no visible activity with a URL).
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_activitynavbuttons\local;

defined('MOODLE_INTERNAL') || die();

use cm_info;
use course_modinfo;
use moodle_exception;
use moodle_url;
use section_info;
use stdClass;

/**
 * Builds the button-bar data for one page request.
 */
class navigation {
    /** @var string Plugin component. */
    public const COMPONENT = 'local_activitynavbuttons';

    /** @var string Classes used when the setting is empty or not a class list. */
    public const DEFAULT_BUTTON_CLASS = 'btn btn-outline-primary';

    /** @var int Longest accepted custom URL. */
    private const MAX_URL_LENGTH = 2000;

    /**
     * Build the template data for this page, or null when nothing should render.
     *
     * Uses one get_fast_modinfo() call. Returns null when the master switch is
     * off, the page is not an activity view, or every button is omitted.
     *
     * @return stdClass|null
     */
    public function build(): ?stdClass {
        global $CFG;

        if (during_initial_install() || !empty($CFG->upgraderunning)) {
            return null;
        }
        if (!$this->is_activity_view()) {
            return null;
        }
        if (!$this->config_flag('enabled', true)) {
            return null;
        }

        $course = $this->resolve_course();
        if ($course === null) {
            return null;
        }

        try {
            $modinfo = get_fast_modinfo($course);
        } catch (moodle_exception) {
            return null;
        }

        $sequence = $this->display_sequence($modinfo);
        [$previouscm, $nextcm] = $this->neighbours($sequence);

        $classes = $this->button_classes();
        $newtab = $this->config_flag('newtab', false);
        $previous = $this->direction_button('previous', $previouscm, $classes, $newtab);
        $next = $this->direction_button('next', $nextcm, $classes, $newtab);
        $back = $this->back_to_course_button($course, $classes, $newtab);

        if ($previous === null && $next === null && $back === null) {
            return null;
        }

        $data = (object)[
            'navigationlabel' => get_string('navigation', self::COMPONENT),
        ];
        if ($previous !== null) {
            $data->previous = $previous;
        }
        if ($next !== null) {
            $data->next = $next;
        }
        if ($back !== null) {
            $data->backtocourse = $back;
        }
        return $data;
    }

    /**
     * Whether a string is a list of CSS class tokens.
     *
     * Tokens may contain letters, numbers, hyphens, and underscores, separated
     * by single spaces.
     *
     * @param string $value Trimmed class list.
     * @return bool
     */
    public static function is_valid_button_class(string $value): bool {
        if ($value === '' || strlen($value) > 200) {
            return false;
        }
        return (bool)preg_match('/^[A-Za-z0-9_-]+(?: [A-Za-z0-9_-]+)*$/', $value);
    }

    /**
     * Return a moodle_url for an allowed link, or null when the value must not be used.
     *
     * Allowed values are a root-relative path (a single leading slash, then
     * bound to wwwroot) or an absolute http or https URL. javascript:, data:,
     * vbscript:, file:, blob:, about:, protocol-relative URLs, and any other
     * scheme are rejected. Empty input returns null.
     *
     * @param string $raw Administrator-supplied URL.
     * @return moodle_url|null
     */
    public static function clean_link(string $raw): ?moodle_url {
        $raw = trim($raw);
        if ($raw === '' || strlen($raw) > self::MAX_URL_LENGTH) {
            return null;
        }
        if (preg_match('/[\x00-\x20\x7f]/', $raw)) {
            return null;
        }
        if (strpbrk($raw, "\\<>\"'") !== false) {
            return null;
        }

        $decoded = rawurldecode($raw);
        if (preg_match('/[\x00-\x20\x7f]/', $decoded)) {
            return null;
        }
        foreach ([$raw, ltrim($decoded)] as $candidate) {
            if (preg_match('#^(?:javascript|data|vbscript|file|blob|about)\s*:#i', $candidate)) {
                return null;
            }
        }
        if (str_starts_with($raw, '//') || str_starts_with(ltrim($decoded), '//')) {
            return null;
        }

        $absolute = (bool)preg_match('#^https?://#i', $raw);
        $local = str_starts_with($raw, '/') && !str_starts_with($raw, '//');
        if (!$absolute && !$local) {
            return null;
        }
        if ($absolute && filter_var($raw, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        try {
            $url = new moodle_url($raw);
        } catch (moodle_exception) {
            return null;
        }

        $scheme = strtolower((string)$url->get_scheme());
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        return $url;
    }

    /**
     * Whether this request is an activity or resource view page.
     *
     * Requires a module context whose instance is $PAGE->cm, and a pagetype of
     * mod-*-view. Course pages, the site home, and admin pages use other pagetypes.
     *
     * @return bool
     */
    private function is_activity_view(): bool {
        global $PAGE;

        if (empty($PAGE->cm) || !($PAGE->cm instanceof cm_info) || empty($PAGE->cm->id)) {
            return false;
        }
        if (!preg_match('/^mod-[a-z0-9_]+-view$/i', (string)$PAGE->pagetype)) {
            return false;
        }
        if (empty($PAGE->context) || (int)$PAGE->context->contextlevel !== CONTEXT_MODULE) {
            return false;
        }
        if ((int)$PAGE->context->instanceid !== (int)$PAGE->cm->id) {
            return false;
        }
        return true;
    }

    /**
     * Course for the current module, or null for the site home or a mismatch.
     *
     * @return stdClass|null
     */
    private function resolve_course(): ?stdClass {
        global $PAGE;

        $course = $PAGE->course ?? null;
        if (!is_object($course) || empty($course->id) || (int)$course->id === SITEID) {
            return null;
        }
        if ((int)$PAGE->cm->course !== (int)$course->id) {
            return null;
        }
        return $course;
    }

    /**
     * Course modules in on-screen order.
     *
     * The list includes modules that are not link targets so the current
     * activity can still be located. Deletion-in-progress modules are left out
     * except the current one, which remains an anchor.
     *
     * @param course_modinfo $modinfo Course module cache for the current user.
     * @return cm_info[]
     */
    private function display_sequence(course_modinfo $modinfo): array {
        global $PAGE;

        $sequence = [];
        $seen = [];
        $visitedsections = [];
        $currentid = (int)$PAGE->cm->id;
        $sections = $modinfo->get_section_info_all();

        foreach ($sections as $section) {
            if ($section->is_delegated()) {
                continue;
            }
            $this->append_section($modinfo, $section, $currentid, $sequence, $seen, $visitedsections);
        }
        foreach ($sections as $section) {
            if (!$section->is_delegated() || isset($visitedsections[(int)$section->section])) {
                continue;
            }
            $this->append_section($modinfo, $section, $currentid, $sequence, $seen, $visitedsections);
        }

        return $sequence;
    }

    /**
     * Append one section's modules, inlining any delegated subsection at the owning module.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param section_info $section Section to walk.
     * @param int $currentid Current course-module id.
     * @param cm_info[] $sequence Collected modules.
     * @param array<int, bool> $seen Course-module ids already collected.
     * @param array<int, bool> $visitedsections Section numbers already walked.
     * @return void
     */
    private function append_section(
        course_modinfo $modinfo,
        section_info $section,
        int $currentid,
        array &$sequence,
        array &$seen,
        array &$visitedsections
    ): void {
        $sectionnum = (int)$section->section;
        if (isset($visitedsections[$sectionnum])) {
            return;
        }
        $visitedsections[$sectionnum] = true;

        $sections = $modinfo->get_sections();
        $cmids = $sections[$sectionnum] ?? [];
        $cms = $modinfo->get_cms();

        foreach ($cmids as $cmid) {
            $cmid = (int)$cmid;
            if (isset($seen[$cmid]) || !isset($cms[$cmid])) {
                continue;
            }
            $seen[$cmid] = true;
            $cm = $cms[$cmid];

            if (empty($cm->deletioninprogress) || $cmid === $currentid) {
                $sequence[] = $cm;
            }

            $delegated = $modinfo->get_section_info_by_component('mod_' . $cm->modname, (int)$cm->instance);
            if ($delegated instanceof section_info) {
                $this->append_section($modinfo, $delegated, $currentid, $sequence, $seen, $visitedsections);
            }
        }
    }

    /**
     * Nearest earlier and later navigable modules around the current activity.
     *
     * @param cm_info[] $sequence Display-order modules.
     * @return array{0: cm_info|null, 1: cm_info|null} Previous and next.
     */
    private function neighbours(array $sequence): array {
        global $PAGE;

        $currentid = (int)$PAGE->cm->id;
        $index = null;
        foreach ($sequence as $position => $cm) {
            if ((int)$cm->id === $currentid) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            return [null, null];
        }

        $skip = $this->config_flag('skipnonnavigable', true);
        return [
            $this->nearest($sequence, $index, -1, $skip),
            $this->nearest($sequence, $index, 1, $skip),
        ];
    }

    /**
     * Walk away from the current activity until a module that can be linked.
     *
     * @param cm_info[] $sequence Display-order modules.
     * @param int $index Index of the current activity.
     * @param int $direction -1 for previous, 1 for next.
     * @param bool $skipnonnavigable Whether labels and modules without a view link are skipped.
     * @return cm_info|null
     */
    private function nearest(array $sequence, int $index, int $direction, bool $skipnonnavigable): ?cm_info {
        $count = count($sequence);
        for ($i = $index + $direction; $i >= 0 && $i < $count; $i += $direction) {
            if ($this->is_navigable($sequence[$i], $skipnonnavigable)) {
                return $sequence[$i];
            }
        }
        return null;
    }

    /**
     * Whether this module may be the target of an automatic previous or next link.
     *
     * Omitted when the user cannot see it, it is being deleted, or it has no URL.
     * With skip non-navigable enabled, labels, modules whose has_view() is false,
     * and modules declaring FEATURE_NO_VIEW_LINK are omitted as well.
     *
     * @param cm_info $cm Candidate module.
     * @param bool $skipnonnavigable Setting value.
     * @return bool
     */
    private function is_navigable(cm_info $cm, bool $skipnonnavigable): bool {
        if (!$cm->uservisible || !empty($cm->deletioninprogress)) {
            return false;
        }
        $url = $cm->url;
        if (!$url instanceof moodle_url) {
            return false;
        }
        if (!$skipnonnavigable) {
            return true;
        }
        if ($cm->modname === 'label' || !$cm->has_view()) {
            return false;
        }
        if (plugin_supports('mod', $cm->modname, FEATURE_NO_VIEW_LINK)) {
            return false;
        }
        return true;
    }

    /**
     * Previous or next button, from a custom URL or from the neighbour module.
     *
     * Config keys are show{direction}, {direction}label, and {direction}url.
     *
     * @param string $direction previous or next. Also the language-string key for the default label.
     * @param cm_info|null $target Automatic neighbour, when one exists.
     * @param string $classes Button classes.
     * @param bool $newtab Whether the link opens in a new tab.
     * @return stdClass|null
     */
    private function direction_button(
        string $direction,
        ?cm_info $target,
        string $classes,
        bool $newtab
    ): ?stdClass {
        if (!$this->config_flag('show' . $direction, true)) {
            return null;
        }

        $custom = $this->config_text($direction . 'url');
        if ($custom !== '') {
            $url = self::clean_link($custom);
            if ($url === null) {
                return null;
            }
            return $this->make_button($direction, $direction . 'label', $url, null, false, $classes, $newtab);
        }

        if ($target === null || !$target->url instanceof moodle_url) {
            return null;
        }

        // forceview keeps resources that would otherwise redirect to the course
        // on their own view page, which is where this bar is rendered.
        $url = new moodle_url($target->url, ['forceview' => 1]);
        return $this->make_button($direction, $direction . 'label', $url, $target, true, $classes, $newtab);
    }

    /**
     * Back to course button.
     *
     * Hidden when its checkbox is off. An empty custom URL uses the course page.
     * A rejected custom URL hides the button. A missing course hides it.
     *
     * @param stdClass $course Current course.
     * @param string $classes Button classes.
     * @param bool $newtab Whether the link opens in a new tab.
     * @return stdClass|null
     */
    private function back_to_course_button(stdClass $course, string $classes, bool $newtab): ?stdClass {
        if (!$this->config_flag('showbacktocourse', false)) {
            return null;
        }
        if (empty($course->id) || (int)$course->id === SITEID) {
            return null;
        }

        $custom = $this->config_text('backtocourseurl');
        if ($custom !== '') {
            $url = self::clean_link($custom);
            if ($url === null) {
                return null;
            }
        } else {
            $url = new moodle_url('/course/view.php', ['id' => (int)$course->id]);
        }

        return $this->make_button('backtocourse', 'backtocourselabel', $url, null, false, $classes, $newtab);
    }

    /**
     * One link for the template.
     *
     * Activity names use format_string in the module context with escape left
     * to the template. The name is appended only for an automatic neighbour
     * when includename is on. The accessible name includes the direction and
     * the activity name whenever that name is known.
     *
     * @param string $stringkey Default label language string.
     * @param string $labelsetting Config name of the label override.
     * @param moodle_url $url Destination.
     * @param cm_info|null $target Automatic target, or null for a custom URL.
     * @param bool $automatic True when the destination is a course module.
     * @param string $classes Button classes.
     * @param bool $newtab Whether the link opens in a new tab.
     * @return stdClass
     */
    private function make_button(
        string $stringkey,
        string $labelsetting,
        moodle_url $url,
        ?cm_info $target,
        bool $automatic,
        string $classes,
        bool $newtab
    ): stdClass {
        $label = $this->resolve_label($labelsetting, $stringkey);
        $activityname = null;
        if ($automatic && $target !== null) {
            $activityname = trim($target->get_formatted_name(['escape' => false]));
            if ($activityname === '') {
                $activityname = null;
            }
        }

        $appendname = $activityname !== null && $this->config_flag('includename', true);
        if ($appendname) {
            $text = get_string('withname', self::COMPONENT, (object)[
                'label' => $label,
                'name' => $activityname,
            ]);
        } else {
            $text = $label;
        }

        if ($activityname !== null && ($stringkey === 'previous' || $stringkey === 'next')) {
            $ariakey = $stringkey === 'previous' ? 'aria_previous' : 'aria_next';
            $aria = get_string($ariakey, self::COMPONENT, $activityname);
        } else {
            $aria = $text;
        }
        if ($newtab) {
            $aria .= ' (' . get_string('opensinnewtab', self::COMPONENT) . ')';
        }

        return (object)[
            'url' => $url->out(false),
            'text' => $text,
            'arialabel' => $aria,
            'classes' => $classes,
            'newtab' => $newtab,
        ];
    }

    /**
     * Label from config, or the language string when the stored value is empty.
     *
     * get_config() returns false until an administrator saves the setting.
     * Both that and a stored empty string use the language pack.
     *
     * @param string $setting Config name.
     * @param string $stringkey Language string used as the default.
     * @return string
     */
    private function resolve_label(string $setting, string $stringkey): string {
        $value = $this->config_text($setting);
        if ($value === '') {
            return get_string($stringkey, self::COMPONENT);
        }
        $clean = clean_param($value, PARAM_TEXT);
        if ($clean === '') {
            return get_string($stringkey, self::COMPONENT);
        }
        return $clean;
    }

    /**
     * Button classes, replaced with the default when unset, empty, or invalid.
     *
     * @return string
     */
    private function button_classes(): string {
        $value = get_config(self::COMPONENT, 'buttonclass');
        if ($value === false) {
            return self::DEFAULT_BUTTON_CLASS;
        }
        $value = trim((string)$value);
        if (!self::is_valid_button_class($value)) {
            return self::DEFAULT_BUTTON_CLASS;
        }
        return $value;
    }

    /**
     * Checkbox config, using $default when the setting has never been saved.
     *
     * A saved "0" stays off. Only a missing config entry uses $default.
     *
     * @param string $setting Config name.
     * @param bool $default Value before the administrator saves the form.
     * @return bool
     */
    private function config_flag(string $setting, bool $default): bool {
        $value = get_config(self::COMPONENT, $setting);
        if ($value === false) {
            return $default;
        }
        return (string)$value === '1';
    }

    /**
     * Text config, trimmed. Missing and empty values are both an empty string.
     *
     * @param string $setting Config name.
     * @return string
     */
    private function config_text(string $setting): string {
        $value = get_config(self::COMPONENT, $setting);
        if ($value === false || $value === null) {
            return '';
        }
        return trim(clean_param((string)$value, PARAM_RAW_TRIMMED));
    }
}
