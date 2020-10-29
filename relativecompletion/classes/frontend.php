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
 * Front-end class.
 *
 * @package availability_relativecompletion
 * @copyright 2017 Zimcke Van de Staey <zimcke@gmail.com>, Tobias Verlinde
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_relativecompletion;

defined('MOODLE_INTERNAL') || die();

/**
 * Front-end class.
 *
 * @package availability_relativecompletion
 * @copyright 2017 Zimcke Van de Staey <zimcke@gmail.com>, Tobias Verlinde
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class frontend extends \core_availability\frontend {
    /**
     * @var array Cached init parameters
     */
    protected $cacheparams = array();

    /**
     * @var string IDs of course, cm, and section for cache (if any)
     */
    protected $cachekey = '';

    /**
     * Gets a list of string identifiers that are required in JavaScript 
     * for this plugin. 
     * @return array Array of required string identifiers
     */
    protected function get_javascript_strings() {
        return array('option_complete', 'option_fail', 'option_incomplete', 'option_pass',
            'label_cm', 'label_completion', 'previous_activity', 'previous_section', 'one_activity', 'all_activities',
            'current_previous_activity', 'current_previous_section', 'no_current_previous_activity', 'no_current_previous_section',
            'activity', 'section', 'relative_completion', 'creation_new_activity');
    }

        /**
     * Gets additional parameters for the plugin's initInner function.
     *
     * @param \stdClass $course Course object
     * @param \cm_info $cm Course-module currently being edited (null if none)
     * @param \section_info $section Section currently being edited (null if none)
     * @return array Array with parameters sections/activities, bool isSection, 
     *                          bool isNewActivity for the JavaScript function
     */
    protected function get_javascript_init_params($course, \cm_info $cm = null,
            \section_info $section = null) {
        global $DB;

        // Defines if we are working on a section or on a module.
        if ($section != null) {
            $issection = true;
        } else {
            $issection = false;
        }
        $isnewactivity = false;

        // Use cached result if available. The cache is just because we call it
        // twice (once from allow_add) so it's nice to avoid doing all the
        // print_string calls twice.
        $cachekey = $course->id . ',' . ($cm ? $cm->id : '') . ($section ? $section->id : '');
        if ($cachekey !== $this->cachekey) {

            if (!$issection) { // We are adjusting availability of an activity.
                if (empty($cm)) {
                    $isnewactivity = true;
                }

                // Get list of activities on course which have completion values,
                // to fill the dropdown.
                $cms = $this->get_previous_activity($course, $cm);

                $this->cachekey = $cachekey;
                $this->cacheinitparams = array($cms, $issection, $isnewactivity);

            } else {    // We are adjusting availability of a section.
                // Get previous section on course,
                // to fill the dropdown.
                $sections = $this->get_previous_section($course, $section);

                $this->cachekey = $cachekey;
                $this->cacheinitparams = array($sections, $issection, $isnewactivity);
            }
        }
        return $this->cacheinitparams;
    }

    /**
     * Return the previous visible activity in the same section of the given course (or null if none)
     *
     * @param \stdClass $course Course object
     * @param \cm_info $cm Course-module currently being edited (null if none)
     * @return array with the previous visible activity in the same section or empty array if there is none
     */
    private function get_previous_activity($course, \cm_info $cm = null) {
        global $DB;
        $context = \context_course::instance($course->id);
        $modinfo = get_fast_modinfo($course);
        $previousactivity = array();

        if (!is_null($cm)) {
            // Get Section and sequence of activities in that section.
            $sectionid = $cm->section;
            $sql = "SELECT cs.id, cs.name, cs.sequence
					FROM {course_sections} cs
					WHERE cs.id = :sectionid
					AND cs.course = :courseid";
            $params = array('sectionid' => $sectionid, 'courseid' => $course->id);
            $section = $DB->get_record_sql($sql, $params);
            $sequence = $section->sequence;
            $sequencearray = explode(",", $sequence);   // Longtext to array.

            $cmindex = array_search ($cm->id , $sequencearray);

            // Check if any of the previous activities in the section are visible and have completion enabled.
            for ($i = $cmindex - 1; $i >= 0; $i--) {
                $othercmid = $sequencearray[$i];
                $othercm = $modinfo->get_cm($othercmid);

                if ($othercm->completion && $othercm->visible && !$othercm->deletioninprogress) {
                    $previousactivity[] = (object)array('id' => $othercmid,
                        'name' => format_string($othercm->name, true, array('context' => $context)),
                        'completiongradeitemnumber' => $othercm->completiongradeitemnumber);
                    break;
                }
            } // Else empty array when there is no previous activity.

        }
        return $previousactivity;
    }

    /**
     * Return the previous visible section (if any)
     *
     * @param \stdClass $course Course object
     * @param \section_info $section Section currently being edited (null if none)
     * @return array with the previous visible section or empty array if there is none
     */
    private function get_previous_section($course, \section_info $section = null) {
        $context = \context_course::instance($course->id);
        $previoussection = array();

        if (!is_null($section)) {
            $currentid = $section->id;
            $modinfo = get_fast_modinfo($course);
            $sections = $modinfo->get_section_info_all();

            // Get section number of current section in course.
            $currentsectionnumber = -1;
            foreach ($sections as $key => $value) {
                if ($currentid == (int)$value->id) {
                    $currentsectionnumber = $key;
                    break;
                }
            }

            // Get previous visible section.
            for ($sectionnumber = $currentsectionnumber - 1; $sectionnumber >= 0; $sectionnumber--) {
                if ($sections[$sectionnumber]->visible == 1) {
                    $previoussection[] = (object) array('id' => $sections[$sectionnumber]->id,
                    'name' => format_string(get_section_name($course, $sections[$sectionnumber]), true, array('context' => $context)));
                    break;
                }
            } // Else empty array because there is no previous visible section.
        }
        return $previoussection;
    }

    /**
     * Decides whether this plugin should be available in a given course. 
     * Checks if completion is enabled for the course.
     *
     * @param \stdClass $course Course object
     * @param \cm_info $cm Course-module currently being edited (null if none)
     * @param \section_info $section Section currently being edited (null if none)
     * @return bool True if relative completion should be available in the given course
     */
    protected function allow_add($course, \cm_info $cm = null,
            \section_info $section = null) {
        global $CFG;

        // Check if completion is enabled for the course.
        require_once($CFG->libdir . '/completionlib.php');
        $info = new \completion_info($course);
        if (!$info->is_enabled()) {
            return false;
        } else {
            return true;
        }
    }
}