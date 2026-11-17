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
 * Renderable button bar for activity navigation.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_activitynavbuttons\output;

defined('MOODLE_INTERNAL') || die();

use renderer_base;
use stdClass;
use templatable;

/**
 * Template export for local_activitynavbuttons/navigation.
 *
 * Strings are prepared for Mustache {{ }} escaping. Activity names are passed
 * through format_string with escape disabled so multilang and filters run
 * once, and the template encodes the result. Labels are plain text cleaned
 * with PARAM_TEXT. Pre-escaping with s() would double-encode under {{ }}.
 */
class navigation implements \renderable, templatable {
    /** @var stdClass Template data from the navigation builder. */
    private $data;

    /**
     * Constructor.
     *
     * @param stdClass $data Button bar context.
     */
    public function __construct(stdClass $data) {
        $this->data = $data;
    }

    /**
     * Export data for the navigation template.
     *
     * @param renderer_base $output Renderer (unused; strings are already prepared).
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        return $this->data;
    }
}
