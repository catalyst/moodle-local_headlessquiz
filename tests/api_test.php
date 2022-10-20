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
            ['category' => $this->category->id, 'questiontext' => 'text']);
        quiz_add_quiz_question($this->question->id, $this->quiz);
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

        $expectedata = (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'cmid' => (int) $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'state' => $attempt->state,
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'grade' => null,
                'number' => 1,
                'responses' => [
                    [
                        'questionid' => $this->question->id,
                        'state' => \question_state::get('todo'),
                        'status' => 'Not yet answered',
                        'mark' => null,
                        'data' => null
                    ]
                ]
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }

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

        $expectedata = (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'cmid' => (int) $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'state' => $attempt->state,
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'grade' => null,
                'number' => 1,
                'responses' => [
                    [
                        'questionid' => $this->question->id,
                        'state' => \question_state::get('todo'),
                        'status' => 'Not yet answered',
                        'mark' => null,
                        'data' => null
                    ]
                ]
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }

    public function test_with_finished_previous_attempt() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $attemptobj = $this->start_attempt();

        // Answer the question and finish the attempt.
        $attemptobj = $this->finish_attempt($attemptobj, [ 1 => ['answer' => 'test' ]]);

        // Get quiz made in setUp(), forcenew = false.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, false);

        $attemptreview = \mod_quiz_external::get_attempt_review($attemptobj->get_attempt()->id);
        $responses = $attemptreview['questions'];

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Verify the expected data is returned.
        $expectedata = (object) [
            'user' => [
                'id' => $this->user->id
            ],
            'quiz' => [
                'id' => $this->quiz->id,
                'cmid' => $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $attemptobj->get_attempt()->id,
                'state' => $attemptobj->get_attempt()->state,
                'timestart' => (int) $attemptobj->get_attempt()->timestart,
                'timemodified' => (int) $attemptobj->get_attempt()->timemodified,
                'grade' => 0,
                'number' => 1,
                'responses' => array_map(function($r) {
                    return([
                        'questionid' => (int) $this->question->id,
                        'state' => $r['state'],
                        'status' => $r['status'],
                        'mark' => $r['mark'],
                        'data' => '{"answer":"test"}'
                    ]);
                }, $responses)
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }

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

        $expectedata = (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'cmid' => (int) $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $attempt->id,
                'state' => $attempt->state,
                'timestart' => (int) $attempt->timestart,
                'timemodified' => (int) $attempt->timemodified,
                'grade' => null,
                'number' => 1,
                'responses' => [
                    [
                        'questionid' => (int) $this->question->id,
                        'state' => \question_state::get('todo'),
                        'status' => 'Not yet answered',
                        'mark' => null,
                        'data' => null
                    ]
                ]
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }

    public function test_with_inprogress_previous_attempt_forcenew() {
        // Quiz is already made in setup. Start an attempt here (outside of the API).
        $this->start_attempt();

        // Get quiz made in setUp(), forcenew = true.
        // Should abandon the previous attempt and start a new one.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id, true);

        // Verify no errors returned.
        $this->assertFalse(isset($res->error));

        // Get this users attempts via the quiz api to verify (should be 1 attempt).
        $attempts = array_values(quiz_get_user_attempts($this->quiz->id, $this->user->id, 'all'));
        $this->assertCount(2, $attempts);

        $abandondedattempt = $attempts[0];
        $this->assertEquals('abandoned', $abandondedattempt->state);

        $newattempt = $attempts[1];
        $this->assertFalse(empty($newattempt));

        $expectedata = (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'cmid' => (int) $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $newattempt->id,
                'state' => $newattempt->state,
                'timestart' => (int) $newattempt->timestart,
                'timemodified' => (int) $newattempt->timemodified,
                'grade' => null,
                'number' => 2,
                'responses' => [
                    [
                        'questionid' => (int) $this->question->id,
                        'state' => \question_state::get('todo'),
                        'status' => 'Not yet answered',
                        'mark' => null,
                        'data' => null
                    ]
                ]
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }

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

        $expectedata = (object) [
            'user' => [
                'id' => (int) $this->user->id
            ],
            'quiz' => [
                'id' => (int) $this->quiz->id,
                'cmid' => (int) $this->quiz->cmid,
                'passinggrade' => 50.00000,
                'questions' => [
                    [
                        'id' => (int) $this->question->id,
                        'name' => $this->question->name,
                        'questiontext' => $this->question->questiontext
                    ]
                ]
            ],
            'attempt' => [
                'id' => (int) $newattempt->id,
                'state' => $newattempt->state,
                'timestart' => (int) $newattempt->timestart,
                'timemodified' => (int) $newattempt->timemodified,
                'grade' => 0.0,
                'number' => 2,
                'responses' => [
                    [
                        'questionid' => (int) $this->question->id,
                        'state' => \question_state::get('todo'),
                        'status' => 'Not yet answered',
                        'mark' => null,
                        'data' => null
                    ]
                ]
            ]
        ];

        $this->assertEquals($expectedata, $res->data);
    }
}
