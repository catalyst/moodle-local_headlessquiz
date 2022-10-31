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

/**
 * Headless quiz unit tests
 *
 * @package    local_headlessquiz
 * @copyright  2022 Catalyst IT
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_headlessquiz\api::get_headless_quiz
 */
class api_test extends \advanced_testcase {
    public function setUp(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $this->dg = $this->getDataGenerator();
        $this->qg = $this->dg->get_plugin_generator('mod_quiz');
        $this->qsg = $this->dg->get_plugin_generator('core_question');

        $this->user = $this->dg->create_user();
        $this->course = $this->dg->create_course();
        $this->dg->enrol_user($this->user->id, $this->course->id);

        // Create quiz and add a single question.
        $this->quiz = $this->qg->create_instance(['course' => $this->course, 'grade' => 100.0, 'sumgrades' => 2,
            'gradepass' => 50.0]);
        $this->category = $this->qsg->create_question_category();
        $this->question = $this->qsg->create_question(\local_headlessquiz\api::SUPPORTED_QTYPES[0], null,
            ['category' => $this->category->id]);
        quiz_add_quiz_question($this->question->id, $this->quiz);

        // Load the questions from the quiz so they get quiz related data (slot, etc..).
        $quizobj = \quiz::create($this->quiz->id);
        $quizobj->preload_questions();
        $quizobj->load_questions();
        $questions = $quizobj->get_questions();
        $this->question = current($questions);
    }

    /**
     * Starts the attempt for quiz created in setUp().
     * @return object $attempt
     */
    private function start_attempt() {
        $quba = \question_engine::make_questions_usage_by_activity('mod_quiz', \context_module::instance($this->quiz->cmid));
        $quba->set_preferred_behaviour($this->quiz->preferredbehaviour);
        $timenow = time();

        $quizobj = \quiz::create($this->quiz->id);
        $attempt = quiz_create_attempt($quizobj, 1, false, $timenow, false, $this->user->id);
        quiz_start_new_attempt($quizobj, $quba, $attempt, 1, $timenow);
        quiz_attempt_save_started($quizobj, $quba, $attempt);

        return $attempt;
    }

    /**
     * Finishes the given attempt.
     * @param object $attempt
     * @param array $answers answers to submit to attempt
     * @return \quiz_attempt $attempt;
     */
    private function finish_attempt(object $attempt, array $answers) {
        $timenow = time();
        $attemptobj = \quiz_attempt::create($attempt->id);

        // Finish the attempt.
        $attemptobj->process_submitted_actions($timenow, false, $answers);
        $attemptobj->process_finish($timenow, false);

        return $attemptobj;
    }

