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

////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


// Get the current user's roles
global $DB, $USER;

// Fetch roles assigned to the current user
$userroles = $DB->get_records_sql("SELECT r.shortname, r.id FROM {role_assignments} ra JOIN {role} r ON ra.roleid = r.id WHERE ra.userid = ?", [$USER->id]);

// Check if the user is an admin
$isAdmin = false;
$hasAccess = false;
foreach ($userroles as $role) {
    if ($role->shortname === 'admin') {
        $isAdmin = true;
        break;
    }
    if (in_array($role->id, [5, 7])) {
        $hasAccess = true;
    }
}

// Display content
if ($isAdmin) {
    echo '<h1>Admin Dashboard</h1>';
} elseif ($hasAccess) {
    // Start of the HTML document
    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>Custom Moodle Dashboard</title>';
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    echo '<script src="https://cdn.jsdelivr.net/npm/gaugeJS/dist/gauge.min.js"></script>';

    echo '<style>';
    // Basic styles for the dashboard layout
    echo 'body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f5f5f5; }';
    echo '.dashboard { display: flex; height: 100vh; }';
    echo '.left-panel { width: 20%; background-color: white; padding: 20px;border-radius: 5px; box-shadow: 2px 0 5px rgba(0, 0, 0, 0.1); text-align: center; }';
    echo '.profile-img { width: 80px; height: 80px; border-radius: 50%; }';
    echo '.edit-button { background: #fff7e6; color: white; padding: 5px 10px; border: none; border-radius: 5px; cursor: pointer; }';

    // Styles for the right panel
    echo '.right-panel { width: 80%; display: flex; flex-direction: column; padding-left: 20px; padding-right: 1px; }';
    echo '.top-section { display: flex; justify-content: space-between; gap: 10px; }';
    echo '.top-section .stat-box { flex: 1; height: 100px; background:rgb(255, 255, 255); padding: 10px; text-align: center; border-radius: 8px; box-shadow: 0px 2px 5px rgba(0, 0, 0, 0.1); font-weight: bold; }';
    echo '.bottom-section { display: flex; flex-grow: 1; margin-top: 1px; gap: 10px; }';
    echo '.bottom-left { width: 60%; display: flex; flex-direction: column; gap: 10px; }';
    echo '.chart-container, .speedometer-container { max-height: 230px; flex: 1; background: white; padding: 20px; border-radius: 8px; box-shadow: 0px 2px 5px rgba(0, 0, 0, 0.1); }';
    echo '.bottom-right { width: 40%; display: flex; flex-direction: column; background: white; padding: 10px; border-radius: 8px; box-shadow: 0px 2px 5px rgba(0, 0, 0, 0.1); }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
////////////////////////////////////////////////////////////////////////////////////////////////////////////////////profile details
    // Left panel for user profile
    echo '<div class="dashboard">';
    echo '<div class="left-panel">';
    if (isloggedin() && !isguestuser()) {
        $firstname = $USER->firstname;
        $lastname  = $USER->lastname;
        $email     = $USER->email;
        $department= $USER->department;
        $contact   = $USER->phone1;
        $userid    = $USER->id;
        $employeeid= $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 1]);
        $designation= $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 4]);
        $state     = $DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => 5]);
        
        // Fetch user profile picture
        $user_picture = new user_picture($USER);
        $user_picture->size = 100;
        $profile_image_url = $user_picture->get_url($PAGE)->out();
        
        echo '<img src="' . $profile_image_url . '" class="profile-img" alt="Profile" style="border-radius: 50%; width: 90px; height: 90px; object-fit: cover; display: block; margin: 0 auto; border: 3px solid #0056b3;">';
        echo '<h3 style="color: #0056b3; text-align: center; margin-top: 1px;margin-bottom: -3px">' . htmlspecialchars($firstname) . ' ' . htmlspecialchars($lastname) . '</h3>';
        echo '<p>' . htmlspecialchars($state) . '</p>';
        echo '<a href="'.$CFG->wwwroot.'/user/edit.php?id='.$USER->id.'&returnto=profile" class="edit-button" style="display: flex; align-items: center; justify-content: center; margin: 1px auto; padding: 5px 12px; font-size: 14px; color: #0056b3; border: 1px solid #0056b3; border-radius: 8px; text-decoration: none; font-weight: bold; width: 40%; text-align: center; margin-bottom: 50px;">';
        echo '<img src="https://cdn-icons-png.flaticon.com/512/84/84380.png" width="14" height="14" alt="Edit" style="filter: invert(20%) sepia(100%) saturate(5000%) hue-rotate(200deg); margin-right: 5px;"> Edit Profile';
        echo '</a>';
        
        echo '<h5  style="color: #0056b3; text-align: left; margin-top: 10px;">Profile Details</h5>';
        echo '<p style="text-align: left"><strong>Email:</strong> <a href="mailto:' . htmlspecialchars($email) . '">' . htmlspecialchars($email) . '</a></p>';
        echo '<p style="text-align: left"><strong>Department:</strong> ' . htmlspecialchars($department) . '</p>';
        echo '<p style="text-align: left"><strong>Employee ID:</strong> ' . htmlspecialchars($employeeid) . '</p>';
        echo '<p style="text-align: left"><strong>Designation:</strong> ' . htmlspecialchars($designation) . '</p>';
        echo '<p style="text-align: left"><strong>Contact:</strong> ' . htmlspecialchars($contact) . '</p>';
    } else {
        echo '<p style="color: red; font-weight: bold;">No user is currently logged in.</p>';
    }
    echo '</div>';
