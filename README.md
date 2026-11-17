# Activity Navigation Buttons

Optional Previous and Next buttons at the bottom of every activity and resource page. A third Back to course button can be turned on separately.

The buttons are added with the output hook `\core\hook\output\before_footer_html_generation`. Moodle runs that hook at the end of the page body, before the layout footer, so the bar sits at the bottom of the main content. There is no legacy `before_footer` callback, so the bar is not printed twice.

## Requirements

- Moodle 4.5 or later (`requires` 2024100700)
- The PHP version required by that Moodle release

The plugin does not add a capability and does not store personal data. Anyone who can view the activity sees the buttons.

## Install

1. Copy this folder to `local/activitynavbuttons` inside the Moodle directory. `version.php` must be at `local/activitynavbuttons/version.php`.
2. Log in as an administrator and open **Site administration → Notifications** to install the plugin.

You can also unzip `local_activitynavbuttons.zip` inside Moodle's `local` directory. The zip's top level is the folder `local_activitynavbuttons`.

## Settings

**Site administration → Plugins → Local plugins → Activity Navigation Buttons**

All values are stored as `config_plugins` entries `local_activitynavbuttons/<setting>`.

| Setting | Default | Behaviour |
| --- | --- | --- |
| Enable activity navigation buttons | On | Master switch. When off, the page renders nothing. |
| Show previous button | On | Hides the previous button when off. |
| Previous button label | Empty | An empty value uses the language string "Previous", so language packs work. |
| Custom previous URL | Empty | A non-empty value replaces automatic previous. The activity name is not appended. |
| Show next button | On | Hides the next button when off. |
| Next button label | Empty | An empty value uses the language string "Next". |
| Custom next URL | Empty | A non-empty value replaces automatic next. The activity name is not appended. |
| Append activity name | On | For automatic links only, the visible name is appended, for example "Previous: Introduction to Topic 1". |
| Skip non-navigable activities | On | Skips labels and modules with no view link. Modules with no URL are always skipped. |
| Button CSS classes | `btn btn-outline-primary` | Letters, numbers, hyphens, underscores, and single spaces. An empty or invalid value uses the default. |
| Open links in a new tab | Off | Adds `target="_blank"` and `rel="noopener noreferrer"`. |
| Show back to course button | Off | Independent of Previous and Next. |
| Back to course label | Empty | An empty value uses the language string "Back to course". |
| Custom back to course URL | Empty | An empty value links to the course page. |

Custom URLs must be a site path that starts with `/` (for example `/course/view.php?id=2`) or an absolute `http` or `https` URL. `javascript:` and other schemes are rejected and are not saved.

Until an administrator saves the settings page, unsaved checkboxes use the defaults above.

## Behaviour

Buttons appear only on an activity or resource view: the page has a course module, the context is that module, and the page type matches `mod-*-view`. Course pages, the site home, and admin pages are left unchanged.

Previous and Next are chosen independently.

- The show checkbox for that button is on.
- A valid custom URL is used as-is, without an activity name.
- An empty custom URL links to the previous or next activity the current user can see.
- A custom URL that is not empty but fails validation is not printed, and that button does not fall back to the automatic activity.
- The button is omitted when it has no usable destination. The first activity has no Previous button. The last activity has no Next button.

Automatic order follows the course page, using one `get_fast_modinfo()` lookup and no extra per-activity queries:

- Top-level sections stay in `get_section_info_all()` order.
- Activities inside a section follow that section's sequence.
- Moodle 4.5 subsections (delegated sections) are inserted where the subsection sits on the page, including nested subsections.
- Only activities visible to the current user are linked. Hidden activities, availability restrictions, labels, modules being deleted, and modules without a URL are not targets. With "Skip non-navigable activities" off, a module is still skipped when it has no URL.
- Guests and other users only receive links to activities they can access.

Back to course is shown only when its checkbox is on. An empty custom URL opens `/course/view.php?id=<courseid>`. The button is omitted when the course cannot be resolved (including the site home).

Each link is a normal focusable anchor. The navigation landmark has an accessible name. When the target activity is known, the link's accessible name includes the direction and the activity name. Activity names are the visible module name, passed through Moodle's string formatter and escaped in the template.

## Upgrade

1. Replace the files under `local/activitynavbuttons`.
2. Raise `$plugin->version` in `version.php` when you ship a new build.
3. Open **Site administration → Notifications**.

Release 1.0.0 does not create database tables. There is no `db/upgrade.php` step.

## License

GNU General Public License version 3 or later. See <http://www.gnu.org/licenses/>.
