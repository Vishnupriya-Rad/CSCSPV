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
 * My Moodle -- a user's personal dashboard
 *
 * - each user can currently have their own page (cloned from system and then customised)
 * - only the user can see their own dashboard
 * - users can add any blocks they want
 * - the administrators can define a default site dashboard for users who have
 *   not created their own dashboard
 *
 * This script implements the user's view of the dashboard, and allows editing
 * of the dashboard.
 *
 * @package    moodlecore
 * @subpackage my
 * @copyright  2010 Remote-Learner.net
 * @author     Hubert Chathi <hubert@remote-learner.net>
 * @author     Olav Jordan <olav.jordan@remote-learner.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../config.php');
require_once($CFG->dirroot . '/my/lib.php');

redirect_if_major_upgrade_required();

// TODO Add sesskey check to edit
$edit   = optional_param('edit', null, PARAM_BOOL);    // Turn editing on and off
$reset  = optional_param('reset', null, PARAM_BOOL);

require_login();

$hassiteconfig = has_capability('moodle/site:config', context_system::instance());
if ($hassiteconfig && moodle_needs_upgrading()) {
    redirect(new moodle_url('/admin/index.php'));
}

$strmymoodle = get_string('myhome');

if (empty($CFG->enabledashboard)) {
    // Dashboard is disabled, so the /my page shouldn't be displayed.
    $defaultpage = get_default_home_page();
    if ($defaultpage == HOMEPAGE_MYCOURSES) {
        // If default page is set to "My courses", redirect to it.
        redirect(new moodle_url('/my/courses.php'));
    } else {
        // Otherwise, raise an exception to inform the dashboard is disabled.
        throw new moodle_exception('error:dashboardisdisabled', 'my');
    }
}

if (isguestuser()) {  // Force them to see system default, no editing allowed
    // If guests are not allowed my moodle, send them to front page.
    if (empty($CFG->allowguestmymoodle)) {
        redirect(new moodle_url('/', array('redirect' => 0)));
    }

    $userid = null;
    $USER->editing = $edit = 0;  // Just in case
    $context = context_system::instance();
    $PAGE->set_blocks_editing_capability('moodle/my:configsyspages');  // unlikely :)
    $strguest = get_string('guest');
    $pagetitle = "$strmymoodle ($strguest)";

} else {        // We are trying to view or edit our own My Moodle page
    $userid = $USER->id;  // Owner of the page
    $context = context_user::instance($USER->id);
    $PAGE->set_blocks_editing_capability('moodle/my:manageblocks');
    $pagetitle = $strmymoodle;
}

// Get the My Moodle page info.  Should always return something unless the database is broken.
if (!$currentpage = my_get_page($userid, MY_PAGE_PRIVATE)) {
    throw new \moodle_exception('mymoodlesetup');
}

// Start setting up the page
$params = array();
$PAGE->set_context($context);
$PAGE->set_url('/my/index.php', $params);
$PAGE->set_pagelayout('mydashboard');
$PAGE->add_body_class('limitedwidth');
$PAGE->set_pagetype('my-index');
$PAGE->blocks->add_region('content');
$PAGE->set_subpage($currentpage->id);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($pagetitle);

if (!isguestuser()) {   // Skip default home page for guests
    if (get_home_page() != HOMEPAGE_MY) {
        if (optional_param('setdefaulthome', false, PARAM_BOOL)) {
            set_user_preference('user_home_page_preference', HOMEPAGE_MY);
        } else if (!empty($CFG->defaulthomepage) && $CFG->defaulthomepage == HOMEPAGE_USER) {
            $frontpagenode = $PAGE->settingsnav->add(get_string('frontpagesettings'), null, navigation_node::TYPE_SETTING, null);
            $frontpagenode->force_open();
            $frontpagenode->add(get_string('makethismyhome'), new moodle_url('/my/', array('setdefaulthome' => true)),
                    navigation_node::TYPE_SETTING);
        }
    }
}