/////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    // Right panel for statistics and charts
    echo '<div class="right-panel" style="display: flex; flex-direction: column; gap: 20px;">';
    echo '<div class="top-section" style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 10px;">';
///////////////////////////////////////////////////////////////////////////////////////////////////// - total courses enrolled
echo '<div class="stat-box" style="flex: 1; min-width: 150px;">';
global $USER, $DB;
$userid = $USER->id;
$sql = "SELECT COUNT(DISTINCT c.id) AS totalcourses FROM {course} c JOIN {enrol} e ON e.courseid = c.id JOIN {user_enrolments} ue ON ue.enrolid = e.id WHERE ue.userid = :userid";
$totalcourses = $DB->get_field_sql($sql, ['userid' => $userid]);
$finishedCourses = rand(5, 15);
$pendingCourses = rand(1, 10);

echo '<div class="tab-container">
        <span class="tab active" onclick="showTab(\'enrolled\', this)">Enrolled</span>
        <span class="tab" onclick="showTab(\'finished\', this)">Finished</span>
        <span class="tab" onclick="showTab(\'pending\', this)">Pending</span>
      </div>';

echo '<div id="enrolled" class="tab-content active"><div class="count-box">' . htmlspecialchars($totalcourses) . '</div><strong>Enrolled Courses</strong></div>';
echo '<div id="finished" class="tab-content"><div class="count-box">' . $finishedCourses . '</div><strong>Total Finished</strong></div>';
echo '<div id="pending" class="tab-content"><div class="count-box">' . $pendingCourses . '</div><strong>Total Pending</strong></div>';

