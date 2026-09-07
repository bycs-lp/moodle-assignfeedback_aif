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
 * Tests for AI feedback tasks, observer and external API.
 *
 * @package    assignfeedback_aif
 * @category   test
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aif;

use assignfeedback_aif\task\process_feedback_adhoc;
use assignfeedback_aif\external\regenerate_feedback;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../tests/generator.php');
require_once(__DIR__ . '/generator_trait.php');

/**
 * Tests for scheduled tasks, adhoc tasks, event observer and external API.
 *
 * @package    assignfeedback_aif
 * @copyright  2024 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \assignfeedback_aif\task\process_feedback_adhoc
 * @covers \assignfeedback_aif\event\observer
 * @covers \assignfeedback_aif\external\regenerate_feedback
 */
final class process_feedback_test extends \advanced_testcase {
    use aif_test_helper;

    /**
     * Set up the DI mock for the AI request provider before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->setup_ai_mock();
    }

    /**
     * Register a mock AI request provider in the DI container.
     *
     * @param string $response The response to return from the mock.
     */
    private function setup_ai_mock(string $response = 'AI Feedback'): void {
        $mock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $mock->method('perform_request_core_ai')->willReturn($response);
        $mock->method('perform_request_local_ai_manager')->willReturn($response);
        $mock->method('is_available')->willReturn(true);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $mock);
    }


    /**
     * Test the adhoc task generates feedback for a specific user.
     *
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_adhoc_task_generates_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student assignment text');
        $this->create_aif_config($env, 'Provide feedback');

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($env->assign->id),
            'userid' => intval($env->student->id),
            'action' => 'generate',
        ]);
        $task->set_userid($env->student->id);

        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test auto-triggered generation runs extraction and AI request as the student.
     *
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_adhoc_task_auto_trigger_uses_student_as_acting_user(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Provide feedback');
        $this->create_and_submit($env, 'Student assignment text');

        $captured = $this->capture_acting_users();

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($env->assign->id),
            'userid' => intval($env->student->id),
            'action' => 'generate',
        ]);
        $task->set_userid($env->student->id);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals($env->student->id, $captured->extractionuserid);
        $this->assertEquals($env->student->id, $captured->requestuserid);
    }

    /**
     * Test manual-triggered generation runs extraction and AI request as the teacher.
     *
     * Covers both the grading view trigger and the bulk grading action, which queue
     * the task with the teacher as task runner.
     *
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_adhoc_task_manual_trigger_uses_teacher_as_acting_user(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Provide feedback');
        $this->create_and_submit($env, 'Student assignment text');

        $captured = $this->capture_acting_users();

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($env->assign->id),
            'userid' => intval($env->student->id),
            'action' => 'generate',
        ]);
        $task->set_userid($env->teacher->id);

        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals($env->teacher->id, $captured->extractionuserid);
        $this->assertEquals($env->teacher->id, $captured->requestuserid);
    }

    /**
     * Test a manual trigger re-points an already queued automatic task to the triggering user.
     *
     * The task runner defines the user all AI requests are performed for, so a teacher
     * requesting feedback while the automatically queued task of the student is still
     * waiting must not be silently downgraded to a run as the student.
     *
     * @covers \assignfeedback_aif\local\task_manager::queue_generation
     */
    public function test_queue_generation_repoints_queued_task_to_new_task_user(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Provide feedback');

        // Automatic trigger on submission: the task runs as the student.
        \assignfeedback_aif\local\task_manager::queue_generation(
            $env->assign->id,
            $env->student->id,
            $env->student->id
        );
        $task = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        $this->assertEquals($env->student->id, $task->get_userid());

        // Manual trigger by the teacher while the automatic task is still queued.
        \assignfeedback_aif\local\task_manager::queue_generation(
            $env->assign->id,
            $env->student->id,
            $env->teacher->id
        );

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_feedback_adhoc::class));
        $task = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        $this->assertEquals($env->teacher->id, $task->get_userid());

        // A repeated trigger by the same user must not queue an additional task.
        \assignfeedback_aif\local\task_manager::queue_generation(
            $env->assign->id,
            $env->student->id,
            $env->teacher->id
        );

        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_feedback_adhoc::class));
    }

    /**
     * Register an aif mock in the DI container that records the acting user IDs.
     *
     * @return \stdClass Object whose 'extractionuserid' and 'requestuserid' are filled during execution.
     */
    private function capture_acting_users(): \stdClass {
        $captured = (object) ['extractionuserid' => null, 'requestuserid' => null];

        $aifmock = $this->createMock(\assignfeedback_aif\aif::class);
        $aifmock->method('set_contextid');
        $aifmock->method('get_prompt')->willReturnCallback(
            function (\stdClass $assignment, string $gradingmethod, int $actinguserid) use ($captured): array {
                $captured->extractionuserid = $actinguserid;
                return ['prompt' => 'Mock prompt text', 'options' => [], 'skippedfiles' => []];
            }
        );
        $aifmock->method('perform_request')->willReturnCallback(
            function (string $prompt, int $userid, string $purpose = 'feedback', array $options = []) use ($captured): string {
                $captured->requestuserid = $userid;
                return 'Mock feedback';
            }
        );
        $aifmock->method('append_disclaimer')->willReturnArgument(0);
        \core\di::set(\assignfeedback_aif\aif::class, $aifmock);

        return $captured;
    }

    /**
     * Test the adhoc task deletes feedback for a specific user.
     *
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_adhoc_task_deletes_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student text');
        $aifid = $this->create_aif_config($env, 'Test prompt');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Insert feedback to delete.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Feedback to remove',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback'));

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($env->assign->id),
            'userid' => intval($env->student->id),
            'action' => 'delete',
        ]);
        $task->set_userid($env->student->id);
        ob_start();
        $task->execute();
        ob_end_clean();

        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback'));
    }

    /**
     * Test that the observer queues an adhoc task when autogenerate is enabled.
     *
     * @covers \assignfeedback_aif\event\observer::submission_submitted
     */
    public function test_observer_queues_task_when_autogenerate_enabled(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Analyse grammar', 1);

        // Submit as student — this triggers the assessable_submitted event.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'My submission for auto-generate test',
        ];
        $generator->create_submission($submissiondata);

        // Count adhoc tasks before submission.
        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();

        // An adhoc task should have been queued.
        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);
        $this->assertGreaterThan($tasksbefore, $tasksafter);

        // Verify the queued task contains the correct student userid.
        $task = $DB->get_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertEquals(
            $env->student->id,
            $customdata->userid,
            'Adhoc task must contain the submitting student userid'
        );
    }

    /**
     * Diagnostic test: trace every checkpoint in the autogenerate chain (submissiondrafts=1).
     *
     * Uses submit_for_grading() which is the path when "Require students to click submit" is YES.
     * Events are NOT intercepted with redirectEvents() so the observer actually runs.
     *
     * @covers \assignfeedback_aif\event\observer::submission_submitted
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_observer_autogenerate_diagnostic(): void {
        global $DB;
        $this->resetAfterTest();

        // Setup: assignment with submissiondrafts=1 and autogenerate enabled.
        $env = $this->create_test_environment([
            'assignfeedback_aif_autogenerate' => 1,
            'submissiondrafts' => 1,
        ]);

        // CP1: AIF config record exists with autogenerate=1.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        $this->assertNotEmpty(
            $aifconfig,
            'CP1a: assignfeedback_aif record must exist for assign.id=' . $env->assign->id
            . '. All records: ' . json_encode($DB->get_records('assignfeedback_aif'))
        );
        $this->assertEquals(1, (int) $aifconfig->autogenerate, 'CP1b: autogenerate must be 1');

        // CP2: AIF plugin enabled on assignment.
        $aifenabled = false;
        foreach ($env->assignobj->get_feedback_plugins() as $plugin) {
            if ($plugin->get_type() === 'aif') {
                $aifenabled = !empty($plugin->is_enabled());
                break;
            }
        }
        $this->assertTrue($aifenabled, 'CP2: AIF feedback plugin must be enabled');

        // Student creates a draft submission.
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $generator->create_submission([
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'Diagnostic test submission text',
        ]);

        // CP3: Submission record exists.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotEmpty($submission, 'CP3: assign_submission record must exist');

        // Count tasks before submission.
        $taskclass = '\\assignfeedback_aif\\task\\process_feedback_adhoc';
        $tasksbefore = $DB->count_records('task_adhoc', ['classname' => $taskclass]);

        // Submit_for_grading fires assessable_submitted, observer runs, task queued.
        // Only redirect messages (notifications), NOT events, so the observer runs.
        $msgsink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $msgsink->close();

        // CP4: Adhoc task was queued by the observer.
        $tasksafter = $DB->count_records('task_adhoc', ['classname' => $taskclass]);
        $this->assertGreaterThan(
            $tasksbefore,
            $tasksafter,
            'CP4: Adhoc task must be queued after submit_for_grading. '
            . "Before={$tasksbefore}, After={$tasksafter}"
        );

        // CP5: Task has correct custom data.
        $task = $DB->get_records('task_adhoc', ['classname' => $taskclass], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertEquals(
            $env->student->id,
            $customdata->userid,
            'CP5a: Task userid must be the student. customdata=' . $task->customdata
        );
        $this->assertEquals(
            $env->assign->id,
            $customdata->assignment,
            'CP5b: Task assignment must be the assign instance id'
        );
        $this->assertEquals('generate', $customdata->action, 'CP5c: Task action must be generate');

        // CP6: Execute the adhoc task — feedback is created.
        $adhoctask = new process_feedback_adhoc();
        $adhoctask->set_custom_data($customdata);
        $adhoctask->set_userid($env->student->id);
        ob_start();
        $adhoctask->execute();
        ob_end_clean();

        $this->assertGreaterThan(
            0,
            $DB->count_records('assignfeedback_aif_feedback'),
            'CP6: Feedback record must be created after task execution'
        );
    }

    /**
     * Diagnostic test: autogenerate chain with submissiondrafts=0 (Moodle default).
     *
     * When "Require students to click submit" is NO (default), the assessable_submitted
     * event fires from save_submission() — a different code path than submit_for_grading().
     * This test ensures the observer works for the default production configuration.
     *
     * @covers \assignfeedback_aif\event\observer::submission_submitted
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_observer_autogenerate_no_submissiondrafts(): void {
        global $DB;
        $this->resetAfterTest();

        // Setup: submissiondrafts=0 (default) with autogenerate enabled.
        $env = $this->create_test_environment([
            'assignfeedback_aif_autogenerate' => 1,
            'submissiondrafts' => 0,
        ]);

        // CP1: AIF config with autogenerate=1.
        $aifconfig = $DB->get_record('assignfeedback_aif', ['assignment' => $env->assign->id]);
        $this->assertNotEmpty($aifconfig, 'CP1a: AIF config must exist');
        $this->assertEquals(1, (int) $aifconfig->autogenerate, 'CP1b: autogenerate must be 1');

        // Count tasks before.
        $taskclass = '\\assignfeedback_aif\\task\\process_feedback_adhoc';
        $tasksbefore = $DB->count_records('task_adhoc', ['classname' => $taskclass]);

        // Student saves a submission. When submissiondrafts=0, this auto-submits.
        // save_submission() fires assessable_submitted at line 7871 of mod/assign/locallib.php.
        $this->setUser($env->student);
        $notices = [];
        $msgsink = $this->redirectMessages();
        $env->assignobj->save_submission(
            (object) [
                'userid' => $env->student->id,
                'onlinetext_editor' => [
                    'text' => '<p>Submission via save_submission path</p>',
                    'format' => FORMAT_HTML,
                    'itemid' => file_get_unused_draft_itemid(),
                ],
            ],
            $notices
        );
        $msgsink->close();

        // CP2: Submission is auto-submitted (status = SUBMITTED, not DRAFT).
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $this->assertNotEmpty($submission, 'CP2a: Submission must exist');
        $this->assertEquals(
            ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            $submission->status,
            'CP2b: Submission status must be SUBMITTED when submissiondrafts=0'
        );

        // CP3: Adhoc task was queued.
        $tasksafter = $DB->count_records('task_adhoc', ['classname' => $taskclass]);
        $this->assertGreaterThan(
            $tasksbefore,
            $tasksafter,
            'CP3: Adhoc task must be queued via save_submission path. '
            . "Before={$tasksbefore}, After={$tasksafter}"
        );

        // CP4: Task has correct data and execution creates feedback.
        $task = $DB->get_records('task_adhoc', ['classname' => $taskclass], 'id DESC', '*', 0, 1);
        $task = reset($task);
        $customdata = json_decode($task->customdata);
        $this->assertEquals(
            $env->student->id,
            $customdata->userid,
            'CP4a: Task userid must be the student'
        );

        $adhoctask = new process_feedback_adhoc();
        $adhoctask->set_custom_data($customdata);
        $adhoctask->set_userid($env->student->id);
        ob_start();
        $adhoctask->execute();
        ob_end_clean();

        $this->assertGreaterThan(
            0,
            $DB->count_records('assignfeedback_aif_feedback'),
            'CP4b: Feedback record must be created'
        );
    }

    /**
     * Test that the observer does not queue a task when autogenerate is disabled.
     */
    public function test_observer_no_task_when_autogenerate_disabled(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Analyse grammar', 0);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $this->setUser($env->student);
        $submissiondata = [
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'My submission without auto-generate',
        ];
        $generator->create_submission($submissiondata);

        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);

        $sink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $sink->close();

        // No new adhoc task should be queued.
        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);
        $this->assertEquals($tasksbefore, $tasksafter);
    }

    /**
     * Test that the submission_removed observer deletes associated AI feedback.
     */
    public function test_observer_submission_removed_deletes_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Text to be removed');
        $aifid = $this->create_aif_config($env, 'Prompt');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Insert feedback.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Feedback to be removed with submission',
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
        ]);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        // Use admin to remove submission (needs editothersubmission capability).
        $this->setAdminUser();
        $env->assignobj->remove_submission($env->student->id);

        // Feedback should be deleted.
        $this->assertEquals(0, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));
    }

    /**
     * Test the regenerate_feedback external API queues an adhoc task.
     */
    public function test_regenerate_external_api_queues_task(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $this->create_aif_config($env, 'Test');

        // External function requires a teacher with grading capability.
        $this->setUser($env->teacher);

        $tasksbefore = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);

        $result = regenerate_feedback::execute($env->assign->id, $env->student->id);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['message']);

        $tasksafter = $DB->count_records('task_adhoc', [
            'classname' => '\\assignfeedback_aif\\task\\process_feedback_adhoc',
        ]);
        $this->assertGreaterThan($tasksbefore, $tasksafter);

        $queuedtask = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        $this->assertNotNull($queuedtask);
        $this->assertEquals($env->teacher->id, $queuedtask->get_userid());

        $taskdata = $queuedtask->get_custom_data();
        $this->assertEquals($env->student->id, (int) $taskdata->userid);
    }

    /**
     * Test that manual regenerate via external API re-points a queued student task to teacher context.
     *
     * With local_ai_manager backend the acting user matters for availability/quota checks,
     * so the manual trigger must always run through task_manager deduplication logic.
     *
     * @covers \assignfeedback_aif\external\regenerate_feedback::execute
     */
    public function test_regenerate_external_api_repoints_queued_task_to_teacher(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $this->create_aif_config($env, 'Test');

        // Precondition: automatic path queued the task as the student.
        \assignfeedback_aif\local\task_manager::queue_generation(
            $env->assign->id,
            $env->student->id,
            $env->student->id
        );
        $queuedtask = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        $this->assertNotNull($queuedtask);
        $this->assertEquals($env->student->id, $queuedtask->get_userid());

        // Manual external trigger by teacher must re-point to teacher context.
        $this->setUser($env->teacher);
        $result = regenerate_feedback::execute($env->assign->id, $env->student->id);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['progressrecordid']);
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks(process_feedback_adhoc::class));

        $queuedtask = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        $this->assertNotNull($queuedtask);
        $this->assertEquals($env->teacher->id, $queuedtask->get_userid());
    }

    /**
     * Test that manual regenerate leaves an already running task and its progress record untouched.
     *
     * A running task holds its stored_progress record by id and has written a pending
     * feedback record. Re-initialising the progress or deleting the feedback would make
     * the teacher's progress bar hang and the task fall back to re-inserting feedback.
     *
     * @covers \assignfeedback_aif\external\regenerate_feedback::execute
     * @covers \assignfeedback_aif\local\task_manager::queue_generation_with_progress
     */
    public function test_regenerate_external_api_keeps_running_task_and_progress(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $aifid = $this->create_aif_config($env, 'Test');

        // The automatic task was queued as the student and has been picked up by cron.
        \assignfeedback_aif\local\task_manager::queue_generation(
            $env->assign->id,
            $env->student->id,
            $env->student->id
        );
        $runningtask = \assignfeedback_aif\local\task_manager::find_task_for_user($env->assign->id, $env->student->id);
        \core\task\manager::adhoc_task_starting($runningtask);
        $runningtask->initialise_stored_progress();
        $progressid = \assignfeedback_aif\local\task_manager::get_progress_id_for_user($env->assign->id, $env->student->id);
        $this->assertGreaterThan(0, $progressid);

        // The running task has written its pending lock record.
        $submission = $DB->get_record('assign_submission', ['assignment' => $env->assign->id, 'userid' => $env->student->id]);
        $DB->insert_record('assignfeedback_aif_feedback', (object) [
            'aif' => $aifid,
            'submission' => $submission->id,
            'feedback' => '',
            'feedbackformat' => FORMAT_HTML,
            'status' => 'pending',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->setUser($env->teacher);
        $result = regenerate_feedback::execute($env->assign->id, $env->student->id);

        $this->assertTrue($result['success']);
        $this->assertEquals($progressid, $result['progressrecordid']);
        $this->assertTrue($DB->record_exists('stored_progress', ['id' => $progressid]));
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        $tasks = \core\task\manager::get_adhoc_tasks(process_feedback_adhoc::class);
        $this->assertCount(1, $tasks);
        $task = reset($tasks);
        $this->assertEquals($runningtask->get_id(), $task->get_id());
        $this->assertEquals($env->student->id, $task->get_userid());
    }

    /**
     * Test that repeated progress initialisation reuses the existing stored_progress record.
     *
     * @covers \assignfeedback_aif\local\task_manager::queue_generation_with_progress
     */
    public function test_queue_generation_with_progress_reuses_existing_record(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_aif_config($env, 'Test');

        $first = \assignfeedback_aif\local\task_manager::queue_generation_with_progress(
            $env->assign->id,
            $env->student->id,
            $env->teacher->id
        );
        $second = \assignfeedback_aif\local\task_manager::queue_generation_with_progress(
            $env->assign->id,
            $env->student->id,
            $env->teacher->id
        );

        $this->assertGreaterThan(0, $first);
        $this->assertEquals($first, $second);
        $this->assertEquals(1, $DB->count_records('stored_progress'));
    }

    /**
     * Test that a task which failed for good does not block re-queueing or crash recovery.
     *
     * Core keeps failed tasks without remaining attempts in the queue for weeks. Such a task
     * must be treated as non-existent: a pending feedback record has to be recovered as an
     * error, and a new trigger by the same user must queue a fresh task and remove the corpse.
     *
     * @covers \assignfeedback_aif\local\task_manager::queue_generation
     * @covers \assignfeedback_aif\local\task_manager::find_task_for_user
     * @covers \assignfeedback_aif\local\task_manager::has_pending_tasks
     */
    public function test_dead_task_is_ignored_and_replaced(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $aifid = $this->create_aif_config($env, 'Test');
        $taskmanager = \assignfeedback_aif\local\task_manager::class;

        // A task ran, wrote its pending lock record and then died. Core's cleanup marks such
        // an orphaned task as failed, which leaves it with zero attempts because the task
        // does not retry until success.
        $taskmanager::queue_generation($env->assign->id, $env->student->id, $env->teacher->id);
        $task = \core\task\manager::get_next_adhoc_task(time(), false, process_feedback_adhoc::class);
        $this->assertNotNull($task);
        $submission = $DB->get_record('assign_submission', ['assignment' => $env->assign->id, 'userid' => $env->student->id]);
        $DB->insert_record('assignfeedback_aif_feedback', (object) [
            'aif' => $aifid,
            'submission' => $submission->id,
            'feedback' => '',
            'feedbackformat' => FORMAT_HTML,
            'status' => 'pending',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        \core\task\manager::adhoc_task_failed($task, false);
        $deadtaskid = $task->get_id();
        $this->assertEquals(0, $DB->get_field('task_adhoc', 'attemptsavailable', ['id' => $deadtaskid]));

        // The dead task is invisible to all lookups, so the pending record is recovered as an error.
        $this->assertNull($taskmanager::find_task_for_user($env->assign->id, $env->student->id));
        $this->assertFalse($taskmanager::is_task_running_for_user($env->assign->id, $env->student->id));
        $this->assertFalse($taskmanager::has_pending_tasks($env->assign->id));
        $record = \assignfeedback_aif\local\feedback_utils::get_feedbackaif($env->assign->id, $env->student->id);
        $this->assertEquals('error', $record->status);

        // Triggering again as the same user replaces the corpse with a fresh task.
        $taskmanager::queue_generation($env->assign->id, $env->student->id, $env->teacher->id);

        $this->assertFalse($DB->record_exists('task_adhoc', ['id' => $deadtaskid]));
        $tasks = \core\task\manager::get_adhoc_tasks(process_feedback_adhoc::class);
        $this->assertCount(1, $tasks);
        $newtask = reset($tasks);
        $this->assertNotEquals($deadtaskid, $newtask->get_id());
        $this->assertEmpty($newtask->get_timestarted());
        $this->assertTrue($taskmanager::has_pending_tasks($env->assign->id));
    }

    /**
     * Test the regenerate_feedback external API requires grade capability.
     *
     * @covers \assignfeedback_aif\external\regenerate_feedback::execute
     */
    public function test_regenerate_external_api_requires_capability(): void {
        $this->resetAfterTest();

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'Student work');
        $this->create_aif_config($env, 'Test');

        // Student should not be able to regenerate feedback.
        $this->setUser($env->student);

        $this->expectException(\required_capability_exception::class);
        regenerate_feedback::execute($env->assign->id, $env->student->id);
    }

    /**
     * Test that resubmission deletes existing AI feedback before queuing new generation.
     *
     * @covers \assignfeedback_aif\event\observer::queue_feedback_generation
     */
    public function test_resubmission_deletes_existing_feedback(): void {
        global $DB;
        $this->resetAfterTest();

        $env = $this->create_test_environment([
            'submissiondrafts' => 1,
            'submissionstatement' => '',
            'requiresubmissionstatement' => 0,
        ]);
        $aifid = $this->create_aif_config($env, 'Analyse grammar', 1);

        // First submission.
        $this->create_and_submit($env, 'First submission text');

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);

        // Simulate existing AI feedback from first submission.
        $clock = \core\di::get(\core\clock::class);
        $DB->insert_record('assignfeedback_aif_feedback', [
            'aif' => $aifid,
            'feedback' => 'Old AI feedback from first submission',
            'feedbackformat' => FORMAT_HTML,
            'submission' => $submission->id,
            'timecreated' => $clock->now()->getTimestamp(),
            'timemodified' => $clock->now()->getTimestamp(),
        ]);
        $this->assertEquals(1, $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]));

        // Revert to draft so student can resubmit.
        $this->setUser($env->teacher);
        $env->assignobj->revert_to_draft($env->student->id);

        // Student resubmits.
        $this->setUser($env->student);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_assign');
        $generator->create_submission([
            'cmid' => $env->cm->id,
            'userid' => $env->student->id,
            'onlinetext' => 'Updated second submission text',
        ]);

        $msgsink = $this->redirectMessages();
        $env->assignobj->submit_for_grading((object) ['userid' => $env->student->id], []);
        $msgsink->close();

        // Old feedback should be deleted after resubmission.
        $this->assertEquals(
            0,
            $DB->count_records('assignfeedback_aif_feedback', ['aif' => $aifid]),
            'Old AI feedback must be deleted when student resubmits'
        );

        // A new adhoc task should have been queued.
        $taskclass = '\\assignfeedback_aif\\task\\process_feedback_adhoc';
        $this->assertGreaterThan(
            0,
            $DB->count_records('task_adhoc', ['classname' => $taskclass]),
            'Adhoc task must be queued for new feedback generation'
        );
    }

    /**
     * Test the adhoc task stores the error when intro attachment extraction fails.
     *
     * When an intro attachment PDF cannot be converted because the AI backend rejects
     * the ITT request (e.g. terms of use not confirmed), get_prompt() throws and the
     * adhoc task must persist the error as an error feedback record so the teacher can
     * see what went wrong.
     *
     * @covers \assignfeedback_aif\task\process_feedback_adhoc::execute
     */
    public function test_adhoc_task_stores_error_when_introattachment_extraction_fails(): void {
        global $DB;
        $this->resetAfterTest();

        // Override the default AI mock so every ITT request fails as if rejected by the backend.
        $backenderror = new \moodle_exception(
            'err_retrievingfeedback',
            'assignfeedback_aif',
            '',
            'AI backend rejected the request'
        );
        $providermock = $this->createMock(\assignfeedback_aif\local\ai_request_provider::class);
        $providermock->method('perform_request_core_ai')->willThrowException($backenderror);
        $providermock->method('perform_request_local_ai_manager')->willThrowException($backenderror);
        $providermock->method('is_available')->willReturn(true);
        \core\di::set(\assignfeedback_aif\local\ai_request_provider::class, $providermock);

        $env = $this->create_test_environment();
        $this->create_and_submit($env, 'My essay about renewable energy');
        $this->create_aif_config($env, 'Analyse the submission');

        // Add a PDF intro attachment (teacher-owned) so ITT extraction is triggered.
        get_file_storage()->create_file_from_string([
            'contextid' => $env->context->id,
            'component' => 'mod_assign',
            'filearea' => 'introattachment',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'instructions.pdf',
            'userid' => $env->teacher->id,
        ], 'fake pdf content');

        // Register a mock extractor that throws when extracting the PDF,
        // simulating an AI backend rejection (e.g. terms of use not confirmed).
        $extractormock = $this->createMock(\local_ai_content\document_extractor::class);
        $extractormock->method('is_file_supported')->willReturn(true);
        $extractormock->method('extract_text_from_file')->willThrowException($backenderror);
        $extractormock->method('get_supported_extensions')->willReturn('PDF, PNG, TXT');
        \core\di::set(\local_ai_content\document_extractor::class, $extractormock);

        $task = new process_feedback_adhoc();
        $task->set_custom_data([
            'assignment' => intval($env->assign->id),
            'userid' => intval($env->student->id),
            'action' => 'generate',
        ]);
        $task->set_userid($env->student->id);

        ob_start();
        $task->execute();
        ob_end_clean();

        // The task must have stored exactly one error feedback record for the submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => $env->assign->id,
            'userid' => $env->student->id,
            'latest' => 1,
        ]);
        $feedback = $DB->get_record('assignfeedback_aif_feedback', ['submission' => $submission->id]);
        $this->assertNotFalse($feedback, 'An error feedback record must be stored on extraction failure.');

        // The error must be stored in the status and errormessage fields.
        $this->assertEquals('error', $feedback->status);
        $this->assertNotEmpty($feedback->errormessage);
        $this->assertStringContainsString('AI backend rejected the request', $feedback->errormessage);

        // No actual feedback text should be stored when generation fails.
        $this->assertSame('', $feedback->feedback);
    }
}
