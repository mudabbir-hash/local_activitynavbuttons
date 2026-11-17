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
 * Admin settings for Activity navigation buttons.
 *
 * Stored as config_plugins keys local_activitynavbuttons/<name>.
 * Label fields default to an empty string. At render time an empty label
 * falls back to the language string so language packs supply the button text.
 *
 * @package    local_activitynavbuttons
 * @copyright  2026 Mudabbir
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_activitynavbuttons',
        get_string('pluginname', 'local_activitynavbuttons')
    );

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_activitynavbuttons/settingsheading',
            get_string('settingsheading', 'local_activitynavbuttons'),
            get_string('settingsheading_desc', 'local_activitynavbuttons')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/enabled',
            get_string('enabled', 'local_activitynavbuttons'),
            get_string('enabled_desc', 'local_activitynavbuttons'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/debug',
            get_string('debug', 'local_activitynavbuttons'),
            get_string('debug_desc', 'local_activitynavbuttons'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/showprevious',
            get_string('showprevious', 'local_activitynavbuttons'),
            get_string('showprevious_desc', 'local_activitynavbuttons'),
            1
        ));
        $settings->add(new admin_setting_configtext(
            'local_activitynavbuttons/previouslabel',
            get_string('previouslabel', 'local_activitynavbuttons'),
            get_string('previouslabel_desc', 'local_activitynavbuttons'),
            '',
            PARAM_TEXT,
            40
        ));
        $settings->add(new \local_activitynavbuttons\admin_setting\url(
            'local_activitynavbuttons/previousurl',
            get_string('previousurl', 'local_activitynavbuttons'),
            get_string('previousurl_desc', 'local_activitynavbuttons')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/shownext',
            get_string('shownext', 'local_activitynavbuttons'),
            get_string('shownext_desc', 'local_activitynavbuttons'),
            1
        ));
        $settings->add(new admin_setting_configtext(
            'local_activitynavbuttons/nextlabel',
            get_string('nextlabel', 'local_activitynavbuttons'),
            get_string('nextlabel_desc', 'local_activitynavbuttons'),
            '',
            PARAM_TEXT,
            40
        ));
        $settings->add(new \local_activitynavbuttons\admin_setting\url(
            'local_activitynavbuttons/nexturl',
            get_string('nexturl', 'local_activitynavbuttons'),
            get_string('nexturl_desc', 'local_activitynavbuttons')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/includename',
            get_string('includename', 'local_activitynavbuttons'),
            get_string('includename_desc', 'local_activitynavbuttons'),
            1
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/skipnonnavigable',
            get_string('skipnonnavigable', 'local_activitynavbuttons'),
            get_string('skipnonnavigable_desc', 'local_activitynavbuttons'),
            1
        ));
        $settings->add(new \local_activitynavbuttons\admin_setting\button_class(
            'local_activitynavbuttons/buttonclass',
            get_string('buttonclass', 'local_activitynavbuttons'),
            get_string('buttonclass_desc', 'local_activitynavbuttons'),
            'btn btn-outline-primary'
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/newtab',
            get_string('newtab', 'local_activitynavbuttons'),
            get_string('newtab_desc', 'local_activitynavbuttons'),
            0
        ));

        $settings->add(new admin_setting_heading(
            'local_activitynavbuttons/backheading',
            get_string('backheading', 'local_activitynavbuttons'),
            get_string('backheading_desc', 'local_activitynavbuttons')
        ));
        $settings->add(new admin_setting_configcheckbox(
            'local_activitynavbuttons/showbacktocourse',
            get_string('showbacktocourse', 'local_activitynavbuttons'),
            get_string('showbacktocourse_desc', 'local_activitynavbuttons'),
            0
        ));
        $settings->add(new admin_setting_configtext(
            'local_activitynavbuttons/backtocourselabel',
            get_string('backtocourselabel', 'local_activitynavbuttons'),
            get_string('backtocourselabel_desc', 'local_activitynavbuttons'),
            '',
            PARAM_TEXT,
            40
        ));
        $settings->add(new \local_activitynavbuttons\admin_setting\url(
            'local_activitynavbuttons/backtocourseurl',
            get_string('backtocourseurl', 'local_activitynavbuttons'),
            get_string('backtocourseurl_desc', 'local_activitynavbuttons')
        ));
    }

    $ADMIN->add('localplugins', $settings);
}
