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
 * Display order is the order a student meets activities on the course page.
 * get_cms() is already that order of appearance, and it is the list neighbours
 * are chosen from. A section walk may replace it only when the walk still
 * contains every one of those course modules.
 *
 * get_sections() is keyed by section number. $cm->sectionnum and
 * $section->section are that number. $cm->section and $section->id are the
 * section id and are never used as a key. Within a section the cm list is
 * section_info::get_sequence_cm_infos(), which reads get_sections() by the
 * section number. Delegated subsections are inlined after the owning cm.
 * The current cm stays in the list even when it is not a link target.
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

    /**
     * Code version printed in the on-page diagnostic.
     * Keep in step with $plugin->version in version.php.
     */
    public const VERSION = 2026111704;

    /** @var int Longest accepted custom URL. */
    private const MAX_URL_LENGTH = 2000;

    /** @var array<int, int[]>|null get_sections() keyed by an integer section number. */
    private ?array $groupedsections = null;

    /** @var string[] Raw get_sections() keys, for the diagnostic. */
    private array $rawsectionkeys = [];

    /** @var int Sections whose cm list was read with the section number. */
    private int $sectionnumberhits = 0;

    /** @var int Sections whose cm list was read with the section id. Always 0: the id is not a key. */
    private int $sectionidfallbacks = 0;

    /** @var string How the sequence list was chosen. */
    private string $sequencesource = '';

    /** @var string Why a section walk was not used, or a walk failure. */
    private string $sequencenote = '';

    /** @var string Failure before buttons could be chosen. Empty when none. */
    private string $builderror = '';

    /** @var int[] Section numbers that had no cm list under either key. */
    private array $unmatchedsections = [];

    /** @var int[] Section numbers walked, in walk order. */
    private array $walkednumbers = [];

    /** @var int Delegated sections in the course. */
    private int $delegatedsectioncount = 0;

    /** @var int Times a delegated section was inlined after its owner. */
    private int $delegatedinlined = 0;

    /** @var string How the delegated-section map was built. */
    private string $delegatednote = '';

    /** @var int[] Cm ids added because the section walk missed them. */
    private array $recoveredcmids = [];

    /** @var bool True when the current cm was inserted after the section walk. */
    private bool $insertedcurrent = false;

    /** @var bool True when the section walk already contained the current cm. */
    private bool $currentinwalk = false;

    /** @var bool True when the finished sequence contains the current cm. */
    private bool $currentfound = false;

    /** @var int|null Index of the current cm in the finished sequence. */
    private ?int $currentindex = null;

    /**
     * Build the template data for this page, or null when nothing should render.
     *
     * Uses one get_fast_modinfo() call. Returns null when the master switch is
     * off or the page is not an activity view. On an activity view, a debug
     * setting that is on (the default, including when it has never been saved)
     * always returns the bar. The diagnostic is attached before that return,
     * including when previous, next, and back are all omitted and when course
     * or sequence lookup fails.
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

        // Read this before any lookup that can fail. Default on, so an activity
        // page still gets the diagnostic when the setting has never been saved.
        $debugon = $this->config_flag('debug', true);
        $this->reset_trace();

        $course = null;
        $modinfo = null;
        $sequence = [];
        $previous = ['button' => null, 'summary' => 'previous: omitted: not built'];
        $next = ['button' => null, 'summary' => 'next: omitted: not built'];
        $back = null;

        try {
            $course = $this->resolve_course();
            if ($course === null) {
                $this->builderror = 'course not resolved';
            } else {
                try {
                    $modinfo = get_fast_modinfo($course);
                } catch (\Throwable $e) {
                    $modinfo = null;
                    $this->builderror = 'get_fast_modinfo failed: ' . $this->one_line($e->getMessage(), 240);
                }
            }
            if ($modinfo instanceof course_modinfo) {
                $sequence = $this->display_sequence($modinfo);
                [$previouscm, $nextcm] = $this->neighbours($sequence);
                $classes = $this->button_classes();
                $newtab = $this->config_flag('newtab', false);
                $previous = $this->resolve_direction('previous', $previouscm, $classes, $newtab, $sequence);
                $next = $this->resolve_direction('next', $nextcm, $classes, $newtab, $sequence);
                $back = $this->back_to_course_button($course, $classes, $newtab);
            }
        } catch (\Throwable $e) {
            $this->builderror = $this->one_line(
                get_class($e) . ': ' . $e->getMessage(),
                300
            );
        }

        $missingdirection = $previous['button'] === null || $next['button'] === null;
        $debugtext = null;
        if ($debugon || $missingdirection) {
            try {
                $debugtext = $this->debug_text(
                    $modinfo instanceof course_modinfo ? $modinfo : null,
                    $sequence,
                    $previous['summary'],
                    $next['summary']
                );
            } catch (\Throwable $e) {
                $debugtext = $this->minimal_debug($e);
            }
        }
        if ($debugon && ($debugtext === null || $debugtext === '')) {
            $debugtext = $this->minimal_debug(null);
        }

        // Debug on: do not return null. The box has to be in the HTML even when
        // every button was omitted. A missing direction also keeps the box.
        if ($previous['button'] === null && $next['button'] === null && $back === null
                && ($debugtext === null || $debugtext === '')) {
            return null;
        }

        try {
            $label = get_string('navigation', self::COMPONENT);
        } catch (\Throwable $e) {
            $label = 'Activity navigation';
        }
        $data = (object)[
            'navigationlabel' => $label,
        ];
        if ($previous['button'] !== null) {
            $data->previous = $previous['button'];
        }
        if ($next['button'] !== null) {
            $data->next = $next['button'];
        }
        if ($back !== null) {
            $data->backtocourse = $back;
        }
        if ($debugtext !== null && $debugtext !== '') {
            $data->debugtext = $debugtext;
        }
        return $data;
    }

    /**
     * Plain diagnostic when the full one cannot be built.
     *
     * The template escapes this. It is not written to the error log.
     *
     * @param \Throwable|null $e Failure, if one was caught.
     * @return string
     */
    private function minimal_debug(?\Throwable $e): string {
        global $PAGE;

        $cmid = 0;
        if (!empty($PAGE->cm) && isset($PAGE->cm->id)) {
            $cmid = (int)$PAGE->cm->id;
        }
        $lines = [
            'plugin version: ' . self::VERSION,
            'pagetype: ' . (string)($PAGE->pagetype ?? ''),
            'cmid: ' . $cmid,
            'sequence count: 0',
            'current index: none',
            'showprevious: ' . $this->flag_status('showprevious', true),
            'shownext: ' . $this->flag_status('shownext', true),
            'previousurl: ' . $this->url_status('previousurl'),
            'nexturl: ' . $this->url_status('nexturl'),
            'previous: omitted: not built',
            'next: omitted: not built',
        ];
        if ($this->builderror !== '') {
            $lines[] = 'build error: ' . $this->builderror;
        }
        if ($e !== null) {
            $lines[] = 'diagnostic failed: ' . $this->one_line(get_class($e) . ': ' . $e->getMessage(), 300);
        }
        return implode("\n", $lines);
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
     * The list starts as get_cms(), which Moodle builds in order of appearance
     * from each section's sequence. That list is what neighbours are chosen
     * from. A section-number walk may replace it only when every course module
     * is still present. Looking up get_sections() by $cm->section (the section
     * id) used to drop the other activities, so previous and next were null.
     * The section id is not a key. Deletion-in-progress modules are left out
     * except the current one, which remains an anchor.
     *
     * @param course_modinfo $modinfo Course module cache for the current user.
     * @return cm_info[]
     */
    private function display_sequence(course_modinfo $modinfo): array {
        global $PAGE;

        $currentid = (int)$PAGE->cm->id;
        $sequence = [];
        $seen = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm instanceof cm_info) {
                continue;
            }
            $cmid = (int)$cm->id;
            if ($cmid <= 0 || isset($seen[$cmid])) {
                continue;
            }
            if (!empty($cm->deletioninprogress) && $cmid !== $currentid) {
                continue;
            }
            $sequence[] = $cm;
            $seen[$cmid] = true;
        }
        $this->sequencesource = 'get_cms order of appearance';
        $this->currentinwalk = isset($seen[$currentid]);

        try {
            $ordered = $this->ordered_from_sections($modinfo, $currentid);
            if ($ordered !== [] && $this->covers($ordered, $sequence)) {
                $sequence = $ordered;
                $seen = [];
                foreach ($sequence as $cm) {
                    $seen[(int)$cm->id] = true;
                }
                $this->sequencesource = 'section number order';
            } else if ($ordered !== []) {
                $missing = $this->missing_ids($ordered, $sequence);
                $this->recoveredcmids = $missing;
                $this->sequencenote = 'section walk dropped cmid ' . $this->number_list($missing) .
                    '; kept get_cms order';
            } else if ($sequence !== []) {
                $this->sequencenote = 'section walk was empty; kept get_cms order';
            }
        } catch (\Throwable $e) {
            $this->sequencenote = 'section walk failed; kept get_cms order: ' .
                $this->one_line(get_class($e) . ': ' . $e->getMessage(), 200);
        }

        $this->ensure_current_cm($modinfo, $currentid, $sequence, $seen);
        return $sequence;
    }

    /**
     * Whether $ordered still contains every course module in $required.
     *
     * @param cm_info[] $ordered Candidate display order.
     * @param cm_info[] $required Modules that must not be dropped.
     * @return bool
     */
    private function covers(array $ordered, array $required): bool {
        return $this->missing_ids($ordered, $required) === [];
    }

    /**
     * Ids from $required that are absent from $ordered.
     *
     * @param cm_info[] $ordered Candidate display order.
     * @param cm_info[] $required Modules that must not be dropped.
     * @return int[]
     */
    private function missing_ids(array $ordered, array $required): array {
        $have = [];
        foreach ($ordered as $cm) {
            $have[(int)$cm->id] = true;
        }
        $missing = [];
        foreach ($required as $cm) {
            $cmid = (int)$cm->id;
            if (!isset($have[$cmid])) {
                $missing[] = $cmid;
            }
        }
        return $missing;
    }

    /**
     * Sections in display order, with delegated subsections inlined after the owner.
     *
     * Cm lists come from get_sequence_cm_infos() or from get_sections() keyed by
     * the section number. The section id is not consulted.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param int $currentid Current course-module id.
     * @return cm_info[]
     */
    private function ordered_from_sections(course_modinfo $modinfo, int $currentid): array {
        $sequence = [];
        $seen = [];
        $visitedsections = [];
        $delegatedbycm = $this->delegated_sections_by_cm($modinfo);
        $sections = $modinfo->get_section_info_all();

        $this->delegatedsectioncount = 0;
        foreach ($sections as $key => $section) {
            if (!$section instanceof section_info) {
                continue;
            }
            if ($this->section_is_delegated($section)) {
                $this->delegatedsectioncount++;
            }
        }

        foreach ($sections as $key => $section) {
            if (!$section instanceof section_info || $this->section_is_delegated($section)) {
                continue;
            }
            $this->append_section(
                $modinfo,
                $section,
                $key,
                $currentid,
                $sequence,
                $seen,
                $visitedsections,
                $delegatedbycm
            );
        }
        foreach ($sections as $key => $section) {
            if (!$section instanceof section_info || !$this->section_is_delegated($section)) {
                continue;
            }
            $number = $this->section_number($section, $key);
            if ($number !== null && isset($visitedsections[$number])) {
                continue;
            }
            $this->append_section(
                $modinfo,
                $section,
                $key,
                $currentid,
                $sequence,
                $seen,
                $visitedsections,
                $delegatedbycm
            );
        }
        return $sequence;
    }

    /**
     * Append one section's modules, then inline a delegated subsection after its owner.
     *
     * The owner is added before its subsection. The cm list is the section's
     * sequence for its section number, not for its section id.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param section_info $section Section to walk.
     * @param int|string $key Key from get_section_info_all(), normally the section number.
     * @param int $currentid Current course-module id.
     * @param cm_info[] $sequence Collected modules.
     * @param array<int, bool> $seen Course-module ids already collected.
     * @param array<int, bool> $visitedsections Section numbers already walked.
     * @param array<int, section_info> $delegatedbycm Delegated section by owning cmid.
     * @return void
     */
    private function append_section(
        course_modinfo $modinfo,
        section_info $section,
        int|string $key,
        int $currentid,
        array &$sequence,
        array &$seen,
        array &$visitedsections,
        array $delegatedbycm
    ): void {
        $sectionnum = $this->section_number($section, $key);
        if ($sectionnum === null) {
            $this->unmatchedsections[] = -1;
            return;
        }
        if (isset($visitedsections[$sectionnum])) {
            return;
        }
        $visitedsections[$sectionnum] = true;
        $this->walkednumbers[] = $sectionnum;

        foreach ($this->section_cms($modinfo, $section, $sectionnum) as $cm) {
            $cmid = (int)$cm->id;
            if ($cmid <= 0 || isset($seen[$cmid])) {
                continue;
            }
            $seen[$cmid] = true;
            if (empty($cm->deletioninprogress) || $cmid === $currentid) {
                $sequence[] = $cm;
            }

            if (!isset($delegatedbycm[$cmid]) || !$delegatedbycm[$cmid] instanceof section_info) {
                continue;
            }
            $this->delegatedinlined++;
            $this->append_section(
                $modinfo,
                $delegatedbycm[$cmid],
                $this->section_number($delegatedbycm[$cmid], $sectionnum) ?? $sectionnum,
                $currentid,
                $sequence,
                $seen,
                $visitedsections,
                $delegatedbycm
            );
        }
    }

    /**
     * Course modules in one section, in sequence order.
     *
     * get_sequence_cm_infos() reads get_sections() with the section number.
     * The same map is used directly when that method is missing or throws.
     * $cm->section, the section id, is not a key and is not tried.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param section_info $section Section being walked.
     * @param int $sectionnum Section number.
     * @return cm_info[]
     */
    private function section_cms(course_modinfo $modinfo, section_info $section, int $sectionnum): array {
        if (method_exists($section, 'get_sequence_cm_infos')) {
            try {
                $cms = $section->get_sequence_cm_infos();
                if (is_array($cms)) {
                    $this->sectionnumberhits++;
                    $list = [];
                    foreach ($cms as $cm) {
                        if ($cm instanceof cm_info) {
                            $list[] = $cm;
                        }
                    }
                    return $list;
                }
            } catch (\Throwable $e) {
                $this->sequencenote = 'get_sequence_cm_infos failed: ' .
                    $this->one_line($e->getMessage(), 160);
            }
        }

        $grouped = $this->grouped_sections($modinfo);
        if (array_key_exists($sectionnum, $grouped)) {
            $this->sectionnumberhits++;
            return $this->cms_from_ids($modinfo, $grouped[$sectionnum]);
        }

        $this->unmatchedsections[] = $sectionnum;
        return [];
    }

    /**
     * Section number for get_sections(), or null when it cannot be read.
     *
     * $section->section is the number. The array key from get_section_info_all()
     * is the same number and is used when the property cannot be read. The
     * section id is not returned.
     *
     * @param section_info $section Section to read.
     * @param int|string $key Key that accompanied the section.
     * @return int|null
     */
    private function section_number(section_info $section, int|string $key): ?int {
        try {
            $number = $section->section;
            if (is_int($number) || (is_string($number) && is_numeric($number))) {
                return (int)$number;
            }
        } catch (\Throwable $e) {
            $this->sequencenote = 'section number unreadable: ' . $this->one_line($e->getMessage(), 120);
        }
        $keytext = (string)$key;
        if ($keytext !== '' && ctype_digit($keytext)) {
            return (int)$keytext;
        }
        return null;
    }

    /**
     * Whether this section is delegated to a component.
     *
     * A missing method or a failure is treated as not delegated, so the
     * section's own modules stay in the top-level pass.
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
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Course modules for a list of ids from get_sections().
     *
     * Ids may be ints or numeric strings. Anything else is ignored.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param array $cmids Values from one get_sections() entry.
     * @return cm_info[]
     */
    private function cms_from_ids(course_modinfo $modinfo, array $cmids): array {
        $cms = $modinfo->get_cms();
        $list = [];
        foreach ($this->normalise_cmids($cmids) as $cmid) {
            if (isset($cms[$cmid]) && $cms[$cmid] instanceof cm_info) {
                $list[] = $cms[$cmid];
            }
        }
        return $list;
    }

    /**
     * get_sections() with integer keys.
     *
     * Numeric string keys are cast to int, which is how PHP stores them.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @return array<int, array>
     */
    private function grouped_sections(course_modinfo $modinfo): array {
        if ($this->groupedsections !== null) {
            return $this->groupedsections;
        }

        $raw = $modinfo->get_sections();
        $grouped = [];
        $this->rawsectionkeys = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $cmids) {
                $this->rawsectionkeys[] = (string)$key;
                $keytext = (string)$key;
                if ($keytext !== '' && ctype_digit($keytext)) {
                    $grouped[(int)$keytext] = is_array($cmids) ? $cmids : [];
                }
            }
        }
        $this->groupedsections = $grouped;
        return $grouped;
    }

    /**
     * Keep numeric course-module ids.
     *
     * @param array $cmids Values from one get_sections() entry.
     * @return int[]
     */
    private function normalise_cmids(array $cmids): array {
        $ids = [];
        foreach ($cmids as $cmid) {
            if ($cmid instanceof cm_info) {
                $cmid = $cmid->id;
            }
            if (is_int($cmid) || (is_string($cmid) && ctype_digit($cmid))) {
                $ids[] = (int)$cmid;
            }
        }
        return $ids;
    }

    /**
     * Delegated section for each owning course module.
     *
     * get_sections_delegated_by_cm() is the modinfo map (cm id => section).
     * The component-and-instance lookup is only used when that method is missing
     * or throws, so a normal activity is not treated as the owner of a section.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @return array<int, section_info>
     */
    private function delegated_sections_by_cm(course_modinfo $modinfo): array {
        if (method_exists($modinfo, 'get_sections_delegated_by_cm')) {
            try {
                $map = $modinfo->get_sections_delegated_by_cm();
                if (is_array($map)) {
                    $bycm = [];
                    foreach ($map as $cmid => $section) {
                        if ($section instanceof section_info) {
                            $bycm[(int)$cmid] = $section;
                        }
                    }
                    $this->delegatednote = 'get_sections_delegated_by_cm (' . count($bycm) . ' owners)';
                    return $bycm;
                }
                $this->delegatednote = 'get_sections_delegated_by_cm returned no map';
            } catch (\Throwable $e) {
                $this->delegatednote = 'get_sections_delegated_by_cm failed: ' .
                    $this->one_line($e->getMessage(), 160);
            }
        } else {
            $this->delegatednote = 'get_sections_delegated_by_cm missing';
        }

        return $this->delegated_sections_by_component($modinfo);
    }

    /**
     * Owning cm to delegated section, matched by component and instance.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @return array<int, section_info>
     */
    private function delegated_sections_by_component(course_modinfo $modinfo): array {
        $bycm = [];
        foreach ($modinfo->get_cms() as $cm) {
            try {
                $delegated = $modinfo->get_section_info_by_component(
                    'mod_' . $cm->modname,
                    (int)$cm->instance
                );
            } catch (\Throwable $e) {
                continue;
            }
            if ($delegated instanceof section_info) {
                $bycm[(int)$cm->id] = $delegated;
            }
        }
        $suffix = 'component lookup (' . count($bycm) . ' owners)';
        if ($this->delegatednote === '') {
            $this->delegatednote = $suffix;
        } else {
            $this->delegatednote .= '; ' . $suffix;
        }
        return $bycm;
    }

    /**
     * Insert the current course module when it is still absent.
     *
     * @param course_modinfo $modinfo Course module cache.
     * @param int $currentid Current course-module id.
     * @param cm_info[] $sequence Collected modules.
     * @param array<int, bool> $seen Course-module ids already collected.
     * @return void
     */
    private function ensure_current_cm(
        course_modinfo $modinfo,
        int $currentid,
        array &$sequence,
        array &$seen
    ): void {
        if (isset($seen[$currentid])) {
            return;
        }
        global $PAGE;

        $cm = null;
        if (isset($modinfo->get_cms()[$currentid])) {
            $cm = $modinfo->get_cms()[$currentid];
        } else if ($PAGE->cm instanceof cm_info && (int)$PAGE->cm->id === $currentid) {
            $cm = $PAGE->cm;
        }
        if (!$cm instanceof cm_info) {
            return;
        }
        $this->insert_cm($sequence, $cm);
        $seen[$currentid] = true;
        $this->insertedcurrent = true;
    }

    /**
     * Place a course module with others that share its section number.
     *
     * @param cm_info[] $sequence Collected modules.
     * @param cm_info $cm Module to insert.
     * @return void
     */
    private function insert_cm(array &$sequence, cm_info $cm): void {
        $sectionnum = (int)$cm->sectionnum;
        $at = count($sequence);
        $lastsame = null;
        foreach ($sequence as $i => $existing) {
            $existingnum = (int)$existing->sectionnum;
            if ($existingnum === $sectionnum) {
                $lastsame = $i;
                continue;
            }
            if ($lastsame === null && $existingnum > $sectionnum && $at === count($sequence)) {
                $at = $i;
            }
        }
        if ($lastsame !== null) {
            $at = $lastsame + 1;
        }
        array_splice($sequence, $at, 0, [$cm]);
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
        $this->currentfound = $index !== null;
        $this->currentindex = $index;
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
        return $this->navigable_block($cm, $skipnonnavigable) === null;
    }

    /**
     * Why a module cannot be an automatic previous or next target.
     *
     * Null means the module can be linked. The returned phrase is one of
     * "not uservisible", "no url", "skipped non-navigable", or
     * "deletion in progress". "no url" may include the value's type.
     *
     * @param cm_info $cm Candidate module.
     * @param bool $skipnonnavigable Setting value.
     * @return string|null
     */
    private function navigable_block(cm_info $cm, bool $skipnonnavigable): ?string {
        try {
            if (!empty($cm->deletioninprogress)) {
                return 'deletion in progress';
            }
            if (!$cm->uservisible) {
                return 'not uservisible';
            }
            if ($this->activity_url($cm) === null) {
                return 'no url' . $this->url_type_suffix($cm);
            }
            if ($skipnonnavigable && $this->is_nonnavigable_type($cm)) {
                return 'skipped non-navigable';
            }
        } catch (\Throwable $e) {
            return 'lookup failed (' . $this->one_line($e->getMessage(), 80) . ')';
        }
        return null;
    }

    /**
     * Whether skip-non-navigable should pass over this module even though it has a URL.
     *
     * @param cm_info $cm Candidate module.
     * @return bool
     */
    private function is_nonnavigable_type(cm_info $cm): bool {
        if ($cm->modname === 'label') {
            return true;
        }
        if (method_exists($cm, 'has_view') && !$cm->has_view()) {
            return true;
        }
        return (bool)plugin_supports('mod', $cm->modname, FEATURE_NO_VIEW_LINK);
    }

    /**
     * Link for a course module, including a URL object that is not moodle_url.
     *
     * Moodle 4.5 stores a moodle_url. Later releases alias that class. An object
     * with out() is still a link; treating it as "no url" hid every neighbour.
     *
     * @param cm_info $cm Candidate module.
     * @return moodle_url|null
     */
    private function activity_url(cm_info $cm): ?moodle_url {
        $url = $cm->url;
        if ($url instanceof moodle_url) {
            return $url;
        }
        if (!is_object($url) || !method_exists($url, 'out')) {
            return null;
        }
        try {
            $text = $url->out(false);
        } catch (moodle_exception) {
            return null;
        }
        if (!is_string($text) || $text === '') {
            return null;
        }
        try {
            return new moodle_url($text);
        } catch (moodle_exception) {
            return null;
        }
    }

    /**
     * Extra text after "no url" when the value is present but not a link.
     *
     * @param cm_info $cm Candidate module.
     * @return string
     */
    private function url_type_suffix(cm_info $cm): string {
        $url = $cm->url;
        if ($url === null || $url === '' || $url === false) {
            return '';
        }
        if (is_object($url)) {
            return ' (' . get_class($url) . ')';
        }
        return ' (' . gettype($url) . ')';
    }

    /**
     * Previous or next button, and the diagnostic line for that direction.
     *
     * The show checkbox is honoured: a saved value other than "1" hides the
     * button. A non-empty custom URL that fails validation hides the button
     * and does not fall back to the neighbour. The summary still names the
     * neighbour or the omit reason.
     *
     * @param string $direction previous or next.
     * @param cm_info|null $target Automatic neighbour, when one exists.
     * @param string $classes Button classes.
     * @param bool $newtab Whether the link opens in a new tab.
     * @param cm_info[] $sequence Display-order modules.
     * @return array{button: stdClass|null, summary: string}
     */
    private function resolve_direction(
        string $direction,
        ?cm_info $target,
        string $classes,
        bool $newtab,
        array $sequence
    ): array {
        $way = $direction === 'previous' ? -1 : 1;
        $automatic = $this->automatic_summary($target, $sequence, $way);

        if (!$this->config_flag('show' . $direction, true)) {
            $raw = get_config(self::COMPONENT, 'show' . $direction);
            $saved = $raw === false ? 'not saved' : 'saved "' . $this->one_line(trim((string)$raw), 40) . '"';
            return [
                'button' => null,
                'summary' => $direction . ': omitted: show checkbox off (' . $saved . '); ' . $automatic,
            ];
        }

        $custom = $this->config_text($direction . 'url');
        if ($custom !== '') {
            $url = self::clean_link($custom);
            if ($url === null) {
                return [
                    'button' => null,
                    'summary' => $direction . ': omitted: invalid custom URL; ' . $automatic,
                ];
            }
            return [
                'button' => $this->make_button($direction, $direction . 'label', $url, null, false, $classes, $newtab),
                'summary' => $direction . ': custom URL; ' . $automatic,
            ];
        }

        $activityurl = $target === null ? null : $this->activity_url($target);
        if ($target === null || $activityurl === null) {
            $reason = $target === null
                ? $this->omit_reason($sequence, $this->currentindex, $way)
                : 'no url' . ($target === null ? '' : $this->url_type_suffix($target));
            return [
                'button' => null,
                'summary' => $direction . ': omitted: ' . $reason,
            ];
        }

        // forceview keeps resources that would otherwise redirect to the course
        // on their own view page, which is where this bar is rendered.
        $url = new moodle_url($activityurl, ['forceview' => 1]);
        $skipped = $this->skipped_notes($sequence, $this->currentindex, $way, $target);
        $extra = $skipped === '' ? '' : '; ' . $skipped;
        return [
            'button' => $this->make_button($direction, $direction . 'label', $url, $target, true, $classes, $newtab),
            'summary' => $direction . ': cmid ' . (int)$target->id . ' modname ' . $target->modname . $extra,
        ];
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
            try {
                $activityname = trim($target->get_formatted_name(['escape' => false]));
            } catch (\Throwable $e) {
                $activityname = '';
            }
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
        return trim((string)$value) === '1';
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

    /**
     * Clear sequence facts from an earlier build on this object.
     *
     * @return void
     */
    private function reset_trace(): void {
        $this->groupedsections = null;
        $this->rawsectionkeys = [];
        $this->sectionnumberhits = 0;
        $this->sectionidfallbacks = 0;
        $this->sequencesource = '';
        $this->sequencenote = '';
        $this->builderror = '';
        $this->unmatchedsections = [];
        $this->walkednumbers = [];
        $this->delegatedsectioncount = 0;
        $this->delegatedinlined = 0;
        $this->delegatednote = '';
        $this->recoveredcmids = [];
        $this->insertedcurrent = false;
        $this->currentinwalk = false;
        $this->currentfound = false;
        $this->currentindex = null;
    }

    /**
     * Diagnostic description of the automatic neighbour, ignoring the checkbox and custom URL.
     *
     * @param cm_info|null $target Automatic neighbour, when one exists.
     * @param cm_info[] $sequence Display-order modules.
     * @param int $way -1 for previous, 1 for next.
     * @return string
     */
    private function automatic_summary(?cm_info $target, array $sequence, int $way): string {
        if ($target !== null && $this->activity_url($target) !== null) {
            $skipped = $this->skipped_notes($sequence, $this->currentindex, $way, $target);
            $extra = $skipped === '' ? '' : '; ' . $skipped;
            return 'automatic cmid ' . (int)$target->id . ' modname ' . $target->modname . $extra;
        }
        $reason = $target === null
            ? $this->omit_reason($sequence, $this->currentindex, $way)
            : 'no url' . $this->url_type_suffix($target);
        return 'automatic omitted: ' . $reason;
    }

    /**
     * Exact reason there is no automatic neighbour in one direction.
     *
     * The phrase is "no neighbour" when nothing sits on that side, otherwise the
     * nearest module's reason: "not uservisible", "no url", or "skipped non-navigable".
     *
     * @param cm_info[] $sequence Display-order modules.
     * @param int|null $index Index of the current activity.
     * @param int $direction -1 for previous, 1 for next.
     * @return string
     */
    private function omit_reason(array $sequence, ?int $index, int $direction): string {
        if ($index === null) {
            if ($sequence === []) {
                return 'no neighbour (sequence empty)';
            }
            return 'no neighbour (current cmid not in sequence)';
        }

        $skip = $this->config_flag('skipnonnavigable', true);
        $count = count($sequence);
        $reasons = [];
        $more = false;
        for ($i = $index + $direction; $i >= 0 && $i < $count; $i += $direction) {
            $cm = $sequence[$i];
            $block = $this->navigable_block($cm, $skip);
            if ($block === null) {
                return 'cmid ' . (int)$cm->id . ' modname ' . $cm->modname;
            }
            if (count($reasons) >= 8) {
                $more = true;
                break;
            }
            $nearest = $reasons === [] ? 'nearest ' : '';
            $reasons[] = $block . ' (' . $nearest . 'cmid ' . (int)$cm->id . ' modname ' . $cm->modname . ')';
        }
        if ($reasons === []) {
            return 'no neighbour';
        }
        $text = implode('; ', $reasons);
        if ($more) {
            $text .= '; and more';
        }
        return $text;
    }

    /**
     * Modules skipped between the current activity and the chosen neighbour.
     *
     * @param cm_info[] $sequence Display-order modules.
     * @param int|null $index Index of the current activity.
     * @param int $direction -1 for previous, 1 for next.
     * @param cm_info $target Chosen neighbour.
     * @return string
     */
    private function skipped_notes(array $sequence, ?int $index, int $direction, cm_info $target): string {
        if ($index === null) {
            return '';
        }
        $skip = $this->config_flag('skipnonnavigable', true);
        $notes = [];
        $count = count($sequence);
        for ($i = $index + $direction; $i >= 0 && $i < $count; $i += $direction) {
            $cm = $sequence[$i];
            if ((int)$cm->id === (int)$target->id) {
                break;
            }
            $block = $this->navigable_block($cm, $skip);
            if ($block === null) {
                $block = 'skipped';
            }
            $notes[] = $block . ' (cmid ' . (int)$cm->id . ' modname ' . $cm->modname . ')';
            if (count($notes) >= 8) {
                break;
            }
        }
        return implode('; ', $notes);
    }

    /**
     * Plain-text diagnostic for the button bar.
     *
     * The template escapes this string. It is not written to the error log.
     *
     * @param course_modinfo|null $modinfo Course module cache, or null when it could not be loaded.
     * @param cm_info[] $sequence Display-order modules.
     * @param string $previoussummary Previous line, including cmid or the omit reason.
     * @param string $nextsummary Next line, including cmid or the omit reason.
     * @return string
     */
    private function debug_text(
        ?course_modinfo $modinfo,
        array $sequence,
        string $previoussummary,
        string $nextsummary
    ): string {
        global $PAGE;

        $cm = $PAGE->cm;
        if ($modinfo instanceof course_modinfo) {
            try {
                $cm = $modinfo->get_cm((int)$PAGE->cm->id);
            } catch (\Throwable $e) {
                $cm = $PAGE->cm;
            }
        }

        $sectionnum = null;
        $sectionid = null;
        try {
            $sectionnum = (int)$cm->sectionnum;
        } catch (\Throwable $e) {
            $sectionnum = null;
        }
        try {
            // Section id. Not a get_sections() key.
            $sectionid = (int)$cm->section;
        } catch (\Throwable $e) {
            $sectionid = null;
        }

        $grouped = [];
        $cmcount = 0;
        if ($modinfo instanceof course_modinfo) {
            try {
                $grouped = $this->grouped_sections($modinfo);
                $cmcount = count($modinfo->get_cms());
            } catch (\Throwable $e) {
                if ($this->builderror === '') {
                    $this->builderror = 'modinfo read failed: ' . $this->one_line($e->getMessage(), 160);
                }
            }
        }

        $installed = get_config(self::COMPONENT, 'version');
        $inserted = !$this->currentinwalk && ($this->insertedcurrent || $this->current_was_recovered());
        $counts = $this->other_reason_counts($sequence);
        $numberpresent = $sectionnum !== null && array_key_exists($sectionnum, $grouped);
        $idpresent = $sectionid !== null && array_key_exists($sectionid, $grouped);

        $lines = [
            'plugin version: ' . self::VERSION,
            'installed version: ' . ($installed === false ? 'not saved' : (string)$installed),
            'pagetype: ' . (string)$PAGE->pagetype,
            'cmid: ' . (int)$cm->id,
            'modname: ' . (string)$cm->modname,
            'cm sectionnum: ' . ($sectionnum === null ? 'unreadable' : (string)$sectionnum),
            'cm section id: ' . ($sectionid === null ? 'unreadable' : (string)$sectionid),
            'sectionnum key present: ' . ($numberpresent ? 'yes' : 'no'),
            'section id key present: ' . ($idpresent ? 'yes' : 'no'),
            'section id used as key: no',
            'sequence source: ' . ($this->sequencesource === '' ? 'none' : $this->sequencesource),
            'sequence note: ' . ($this->sequencenote === '' ? 'none' : $this->sequencenote),
            'build error: ' . ($this->builderror === '' ? 'none' : $this->builderror),
            'modinfo cms: ' . $cmcount,
            'sequence count: ' . count($sequence),
            'current found: ' . ($this->currentfound ? 'yes' : 'no'),
            'current index: ' . ($this->currentindex === null ? 'none' : (string)$this->currentindex),
            'current in section walk: ' . ($this->currentinwalk ? 'yes' : 'no'),
            'current inserted: ' . ($inserted ? 'yes' : 'no'),
            'get_sections keys: ' . $this->number_list($this->rawsectionkeys),
            'section numbers walked: ' . $this->number_list($this->walkednumbers),
            'section-number hits: ' . $this->sectionnumberhits,
            'section-id fallbacks: ' . $this->sectionidfallbacks,
            'sections with no cm ids: ' . $this->number_list($this->unmatchedsections),
            'delegated sections: ' . $this->delegatedsectioncount,
            'delegated inlined: ' . $this->delegatedinlined,
            'delegated map: ' . ($this->delegatednote === '' ? 'none' : $this->delegatednote),
            'cms kept after a short walk: ' . $this->number_list($this->recoveredcmids),
            'showprevious: ' . $this->flag_status('showprevious', true),
            'shownext: ' . $this->flag_status('shownext', true),
            'showbacktocourse: ' . $this->flag_status('showbacktocourse', false),
            'previousurl: ' . $this->url_status('previousurl'),
            'nexturl: ' . $this->url_status('nexturl'),
            'backtocourseurl: ' . $this->url_status('backtocourseurl'),
            'skipnonnavigable: ' . $this->flag_status('skipnonnavigable', true),
            'other cms: ' . $counts['other'],
            'other navigable: ' . $counts['navigable'],
            'other not uservisible: ' . $counts['not uservisible'],
            'other no url: ' . $counts['no url'],
            'other skipped non-navigable: ' . $counts['skipped non-navigable'],
            'other deletion in progress: ' . $counts['deletion in progress'],
            'sequence around current: ' . $this->sequence_window($sequence, $this->currentindex),
            $previoussummary,
            $nextsummary,
        ];
        return implode("\n", $lines);
    }

    /**
     * Whether recovery inserted the current course module.
     *
     * @return bool
     */
    private function current_was_recovered(): bool {
        global $PAGE;

        return in_array((int)$PAGE->cm->id, $this->recoveredcmids, true);
    }

    /**
     * Counts of why other modules in the sequence cannot be linked.
     *
     * @param cm_info[] $sequence Display-order modules.
     * @return array<string, int>
     */
    private function other_reason_counts(array $sequence): array {
        $skip = $this->config_flag('skipnonnavigable', true);
        $counts = [
            'other' => 0,
            'navigable' => 0,
            'not uservisible' => 0,
            'no url' => 0,
            'skipped non-navigable' => 0,
            'deletion in progress' => 0,
        ];
        foreach ($sequence as $position => $cm) {
            if ($this->currentindex !== null && $position === $this->currentindex) {
                continue;
            }
            $counts['other']++;
            $reason = $this->navigable_block($cm, $skip);
            if ($reason === null) {
                $counts['navigable']++;
                continue;
            }
            foreach (['deletion in progress', 'not uservisible', 'no url', 'skipped non-navigable'] as $bucket) {
                if ($reason === $bucket || str_starts_with($reason, $bucket . ' ') || str_starts_with($reason, $bucket . '(')) {
                    $counts[$bucket]++;
                    break;
                }
            }
        }
        return $counts;
    }

    /**
     * A short slice of the sequence, with the current module in brackets.
     *
     * @param cm_info[] $sequence Display-order modules.
     * @param int|null $index Index of the current activity.
     * @return string
     */
    private function sequence_window(array $sequence, ?int $index): string {
        $count = count($sequence);
        if ($count === 0) {
            return '(empty)';
        }

        $tokens = [];
        $append = function (int $i) use ($sequence, $index, &$tokens): void {
            $cm = $sequence[$i];
            $token = (int)$cm->id . ':' . $cm->modname;
            if ($index !== null && $i === $index) {
                $token = '[' . $token . ']';
            }
            $tokens[] = $token;
        };

        if ($index === null && $count > 10) {
            for ($i = 0; $i < 5; $i++) {
                $append($i);
            }
            $tokens[] = '...';
            for ($i = $count - 5; $i < $count; $i++) {
                $append($i);
            }
            return implode(', ', $tokens);
        }

        $start = 0;
        $end = $count - 1;
        if ($index !== null) {
            $start = max(0, $index - 5);
            $end = min($count - 1, $index + 5);
        } else {
            $end = min($end, 9);
        }
        if ($start > 0) {
            $tokens[] = '...';
        }
        for ($i = $start; $i <= $end; $i++) {
            $append($i);
        }
        if ($end < $count - 1) {
            $tokens[] = '...';
        }
        return implode(', ', $tokens);
    }

    /**
     * Comma-separated values, capped so a large course stays readable.
     *
     * @param array $numbers Values to list.
     * @return string
     */
    private function number_list(array $numbers): string {
        if ($numbers === []) {
            return '(none)';
        }
        $shown = array_slice(array_values($numbers), 0, 40);
        $text = implode(',', array_map(static function ($value): string {
            return (string)$value;
        }, $shown));
        $extra = count($numbers) - count($shown);
        if ($extra > 0) {
            $text .= ' +' . $extra . ' more';
        }
        return $text;
    }

    /**
     * Checkbox value as stored, so a saved "0" is visible beside the default.
     *
     * @param string $setting Config name.
     * @param bool $default Value used when the setting has never been saved.
     * @return string
     */
    private function flag_status(string $setting, bool $default): string {
        $value = get_config(self::COMPONENT, $setting);
        if ($value === false) {
            return 'not saved, default ' . ($default ? 'on' : 'off');
        }
        $text = trim((string)$value);
        $on = $text === '1';
        return 'saved "' . $this->one_line($text, 40) . '" (' . ($on ? 'on' : 'off') . ')';
    }

    /**
     * Whether a custom URL is empty, valid, or rejected.
     *
     * @param string $setting Config name.
     * @return string
     */
    private function url_status(string $setting): string {
        $value = $this->config_text($setting);
        if ($value === '') {
            return 'empty';
        }
        if (self::clean_link($value) === null) {
            return 'invalid (' . $this->one_line($value, 120) . ')';
        }
        return 'valid';
    }

    /**
     * Single-line, length-capped text for the diagnostic.
     *
     * @param string $value Raw text.
     * @param int $limit Maximum characters before an ellipsis.
     * @return string
     */
    private function one_line(string $value, int $limit = 80): string {
        $value = preg_replace('/\s+/', ' ', $value);
        if (!is_string($value)) {
            return '';
        }
        if (strlen($value) > $limit) {
            return substr($value, 0, $limit) . '...';
        }
        return $value;
    }
}
