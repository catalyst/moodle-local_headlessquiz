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

use qtype_random;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/attemptlib.php');
require_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
require_once($CFG->libdir . '/gradelib.php');

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
        'multichoice',
        'random'
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
            $user = \core_user::get_user($userid);
            $user = !empty($user) ? $user : null;
            [$validuser, $usererror] = self::validate_user($user, $quiz->course);

            if (!$validuser) {
                return self::form_validation_error_response($usererror);
            }

            $quizobj = \quiz::create($quiz->instance);

            // Get latest attempt for this user.
            $attempt = self::get_latest_attempt($user, $quiz);

            // If no attempt OR forcenew, start an attempt.
            if (empty($attempt) || $forcenew === true) {

                // If previous attempt was inprogress, abandon it.
                if (!empty($attempt) && $attempt->state === 'inprogress') {
                    $attemptobj = \quiz_attempt::create($attempt->id);
                    $attemptobj->process_finish(time(), false);
                }

                // Start a new attempt.
                $nextattemptnumber = empty($attempt) ? 1 : $attempt->attempt + 1;
                $attempt = self::start_attempt($user, $quiz, $nextattemptnumber, $attempt);
            }
            $attemptobj = \quiz_attempt::create($attempt->id);

            // Get attempt review data (attempts, grade, state, status, etc...).
            $attemptreview = self::get_attempt_review_data($attempt->id);

            // Get all the quiz questions.
            $quizobj->preload_questions();
            $quizobj->load_questions();
            $questions = $quizobj->get_questions();

            // Get the attempt feedback.
            $attemptfeedback = self::get_attempt_data($attemptobj);

            // Get the quiz grade data from the gradebook.
            $gradedata = self::get_grade_data($quizobj, $userid);

            // Format and return the response.
            return (object) [
                'data' => self::format_response($quiz, $user, $attempt, $attemptreview, $attemptfeedback, $gradedata, $questions)
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
     * Get the grade data for the quiz and user
     * @param \quiz $quizobj
     * @param int $userid
     * @return array array of gradetopass and bestgrade
     */
    private static function get_grade_data(\quiz $quizobj, int $userid) {
        $gradedata = \mod_quiz_external::get_user_best_grade($quizobj->get_quizid(), $userid);

        // We calculate the best grade manually, since the calculation in get_user_best_grade is not always correct.
        $attempts = quiz_get_user_attempts($quizobj->get_quizid(), $userid);
        $bestgrade = quiz_calculate_best_grade($quizobj->get_quiz(), $attempts);
        $bestgrade = quiz_rescale_grade($bestgrade, $quizobj->get_quiz(), false);
        $gradeitem = (object) grade_get_grades($quizobj->get_courseid(), 'mod', 'quiz', $quizobj->get_quizid(), $userid);

        return [
            'gradetopass' => $gradedata['gradetopass'] ?? null,
            'bestgrade' => $bestgrade ?? null,
            'maxgrade' => !empty($gradeitem->items) ? (float) $gradeitem->items[0]->grademax : null
        ];
    }

    /**
     * Get the attempt data.
     * @param \quiz_attempt $attemptobj
     * @return array of attempt data such as feedback, scaled grade, and marks sum
     */
    private static function get_attempt_data(\quiz_attempt $attemptobj): array {
        // Get the attempt grade, replacing 'not yet graded' with zero.
        $summarks = $attemptobj->get_sum_marks();
        $attemptgrade = quiz_rescale_grade($summarks, $attemptobj->get_quiz(), false);
        $attemptgrade = gettype($attemptgrade) == 'string' ? 0 : $attemptgrade;
        $attemptfeedback = $attemptobj->get_overall_feedback($attemptgrade);

        // Get the required grade to pass to determine if the user has passed or not.
        $bestgradedata = \mod_quiz_external::get_user_best_grade($attemptobj->get_quizid(), $attemptobj->get_userid());

        // This must be set otherwise we cannot determine if the user has 'passed' or not.
        if (isset($bestgradedata['gradetopass'])) {
            $gradetopass = (float) $bestgradedata['gradetopass'];
            $passed = $attemptgrade >= $gradetopass;
        } else {
            $passed = null;
        }

        return [
            'summarks' => $summarks,
            'scaledgrade' => $attemptgrade,
            'feedback' => $attemptfeedback,
            'passed' => $passed
        ];
    }

    /**
     * Gets the review data for the given attempt. Based on \mod_quiz_external::get_attempt_review.
     * @param int $attemptid ID of the quiz_attempt
     * @return array of question data with containing responses.
     */
    private static function get_attempt_review_data(int $attemptid): array {
        global $PAGE;
        $review = true;
        $page = 0;

        // Cannot use \mod_quiz_external::get_attempt_review here since it enforces that the attempt be finished.
        $attemptobj = \quiz_attempt::create($attemptid);
        $displayoptions = $attemptobj->get_display_options($review);
        $slots = $attemptobj->get_slots($page);
        $renderer = $PAGE->get_renderer('mod_quiz');

        return array_map(function($slot) use ($attemptobj, $displayoptions, $review, $renderer, $PAGE) {
            $qattempt = $attemptobj->get_question_attempt($slot);

            $lastqtdata = $qattempt->get_last_qt_data();
            $data = !empty($lastqtdata) ? json_encode($lastqtdata) : null;

            $questionid = $qattempt->get_question_id();

            // An example of a non-real question is a description 'question'.
            if ($attemptobj->is_real_question($slot)) {
                $state = (string) $attemptobj->get_question_state($slot);
                $status = $attemptobj->get_question_status($slot, $displayoptions->correctness);
            }

            $html = $attemptobj->render_question($slot, $review, $renderer);

            $slot = $qattempt->get_slot();

            $sequencecheck = $qattempt->get_sequence_check_count();

            // Get the various feedback responses.
            $question = $qattempt->get_question();
            $qrenderer = $question->get_renderer($PAGE);
            $feedback = $qrenderer->feedback($qattempt, $displayoptions);

            $qmark = $attemptobj->get_question_mark($slot);
            $mark = $qmark !== "" ? (float) $qmark : null;

            return [
                'mark' => $mark,
                'data' => $data,
                'state' => $state ?? null,
                'status' => $status ?? null,
                'questionid' => (int) $questionid,
                'html' => $html,
                'slot' => (int) $slot,
                'sequencecheck' => (int) $sequencecheck,
                'feedback' => $feedback
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
        return [false, get_string('error:validation:'.$code, 'local_headlessquiz')];
    }

    /**
     * Starts a quiz attempt.
     * @param object $user
     * @param object $quiz
     * @param int $attemptnumber attempt number (must be unique for this user and quiz)
     * @param object $prevattempt the previous attempt, required if $attemptonlast is enabled for the quiz.
     * @return object attempt object
     */
    private static function start_attempt(object $user, object $quiz, int $attemptnumber, ?object $prevattempt): object {
        $quizobj = \quiz::create($quiz->instance);

        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', $quizobj->get_context());
        $quba->set_preferred_behaviour($quizobj->get_quiz()->preferredbehaviour);
        $timenow = time();

        $attempt = quiz_create_attempt($quizobj, $attemptnumber, $prevattempt, $timenow, false, $user->id);

        // If build on last attempt is enabled, we need to call a different function.
        // Otherwise the previous answers will not be saved.
        if ($quizobj->get_quiz()->attemptonlast && !empty($prevattempt)) {
            quiz_start_attempt_built_on_last($quba, $attempt, $prevattempt);
        } else {
            quiz_start_new_attempt($quizobj, $quba, $attempt, $attemptnumber, $timenow);
        }

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
        if (self::contains_invalid_qtype($questions)) {
            return self::form_validation_error('invalidqtype');
        }

        // This won't get every single possible case, but it covers the most common ones.
        if (self::contains_invalid_q_contents($questions)) {
            return self::form_validation_error('invalidqcontent');
        }

        // Return valid, no error.
        return [true, null];
    }

    /**
     * Determines if the array of questions contains an invalid qtype.
     * If a qtype is 'random', it recursively checks the linked category question types.
     *
     * @param array $questions array of questions
     * @return bool true if contains an invalid question, else false.
     */
    private static function contains_invalid_qtype(array $questions): bool {
        $invalidqtypequestions = array_filter($questions, function($q) {

            // Can either come in as string or qtype class, in that case call func to get the name.
            $qtypestr = is_string($q->qtype) ? $q->qtype : $q->qtype->name();

            if (!in_array((string) $qtypestr, self::SUPPORTED_QTYPES)) {
                return true;
            };

            // Check for random Qtype.
            if ((string) $qtypestr == 'random') {
                $randomqquestions = self::get_random_qtype_questions($q);

                // Random Qtype not linked to any questions.
                if (empty($randomqquestions)) {
                    return true;
                }

                if (self::contains_invalid_qtype($randomqquestions)) {
                    return true;
                }
            }

            return false;
        });
        return count($invalidqtypequestions) > 0;
    }

    /**
     * Determines if the array of questions contains a question with invalid contents
     * If a qtype is 'random', it recursively checks the linked category questions' contents.
     *
     * @param array $questions array of questions
     * @return bool true if contains an invalid question, else false.
     */
    private static function contains_invalid_q_contents(array $questions): bool {
        $invalidquestioncontents = array_filter($questions, function($q) {
            $qtypestr = is_string($q->qtype) ? $q->qtype : $q->qtype->name();

            // Question is empty it is invalid.
            if (empty($q)) {
                return true;
            }

            if ($qtypestr == 'random') {
                // We don't validate random qtype directly, we validate all the linked questions.
                $linkedquestions = self::get_random_qtype_questions($q);
                return self::contains_invalid_q_contents($linkedquestions);
            }

            // No question text - invalid.
            if (empty($q->questiontext)) {
                return true;
            }

            // Check for various things using regex.
            $pluginfileplaceholders = preg_match("/(@@PLUGINFILE@@)/", $q->questiontext);

            // Match image tags (but not image links, since it could be a hyperlink which technically is allowed).
            $imgtags = preg_match("/(<img)/", $q->questiontext);

            $checks = [
                $pluginfileplaceholders,
                $imgtags
            ];

            // See if any of the regex patterns matched.
            return in_array(1, $checks);
        });

        return count($invalidquestioncontents) > 0;
    }

    /**
     * Gets the questions linked to the random question.
     *
     * @param object $randomq the question that is of qtype 'random'
     * @return array array of questions linked to this random question.
     */
    private static function get_random_qtype_questions(object $randomq) {
        $conditions = json_decode($randomq->filtercondition);
        $categoryid = $conditions->questioncategoryid;
        $includesub = $conditions->includingsubcategories;

        // Find the linked questions to this random qtype.
        $qtyperandom = \question_bank::get_qtype('random');
        $questionids = $qtyperandom->get_available_questions_from_category($categoryid, $includesub);
        $questions = array_map(function($questionid) {
            return \question_bank::load_question($questionid);
        }, $questionids);

        return $questions;
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
        return [true, null];
    }

    /**
     * Organises the given data into the response structure.
     *
     * @param object $quiz quiz object
     * @param object $user user object
     * @param object $attempt attempt object (id, etc...)
     * @param array $attemptreview attempt review (responses to questions)
     * @param array $attemptfeedback attempt feedback (feedback string, grade, marks)
     * @param array $grade grade data (grade to pass, max grade)
     * @param array $questions array of questions for this quiz
     */
    private static function format_response(object $quiz, object $user, object $attempt, array $attemptreview,
        array $attemptfeedback, array $grade, array $questions): object {

        // Clean the question data.
        $cleanedquestions = array_map(function($q) use($attempt) {
            // If the qtype is random, we need to find the question
            // that was randomly chosen for this attempt.
            if ($q->qtype == "random") {
                $attemptobj = \quiz_attempt::create($attempt->id);
                $qattempt = $attemptobj->get_question_attempt($q->slot);
                $realquestionid = $qattempt->get_question_id();
                $q = \question_bank::load_question_data($realquestionid);
                $q->slotid = $qattempt->get_slot();
            }

            return [
                'id' => (int) $q->id,
                'name' => $q->name,
                'questiontext' => $q->questiontext,
                'options' => isset($q->options) ? json_encode($q->options) : null,
                'type' => $q->qtype,
                'slot' => (int) $q->slotid
            ];
        }, $questions);

        // Package up into required structure.
        return (object) [
            'user' => [
                'id' => (int) $user->id
            ],
            'quiz' => [
                'id' => (int) $quiz->instance,
                'name' => $quiz->name,
                'cmid' => (int) $quiz->id,
                'gradetopass' => $grade['gradetopass'],
                'questions' => array_values($cleanedquestions),
                'maxgrade' => $grade['maxgrade'],
                'bestgrade' => $grade['bestgrade']
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'feedback' => $attemptfeedback['feedback'],
                'summarks' => (float) $attemptfeedback['summarks'],
                'scaledgrade' => (float) $attemptfeedback['scaledgrade'],
                'passed' => $attemptfeedback['passed'],
                'state' => $attempt->state,
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'responses' => $attemptreview,
                'number' => (int) $attempt->attempt
            ]
        ];
    }
}
