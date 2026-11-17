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
 * 3. Those top-level modules are recorded before any delegated section is
 *    entered. A subsection lookup only inserts children after the owning
 *    module. If that lookup fails, the top-level modules stay where they are.
 * 4. When a module owns a delegated section and has a URL, the module is kept
 *    and the subsection modules follow it, including nested subsections.
 *    A module with no URL is not itself a target; its subsection modules are
 *    still inserted at that point.
 * 5. Delegated sections that were not reached from a parent module are appended
 *    in get_section_info_all() order so an orphaned subsection is not dropped.
 *
 * The current activity is always kept in that list, even when it would not be
 * a link target. Neighbours are chosen from the list afterwards. They are not
 * discovered by the delegated-section walk.
 *
 * Visibility. A link target must be uservisible. Modinfo already applies
 * availability, group, and hidden-section rules for the current user, including
 * guests and users without access. Activities with deletioninprogress are not
 * targets. The current activity stays in the list even when it would not
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
     * Course, site, and admin pages never call $PAGE->set_cm(), so they have no
     * $PAGE->cm and are excluded. Activity scripts usually get a pagetype of
     * mod-<modname>-view, but that string is not always exact: a module may
     * append a suffix or set a longer pagetype, and later code may replace
     * $PAGE->context after set_cm(). A prefix match is enough when a course
     * module is present. When the pagetype does not match, a valid cm_info
     * with a module context is still an activity or resource page.
     *
     * @return bool
     */
    private function is_activity_view(): bool {
        global $PAGE;

        // No course module: course page, site home, or admin.
        if (empty($PAGE->cm) || !($PAGE->cm instanceof cm_info) || empty($PAGE->cm->id)) {
            return false;
        }
        // mod-<modname>-view, including a suffix after "-view". The old
        // full-string match (and a required context instance id) missed real
        // activity and resource view pages, so the bar was never rendered.
        if (preg_match('/^mod-[a-z0-9_]+-view/i', (string)$PAGE->pagetype)) {
            return true;
        }
        return !empty($PAGE->context) && (int)$PAGE->context->contextlevel === CONTEXT_MODULE;
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
     * Non-delegated sections are copied from get_sections() before any
     * subsection is entered. The current activity is kept even when it is not
     * a link target, so neighbours() can look on either side of it. A
     * delegated-section lookup only inserts children after the owning module.
     * It is not how the current module or its neighbours are found: if the
     * lookup fails, the top-level modules are already in the list.
     *
     * @param course_modinfo $modinfo Course module cache for the current user.
     * @return cm_info[]
     */
    private function display_sequence(course_modinfo $modinfo): array {
        global $PAGE;

        $sequence = [];
        $seen = [];
        $inlined = [];
        $currentid = (int)$PAGE->cm->id;
        $cms = $modinfo->get_cms();
        $bysection = $modinfo->get_sections();
        // Indexed by course-module id. Empty when the lookup itself fails, so
        // a broken subsection delegate cannot remove the activities around it.
        $delegatedbycm = $this->delegated_sections_by_cm($modinfo);

        foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
            if ($this->section_is_delegated($section)) {
                continue;
            }
            $sectionnum = $this->resolved_section_number((int)$sectionnum, $section, $bysection);
            try {
                $this->append_section_modules(
                    $sectionnum,
                    $currentid,
                    $cms,
                    $bysection,
                    $delegatedbycm,
                    $sequence,
                    $seen,
                    $inlined
                );
            } catch (\Throwable) {
                // Modules already copied stay. Later sections are still walked.
            }
        }
        foreach ($modinfo->get_section_info_all() as $sectionnum => $section) {
            if (!$this->section_is_delegated($section)) {
                continue;
            }
            $sectionnum = $this->resolved_section_number((int)$sectionnum, $section, $bysection);
            if (isset($inlined[$sectionnum])) {
                continue;
            }
            try {
                $this->append_section_modules(
                    $sectionnum,
                    $currentid,
                    $cms,
                    $bysection,
                    $delegatedbycm,
                    $sequence,
                    $seen,
                    $inlined
                );
            } catch (\Throwable) {
                // Orphan subsections are optional. Top-level modules are already recorded.
            }
        }

        $this->ensure_current_anchor($currentid, $cms, $bysection, $sequence, $seen);
        return $sequence;
    }

    /**
     * Append one section's modules.
     *
     * Top-level modules are appended before the method follows a delegated
     * subsection, so a failed follow-up cannot drop them. A module that owns a
     * subsection is included when it has a URL (or when it is the current
     * activity); its subsection modules come next.
     *
     * @param int $sectionnum Section number (not the section id).
     * @param int $currentid Current course-module id.
     * @param cm_info[] $cms Course modules keyed by id.
     * @param array<int, int[]> $bysection Section number => cmids in sequence order.
     * @param array<int, section_info> $delegatedbycm Delegated sections keyed by owning cmid.
     * @param cm_info[] $sequence Collected modules.
     * @param array<int, bool> $seen Course-module ids already considered.
     * @param array<int, bool> $inlined Section numbers already walked.
     * @return void
     */
    private function append_section_modules(
        int $sectionnum,
        int $currentid,
        array $cms,
        array $bysection,
        array $delegatedbycm,
        array &$sequence,
        array &$seen,
        array &$inlined
    ): void {
        if (isset($inlined[$sectionnum])) {
            return;
        }
        $inlined[$sectionnum] = true;

        $cmids = $this->section_cmids($bysection, $sectionnum);
        foreach ($cmids as $cmid) {
            if (isset($seen[$cmid]) || !isset($cms[$cmid])) {
                continue;
            }
            $cm = $cms[$cmid];
            $owned = $this->owned_delegated_section($delegatedbycm, $cmid);
            $seen[$cmid] = true;
            if ($this->keep_module($cm, $cmid === $currentid, $owned)) {
                $sequence[] = $cm;
            }
            if (!$owned instanceof section_info) {
                continue;
            }
            $childnum = $this->section_number($owned);
            if ($childnum === null || isset($inlined[$childnum])) {
                continue;
            }
            try {
                $this->append_section_modules(
                    $childnum,
                    $currentid,
                    $cms,
                    $bysection,
                    $delegatedbycm,
                    $sequence,
                    $seen,
                    $inlined
                );
            } catch (\Throwable) {
                // The parent modules are already recorded. Leave this section
                // unmarked so the orphan pass can still append its modules.
                unset($inlined[$childnum]);
            }
        }
    }

    /**
     * Course-module ids for a section number, in sequence order.
     *
     * get_sections() is keyed by section number. A numeric string key is the
     * same entry. An empty result is an empty section, not a reason to drop
     * other sections.
     *
     * @param array<int, int[]> $bysection Section number => cmids.
     * @param int $sectionnum Section number.
     * @return int[]
     */
    private function section_cmids(array $bysection, int $sectionnum): array {
        $cmids = $bysection[$sectionnum] ?? [];
        if (!is_array($cmids)) {
            return [];
        }
        $ids = [];
        foreach ($cmids as $cmid) {
            $ids[] = (int)$cmid;
        }
        return $ids;
    }

    /**
     * Whether this section is delegated to a component.
     *
     * A failure here is treated as not delegated, so the section's own modules
     * stay in the top-level pass instead of being skipped.
     *
     * @param section_info $section Section from get_section_info_all().
     * @return bool
     */
    private function section_is_delegated(section_info $section): bool {
        if (!method_exists($section, 'is_delegated')) {
            return false;
        }
        try {
            return (bool)$section->is_delegated();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Delegated sections keyed by the course-module that owns them.
     *
     * This is get_sections_delegated_by_cm(), keyed by course-module id.
     * Looking up get_section_info_by_component('mod_' . modname, instance) for
     * every module, then walking that section with the same visited-section
     * guard as top-level sections, could mark a section visited and skip its
     * modules. An empty map only skips inlining. Top-level modules are already
     * recorded by the caller.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @return array<int, section_info>
     */
    private function delegated_sections_by_cm(course_modinfo $modinfo): array {
        if (!method_exists($modinfo, 'get_sections_delegated_by_cm')) {
            return [];
        }
        try {
            $map = $modinfo->get_sections_delegated_by_cm();
        } catch (\Throwable) {
            return [];
        }
        return is_array($map) ? $map : [];
    }

    /**
     * Delegated section owned by this course module, if any.
     *
     * @param array<int, section_info> $delegatedbycm Map from delegated_sections_by_cm().
     * @param int $cmid Course-module id.
     * @return section_info|null
     */
    private function owned_delegated_section(array $delegatedbycm, int $cmid): ?section_info {
        if (!isset($delegatedbycm[$cmid]) || !$delegatedbycm[$cmid] instanceof section_info) {
            return null;
        }
        $section = $delegatedbycm[$cmid];
        if (!$this->section_is_delegated($section)) {
            return null;
        }
        return $section;
    }

    /**
     * Section number to use with get_sections().
     *
     * get_section_info_all() is keyed by section number. When that key has no
     * modules and the section's own number does, the property is used. An
     * empty section stays empty; other sections are unaffected.
     *
     * @param int $key Key from get_section_info_all().
     * @param section_info $section Section at that key.
     * @param array<int, int[]> $bysection Section number => cmids.
     * @return int
     */
    private function resolved_section_number(int $key, section_info $section, array $bysection): int {
        if ($this->section_cmids($bysection, $key) !== []) {
            return $key;
        }
        $fromproperty = $this->section_number($section);
        if ($fromproperty !== null && $fromproperty !== $key && $this->section_cmids($bysection, $fromproperty) !== []) {
            return $fromproperty;
        }
        return $key;
    }

    /**
     * Section number for a section_info, or null when it cannot be read.
     *
     * @param section_info $section Section to read.
     * @return int|null
     */
    private function section_number(section_info $section): ?int {
        try {
            return (int)$section->section;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Whether this module belongs in the display sequence.
     *
     * The current activity is always kept, including when it is not uservisible
     * or has no URL, so it can anchor the previous and next search. Other
     * modules being deleted are left out. A module that owns a subsection is
     * kept when it has a URL; without one it is only a container, and the
     * subsection modules are still added by the caller.
     *
     * @param cm_info $cm Candidate module.
     * @param bool $iscurrent True when this is the activity being viewed.
     * @param section_info|null $owned Delegated section owned by this module.
     * @return bool
     */
    private function keep_module(cm_info $cm, bool $iscurrent, ?section_info $owned): bool {
        if ($iscurrent) {
            return true;
        }
        if (!empty($cm->deletioninprogress)) {
            return false;
        }
        if ($owned instanceof section_info && !$this->has_link_url($cm)) {
            return false;
        }
        return true;
    }

    /**
     * Whether the module has a view URL a button could open.
     *
     * This is not the full navigable check. Availability and the skip setting
     * are applied later, when a neighbour is chosen.
     *
     * @param cm_info $cm Candidate module.
     * @return bool
     */
    private function has_link_url(cm_info $cm): bool {
        try {
            return $cm->url instanceof moodle_url;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Put the current activity into the sequence when the section walk missed it.
     *
     * get_sections() is the course order for modules the cache lists. The
     * activity open in the browser can be absent from that list, or it can
     * fail every link-target check, and the previous and next modules are
     * still on either side. Neighbour search needs this anchor. Placement
     * follows the section sequence when the cmid is there, otherwise the
     * section number.
     *
     * @param int $currentid Current course-module id.
     * @param cm_info[] $cms Course modules keyed by id.
     * @param array<int, int[]> $bysection Section number => cmids.
     * @param cm_info[] $sequence Collected modules.
     * @param array<int, bool> $seen Course-module ids already considered.
     * @return void
     */
    private function ensure_current_anchor(
        int $currentid,
        array $cms,
        array $bysection,
        array &$sequence,
        array &$seen
    ): void {
        // Seen is not enough: a container module can be considered and left out
        // of the list. The anchor is the current id actually present in it.
        if ($this->sequence_index($sequence, $currentid) !== null) {
            $seen[$currentid] = true;
            return;
        }
        $cm = $cms[$currentid] ?? null;
        if (!$cm instanceof cm_info) {
            global $PAGE;
            if (($PAGE->cm instanceof cm_info) && (int)$PAGE->cm->id === $currentid) {
                $cm = $PAGE->cm;
            } else {
                return;
            }
        }

        $sectionnum = null;
        try {
            $sectionnum = (int)$cm->sectionnum;
        } catch (\Throwable) {
            $sectionnum = null;
        }

        $insertat = $this->anchor_index($sequence, $bysection, $currentid, $sectionnum);
        array_splice($sequence, $insertat, 0, [$cm]);
        $seen[$currentid] = true;
    }

    /**
     * Index at which the current activity should be inserted.
     *
     * @param cm_info[] $sequence Modules already collected.
     * @param array<int, int[]> $bysection Section number => cmids.
     * @param int $currentid Current course-module id.
     * @param int|null $sectionnum Section number of the current activity.
     * @return int
     */
    private function anchor_index(array $sequence, array $bysection, int $currentid, ?int $sectionnum): int {
        if ($sectionnum !== null) {
            $cmids = $this->section_cmids($bysection, $sectionnum);
            $pos = array_search($currentid, $cmids, true);
            if ($pos !== false) {
                for ($i = $pos - 1; $i >= 0; $i--) {
                    $idx = $this->sequence_index($sequence, $cmids[$i]);
                    if ($idx !== null) {
                        return $idx + 1;
                    }
                }
                for ($i = $pos + 1, $count = count($cmids); $i < $count; $i++) {
                    $idx = $this->sequence_index($sequence, $cmids[$i]);
                    if ($idx !== null) {
                        return $idx;
                    }
                }
            }
            foreach ($sequence as $i => $existing) {
                try {
                    $existingnum = (int)$existing->sectionnum;
                } catch (\Throwable) {
                    continue;
                }
                if ($existingnum > $sectionnum) {
                    return $i;
                }
            }
        }
        return count($sequence);
    }

    /**
     * Position of a course module in the sequence, or null when it is absent.
     *
     * @param cm_info[] $sequence Collected modules.
     * @param int $cmid Course-module id.
     * @return int|null
     */
    private function sequence_index(array $sequence, int $cmid): ?int {
        foreach ($sequence as $i => $existing) {
            if ((int)$existing->id === $cmid) {
                return $i;
            }
        }
        return null;
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
