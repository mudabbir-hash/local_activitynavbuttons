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
 * English language strings for Activity navigation buttons.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Activity Navigation Buttons';
$string['privacy:metadata'] = 'The Activity navigation buttons plugin does not store any personal data.';

$string['settingsheading'] = 'Activity navigation buttons';
$string['settingsheading_desc'] = 'Show optional Previous and Next buttons at the bottom of activity and resource pages. A label left blank uses the language pack, so translations keep working.';

$string['enabled'] = 'Enable activity navigation buttons';
$string['enabled_desc'] = 'When this is off, activity pages do not show the button bar.';

$string['debug'] = 'Show navigation debug';
$string['debug_desc'] = 'Show a plain-text box beside Back to course with the plugin version, this page, the activity sequence, and why Previous and Next are shown or hidden. For this release the box is shown in that bar even when this setting is off.';

$string['showprevious'] = 'Show previous button';
$string['showprevious_desc'] = 'Show a button for the previous activity, or for the custom previous URL.';
$string['previouslabel'] = 'Previous button label';
$string['previouslabel_desc'] = 'Text on the previous button. Leave blank to use the language pack default.';
$string['previous'] = 'Previous';
$string['previousurl'] = 'Custom previous URL';
$string['previousurl_desc'] = 'Optional. A site path starting with / or an absolute http(s) URL. When this is set, the previous button uses it and does not append an activity name. Leave blank to open the previous visible activity.';

$string['shownext'] = 'Show next button';
$string['shownext_desc'] = 'Show a button for the next activity, or for the custom next URL.';
$string['nextlabel'] = 'Next button label';
$string['nextlabel_desc'] = 'Text on the next button. Leave blank to use the language pack default.';
$string['next'] = 'Next';
$string['nexturl'] = 'Custom next URL';
$string['nexturl_desc'] = 'Optional. A site path starting with / or an absolute http(s) URL. When this is set, the next button uses it and does not append an activity name. Leave blank to open the next visible activity.';

$string['includename'] = 'Append activity name';
$string['includename_desc'] = 'For automatic previous and next links, append the activity name, for example "Previous: Introduction to Topic 1". Custom URLs are left unchanged.';
$string['withname'] = '{$a->label}: {$a->name}';

$string['skipnonnavigable'] = 'Skip non-navigable activities';
$string['skipnonnavigable_desc'] = 'Skip labels and activities that have no view link when choosing the previous and next activity. Activities without a URL are always skipped.';

$string['buttonclass'] = 'Button CSS classes';
$string['buttonclass_desc'] = 'Classes applied to each button. Use letters, numbers, hyphens, underscores, and single spaces. Leave blank to use the default.';
$string['invalidbuttonclass'] = 'Button classes may contain only letters, numbers, hyphens, underscores, and single spaces between tokens.';

$string['newtab'] = 'Open links in a new tab';
$string['newtab_desc'] = 'Open each button in a new tab and mark the link with rel="noopener noreferrer".';
$string['opensinnewtab'] = 'Opens in a new tab';

$string['backheading'] = 'Back to course';
$string['backheading_desc'] = 'An optional third button that links to the course page or to a custom URL.';
$string['showbacktocourse'] = 'Show back to course button';
$string['showbacktocourse_desc'] = 'Show a button that returns to the course, or to the custom back to course URL.';
$string['backtocourselabel'] = 'Back to course label';
$string['backtocourselabel_desc'] = 'Text on the back to course button. Leave blank to use the language pack default.';
$string['backtocourse'] = 'Back to course';
$string['backtocourseurl'] = 'Custom back to course URL';
$string['backtocourseurl_desc'] = 'Optional. A site path starting with / or an absolute http(s) URL. Leave blank to open the course page.';

$string['invalidurl'] = 'Enter a site path starting with / or an absolute http(s) URL. Other schemes, including javascript:, are not allowed.';

$string['navigation'] = 'Activity navigation';
$string['aria_previous'] = 'Previous activity: {$a}';
$string['aria_next'] = 'Next activity: {$a}';
