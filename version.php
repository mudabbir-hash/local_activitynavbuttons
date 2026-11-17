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
 * Version information for Activity navigation buttons.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_activitynavbuttons';
// The diagnostic is printed in the same bar as Back to course, beside that
// button. This release shows it even when the debug setting is saved off.
// Neighbours come from get_cms(), and a section walk is used only when it
// keeps those course modules. get_sections() is read by section number, never
// by the section id in $cm->section. An empty get_sequence_cm_infos() result
// does not discard the modules stored under that section number.
// Keep in step with local_activitynavbuttons\local\navigation::VERSION.
$plugin->version = 2026111705;
$plugin->requires = 2024100700;
$plugin->maturity = MATURITY_STABLE;
$plugin->release = '1.0.0';
