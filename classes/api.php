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

namespace local_headlessquiz;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');

/**
 * Headless quiz api class
 *
 * @package    local_headlessquiz
 * @copyright  2022 Catalyst IT
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /**
     * @var string Error caused by a validation function
     */
    public const ERROR_VALIDATION = 'validationerr';

    /**
     * @var string Error caught that was not handled.
     */
    public const ERROR_UNKNOWN = 'unknownerr';

    /**
     * @var array Supported question type shortnames.
     */
    public const SUPPORTED_QTYPES = [
        'shortanswer',
        'truefalse',
        'multichoice'
    ];

    /**
     * Gets headless quiz data.
     * @param int $coursemoduleid ID of course module
     * @param int $userid ID of user
     * @param bool $forcenew If true, will abandon previous inprogress attempts and start a new attempt
     * @return object object with data or error response.
     */
    public static function get_headless_quiz(int $coursemoduleid, int $userid, bool $forcenew = false): object {
        try {
            // Get and validate the quiz.
            $quiz = self::get_quiz($coursemoduleid);
            [$validquiz, $quizerror] = self::validate_quiz($quiz);

            if (!$validquiz) {
                return self::form_validation_error_response($quizerror);
            }

            // Get and validate the user.
            $user = self::get_user($userid);
            [$validuser, $usererror] = self::validate_user($user, $quiz->course);

            if (!$validuser) {
                return self::form_validation_error_response($usererror);
            }

            // Get latest attempt for this user.
            $attempt = self::get_latest_attempt($user, $quiz);

            // If no attempt OR forcenew, start an attempt.
            if (empty($attempt) || $forcenew === true) {

                // If previous attempt was inprogress, abandon it.
                if (!empty($attempt) && $attempt->state === 'inprogress') {
                    self::abandon_attempt($attempt);
                }

                // Start a new attempt.
                $nextattemptnumber = empty($attempt) ? 1 : $attempt->attempt + 1;
                $attempt = self::start_attempt($user, $quiz, $nextattemptnumber);
            }

            // Get attempt review data (attempts, grade, state, status, etc...).
            $attemptreview = self::get_attempt_review_data($attempt->id);

            // Get grades for quiz.
            $grade = \mod_quiz_external::get_user_best_grade($quiz->instance, $user->id);

            $quizobj = \quiz::create($quiz->instance);
            $quizobj->preload_questions();
            $quizobj->load_questions();
            $questions = $quizobj->get_questions();

            // Format and return the response.
            return (object) [
                'data' => self::format_response($quiz, $user, $attempt, $attemptreview, $grade, $questions)
            ];
        } catch (\Exception $e) {
            // Unhandled error, return error message.
            return (object) [
                    'error' => (object) [
                        'type' => self::ERROR_UNKNOWN,
                        'message' => $e->getMessage()
                    ]
                ];
        }
    }

    /**
     * Gets the review data for the given attempt. Based on \mod_quiz_external::get_attempt_review.
     * @param int $attemptid ID of the quiz_attempt
     * @return array of question data with containing responses.
     */
    private static function get_attempt_review_data(int $attemptid): array {
        // Enforce page to be the first page (zero indexed, here).
        $page = 0;
        $review = false;

        $attemptobj = \quiz_attempt::create($attemptid);
        $displayoptions = $attemptobj->get_display_options($review);
        $slots = $attemptobj->get_slots($page);

        return array_map(function($slot) use ($attemptobj, $displayoptions) {
            $qattempt = $attemptobj->get_question_attempt($slot);

            // Get the necessary data.
            $qmark = $attemptobj->get_question_mark($slot);
            $mark = $qmark !== "" ? $qmark : null;

            $lastqtdata = $qattempt->get_last_qt_data();
            $data = !empty($lastqtdata) ? json_encode($lastqtdata) : null;

            $questionid = $qattempt->get_question_id();

            // An example of a non-real question is a description.
            if ($attemptobj->is_real_question($slot)) {
                $state = (string) $attemptobj->get_question_state($slot);
                $status = $attemptobj->get_question_status($slot, $displayoptions->correctness);
            }

            return [
                'mark' => $mark,
                'data' => $data,
                'state' => $state ?? null,
                'status' => $status ?? null,
                'questionid' => $questionid
            ];
        }, $slots);
    }

    /**
     * Formats a validation error response object.
     * @param string $errormsg Error message
     * @return object
     */
    private static function form_validation_error_response(string $errormsg): object {
        return (object) [
            'error' => (object) [
                'type' => self::ERROR_VALIDATION,
                'message' => $errormsg
            ]
        ];
    }

    /**
     * Formats a validation error response for validation functions.
     * @param string $code error code, used in lang string.
     * @return array array of valid (false) and error message
     */
    private static function form_validation_error(string $code): array {
        return [ false, get_string('error:validation:'.$code, 'local_headlessquiz') ];
    }

    /**
     * Abandons a given attempt
     * @param object $attempt
     */
    private static function abandon_attempt(object $attempt): void {
        $attemptobj = \quiz_attempt::create($attempt->id);
        $attemptobj->process_abandon(time(), false);
    }

    /**
     * Starts a quiz attempt.
     * @param object $user
     * @param object $quiz
     * @param int $attemptnumber attempt number (must be unique for this user and quiz)
     * @return object attempt object
     */
    private static function start_attempt(object $user, object $quiz, int $attemptnumber): object {
        $quizobj = \quiz::create($quiz->instance);

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $timenow = time();

        $attempt = quiz_create_attempt($quizobj, $attemptnumber, false, $timenow, false, $user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);
        return $attempt;
    }

    /**
     * Gets tha latest quiz attempt for the given user
     * @param object $user
     * @param object $quiz
     * @return ?object latest attempt or null (if no attempts)
     */
    private static function get_latest_attempt(object $user, object $quiz): ?object {
        $allattempts = quiz_get_user_attempts($quiz->instance, $user->id, 'all');

        // No previous attempt.
        if (empty($allattempts)) {
            return null;
        }

        // Sort by timestart.
        usort($allattempts, function($a, $b) {
            return $a->timestart > $b->timestart;
        });

        // Take the most recent.
        return array_pop($allattempts);
    }

    /**
     * Gets a quiz from a course module.
     * @param int $coursemoduleid
     * @return ?object $quiz object or null if not found
     */
    private static function get_quiz(int $coursemoduleid): ?object {
        // Don't specify the modulename so that we can get more detailed error messages in validate_quiz.
        $quiz = get_coursemodule_from_id('', $coursemoduleid);

        // Typehinting indicates we must return object or null, so cast false to null.
        return !empty($quiz) ? $quiz : null;
    }

    /**
     * Validates quiz against various criteria
     * @param object $quiz quiz or null
     * @return array array containing two values, [$valid, $errormsg]
     */
    private static function validate_quiz(?object $quiz): array {
        // Ensure the quiz exists.
        if (empty($quiz)) {
            return self::form_validation_error('missingquiz');
        }

        // Is it a quiz module?
        if (!isset($quiz->modname) || $quiz->modname !== 'quiz') {
            return self::form_validation_error('notaquiz');
        }

        $quizobj = \quiz::create($quiz->instance);

        // Load questions.
        $quizobj->preload_questions();
        $quizobj->load_questions();
        $questions = $quizobj->get_questions();

        // Ensure no question have a page !== 1.
        $invalidpagequestions = array_filter($questions, function($q) {
            return $q->page !== '1';
        });

        if (!empty($invalidpagequestions)) {
            return self::form_validation_error('notsinglepage');
        }

        // Are any of the questions a disallowed qtype ?
        $invalidqtypequestions = array_filter($questions, function($q) {
            return !in_array($q->qtype, self::SUPPORTED_QTYPES);
        });

        if (!empty($invalidqtypequestions)) {
            return self::form_validation_error('invalidqtype');
        }

        // Return valid, no error.
        return [ true, null ];
    }

    /**
     * Gets a user
     * @param int $userid
     * @return ?object $user object or null
     */
    private static function get_user(int $userid): ?object {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);

        // Typehinting indicates we must return object or null, so cast false to null.
        return !empty($user) ? $user : null;
    }

    /**
     * Validates user against various criteria
     * @param object $user user or null
     * @param int $courseid ID of course, to validate enrollment
     * @return array array containing two values, [$valid, $errormsg]
     */
    private static function validate_user(?object $user, int $courseid): array {
        // Does user exist?
        if (empty($user)) {
            return self::form_validation_error('missinguser');
        }

        // Is user enrolled in given course?
        $isenrolled = is_enrolled(\context_course::instance($courseid), $user);

        if (!$isenrolled) {
            return self::form_validation_error('usernotenrolled');
        }

        // Return valid, no error.
        return [ true, null ];
    }

    /**
     * Organises the given data into the response structure.
     * @param object $quiz
     * @param object $user
     * @param object $attempt
     * @param array $attemptreview
     * @param array $grade
     * @param array $questions
     */
    private static function format_response(object $quiz, object $user, object $attempt, array $attemptreview, array $grade,
        array $questions): object {

        // Clean the question data.
        $cleanedquestions = array_map(function($q) {
            return [
                'id' => $q->id,
                'name' => $q->name,
                'questiontext' => $q->questiontext
            ];
        }, $questions);

        // Package up into required structure.
        return (object) [
            'user' => [
                'id' => (int) $user->id
            ],
            'quiz' => [
                'id' => (int) $quiz->instance,
                'cmid' => (int) $quiz->id,
                'passinggrade' => $grade['gradetopass'] ?? null,
                'questions' => array_values($cleanedquestions)
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'state' => $attempt->state,
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'grade' => $grade['grade'] ?? null,
                'responses' => $attemptreview,
                'number' => (int) $attempt->attempt
            ]
        ];
    }
}
