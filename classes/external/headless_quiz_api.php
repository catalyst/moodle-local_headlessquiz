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

namespace local_headlessquiz\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/externallib.php");

/**
 * Headless quiz external API class
 *
 * @package    local_headlessquiz
 * @copyright  2022 Catalyst IT
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class headless_quiz_api extends \external_api {
    /**
     * Headless quiz GET function paramters
     * @return \external_function_parameters
     */
    public static function get_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, "Course Module ID"),
            'forcenew' => new \external_value(PARAM_BOOL, "Force create a new attempt. Abandon exsting inprogress attempts",
                VALUE_DEFAULT, false)
        ]);
    }

    /**
     * Headless quiz GET function
     * @param int $cmid Course module ID
     * @param bool $forcenew If true, forces a new attempt (abandons previous inprogress attempts)
     * @return object object containing either error or data
     */
    public static function get(int $cmid, bool $forcenew): object {
        global $USER;
        // USER is set to the webservice user - usually from token created by tool_token.
        return \local_headlessquiz\api::get_headless_quiz($cmid, $USER->id, $forcenew);
    }

    /**
     * Headless quiz GET function return spec
     * @return \external_single_structure
     */
    public static function get_returns(): \external_single_structure {
        $userstructure = new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'User ID')
        ], 'User');

        $questionstructure = new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'Question ID'),
            'name' => new \external_value(PARAM_TEXT, 'Question Name'),
            'questiontext' => new \external_value(PARAM_RAW, 'Question Text'),
            'type' => new \external_value(PARAM_TEXT, 'Question type'),
            'slot' => new \external_value(PARAM_INT, 'Question slot'),
            'options' => new \external_value(PARAM_RAW, 'JSON encoded option data')
        ], 'Question');

        $quizstructure = new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'Quiz ID'),
            'name' => new \external_value(PARAM_TEXT, 'Quiz name'),
            'cmid' => new \external_value(PARAM_INT, 'Course module ID'),
            'gradetopass' => new \external_value(PARAM_FLOAT, 'Grade required to pass the quiz activity', VALUE_OPTIONAL, null),
            'bestgrade' => new \external_value(PARAM_FLOAT, 'The best grade a user has achieved in this quiz', VALUE_DEFAULT, null),
            'maxgrade' => new \external_value(PARAM_FLOAT, 'Maximum grade possible', VALUE_OPTIONAL, null),
            'questions' => new \external_multiple_structure($questionstructure, 'Quiz Questions', VALUE_DEFAULT, [])
        ], 'Quiz');

        $responsestructure = new \external_single_structure([
            'questionid' => new \external_value(PARAM_INT, 'Question ID'),
            'state' => new \external_value(PARAM_TEXT, 'Question State', VALUE_DEFAULT, null),
            'mark' => new \external_value(PARAM_FLOAT, 'Question attempt mark', VALUE_DEFAULT, null),
            'status' => new \external_value(PARAM_TEXT, 'Question Status'),
            'data' => new \external_value(PARAM_TEXT, 'Question Data (response)', VALUE_DEFAULT, null),
            'slot' => new \external_value(PARAM_INT, 'Slot ID'),
            'html' => new \external_value(PARAM_RAW, 'Question and response HTML'),
            'sequencecheck' => new \external_value(PARAM_INT, 'Sequence check number'),
            'feedback' => new \external_value(PARAM_RAW, 'Feedback for question (html)'),
        ], 'Quiz attempt question response');

        $attemptstructure = new \external_single_structure([
            'id' => new \external_value(PARAM_INT, 'Attempt ID'),
            'state' => new \external_value(PARAM_TEXT, 'Attempt state'),
            'feedback' => new \external_value(PARAM_RAW, 'Attempt feedback', VALUE_DEFAULT, null),
            'summarks' => new \external_value(PARAM_FLOAT, 'Sum of all marks in the attempt', VALUE_DEFAULT, null),
            'passed' => new \external_value(PARAM_BOOL, 'Does this attempts grade meet the required passing grade for the quiz',
                VALUE_DEFAULT, null),
            'scaledgrade' => new \external_value(PARAM_FLOAT, 'Summarks scaled according to the quiz', VALUE_DEFAULT, null),
            'timestart' => new \external_value(PARAM_INT, 'Time started'),
            'timemodified' => new \external_value(PARAM_INT, 'Time modified'),
            'number' => new \external_value(PARAM_INT, 'Attempt Number'),
            'responses' => new \external_multiple_structure($responsestructure, 'Responses', VALUE_DEFAULT, [])
        ], 'Quiz attempt');

        $datastructure = new \external_single_structure([
            'user' => $userstructure,
            'quiz' => $quizstructure,
            'attempt' => $attemptstructure,
        ], 'Response', VALUE_DEFAULT, null);

        $errorstructure = new \external_single_structure([
            'type' => new \external_value(PARAM_TEXT, 'Error Type'),
            'message' => new \external_value(PARAM_TEXT, 'Error Message')
        ], 'Error return', VALUE_DEFAULT, null);

        return new \external_single_structure([
            'error' => $errorstructure,
            'data' => $datastructure
        ]);
    }
}