echo '<style>
    .stat-box { background: #fff; padding: 20px; border-radius: 12px; box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1); width: 320px; text-align: center; font-family: Arial, sans-serif; }
    .tab-container { display: flex; justify-content: space-around; margin-bottom: 10px; border-bottom: 2px solid #fff; }
    .tab { cursor: pointer; padding: 5px; font-weight: bold; color:rgb(119, 119, 119); transition: color 0.3s ease-in-out; }
    .tab.active { color: #0056b3; border-bottom: 3px solid #0056b3; }
    .tab-content { display: none; }
    .tab-content.active { display: block; }
    .count-box { background: rgb(255, 229, 204); display: inline-block; padding: 10px; font-size: 24px; font-weight: bold; border-radius: 8px; margin-bottom: 5px; }
</style>';

echo '<script>
    function showTab(tabId, element) {
        document.querySelectorAll(".tab-content").forEach(tab => tab.classList.remove("active"));
        document.getElementById(tabId).classList.add("active");
        document.querySelectorAll(".tab").forEach(tab => { tab.classList.remove("active"); tab.style.color = "#4a4a4a"; });
        element.classList.add("active"); element.style.color = "#0056b3";
    }
</script>';
echo '</div>';
/////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////pending task
echo '<div class="stat-box" style="flex: 1; min-width: 150px;">';

// Tab filters: Assessments (default) & Feedback
echo '<div style="display: flex; align-items: left; padding-bottom: 10px; border-bottom: none;">
        <span id="assessments-tab" style="color: #0056b3; font-weight: bold; cursor: pointer; border-bottom: 2px solid #0056b3;padding-right: 10px; padding-bottom: 3px;">Assessments</span>
        <span id="feedback-tab" style="color: #777; cursor: pointer;padding-left: 10px;">Feedback</span>
      </div>';

// Get user ID
$userid = $USER->id;

// Function to get pending count for a specific module type
function get_pending_count($moduleid) {
    global $DB, $userid;

    // Get total count of that module type in enrolled courses
    $totalSql = "SELECT COUNT(cm.id) AS totalmodules
                 FROM {course_modules} cm
                 JOIN {enrol} e ON e.courseid = cm.course
                 JOIN {user_enrolments} ue ON ue.enrolid = e.id
                 WHERE ue.userid = :userid
                 AND cm.module = :moduleid";
    $totalModules = $DB->get_field_sql($totalSql, ['userid' => $userid, 'moduleid' => $moduleid]);

    // Get completed count of that module type
    $completedSql = "SELECT COUNT(cmc.id) AS completedmodules
                     FROM {course_modules_completion} cmc
                     JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                     WHERE cmc.userid = :userid
                     AND cm.module = :moduleid
                     AND cmc.completionstate = 1";
    $completedModules = $DB->get_field_sql($completedSql, ['userid' => $userid, 'moduleid' => $moduleid]);

    // Calculate pending tasks
    return max(0, $totalModules - $completedModules);
}

// Get pending counts
$pendingAssessments = get_pending_count(18); // Assessments
$pendingFeedback = get_pending_count(8); // Feedback

// Assessments section (default view)
echo '<div id="assessments-section" style="display: block;">
        <div style="display: flex; align-items: left; background-color: #FFE0B2; border-radius: 8px; padding: 10px; margin-top: 10px; width: fit-content;">
            <span style="font-size: 24px; font-weight: bold; margin-right: 10px;">' . $pendingAssessments . '</span>
            <div><strong>Pending Assessments</strong><br>
            <span style="color: #666;">' . ($pendingAssessments > 0 ? "" : "All assessments completed!") . '</span>
            </div>
        </div>
      </div>';

// Feedback section (hidden initially)
echo '<div id="feedback-section" style="display: none;">
        <div style="display: flex; align-items: left; background-color: #D6EAF8; border-radius: 8px; padding: 10px; margin-top: 10px; width: fit-content;">
            <span style="font-size: 24px; font-weight: bold; margin-right: 10px;">' . $pendingFeedback . '</span>
            <div><strong>Pending Feedback</strong><br>
            <span style="color: #666;">' . ($pendingFeedback > 0 ? "" : "All feedback completed!") . '</span>
            </div>
        </div>
      </div>';

// JavaScript to switch tabs
echo '<script>
        document.getElementById("assessments-tab").addEventListener("click", function() {
            document.getElementById("assessments-section").style.display = "block";
            document.getElementById("feedback-section").style.display = "none";
            this.style.color = "#0056b3";
            this.style.borderBottom = "2px solid #0056b3";
            document.getElementById("feedback-tab").style.color = "#777";
            document.getElementById("feedback-tab").style.borderBottom = "none";
        });

        document.getElementById("feedback-tab").addEventListener("click", function() {
            document.getElementById("assessments-section").style.display = "none";
            document.getElementById("feedback-section").style.display = "block";
            this.style.color = "#0056b3";
            this.style.borderBottom = "2px solid #0056b3";
            document.getElementById("assessments-tab").style.color = "#777";
            document.getElementById("assessments-tab").style.borderBottom = "none";
        });
      </script>';

echo '</div>'; // Close stat-box
//////////////////////////////////////////
////////////////////////////////////////////////////////////////////////////////////////////////upcoming events
echo '<div class="stat-box" style="flex: 1; min-width: 150px; background: #fff; padding: 10px; border-radius: 12px; box-shadow: 0px 2px 8px rgba(0, 0, 0, 0.1); width: 350px; font-family: Arial, sans-serif;">';

// Title with calendar icon
echo '<strong style="font-size: 14px; display: flex; align-items: center;">
        <i class="fa-regular fa-calendar" style="font-size: 18px; margin-right: 8px;"></i>
        Upcoming Events
      </strong>';

// Fetch upcoming events
$currentTimestamp = time();
$enrolledCourses = enrol_get_users_courses($USER->id, true, 'id');

if (!empty($enrolledCourses)) {
    $courseIds = array_keys($enrolledCourses);
    list($inSql, $params) = $DB->get_in_or_equal($courseIds, SQL_PARAMS_NAMED);
} else {
    $inSql = 'NULL';
    $params = [];
}

$params['userid'] = $USER->id;
$params['currenttime'] = $currentTimestamp;

$sql = "
    SELECT e.id, e.name, e.timestart, e.eventtype
    FROM {event} e
    WHERE (e.userid = :userid OR e.courseid $inSql OR e.eventtype = 'site') 
      AND e.timestart > :currenttime
    ORDER BY e.timestart ASC
    LIMIT 1;
";

$events = $DB->get_records_sql($sql, $params);

if (!empty($events)) {
    foreach ($events as $event) {
        $eventName = format_string($event->name);
        $eventStart = userdate($event->timestart, '%I:%M %p');

        echo '<div style="margin-top: 1px; padding: 5px; background: #EEF8E8; border-radius: 8px; display: flex; flex-direction: column; gap: 6px;">
                <div style="display: flex; align-items: center;">
                    <div style="width: 4px; background: green; height: 30px; margin-right: 10px; border-radius: 4px;"></div>
                    <div style="font-size: 14px; font-weight: bold; color: #333;">' . $eventName . '</div>
                </div>
                <div style="display: flex; align-items: center; font-size: 14px; color: #555;">
                    <i class="fa-regular fa-clock" style="font-size: 14px; margin-right: 5px;"></i>
                    ' . $eventStart . '
                </div>
              </div>';
    }
} else {
    echo '<div style="text-align: center; margin: 20px; font-size: 14px; color: #555;">
            No upcoming events found.
          </div>';
}

echo '</div>';
//////////////////////////////////////////
//////////////////////////////////////////////////////////////////////////////////////////////////last login
echo '<div class="stat-box" style="display: flex: 1; min-width: 150px; align-items: center; justify-content: space-between; background: #fff; padding: 10px; border-radius: 10px; box-shadow: 0px 4px 8px rgba(0, 0, 0, 0.1); width: 300px; font-family: Arial, sans-serif;">';

// Get last login details and format
$lastlogin = $USER->lastlogin;
$lastlogindate = $lastlogin ? date('jS M Y', $lastlogin) : 'Never logged in';
$lastlogintime = $lastlogin ? date('g:i A', $lastlogin) : '';

echo '<div class="login-text" style="font-size: 14px; font-weight: semi-bold; color: #333;">Last Login Details:</div>
      <div class="login-details" style="background:rgb(253, 236, 215); padding: 8px 12px; border-radius: 8px; font-size: 14px; font-weight: bold; color: black; text-align: center;">
          <span>' . htmlspecialchars($lastlogindate) . '</span><br><span>' . htmlspecialchars($lastlogintime) . '</span>
      </div>';

echo '</div>';
/////////////////////////////////////////
    echo '</div>';

    echo '<div class="bottom-section">';
    echo '<div class="bottom-left">';
//////////////////////////////////////////////////////////////////////////////////////////////////////Time  - Bard chart
echo '<div class="chart-container">';
global $DB, $USER;

$userid = $USER->id;
$selectedCourse = optional_param('courseid', 0, PARAM_INT);
$selectedFilter = optional_param('filter', 'daily', PARAM_ALPHA);
$idletime = 30 * 60; // 30 minutes idle threshold

// Get current month and year
$currentMonth = date('n');
$currentYear = date('Y');
$daysInMonth = date('t');

// Initialize arrays for time tracking
$timePerDay = array_fill(1, $daysInMonth, 0);
$timePerWeek = array_fill(0, 5, 0);
$timePerMonth = array_fill(1, 12, 0);
$totalTimePerCourse = [];

// Get start of each week in the current month
$weeks = [];
$startOfMonth = strtotime("$currentYear-$currentMonth-01");
$weekStart = $startOfMonth;

while (date('n', $weekStart) == $currentMonth) {
    $weeks[] = $weekStart;
    $weekStart = strtotime("+1 week", $weekStart);
}

// Fetch enrolled courses
$courses = $DB->get_records_sql("
    SELECT c.id, c.fullname 
    FROM {course} c 
    JOIN {enrol} e ON e.courseid = c.id 
    JOIN {user_enrolments} ue ON ue.enrolid = e.id 
    WHERE ue.userid = ?", [$userid]
);

// Calculate time spent for each course
foreach ($courses as $course) {
    $logs = $DB->get_records_sql("
        SELECT timecreated 
        FROM {logstore_standard_log} 
        WHERE userid = ? AND courseid = ? 
        ORDER BY timecreated ASC", 
        [$userid, $course->id]
    );

    $courseTimeSpent = 0;
    $prevTime = null;

    foreach ($logs as $log) {
        if ($prevTime !== null) {
            $diff = $log->timecreated - $prevTime;
            if ($diff < $idletime) {
                $courseTimeSpent += $diff; // Accumulate time spent

                $logDay = date('j', $log->timecreated);
                $logMonth = date('n', $log->timecreated);

                // Update time based on selected filter
                if ($selectedFilter == 'daily' && $logMonth == $currentMonth) {
                    $timePerDay[$logDay] += $diff;
                } elseif ($selectedFilter == 'weekly') {
                    foreach ($weeks as $index => $weekStart) {
                        $weekEnd = strtotime("+6 days", $weekStart);
                        if ($log->timecreated >= $weekStart && $log->timecreated <= $weekEnd) {
                            $timePerWeek[$index] += $diff;
                            break;
                        }
                    }
                } elseif ($selectedFilter == 'monthly') {
                    $timePerMonth[$logMonth] += $diff;
                }
            }
        }
        $prevTime = $log->timecreated; // Update previous time
    }
    // Store total time spent in hours for the course
    $totalTimePerCourse[$course->id] = round($courseTimeSpent / 3600, 2); // Convert to hours
}

// Convert total time to HH:MM:SS
$totalSeconds = array_sum($totalTimePerCourse) * 3600; // Total time in seconds
$formattedTime = sprintf("%02d:%02d:%02d", floor($totalSeconds / 3600), floor(($totalSeconds % 3600) / 60), $totalSeconds % 60);
$timePerDayHours = array_map(fn($t) => round($t / 3600, 2), $timePerDay); // Convert to hours
$timePerWeekHours = array_map(fn($t) => round($t / 3600, 2), $timePerWeek); // Convert to hours
$timePerMonthHours = array_map(fn($t) => round($t / 3600, 2), $timePerMonth); // Convert to hours

// Generate labels
$dayLabels = range(1, $daysInMonth);
$weekLabels = array_map(fn($i) => "Week " . ($i + 1), array_keys($weeks));

// Course selection dropdown and filter dropdown side by side
echo '<form method="get" onchange="this.submit()" style="display: flex; justify-content: space-between; width: 100%;">';
echo '<div style="flex: 1; margin-right: 10px;">'; // First filter
echo '<label for="course">Select Course: </label>';
echo '<select name="courseid" id="course" style="width: 100%;">';
echo '<option value="0">Total Time Spent (All Courses)</option>';
foreach ($courses as $course) {
    $selected = ($course->id == $selectedCourse) ? 'selected' : '';
    echo '<option value="' . $course->id . '" ' . $selected . '>' . $course->fullname . '</option>';
}
echo '</select>';
echo '</div>'; // End of first filter

echo '<div style="flex: 1; margin-left: 10px;">'; // Second filter
echo '<label for="filter">Filter: </label>';
echo '<select name="filter" id="filter" style="width: 100%;">';
echo '<option value="daily" ' . ($selectedFilter == 'daily' ? 'selected' : '') . '>Daily</option>';
echo '<option value="weekly" ' . ($selectedFilter == 'weekly' ? 'selected' : '') . '>Weekly</option>';
echo '<option value="monthly" ' . ($selectedFilter == 'monthly' ? 'selected' : '') . '>Monthly</option>';
echo '</select>';
echo '</div>'; // End of second filter
echo '</form>';

// Display total time spent
echo "<p>Total Time Spent: <strong>$formattedTime (HH:MM:SS)</strong></p>";
echo '<div style="width: 100%; max-width: 600px;">
        <canvas id="timeChart"></canvas>
      </div>';

// Include Chart.js
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

echo "<script>
    document.addEventListener('DOMContentLoaded', function () {";

// X and Y labels
$chartLabels = $selectedFilter == 'daily' ? json_encode($dayLabels) : 
               ($selectedFilter == 'weekly' ? json_encode($weekLabels) :
               "['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']");

$chartData = $selectedFilter == 'daily' ? json_encode(array_values($timePerDayHours)) :
             ($selectedFilter == 'weekly' ? json_encode(array_values($timePerWeekHours)) :
             json_encode(array_values($timePerMonthHours)));

echo "
    const ctx = document.getElementById('timeChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: $chartLabels,
            datasets: [{
                label: 'Time Spent (Hours)',
                data: $chartData,
                backgroundColor: 'rgba(54, 162, 235, 0.6)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true, 
            maintainAspectRatio: false,
            scales: {
                x: { 
                    title: { display: true, text: 'Time Period' }, // X-Axis Label
                    grid: { display: false } 
                },
                y: { 
                    title: { display: true, text: 'Time Spent (Hours)' }, // Y-Axis Label
                    grid: { display: false } 
                }
            }
        }
    });
";

echo "});
</script>";
echo '</div>'; // Close the chart-container div
//////////////////////////////////////////////////////////////////////////////////////////////////////speedometer
echo '<div class="speedometer-container" style="width: 100%; max-width: 550px; margin: auto; text-align: center; display: flex; align-items: center; justify-content: space-between;">';

// **Fetch enrolled courses from category ID = 2**
$courses = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname
    FROM {course} c
    JOIN {enrol} e ON e.courseid = c.id
    JOIN {user_enrolments} ue ON ue.enrolid = e.id
    WHERE c.category = :categoryid
      AND ue.userid = :userid
", ['categoryid' => 2, 'userid' => $USER->id]);

// **Prepare dropdown options and grade data**
$courseOptions = '<option value="overall">Overall Average</option>';
$totalGrade = 0;
$courseCount = count($courses);
$courseData = [];

foreach ($courses as $course) {
    $courseOptions .= '<option value="' . $course->id . '">' . htmlspecialchars($course->fullname) . '</option>';

    $averageGrade = $DB->get_field_sql("
        SELECT COALESCE(AVG(qg.grade), 0) AS average_grade
        FROM {quiz} q
        LEFT JOIN {quiz_grades} qg ON q.id = qg.quiz AND qg.userid = :userid
        WHERE q.course = :courseid
    ", ['courseid' => $course->id, 'userid' => $USER->id]);

    $totalGrade += $averageGrade;
    $courseData[$course->id] = round($averageGrade); // Store grades
}

// **Calculate Overall Average Grade**
$overallGrade = ($courseCount > 0) ? round($totalGrade / $courseCount) : 0;

// **Encode course data for JavaScript**
$courseDataJSON = json_encode($courseData);
$overallGradeJSON = json_encode($overallGrade);

// **Speedometer Canvas with Fixed Size**
echo '<div style="position: relative; width: 50%; max-width: 300px; margin-right: 10px;">
        <canvas id="speedometerChart" width="300" height="150" style="max-width: 100%; height: auto;"></canvas>
      </div>';

// **Dropdown for Course Selection**
echo '<div style="width: 50%; text-align: left;">
        <h4 class="title" style="font-size: 18px; margin-bottom: 10px;">Performance</h4>
        <select id="courseDropdown" class="dropdown" style="padding: 5px; width: 100%; margin-bottom: 15px;">';
echo $courseOptions;
echo '</select>
      </div>';

// **Include Chart.js**
echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';

echo '<script>
document.addEventListener("DOMContentLoaded", function () {
    const ctx = document.getElementById("speedometerChart").getContext("2d");

    let courseGrades = ' . $courseDataJSON . ';
    let overallGrade = ' . $overallGradeJSON . ';

    let speedometerChart = new Chart(ctx, {
        type: "doughnut",
        data: {
            labels: ["High", "Medium", "Low"],
            datasets: [{
                data: [overallGrade, 10 - overallGrade, 0],
                backgroundColor: ["#008000", "#e03131", "#008000"],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: "75%", 
            rotation: 270,
            circumference: 180,
            plugins: {
                legend: { display: false }
            }
        }
    });

    // **Handle Dropdown Change**
    document.getElementById("courseDropdown").addEventListener("change", function () {
        let selectedCourse = this.value;

        if (selectedCourse === "overall") {
            updateChart(overallGrade);
        } else {
            let grade = courseGrades[selectedCourse] || 0;
            updateChart(grade);
        }
    });

    function updateChart(newGrade) {
        speedometerChart.data.datasets[0].data = [newGrade, 10 - newGrade, 0];
        speedometerChart.update();
    }
});
</script>';

echo '</div>'; // **End of speedometer-container**
//////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
    echo '</div>';



 //////////////////////////////////////////////////////////////////////////////////////////////////////////////course progress   
    echo '<div class="bottom-right" style="background: #ffffff; padding: 10px; border-radius: 10px; max-height: 530px; overflow-y: auto; box-shadow: 0px 2px 5px rgba(0, 0, 0, 0.1); display: flex; flex-direction: column;">';

    echo '<h3 style="text-align: center; margin: 1px;font-size: 16px;">Your Courses</h3>';
    
    global $USER, $DB;
    
    // Fetch the user's enrolled courses, excluding courses from categories with IDs 2 and 3
    $enrolledCourses = enrol_get_users_courses($USER->id, true, 'id, fullname, category');
    
    if (!empty($enrolledCourses)) {
        echo "<ul style='list-style-type: none; padding: 0; margin: 0; overflow-y: auto; flex-grow: 1;'>";

    
        foreach ($enrolledCourses as $course) {
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
    
            // Display each course in a styled box
            echo "<li style='background: white; padding: 5px; border-radius: 8px; margin-bottom: 5px; box-shadow: 0px 1px 3px rgba(0, 0, 0, 0.1); display: flex; align-items: center;'>
                    <span style='flex: 40%; font-weight: semi-bold; font-size: 14px; background-color: #f0f0f0; padding: 10px; border-radius: 5px; text-align: left;'>" . htmlspecialchars($course->fullname) . "</span>
                    <div style='flex: 60%; display: flex; align-items: center; margin-left: 10px;'>
                        <div style='background-color: #eee; border-radius: 5px; height: 8px; width: 100%; position: relative;'>
                            <div style='width: {$completionPercentage}%; background-color: #3366ff; height: 100%; border-radius: 5px;'></div>
                        </div>
                        <span style='margin-left: 8px; font-size: 12px; font-weight: bold; color: #333;'>{$completionPercentage}%</span>
                    </div>
                </li>";
        }
    
        echo "</ul>";
    } else {
        echo "<div style='text-align: center; margin: 20px; font-size: 18px; font-weight: bold; color: red;'>
                You are not enrolled in any courses.
              </div>";
    }
    echo '</div>';
 /////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////   
    





    echo '</div>';
    echo '</div>';
    echo '</div>';
        
}




/////////////////////////////////////////////////////////////////////////////////////////////////
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

?>




