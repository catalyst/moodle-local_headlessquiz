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
 * Headless quiz validation unit tests
 *
 * @package    local_headlessquiz
 * @copyright  2022 Catalyst IT
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_headlessquiz\api::get_headless_quiz
 */
class validation_test extends \advanced_testcase {

    /**
     * A selection of unsupported qtypes that allow unit testing (not all do).
     */
    private const UNSUPPORTED_TEST_QTYPES = [
        'essay',
        'description',
        'numerical'
    ];

    public function setUp(): void {
        $this->setAdminUser();
        $this->resetAfterTest(true);

        $this->dg = $this->getDataGenerator();
        $this->qg = $this->dg->get_plugin_generator('mod_quiz');
        $this->qsg = $this->dg->get_plugin_generator('core_question');

        $this->user = $this->dg->create_user();
        $this->course = $this->dg->create_course();
        $this->dg->enrol_user($this->user->id, $this->course->id);
        $this->quiz = $this->qg->create_instance(['course' => $this->course, 'grade' => 100.0, 'sumgrades' => 2,
            'gradepass' => 50.0]);
    }

    public function test_user_doesnt_exist() {
        // Call API with user ID that cannot exist.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, -1);

        // Should return validation error & no data.
        $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
        $this->assertEquals(get_string('error:validation:missinguser', 'local_headlessquiz'), $res->error->message);
        $this->assertFalse(isset($res->data));
    }

    public function test_quiz_doesnt_exist() {
        // Delete the quiz created in SetUp().
        course_delete_module($this->quiz->cmid);

        // Call API.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $this->user->id);

        // Should return validation error & no data.
        $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
        $this->assertEquals(get_string('error:validation:missingquiz', 'local_headlessquiz'), $res->error->message);
        $this->assertFalse(isset($res->data));
    }

    public function test_module_is_not_quiz() {
        // Create a different module e.g. label.
        $lg = $this->dg->get_plugin_generator('mod_label');
        $label = $lg->create_instance(['course' => $this->course]);

        // Call api.
        $res = \local_headlessquiz\api::get_headless_quiz($label->cmid, $this->user->id);

        // Should return validation error & no data.
        $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
        $this->assertEquals(get_string('error:validation:notaquiz', 'local_headlessquiz'), $res->error->message);
        $this->assertFalse(isset($res->data));
    }

    public function test_user_not_enrolled_in_course() {
        // Create a new user that is not enrolled in the course.
        $unenrolleduser = $this->dg->create_user();

        // Call api.
        $res = \local_headlessquiz\api::get_headless_quiz($this->quiz->cmid, $unenrolleduser->id);

        // Should return validation error & no data.
        $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
        $this->assertEquals(get_string('error:validation:usernotenrolled', 'local_headlessquiz'), $res->error->message);
        $this->assertFalse(isset($res->data));
    }

    public function test_quiz_question_type_validation() {
        // We only support specific types of questions.
        $supportedtypes = \local_headlessquiz\api::SUPPORTED_QTYPES;
        $category = $this->qsg->create_question_category();

        foreach ($supportedtypes as $qtype) {
            // Create quiz with this question type.
            $quiz = $this->qg->create_instance(['course' => $this->course, 'grade' => 100.0, 'sumgrades' => 2,
                'gradepass' => 50.0]);
            $question = $this->qsg->create_question($qtype, null, ['category' => $category->id, 'questiontext' => 'text']);
            quiz_add_quiz_question($question->id, $quiz);

            // Call api.
            $res = \local_headlessquiz\api::get_headless_quiz($quiz->cmid, $this->user->id);

            // Should not return validation error.
            $this->assertFalse(isset($res->error));
            $this->assertTrue(isset($res->data));
        }

        // While it would be ideal here to test all qtypes that are not in the $SUPPORTED_QTYPES array,
        // This might cause issues if for e.g. a bad qtype plugin is installed. Furthermore some qtypes
        // do not have test helper code, so instead we only select some examples for testing.
        foreach (self::UNSUPPORTED_TEST_QTYPES as $qtype) {
            // Create quiz with this question type.
            $quiz = $this->qg->create_instance(['course' => $this->course]);
            $question = $this->qsg->create_question($qtype, null, ['category' => $category->id]);
            quiz_add_quiz_question($question->id, $quiz);

            // Call api.
            $res = \local_headlessquiz\api::get_headless_quiz($quiz->cmid, $this->user->id);

            // Should return validation error.
            $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
            $this->assertEquals(get_string('error:validation:invalidqtype', 'local_headlessquiz'), $res->error->message);
            $this->assertFalse(isset($res->data));
        }
    }

    public function test_quiz_has_more_than_1_page() {
        // Make a quiz with 1 question per page.
        $quiz = $this->qg->create_instance(['course' => $this->course, 'questionsperpage' => 1]);
        $category = $this->qsg->create_question_category();

        // Then add 2 questions so there are two pages.
        $question1 = $this->qsg->create_question(\local_headlessquiz\api::SUPPORTED_QTYPES[0], null, ['category' => $category->id]);
        quiz_add_quiz_question($question1->id, $quiz);

        $question2 = $this->qsg->create_question(\local_headlessquiz\api::SUPPORTED_QTYPES[0], null, ['category' => $category->id]);
        quiz_add_quiz_question($question2->id, $quiz);

        $res = \local_headlessquiz\api::get_headless_quiz($quiz->cmid, $this->user->id);

        // Should return validation error.
        $this->assertEquals(\local_headlessquiz\api::ERROR_VALIDATION, $res->error->type);
        $this->assertFalse(isset($res->data));
    }
}