// Toggle the editing state and switches
if (empty($CFG->forcedefaultmymoodle) && $PAGE->user_allowed_editing()) {
    if ($reset !== null) {
        if (!is_null($userid)) {
            require_sesskey();
            if (!$currentpage = my_reset_page($userid, MY_PAGE_PRIVATE)) {
                throw new \moodle_exception('reseterror', 'my');
            }
            redirect(new moodle_url('/my'));
        }
    } else if ($edit !== null) {             // Editing state was specified
        $USER->editing = $edit;       // Change editing state
    } else {                          // Editing state is in session
        if ($currentpage->userid) {   // It's a page we can edit, so load from session
            if (!empty($USER->editing)) {
                $edit = 1;
            } else {
                $edit = 0;
            }
        } else {
            // For the page to display properly with the user context header the page blocks need to
            // be copied over to the user context.
            if (!$currentpage = my_copy_page($USER->id, MY_PAGE_PRIVATE)) {
                throw new \moodle_exception('mymoodlesetup');
            }
            $context = context_user::instance($USER->id);
            $PAGE->set_context($context);
            $PAGE->set_subpage($currentpage->id);
            // It's a system page and they are not allowed to edit system pages
            $USER->editing = $edit = 0;          // Disable editing completely, just to be safe
        }
    }

    // Add button for editing page
    $params = array('edit' => !$edit);

    $resetbutton = '';
    $resetstring = get_string('resetpage', 'my');
    $reseturl = new moodle_url("$CFG->wwwroot/my/index.php", array('edit' => 1, 'reset' => 1));

    if (!$currentpage->userid) {
        // viewing a system page -- let the user customise it
        $editstring = get_string('updatemymoodleon');
        $params['edit'] = 1;
    } else if (empty($edit)) {
        $editstring = get_string('updatemymoodleon');
    } else {
        $editstring = get_string('updatemymoodleoff');
        $resetbutton = $OUTPUT->single_button($reseturl, $resetstring);
    }

    $url = new moodle_url("$CFG->wwwroot/my/index.php", $params);
    $button = '';
    if (!$PAGE->theme->haseditswitch) {
        $button = $OUTPUT->single_button($url, $editstring);
    }
    $PAGE->set_button($resetbutton . $button);

} else {
    $USER->editing = $edit = 0;
}

echo $OUTPUT->header();










?>


<!-- Custom Dashboard Layout -->
<div style="display: flex; gap: 20px;">
    <!-- Left Panel (20%) -->
    <div style="flex: 0 0 20%; background-color: #fff; padding: 10px; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08); border-radius: 8px;">
        <?php
        global $USER, $DB; // Access the global user and database objects

        // Check if a user is logged in
        if (isloggedin() && !isguestuser()) {
            // Get the logged-in user's details
            $firstname = $USER->firstname;
            $lastname = $USER->lastname;
            $email = $USER->email;
            $department = $USER->department; // Fetch the department field
            $contact = $USER->phone1; // Fetch the phone1 field

            // Fetch Employee ID (fieldid = 1)
            $userid = $USER->id; // Get the logged-in user's ID
            $employeeid = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 1]);

            // Fetch Designation (fieldid = 4)
            $designation = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 4]);

            // Fetch State (fieldid = 5)
            $state = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 5]);

            // Display the logged-in user's details
            echo '<div style="margin-bottom: 10px; border: 1px solid #fff; padding: 10px; border-radius: 8px; background-color: #fff; rgba(0, 0, 0, 0.1);">';
            echo '<strong>Name:</strong> ' . htmlspecialchars($firstname) . ' ' . htmlspecialchars($lastname) . '<br>';
            echo '<strong>Email:</strong> <a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a><br>';
            echo '<strong>Department:</strong> ' . htmlspecialchars($department) . '<br>';
            echo '<strong>Employee ID:</strong> ' . htmlspecialchars($employeeid) . '<br>';
            echo '<strong>Designation:</strong> ' . htmlspecialchars($designation) . '<br>';
            echo '<strong>State:</strong> ' . htmlspecialchars($state) . '<br>';
            echo '<strong>Contact:</strong> ' . htmlspecialchars($contact) . '<br>';
            echo '</div>';
        } else {
            // Message if no user is logged in
            echo '<p style="color: red; font-weight: bold;">No user is currently logged in.</p>';
        }
        ?>
    </div>

    

    <!-- Right Panel (80%) -->
    <div style="flex: 1; background-color: #fff; padding: 10px; padding-top: 0px; padding-bottom: 0px; display: flex; flex-direction: column; gap: 10px;">
        <!-- Top Section (30%) -->
        <div style="flex: 0 0 auto; display: flex; gap: 10px;">
            <!-- Card 1 (Blue) - Total Courses Enrolled -->
            <?php
            global $USER, $DB;

            // Get the logged-in user's ID
            $userid = $USER->id;

            // Query to count total courses the user is enrolled in
            $sql = "SELECT COUNT(DISTINCT c.id) AS totalcourses
                    FROM {course} c
                    JOIN {enrol} e ON e.courseid = c.id
                    JOIN {user_enrolments} ue ON ue.enrolid = e.id
                    WHERE ue.userid = :userid";

            $totalcourses = $DB->get_field_sql($sql, ['userid' => $userid]);

            echo '<div style="flex: 1; background-color:rgb(182, 213, 245); padding: 20px; border: 1px solid #ccc; box-sizing: border-box; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;" 
          onmouseover="this.style.transform=\'scale(1.05)\'; this.style.boxShadow=\'0 6px 12px rgba(0, 0, 0, 0.3)\'; this.style.backgroundColor=\'#3399ff\';" 
          onmouseout="this.style.transform=\'scale(1)\'; this.style.boxShadow=\'0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08)\'; this.style.backgroundColor=\'#66b3ff\';">';