    /**
     * Builds the expected return using the data set in $this context
     * @param object $attempt attempt stdClass data
     * @param array $responses array of responses, or null to use default response (a 'todo' response).
     * @return object expected response output - compared against the return from the api function.
     */
    private function get_expected_response(object $attempt, ?array $responses): object {
        $attemptreview = \mod_quiz_external::get_attempt_review($attempt->id);

        // If expected responses are not given, add the default response.
        if ($responses === null) {
            $questionreview = (object) array_pop($attemptreview['questions']);
            $responses = [
                [
                    'questionid' => (int) $this->question->id,
                    'state' => (string) \question_state::get('todo'),
                    'status' => 'Not yet answered',
                    'mark' => null,
                    'data' => null,
                    'slot' => (int) $this->question->slot,
                    'sequencecheck' => $questionreview->sequencecheck,
                    'html' => '',
                    'feedback' => ''
                ]
            ];
        }

        // Build expected return.
        return (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'name' => $this->quiz->name,
                'cmid' => (int) $this->quiz->cmid,
                'gradetopass' => 50.0,
                'bestgrade' => null,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext,
                        'type' => 'shortanswer',
                        'options' => [],
                        'slot' => (int) $this->question->slotid
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'state' => $attempt->state,
                'feedback' => '',
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'summarks' => 0.0,
                'scaledgrade' => 0.0,
                'passed' => false,
                'number' => (int) $attempt->attempt,
                'responses' => [...$responses]
            ]
        ];
    }

    /**
     * Strips parts from a response that we don't test.
     * For e.g, question attempt html.
     * @param object $data response data
     * @return object cleaned data
     */
    private function strip_ignored_response_parts($data) {
        // Clean the response data. Currently, this only cleans the HTML.
        // This is because every time the HTML is generated, it has different values because of timestamps and random ID values.
        // So it is silly to compare it by value, so instead we compare by existence.
        $data->attempt['responses'] = array_map(function($r) {
            // Return '' if it existed, so we can still check the existence, just not the exact value.
            $r['html'] = isset($r['html']) ? '' : null;
            $r['feedback'] = isset($r['feedback']) ? '' : null;
            return $r;
        }, $data->attempt['responses']);

        // Strip 'options' (aka settings) from the questions.
        $data->quiz['questions'] = array_map(function($q) {
            $q['options'] = [];
            return $q;
        }, $data->quiz['questions']);

        return $data;
    }

    /**
     * Tests getting the headless quiz with no previous attempt for a user
     * Expects a new attempt to be created.
     */
    public function test_with_no_previous_attempt() {
        // Get quiz made in setUp(), forcenew = false.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, false);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all');
        $this->assertCount(1, $attempts);

        $attempt = array_pop($attempts);
        $this->assertFalse(empty($attempt));

        $expectedata = $this->get_expected_response($attempt, null);
        $datacleaned = $this->strip_ignored_response_parts($res->data);

        $this->assertEquals($expectedata, $datacleaned);
    }

    /**
     * Tests getting the headless quiz with a previous inprogress attempt
     * Expects the previous attempt to be returned
     */
    public function test_with_inprogress_previous_attempt() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $this->start_attempt();

        // Get quiz made in setUp(), forcenew = false.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, false);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all');
        $this->assertCount(1, $attempts);

        $attempt = array_pop($attempts);
        $this->assertFalse(empty($attempt));

        $expectedata = $this->get_expected_response($attempt, null);
        $datacleaned = $this->strip_ignored_response_parts($res->data);

        $this->assertEquals($expectedata, $datacleaned);
    }

    /**
     * Tests getting the headless quiz with a previous finished event.
     * Expects that the previous attempt be returned.
     */
    public function test_with_finished_previous_attempt() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $attemptobj = $this->start_attempt();

        // Answer the question and finish the attempt.
        $attemptobj = $this->finish_attempt($attemptobj, [ 1 => ['answer' => 'test' ]]);
        $attempt = $attemptobj->get_attempt();

        // Get quiz made in setUp(), forcenew = false.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, false);

        $attemptreview = \mod_quiz_external::get_attempt_review($attemptobj->get_attempt()->id);
        $responses = $attemptreview['questions'];

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        $expectedresponses = array_map(function($r) use($attemptobj) {
            // Get the latest step to get the attempt response data.
            $qattempt = $attemptobj->get_question_attempt((int) $r['slot']);
            $lastqtdata = $qattempt->get_last_qt_data();
            $data = !empty($lastqtdata) ? $lastqtdata : null;

            return([
                'questionid' => (int) $this->question->id,
                'state' => $r['state'],
                'status' => $r['status'],
                'mark' => $r['mark'],
                'data' => json_encode($data),
                'slot' => (int) $r['slot'],
                'sequencecheck' => (int) $r['sequencecheck'],
                'html' => $r['html']
            ]);
        }, $responses);

        $expecteddata = $this->get_expected_response($attempt, $expectedresponses);

        $datacleaned = $this->strip_ignored_response_parts($res->data);
        $expectedatacleaned = $this->strip_ignored_response_parts($expecteddata);

        $this->assertEquals($expectedatacleaned, $datacleaned);
    }

    /**
     * Tests with $forcenew=true getting the headless quiz with no previous attempt
     * Expects a new attempt to be created.
     */
    public function test_with_no_previous_attempt_forcenew() {
        // Get quiz made in setUp(), forcenew = true.
        // Should do nothing different, since there is not a previous attempt.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, true);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all');
        $this->assertCount(1, $attempts);

        $attempt = array_pop($attempts);
        $this->assertFalse(empty($attempt));

        $expectedata = $this->get_expected_response($attempt, null);
        $datacleaned = $this->strip_ignored_response_parts($res->data);

        $this->assertEquals($expectedata, $datacleaned);
    }

    /**
     * Tests with $forcenew=true getting the headless quiz with a previous inprogress attempt
     * Expects a new attempt to be created and the previous one to be finished.
     */
    public function test_with_inprogress_previous_attempt_forcenew() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $this->start_attempt();

        // Get quiz made in setUp(), forcenew = true.
        // Should finish the previous attempt and start a new one.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, true);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = array_values(quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all'));
        $this->assertCount(2, $attempts);

        $finishedattempt = $attempts[0];
        $this->assertEquals('finished', $finishedattempt->state);

        $newattempt = $attempts[1];
        $this->assertFalse(empty($newattempt));

        $expectedata = $this->get_expected_response($newattempt, null);
        $datacleaned = $this->strip_ignored_response_parts($res->data);

        $this->assertEquals($expectedata, $datacleaned);
    }

    /**
     * Tests with $forcenew=true getting the headless quiz with a previous finished attempt
     * Expects a new attempt to be created and the previous to remain finished.
     */
    public function test_with_finished_previous_attempt_forcenew() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $attempt = $this->start_attempt();
        $this->finish_attempt($attempt, [ 1 => ['answer' => 'test' ]]);

        // Get quiz made in setUp(), forcenew = true.
        // Should NOT abandon the previous attempt (since it is finished) but should start a new one.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, true);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = array_values(quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all'));
        $this->assertCount(2, $attempts);

        $previousattempt = $attempts[0];
        $this->assertEquals('finished', $previousattempt->state);

        $newattempt = $attempts[1];
        $this->assertFalse(empty($newattempt));

        $expectedata = $this->get_expected_response($newattempt, null);
        $datacleaned = $this->strip_ignored_response_parts($res->data);

        $this->assertEquals($expectedata, $datacleaned);
    }

    /**
     * Tests using forcenew where builonlast is enabled.
     */
    public function test_with_buildonlast_forcenew() {
        // Update the quiz in the constructor to have this setting enabled.
        global $DB;
        $this->quiz->attemptonlast = true;
        $DB->update_record('quiz', $this->quiz);
        $quizobj = \quiz::create($this->quiz->id);
        $this->assertEquals('1', $quizobj->get_quiz()->attemptonlast);

        // Call to start an attempt.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id);
        $this->assertTrue(isset($res->data));

        // Then start a new attempt with forcenew.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, true);
        $this->assertTrue(isset($res->data));
    }
}
