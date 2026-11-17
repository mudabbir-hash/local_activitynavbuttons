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
 * Admin setting for a space-separated list of CSS class tokens.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_activitynavbuttons\admin_setting;

defined('MOODLE_INTERNAL') || die();

/**
 * Text setting limited to CSS class tokens.
 */
class button_class extends \admin_setting_configtext {
    /**
     * Constructor.
     *
     * @param string $name Unique setting name (plugin/setting).
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     * @param string $defaultsetting Default class list.
     */
    public function __construct($name, $visiblename, $description, $defaultsetting) {
        parent::__construct($name, $visiblename, $description, $defaultsetting, PARAM_RAW, 40);
    }

    /**
     * Validate CSS class tokens.
     *
     * An empty value is valid. Rendering then uses the built-in default.
     *
     * @param string $data Submitted value.
     * @return true|string True when valid, or a localised error.
     */
    public function validate($data) {
        $data = trim((string)$data);
        if ($data === '') {
            return true;
        }
        if (!\local_activitynavbuttons\local\navigation::is_valid_button_class($data)) {
            return get_string('invalidbuttonclass', 'local_activitynavbuttons');
        }
        return true;
    }

    /**
     * Store the trimmed class list.
     *
     * @param string $data Submitted value.
     * @return string Empty string on success, or an error message.
     */
    public function write_setting($data) {
        return parent::write_setting(trim((string)$data));
    }
}
