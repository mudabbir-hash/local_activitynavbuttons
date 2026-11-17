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
 * Admin setting that accepts a root-relative path or an absolute http(s) URL.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_activitynavbuttons\admin_setting;

defined('MOODLE_INTERNAL') || die();

/**
 * Text setting with the plugin URL rules applied on save.
 */
class url extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Unique setting name (plugin/setting).
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     * @param string $defaultsetting Default value. Empty means no custom URL.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting = '') {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 60);
    }

    /**
     * Validate a submitted URL.
     *
     * An empty value is valid and means "use the automatic destination".
     *
     * @param string $data Submitted value.
     * @return true|string True when valid, or a localised error.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($data === '') {
            return true;
        }
        if (\local_activitynavbuttons\local\navigation::clean_link($data) === null) {
            return get_string('invalidurl', 'local_activitynavbuttons');
        }
        return true;
    }

    /**
     * Store the trimmed URL.
     *
     * @param string $data Submitted value.
     * @return string Empty string on success, or an error message.
     */
    public function write_setting($data) {
        return parent::write_setting(trim((string)$data));
    }
}
