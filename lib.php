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
 * Library functions for local_courseview.
 *
 * @package     local_courseoverview
 * @author      Toni Ginard <toni.ginard@ticxcat.cat>
 * @copyright   2022 Departament d'Educació - Generalitat de Catalunya
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const MODULE_FORUM_NAME = 'forum';
const MODULE_ASSIGN_NAME = 'assign';
const MODULE_QUIZ_NAME = 'quiz';

/**
 * Calculates the number of unread messages, pending tasks and pending
 * quizzes in all user courses.
 *
 * @throws moodle_exception
 * @throws coding_exception
 * @copyright   2022 Departament d'Educació - Generalitat de Catalunya
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package     local_courseoverview
 * @author      Toni Ginard <toni.ginard@ticxcat.cat>
 */
function local_courseoverview_before_footer() {

    global $PAGE, $USER, $CFG, $OUTPUT;

    // Check if the current page is the dashboard page.
    if ($PAGE->pagetype === 'my-index') {

        $coursesenrolled = enrol_get_all_users_courses($USER->id, true);

        $data = [];
        $js = '';

        // Load classes.
        include_once($CFG->dirroot . '/mod/forum/lib.php');
        include_once($CFG->dirroot . '/mod/assign/locallib.php');
        include_once($CFG->dirroot . '/mod/quiz/accessmanager.php');
        include_once($CFG->dirroot . '/mod/quiz/attemptlib.php');

        foreach ($coursesenrolled as $course) {

            $unreadforums = count_pending_forum($USER->id, $course);

            $coursecontext = \context_course::instance($course->id);

            if (check_role($USER->id, $coursecontext, 'student')) {
                $isstudent = true;
                $studentpendingassign = count_student_pending_assign($course, $USER->id);
                $studentpendingquiz = count_student_pending_quiz($course, $USER->id);
            }

            if (check_role($USER->id, $coursecontext, 'teacher') ||
                check_role($USER->id, $coursecontext, 'editingteacher')) {
                $isteacher = true;
                $teacherpendingassign = count_teacher_pending_assign($course, $USER->id);
                $teacherpendingquiz = count_teacher_pending_quiz($course, $USER->id);
            }

            $coursedata = [
                'course_id' => $course->id,
                'is_student' => $isstudent ?? false,
                'is_teacher' => $isteacher ?? false,
                'unread_forums' => $unreadforums,
                'student_pending_assign' => $studentpendingassign ?? 0,
                'student_pending_quiz' => $studentpendingquiz ?? 0,
                'teacher_pending_assign' => $teacherpendingassign ?? 0,
                'teacher_pending_quiz' => $teacherpendingquiz ?? 0,
            ];

            // Generate the HTML code for the course. This code includes img tags and numerical information and
            // will be the data in the javascript script. The preg statement is used to remove tabs, new lines and
            // repeated spaces from the HTML code, so the result is one line of code.
            $data[$course->id] = preg_replace(
                '/\s+/',
                ' ',
                $OUTPUT->render_from_template('local_courseoverview/courseoverview', ['data' => $coursedata])
            );

        }

        // Build an array of courses to be passed to the javascript script. Each item contains the data of a course.
        $jscourses = [];
        foreach ($data as $courseid => $coursedata) {
            $jscourses[] = ['course_id' => $courseid, 'data' => $coursedata];
        }

        // Combine the data of the courses with the javascript code to generate the final script.
        $javascript = $OUTPUT->render_from_template('local_courseoverview/js', ['courses' => $jscourses]);

        // Add the javascript script to the page.
        $PAGE->requires->js_init_code($javascript, true);

    }
}

/**
 * Counts the number of unread forum posts in a course. Is aware of the user groups.
 *
 * @param int $userid
 * @param stdClass $course
 * @return int The number of pending forum posts.
 * @throws coding_exception
 */
function count_pending_forum(int $userid, stdClass $course): int {

    $totalunread = 0;

    // Get all the forums in the course.
    $forums = get_all_instances_in_course(MODULE_FORUM_NAME, $course, $userid);

    // Count the number of unread forum posts in each forum, being aware of the user groups.
    foreach ($forums as $forum) {
        $cm = get_coursemodule_from_instance(MODULE_FORUM_NAME, $forum->id, $course->id);
        $totalunread += forum_tp_count_forum_unread_posts($cm, $course);
    }

    return $totalunread;

}