echo '<h4>Total Courses Enrolled</h4>';
echo '<p>' . htmlspecialchars($totalcourses) . '</p>';
echo '</div>';
            ?>

            <!-- Card 2: Upcoming Events -->
            <div style="flex: 1; background-color:rgb(241, 181, 181); padding: 10px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;" 
     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 6px 12px rgba(0, 0, 0, 0.3)'; this.style.backgroundColor='#e0f7fa';" 
     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08)'; this.style.backgroundColor='#f7f7f7';">
    <h4 style="text-align: center;">Upcoming Events</h4>
    <?php
    global $USER, $DB;

    // Get current timestamp
    $currentTimestamp = time();

    // Get a list of courses the user is enrolled in
    $enrolledCourses = enrol_get_users_courses($USER->id, true, 'id');

    // If the user is enrolled in courses, fetch events from these courses
    if (!empty($enrolledCourses)) {
        $courseIds = array_keys($enrolledCourses); // Extract course IDs
        list($inSql, $params) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
    } else {
        // If no courses, set an empty condition
        $inSql = 'NULL';
        $params = [];
    }

    // Add conditions for fetching events
    $params['userid'] = $USER->id;
    $params['currenttime'] = $currentTimestamp;

    // SQL to fetch up to 2 upcoming events
    $sql = "
        SELECT e.id, e.name, e.timestart, e.eventtype
        FROM {event} e
        WHERE (e.userid = :userid OR e.courseid $inSql OR e.eventtype = 'site') 
          AND e.timestart > :currenttime
        ORDER BY e.timestart ASC
        LIMIT 2; -- Fetch only 2 events
    ";

    // Execute query
    $events = $DB->get_records_sql($sql, $params);

    // Display upcoming events
    if (!empty($events)) {
        echo "<ul style='list-style-type: none; padding: 0;'>";
        foreach ($events as $event) {
            $eventName = format_string($event->name);
            $eventStart = userdate($event->timestart); // Formats the timestamp for the user's timezone

            echo "<li style='margin: 10px 0; padding: 10px; border-bottom: 1px solid #ddd;'>
                <strong>{$eventName}</strong><br>
                <span style='font-size: 14px; color: #555;'>Starts: {$eventStart}</span>
            </li>";
        }
        echo "</ul>";
    } else {
        echo "<div style='text-align: center; margin: 20px; font-size: 16px; color: #555;'>
            No upcoming events found.
        </div>";
    }
    ?>
</div>


            <!-- Card 3 (Orange) - Last Login Details -->
            <div style="flex: 1; background-color:rgb(246, 223, 176); padding: 20px; border: 1px solid #ccc; box-sizing: border-box; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08); transition: all 0.3s ease;" 
     onmouseover="this.style.transform='scale(1.05)'; this.style.boxShadow='0 6px 12px rgba(0, 0, 0, 0.3)'; this.style.backgroundColor='#ffb74d';" 
     onmouseout="this.style.transform='scale(1)'; this.style.boxShadow='0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08)'; this.style.backgroundColor='#ffcc66';">
                <?php
                // Get last login details
                $lastlogin = $USER->lastlogin; // UNIX timestamp of the last login
                $lastloginip = $USER->lastip;  // Last login IP address

                // Format last login time
                if ($lastlogin) {
                    $lastlogintime = date('d-M-Y H:i:s', $lastlogin); // Format: DD-MMM-YYYY HH:MM:SS
                } else {
                    $lastlogintime = 'Never logged in';
                }

                // Display last login details
                echo '<h4>Last Login Details</h4>';
                echo '<p><strong>Last Login:</strong> ' . htmlspecialchars($lastlogintime) . '</p>';
                //echo '<p><strong>IP Address:</strong> ' . htmlspecialchars($lastloginip ? $lastloginip : 'No IP recorded') . '</p>';
                ?>
            </div>
        </div>

