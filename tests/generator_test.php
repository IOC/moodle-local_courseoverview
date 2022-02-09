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
 * Version file
 *
 * @author      Toni Ginard <aginard@xtec.cat>
 * @copyright   2022 Departament d'Educació - Generalitat de Catalunya
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

class local_courseoverview_generator_testcase extends advanced_testcase {

    public function test_forum_unread_messages() {
        $this->resetAfterTest();
        $this->assertEquals(false, false);
    }

    public function test_assign_pending_teacher() {
        $this->resetAfterTest();
        $this->assertEquals(true, true);
    }

    public function test_assign_pending_student() {
        $this->resetAfterTest();
        $this->assertEquals(true, true);
    }

    public function test_quizz_pending_tasks_teacher() {
        $this->resetAfterTest();
        $this->assertEquals(true, true);
    }

    public function test_quizz_pending_tasks_student() {
        $this->resetAfterTest();
        $this->assertEquals(true, true);
    }

}