/**
 * Count the number of tasks in a course where the user is enrolled as
 * a student and has not submitted the answers yet.
 *
 * @param stdClass $course
 * @param int $userid
 * @return int
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function count_student_pending_assign(stdClass $course, int $userid): int {

    global $DB;

    $sum = 0;
    $assignmentids = [];
    $time = time();

    $assignments = get_all_instances_in_course(MODULE_ASSIGN_NAME, $course, $userid);

    foreach ($assignments as $assignment) {
        // Check for groups in the assignment.
        if ($assignment->teamsubmission) {
            // This function adds the assignment id to the list if the assignment is already submitted by any of the group members.
            $assignmentids = get_assignment_ids_teamsubmission($assignment, $course, $userid, $time, $assignmentids);
        } else {
            // Add the element to the array unconditionally.
            $assignmentids = get_assignment_ids($assignment, $time, $assignmentids);
        }
    }

    if (empty($assignmentids)) {
        // No assignments to look at.
        return $sum;
    }

    foreach ($assignments as $assignment) {

        // Check if assignment is open, is visible, is available and has a specified capability.
        if (is_assignment_filtered($assignment, $course->id, $userid, $assignmentids, 'mod/assign:submit')) {
            continue;
        }

        $params = [
            'status' => 'submitted',
            'userid' => $userid,
            'assignment' => $assignment->id,
        ];

        $mysubmissions = $DB->count_records('assign_submission', $params);

        if (!$mysubmissions) {
            $sum++;
        }
    }

    return $sum;
}

/**
 * Count the number of tasks in a course where the user is enrolled as
 * a teacher and has not been graded yet.
 *
 * @param stdClass $course
 * @param int $userid
 * @return int
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function count_teacher_pending_assign(stdClass $course, int $userid): int {

    global $DB;

    $sum = 0;
    $assignmentids = [];
    $time = time();

    $assignments = get_all_instances_in_course(MODULE_ASSIGN_NAME, $course, $userid);

    foreach ($assignments as $assignment) {
        $assignmentids = get_assignment_ids($assignment, $time, $assignmentids);
    }

    if (empty($assignmentids)) {
        // No assignments to look at.
        return $sum;
    }

    [$sqlassignmentids, $assignmentidparams] = $DB->get_in_or_equal($assignmentids);
    $dbparams = array_merge([ASSIGN_SUBMISSION_STATUS_SUBMITTED], $assignmentidparams);

    $unmarkedsubmissions = get_unmarked_submissions($sqlassignmentids, $dbparams);

    foreach ($assignments as $assignment) {

        // Check if assignment is open, is visible, is available and has a specified capability.
        if (is_assignment_filtered($assignment, $course->id, $userid, $assignmentids, 'mod/assign:grade')) {
            continue;
        }

        $groupid = 0;
        $context = \context_module::instance($assignment->coursemodule);

        // If students submit in groups, find out the group id of the user and update $groupid.
        if ($assignment->teamsubmission) {
            // Create an assignment object in order to call its member functions.
            $cm = get_coursemodule_from_instance(MODULE_ASSIGN_NAME, $assignment->id);
            $assign = new \assign($context, $cm, $course);

            // Update the group id.
            $groupid = $assign->get_submission_group($userid)->id;
        }

        $students = get_enrolled_users($context, 'mod/assign:view', $groupid, 'u.id');

        if (empty($students)) {
            continue;
        }

        foreach ($students as $student) {
            if (isset($unmarkedsubmissions[$assignment->id][$student->id])) {
                $sum++;
            }
        }
    }

    return $sum;
}

/**
 * Count the number of quizzes in a course where the user is enrolled as
 * a student and whose answers have not been submitted yet.
 *
 * @param stdClass $course
 * @param int $userid
 * @return int
 * @throws coding_exception
 * @throws moodle_exception
 */
