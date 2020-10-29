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
 * Relative activity and section completion condition.
 *
 * @package availability_relativecompletion
 * @copyright 2017 Zimcke Van de Staey <zimcke@gmail.com>, Tobias Verlinde
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace availability_relativecompletion;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/completionlib.php');

/**
 * Relative activity and section completion condition.
 *
 * @package availability_relativecompletion
 * @copyright 2017 Zimcke Van de Staey <zimcke@gmail.com>, Tobias Verlinde
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class condition extends \core_availability\condition {

    /** @var int IdType activity(0)/section(1) */
    protected $idtype;

    /** @var int Expected completion type (one of the COMPLETE_xx constants) */
    protected $expectedcompletion;

    /** @var array Array of modules used in these conditions for course */
    protected static $modsusedincondition = array();

    /**
     * Constructor.
     *
     * @param \stdClass $structure Data structure from JSON decode
     * @throws \coding_exception If invalid data structure.
     */
    public function __construct($structure) {
        // Get idtype.
        if (isset($structure->idtype) && is_number($structure->idtype)) {
            $this->idtype = (int)$structure->idtype;
        } else {
            throw new \coding_exception('Missing or invalid ->idtype for relative completion condition');
        }

        // Get expected completion.
        if (isset($structure->e) && is_number($structure->e)) {
            if ($this->idtype == 1) {           // Case activity.
                if (in_array($structure->e, array(0, 1, 2, 3))) {
                    $this->expectedcompletion = $structure->e;
                } else {
                    throw new \coding_exception('Missing or invalid ->e for relative completion condition');
                }
            } else if ($this->idtype == 0) {    // Case section.
                if (in_array($structure->e, array(0, 1))) {
                    $this->expectedcompletion = $structure->e;
                } else {
                    throw new \coding_exception('Missing or invalid ->e for relative completion condition');
                }
            } else {
                throw new \coding_exception('Invalid ->idtype for relative completion condition');
            }
        }
    }

    /**
     * Determines whether a particular item is currently available
     * according to this availability condition.
     *
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid User ID to check availability for
     * @return bool True if this section or course module is available
     *              False if this section or course module is unavailable or
     *                    if there is currently no section or cm id
     */
    public function is_available($not, \core_availability\info $info, $grabthelot, $userid) {
        $allow = false;

        // Determine value of $allow.
        if ($this->idtype == 1) {           // Case activities.
            $allow = $this->is_available_activity($info, $grabthelot, $userid);
        } else if ($this->idtype == 0) {     // Case sections.
            $allow = $this->is_available_section($info, $grabthelot, $userid);
        } else {
            throw new \coding_exception('Missing or invalid ->idtype for completion condition');
        }

        // Reverse answer if $not is true.
        if ($not) {
            $allow = !$allow;
        }

        return $allow;
    }

    /**
     * Determines whether the activity is currently available
     * True when:   - the previous activity meets the expected completion
     *              - there is no previous visible activity in the same section
     *
     * @param info $info Item we're checking
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid User ID to check availability for
     * @return bool True if this course module is currently available
     *              False if this course module is currtenly not available
     */
    private function is_available_activity($info, $grabthelot, $userid) {
        $modinfo = $info->get_modinfo();

        $completion = new \completion_info($modinfo->get_course());
        $prevcmid = $this->get_previous_activity_id($info);
        if (is_null($prevcmid)) {
            return true; // If there is no previous visible activity, return true.
        }

        $completiondata = $completion->get_data((object)array('id' => $prevcmid), $grabthelot, $userid, $modinfo);
        $prevactivity = $modinfo->get_cm($prevcmid);
        if ($this->expectedcompletion == COMPLETION_COMPLETE) {
            // Check if the previous activity is completed, or graded (passed/failed).
            return ($completiondata->completionstate != COMPLETION_INCOMPLETE);
        } else if ($this->expectedcompletion == COMPLETION_COMPLETE_PASS || $this->expectedcompletion == COMPLETION_COMPLETE_FAIL) {
            // The previous activity may not have a grade criteria: if it doesn't, relax the pass/fail condition to completed.
            if (!is_null($prevactivity->completiongradeitemnumber)) {
                return ($completiondata->completionstate == $this->expectedcompletion); // Exact match.
            } else {
                return ($completiondata->completionstate != COMPLETION_INCOMPLETE);     // Relax condition to complete.
            }
        } else if ($this->expectedcompletion == COMPLETION_INCOMPLETE) {
            // Check if the previous activity is not completed (exact match).
            return ($completiondata->completionstate == $this->expectedcompletion);
        }
        return false;
    }

    /**
     * Return the ID of the previous visible activity in the same section and course (or null if none)
     *
     * @param info $info Item we're checking
     * @return int id of the previous visible activity in the same section or null if there is none
     */
    private function get_previous_activity_id($info) {
        global $DB;

        $currentid = $info->get_course_module()->id;
        $modinfo = $info->get_modinfo();
        $cm = $modinfo->get_cm($currentid);
        $course = $modinfo->get_course();

        // Get Section and sequence of activities in that section.
        $sectionid = $cm->section;
        $sql = "SELECT cs.id, cs.name, cs.sequence
				FROM {course_sections} cs
				WHERE cs.id = :sectionid
				AND cs.course = :courseid";
        $params = array('sectionid' => $sectionid, 'courseid' => $course->id);
        $section = $DB->get_record_sql($sql, $params);
        $sequence = $section->sequence;
        $sequencearray = explode(",", $sequence);  // Longtext to array.
        $currentcmindex = array_search ($cm->id , $sequencearray);

        // Check if any of the previous activities in the section are visible and have completion enabled.
        for ($i = $currentcmindex - 1; $i >= 0; $i--) {
            $othercmid = $sequencearray[$i];
            $othercm = $modinfo->get_cm($othercmid);
            if ($othercm->completion && $othercm->visible && !$othercm->deletioninprogress) {
                return $othercm->id;
            }
        }
        return null;
    }

    /**
     * Determines whether the section is currently available
     *
     * @param info $info Item we're checking
     * @param bool $grabthelot Performance hint: if true, caches information
     *   required for all course-modules, to make the front page and similar
     *   pages work more quickly (works only for current user)
     * @param int $userid User ID to check availability for
     * @return bool True if this section is currently available
     *              False if this section is currtenly not available
     */
    private function is_available_section($info, $grabthelot, $userid) {
        $modinfo    = $info->get_modinfo();

        $completion = new \completion_info($modinfo->get_course());
        $activities = $this->get_previous_section_activities_ids($info);

        if (is_null($activities)) {
            return true; // No previous visible section OR no visible activities in previous section.
        } else {
            if ($this->expectedcompletion == 0) {
                // All activities for previous section must be complete.
                foreach ($activities as $activity) {
                    $completiondata = $completion->get_data((object)array('id' => $activity), $grabthelot, $userid, $modinfo);
                    if ($completiondata->completionstate == COMPLETION_INCOMPLETE) {
                        return false;
                    }
                }
                return true;
            } else if ($this->expectedcompletion == 1) {
                // Minimum one activity from previous section must be complete.
                foreach ($activities as $activity) {
                    $completiondata = $completion->get_data((object)array('id' => $activity), $grabthelot, $userid, $modinfo);
                    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
                        return true;
                    }
                }
                return false;
            } else {
                throw new \coding_exception('Missing or invalid ->expectedcompletion for relative completion condition');
            }
        }
        return false;
    }

    /*
     * Return the ID's of all visible activities in the previous visible section (or null if no previous section)
     * 
     * @param info $info Item we're checking
     * @return Array array of all visibile activities ids from the previous section 
     *          or null if there is no previous section
     */
    private function get_previous_section_activities_ids($info) {
        $course = $info->get_modinfo()->get_course();
        $courseinfo = get_fast_modinfo($course);
        $prevsection = $this->get_previous_section($info);

        if (is_null($prevsection)) {
            return null; // No previous visible section.
        }

        // Get visible activities in previous section.
        $allactivities = $courseinfo->get_cms();
        $activities = array();
        foreach ($allactivities as $act) {
            if (($act->visible == 1) && ($prevsection->id == $act->section) && (!$act->deletioninprogress)) {
                array_push($activities, $act->id);
            }
        }
        if (count($activities) == 0) {
            return null; // No visible activities in previous section.
        }

        return $activities;
    }

    /**
     * Return the previous visible section (if any)
     *
     * @param info $info Item we're checking
     * @return the previous visible section or null if there is none
     */
    private function get_previous_section($info) {
        $currentid = $info->get_section()->id;
        $modinfo = $info->get_modinfo();
        $course = $modinfo->get_course();
        $courseinfo = get_fast_modinfo($course);
        $sections = $courseinfo->get_section_info_all();

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
                return $sections[$sectionnumber];
            }
        }

        // Return null if no previous visible section found.
        return null;
    }

    /**
     * Used in course/lib.php because we need to disable the completion JS if
     * a completion value affects a conditional activity.
     *
     * @param \stdClass $course Moodle course object
     * @param int $cmid Course-module id
     * @return bool True if this is used in a condition, false otherwise
     */
    public static function completion_value_used($course, $cmid) {
        return true;
    }

    /**
     * Returns a JSON object which corresponds to a condition of this type.
     *
     * Intended for unit testing, as normally the JSON values are constructed
     * by JavaScript code.
     *
     * @param int $currentid Course-module id of other activity or section id
     * @param int $idtype 0 for activities, 1 for sections
     * @param int $expectedcompletion Expected completion value (COMPLETION_xx)
     * @return stdClass Object representing condition
     */
    public static function get_json($currentid, $idtype, $expectedcompletion) {
        return (object)array(
            'type' => 'relativecompletion',
            'idtype' => (int)$idtype,
            'e' => (int)$expectedcompletion
        );
    }
    
    /**
     * Returns an object which corresponds to a condition of this type.
     *
     * @return stdClass Object representing current condition
     */
    public function save() {
        return (object)array(
            'type' => 'relativecompletion',
            'idtype' => $this->idtype,
            'e' => $this->expectedcompletion
        );
    }

    /**
     * Returns a more readable keyword corresponding to a completion state.
     *
     * Used to make lang strings easier to read.
     *
     * @param int $completionstate COMPLETION_xx constant
     * @return string Readable keyword
     */
    protected static function get_lang_string_keyword($completionstate) {
        switch($completionstate) {
            case COMPLETION_INCOMPLETE:
                return 'incomplete';
            case COMPLETION_COMPLETE:
                return 'complete';
            case COMPLETION_COMPLETE_PASS:
                return 'complete_pass';
            case COMPLETION_COMPLETE_FAIL:
                return 'complete_fail';
            default:
                throw new \coding_exception('Unexpected completion state: ' . $completionstate);
        }
    }

    /**
     * Returns a string representation of the condition.
     *
     * @param bool $full Set true if this is the 'full information' view
     * @param bool $not Set true if we are inverting the condition
     * @param info $info Item we're checking
     * @return string representation of the condition
     */
    public function get_description($full, $not, \core_availability\info $info) {
        if ($this->idtype == 1) {
            // Case activities.
            $part1 = 'the_previous_activity';
            if ($not) {
                switch ($this->expectedcompletion) {
                    case COMPLETION_INCOMPLETE:
                        $part2 = 'requires_' . self::get_lang_string_keyword(COMPLETION_COMPLETE);
                        break;
                    case COMPLETION_COMPLETE:
                        $part2 = 'requires_' . self::get_lang_string_keyword(COMPLETION_INCOMPLETE);
                        break;
                    default:
                        $part2 = 'requires_not_' . self::get_lang_string_keyword($this->expectedcompletion);
                    break;
                }
            } else {
                $part2 = 'requires_' . self::get_lang_string_keyword($this->expectedcompletion);
            }
        } else if ($this->idtype == 0) {
            // Case sections.
            if ($this->expectedcompletion == 0) {
                $part1 = 'each_activity_in_prev_section';
            } else if ($this->expectedcompletion == 1) {
                $part1 = 'a_activity_in_prev_section';
            }
            if ($not) {
                $part2 = 'requires_' . self::get_lang_string_keyword(COMPLETION_INCOMPLETE);
            } else {
                $part2 = 'requires_' . self::get_lang_string_keyword(COMPLETION_COMPLETE);
            }
        }
        return get_string($part1, 'availability_relativecompletion').' '.get_string($part2, 'availability_relativecompletion');
    }

    /**
     * Obtains a representation of the options of this condition as a string,
     * for debugging.
     *
     * @return string Text representation of parameters
     */
    protected function get_debug_string() {
        return 'Todo get_debug_string';
    }

    /**
     * Wipes the static cache of modules used in a condition (for unit testing).
     */
    public static function wipe_static_cache() {
        self::$modsusedincondition = array();
    }

    /*
     * If there is a new activity or section involved in this conditional statement, we need to update the id
     * This may happen if the order of sections or activities is adjusted, e.g. by moving the sections or
     * course modules around.
     * @param $table Table with course modules
     * @param int $oldid
     * @param int $newid
     * @return bool True if update is successful
     */
    public function update_dependency_id($table, $oldid, $newid) {
        return true;
        // TODO: uitzoeken of/wat er hier moet gebeuren, zolang we steeds alles berekenen op het moment niets.
        if ($table === 'course_modules' && (int)$this->currentid === (int)$oldid) {
            $this->currentid = $newid;
            return true;
        } else {
            return false;
        }
    }
}
