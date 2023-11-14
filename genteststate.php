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
 * Put plugin into test state by generating lots of stuff
 *
 * @package
 * @author    Guy Thomas
 * @copyright 2023 Citricity Ltd
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define('CLI_SCRIPT', true);
require(__DIR__.'/../../config.php');
require_once($CFG->dirroot.'/lib/phpunit/classes/util.php');
require_once($CFG->dirroot.'/lib/testing/generator/data_generator.php');

class genteststate {
    protected $testprefix = 'test-';

    /**
     * @var testing_data_generator|null
     */
    protected $dg;

    protected function __construct() {
        $this->dg = phpunit_util::get_data_generator();
    }

    public static function go() {
        static $instance;

        if (!$instance) {
            $instance = new genteststate();
        }

        $instance->do_all();
    }

    public function do_all() {
        $this->create_users();
        $this->create_categories();
        $this->create_courses();
        $this->enrol_users();
        $this->create_verbalfeedbacks();
    }

    private function create_users() {
        global $DB;
        mtrace('Creating users');
        $testprefix = $this->testprefix;
        $usersrequired = 100;
        $likeidnumber = $DB->sql_like('idnumber', ':idnumber');
        $params = ['idnumber' => '%' . $DB->sql_like_escape($testprefix) . '%'];
        $usercount = $DB->count_records_select('user', $likeidnumber, $params);
        $c = $usercount;
        while ($c < $usersrequired) {
            $c++;
            mtrace("Creating user $c of $usersrequired");
            $this->dg->create_user([
                'idnumber' => "{$testprefix}-$c"
            ]);
        }
    }

    private function create_categories() {
        global $DB;
        mtrace('Creating categories');
        $testprefix = $this->testprefix;
        $catsrequired = 55;
        $likeidnumber = $DB->sql_like('idnumber', ':idnumber');
        $params = ['idnumber' => '%' . $DB->sql_like_escape($testprefix) . '%'];
        $catcount = $DB->count_records_select('course_categories', $likeidnumber, $params);

        $c = $catcount;
        while ($c < $catsrequired) {
            $c++;
            mtrace("Creating category $c of $catsrequired");
            $this->dg->create_category([
                'name' => "{mlang en}Category $c{mlang}{mlang de}Kategorie $c{mlang}{mlang fr}Catégorie $c{mlang}",
                'idnumber' => "{$testprefix}-$c"
            ]);
        }
    }

    private function create_courses() {
        global $DB;

        mtrace('Creating courses');
        $testprefix = $this->testprefix;
        $likeidnumber = $DB->sql_like('idnumber', ':idnumber');
        $params = ['idnumber' => '%' . $DB->sql_like_escape($testprefix) . '%'];
        $cats = $DB->get_records_select('course_categories', $likeidnumber, $params, 'id ASC');

        $coursesrequired = 5;
        foreach ($cats as $cat) {
            $coursecount = $DB->count_records_select('course', "$likeidnumber AND category = :categoryid", array_merge($params, ['categoryid' => $cat->id]));
            $c = $coursecount;
            while ($c < $coursesrequired) {
                $c++;
                mtrace("Creating course $c of $coursesrequired for category $cat->idnumber");
                $this->dg->create_course([
                    'name' => "{mlang en}Course $c Category $cat->idnumber{mlang}{mlang de}Kurs $c Kategorie $cat->idnumber{mlang}{mlang fr}Cours $c Catégorie $cat->idnumber{mlang}",
                    'category' => $cat->id,
                    'shortname' => "$cat->idnumber-{$testprefix}-$c",
                    'idnumber' => "$cat->idnumber-{$testprefix}-$c"
                ]);
            }
        }
    }

    private function enrol_users() {
        global $DB;

        mtrace('Enrolling users');
        $testprefix = $this->testprefix;
        $likeidnumber = $DB->sql_like('idnumber', ':idnumber');
        $params = ['idnumber' => '%' . $DB->sql_like_escape($testprefix) . '%'];
        $courses = $DB->get_recordset_select('course', $likeidnumber, $params, 'id ASC');
        $users = array_values($DB->get_records_select('user', $likeidnumber, $params, '', 'id'));
        $usercount = count($users);
        $enrolsrequired = $usercount < 20 ? $usercount : 20;
        foreach ($courses as $course) {
            mtrace("Enrolling users on to course $course->id");
            $enrolcount = count_enrolled_users(\context_course::instance($course->id));
            $c = $enrolcount;
            while ($c < $enrolsrequired) {
                $c++;
                mtrace("Enrolling user $c of $enrolsrequired on course $course->id");
                $this->dg->enrol_user($users[$c]->id, $course->id, 'student');
            }
        }
        $courses->close();
    }

    private function create_verbalfeedbacks() {
        global $DB;
        mtrace('Creating verbal feedbacks');

        $courses = $DB->get_recordset('course');
        $instancesrequired = 2;
        foreach ($courses as $course) {
            if (strpos($course->idnumber, $this->testprefix) === false) {
                continue;
            }
            mtrace("Creating verbal feedback for course $course->id");
            $sql = "SELECT count(*)
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module AND m.name = :modname
                      WHERE cm.course = :course";
            $countinstances = $DB->count_records_sql($sql, ['course' => $course->id, 'modname' => 'verbalfeedback']);
            $tocreate = $instancesrequired - $countinstances;
            for ($c = 0; $c < $tocreate; $c++) {
                mtrace("Create verbalfeedback $c of $tocreate for course id $course->id");
                $modgen = $this->dg->get_plugin_generator('mod_verbalfeedback');
                $modgen->create_instance(['course' => $course->id]);
            }
        }
        $courses->close();
    }
}

genteststate::go();