function count_student_pending_quiz(stdClass $course, int $userid): int {

    $sum = 0;

    $quizzes = get_all_instances_in_course(MODULE_QUIZ_NAME, $course, $userid);

    foreach ($quizzes as $quiz) {

        if (check_quiz_time($quiz)) {

            // Check visibility.
            if (!$quiz->visible) {
                continue;
            }

            // Check availability.
            $cm = get_coursemodule_from_instance(MODULE_QUIZ_NAME, $quiz->id, $course->id);
            if (!\core_availability\info_module::is_user_visible($cm, $userid)) {
                continue;
            }

            $context = \context_module::instance($quiz->coursemodule);

            if (has_capability('mod/quiz:viewreports', $context, $userid)) {
                continue;
            }

            // Student: Count the attempts they have made.
            $attempts = quiz_get_user_attempts($quiz->id, $userid);
            if (count($attempts) === 0) {
                $sum++;
            }
        }
    }

    return $sum;
}

/**
 * Count the number of quizzes in a course where the user is enrolled as
 * a teacher and there are submitted answers not reviewed yet.
 *
 * @param stdClass $course
 * @param int $userid
 * @return int
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function count_teacher_pending_quiz(stdClass $course, int $userid): int {

    global $DB;

    $sum = 0;

    $quizzes = get_all_instances_in_course(MODULE_QUIZ_NAME, $course, $userid);

    foreach ($quizzes as $quiz) {
        $cm = get_coursemodule_from_id('quiz', $quiz->coursemodule);

        // Check visibility.
        if (!$quiz->visible) {
            continue;
        }

        // Check availability.
        if (!\core_availability\info_module::is_user_visible($cm, $userid)) {
            continue;
        }

        // Check that quiz has question essay.
        $queryessay = "
                SELECT COUNT({question}.id) AS total
                FROM {question}
                INNER JOIN {quiz_slots} ON ({quiz_slots}.questionid = {question}.id)
                INNER JOIN {quiz} ON ({quiz}.id = {quiz_slots}.quizid)
                WHERE {quiz}.course = " . $course->id . "
                AND {quiz}.id = " . $quiz->id . "
                AND {question}.qtype = 'essay'
            ";

        $resultessay = $DB->get_record_sql($queryessay);

        if ((int)$resultessay->total <= 0) {
            continue;
        }

        if (check_quiz_time($quiz)) {

            $context = \context_module::instance($quiz->coursemodule);

            if (has_capability('mod/quiz:viewreports', $context, $userid)) {
                $quizobj = \quiz::create($quiz->id, $userid);

                // Get enrolled students.
                $coursecontext = \context_course::instance($course->id);
                $users = get_enrolled_users($coursecontext);

                foreach ($users as $user) {
                    if (!check_role($user->id, $coursecontext, 'student')) {
                        continue;
                    }

                    // Get attempts.
                    $attempts = quiz_get_user_attempts($quizobj->get_quizid(), $user->id);

                    foreach ($attempts as $attempt) {
                        // Create attempt object.
                        $attemptobject = $quizobj->create_attempt_object($attempt);

                        // Get questions.
                        $slots = $attemptobject->get_slots();
                        foreach ($slots as $slot) {
                            if (!$attemptobject->is_real_question($slot)) {
                                continue;
                            }

                            // Check if the status is "pending of grading".
                            if ($attemptobject->get_question_status($slot, true) === get_string('requiresgrading', 'question')) {
                                $sum++;
                                continue 4;
                            }
                        }
                    }
                }
            }
        }
    }

    return $sum;
}

/**
 * Get the list of assignment id's that meet the restrictions.
 *
 * @param stdClass $assignment
 * @param int $time
 * @param array $assignmentids
 * @return array
 */
function get_assignment_ids(stdClass $assignment, int $time, array $assignmentids): array {

    if ($assignment->duedate) {
        $duedate = false;
        if ($assignment->cutoffdate) {
            $duedate = $assignment->cutoffdate;
        }
        if ($duedate) {
            $isopen = ($assignment->allowsubmissionsfromdate <= $time && $time <= $duedate);
        } else {
            $isopen = ($assignment->allowsubmissionsfromdate <= $time);
        }
    } else if ($assignment->allowsubmissionsfromdate) {
        $isopen = ($assignment->allowsubmissionsfromdate <= $time);
    } else {
        $isopen = true;
    }

    if ($isopen) {
        $assignmentids[] = $assignment->id;
    }

    return $assignmentids;
}