<!-- Bottom Section (70%) -->
<div style="flex: 1; display: flex; gap: 10px;">
    <!-- Left 50% of Bottom Section -->
    <div style="flex: 1; background-color: #f7f7f7; padding: 10px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08);">
        <h4>My Grades</h4>
        <canvas id="gradesBarChart"></canvas>
        <?php
        global $DB;

        // Fetch courses from category ID = 2
        $courses = $DB->get_records_sql("
            SELECT c.id, c.fullname
            FROM {course} c
            WHERE c.category = :categoryid
        ", ['categoryid' => 2]);

        // Initialize data arrays
        $courseNames = [];
        $courseGrades = [];

        foreach ($courses as $course) {
            $courseNames[] = $course->fullname;

            // Fetch average grade for quizzes in the course (module ID 18)
            $averageGrade = $DB->get_field_sql("
                SELECT AVG(qg.grade) AS average_grade
                FROM {quiz_grades} qg
                JOIN {quiz} q ON q.id = qg.quiz
                WHERE q.course = :courseid
            ", ['courseid' => $course->id]);

            $courseGrades[] = $averageGrade ?: 0; // Default to 0 if no grades found
        }

        // Encode data for JavaScript
        $courseNamesJSON = json_encode($courseNames);
        $courseGradesJSON = json_encode($courseGrades);
        ?>

        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const ctx = document.getElementById('gradesBarChart').getContext('2d');

                // Data from PHP
                const courseNames = <?php echo $courseNamesJSON; ?>;
                const courseGrades = <?php echo $courseGradesJSON; ?>;

                // Create the bar chart
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: courseNames,
                        datasets: [{
                            label: 'Average Grades',
                            data: courseGrades,
                            backgroundColor: 'rgba(75, 192, 192, 0.2)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        scales: {
                            x: {
                                title: {
                                    display: true,
                                    text: 'Courses'
                                }
                            },
                            y: {
                                beginAtZero: true,
                                title: {
                                    display: true,
                                    text: 'Average Grade'
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            }
                        }
                    }
                });
            });
        </script>
    </div>

    <!-- Right 50% of Bottom Section -->
<div style="flex: 1; background-color: #f7f7f7; padding: 10px; border: 1px solid #ccc; border-radius: 8px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1), inset 0 1px 3px rgba(0, 0, 0, 0.08);">
    <h4 style="text-align: center;">Course Progress</h4>
    <?php
    global $USER, $DB;

    // Fetch the user's enrolled courses, excluding courses from categories with IDs 2 and 3
    $enrolledCourses = enrol_get_users_courses($USER->id, true, 'id, fullname, category');

    if (!empty($enrolledCourses)) {
        echo "<ul style='list-style-type: none; padding: 0;'>";

        $courseNumber = 1; // Initialize numbering for courses
        foreach ($enrolledCourses as $course) {
            // Skip courses in category ID 2 or 3
            if (in_array($course->category, [2, 3])) {
                continue;
            }

            // SQL to calculate completion percentage
            $sql = "
                SELECT 
                    FLOOR(
                        (SUM(CASE 
                            WHEN cmc.completionstate = 1 THEN 1 
                            ELSE 0 
                        END) / COUNT(cm.id)) * 100
                    ) AS completion_percentage
                FROM {course_modules} cm
                LEFT JOIN {course_modules_completion} cmc 
                    ON cm.id = cmc.coursemoduleid 
                    AND cmc.userid = :userid
                WHERE cm.course = :courseid
                  AND cm.completion > 0
                GROUP BY cm.course;
            ";

            // Execute the query
            $completion = $DB->get_record_sql($sql, ['userid' => $USER->id, 'courseid' => $course->id]);

            // Format the completion percentage
            $completionPercentage = intval($completion->completion_percentage ?? 0); // Default to 0 if no tracking

            // Display course name with numbering and progress bar on the same line
            echo "<li style='margin: 10px 0; font-size: 12px; display: flex; align-items: center;'>
                <span style='flex: 1;'>{$courseNumber}. {$course->fullname}</span>
                <div style='flex: 3; background-color: #f0f0f0; border-radius: 5px; height: 20px; position: relative; margin-left: 10px;'>
                    <div style='width: {$completionPercentage}%; background-color: #4caf50; height: 100%; border-radius: 5px; text-align: right; line-height: 20px; color: white; padding-right: 5px;'>
                        {$completionPercentage}%
                    </div>
                </div>
            </li>";
            $courseNumber++;
        }
        echo "</ul>";
    } else {
            echo "<div style='text-align: center; margin: 20px; font-size: 18px; font-weight: bold; color: red;'>
                    You are not enrolled in any courses.
                  </div>";
                }
            ?>
            </div>
        </div>
    </div>
</div>



<?php

















if (core_userfeedback::should_display_reminder()) {
    core_userfeedback::print_reminder_block();
}

echo $OUTPUT->addblockbutton('content');

echo $OUTPUT->custom_block_region('content');

echo $OUTPUT->footer();

// Trigger dashboard has been viewed event.
$eventparams = array('context' => $context);
$event = \core\event\dashboard_viewed::create($eventparams);
$event->trigger();