/**
 * Check if the assignment is already submitted by any of the group members.
 *
 * @param stdClass $assignment
 * @param stdClass $course
 * @param int $userid
 * @param int $time
 * @param array $assignmentids
 * @return array
 * @throws coding_exception
 */
function get_assignment_ids_teamsubmission(stdClass $assignment, stdClass $course, int $userid, int $time, array $assignmentids): array {

    // Create an assignment object in order to call its member functions.
    $context = \context_module::instance($assignment->coursemodule);
    $cm = get_coursemodule_from_instance(MODULE_ASSIGN_NAME, $assignment->id);
    $assign = new \assign($context, $cm, $course);

    // If the group have already submitted the assignment, get it.
    $submission = $assign->get_group_submission($userid, 0, false);

    if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
        // There is no group submission yet, so add the assignment to the array.
        $assignmentids = get_assignment_ids($assignment, $time, $assignmentids);
    }

    return $assignmentids;

}

/**
 * Build up an array of unmarked submissions indexed by assignment id / user id for use where
 * the user has grading rights on assignment.
 *
 * @param $sqlassignmentids
 * @param array $dbparams
 * @return array
 * @throws dml_exception
 */
function get_unmarked_submissions($sqlassignmentids, array $dbparams): array {

    global $DB;

    $unmarkedsubmissions = [];

    $rs = $DB->get_recordset_sql('SELECT
                                          s.assignment AS assignment,
                                          s.userid AS userid,
                                          s.id AS id,
                                          s.status AS status,
                                          g.timemodified AS timegraded
                                      FROM {assign_submission} s
                                      LEFT JOIN {assign_grades} g ON
                                          s.userid = g.userid AND
                                          s.assignment = g.assignment AND
                                          g.attemptnumber = s.attemptnumber
                                      WHERE
                                          (g.timemodified IS NULL OR s.timemodified > g.timemodified OR g.grade IS NULL) AND
                                          s.timemodified IS NOT NULL AND
                                          s.status = ? AND
                                          s.latest = 1 AND
                                          s.assignment ' . $sqlassignmentids, $dbparams);

    foreach ($rs as $rd) {
        $unmarkedsubmissions[$rd->assignment][$rd->userid] = $rd->id;
    }

    $rs->close();

    return $unmarkedsubmissions;

}

/**
 * Decide if the assignment is eligible or has to be discarded.
 *
 * @param $assignment
 * @param $courseid
 * @param $userid
 * @param $assignmentids
 * @param $capability
 * @return bool
 * @throws coding_exception
 * @throws moodle_exception
 */
function is_assignment_filtered($assignment, $courseid, $userid, $assignmentids, $capability): bool {

    // Ignore assignments that are not open.
    if (!in_array($assignment->id, $assignmentids, true)) {
        return true;
    }

    // Check visibility.
    if (!$assignment->visible) {
        return true;
    }

    // Check availability.
    $cm = get_coursemodule_from_instance(MODULE_ASSIGN_NAME, $assignment->id, $courseid);
    if (!\core_availability\info_module::is_user_visible($cm, $userid)) {
        return true;
    }

    $context = \context_module::instance($assignment->coursemodule);

    if (!has_capability($capability, $context, $userid)) {
        return true;
    }

    return false;
}

/**
 * Check if the quiz is open.
 *
 * @param $quiz
 * @return bool
 */
function check_quiz_time($quiz): bool {

    $now = time();

    return ($quiz->timeclose >= $now && $quiz->timeopen < $now) ||
        ((int)$quiz->timeclose === 0 && $quiz->timeopen < $now) ||
        ((int)$quiz->timeclose === 0 && (int)$quiz->timeopen === 0);

}

/**
 * Helper function to check the role of a user in a context.
 *
 * @param int $userid
 * @param context|null $context
 * @param string $archetype
 * @return bool
 * @throws dml_exception
 */
function check_role(int $userid = 0, context $context = null, string $archetype = ''): bool {

    global $DB;

    $roles = get_user_roles($context, $userid);

    foreach ($roles as $role) {
        $roledb = $DB->get_record('role', ['id' => $role->roleid]);
        if ($roledb->archetype === $archetype) {
            return true;
        }
    }

    return false;
}
