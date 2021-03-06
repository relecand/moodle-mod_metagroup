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
 * Local stuff for metagroup course enrolment plugin.
 *
 * @package    enrol_metagroup
 * @author     Petr Skoda
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();


/**
 * Event handler for metagroup enrolment plugin.
 *
 * We try to keep everything in sync via listening to events,
 * it may fail sometimes, so we always do a full sync in cron too.
 */
class enrol_metagroup_handler {

    /**
     * Synchronise metagroup enrolments of this user in this course
     * @static
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    protected static function sync_course_instances($courseid, $userid, $groupid=null) {
        global $DB;

        static $preventrecursion = false;

        // does anything want to sync with this parent?
        if ($groupid)
            $enrols = $DB->get_records('enrol', array('customint1'=>$courseid, 'customint2'=>$groupid, 'enrol'=>'metagroup'), 'id ASC');
        else
            $enrols = $DB->get_records('enrol', array('customint1'=>$courseid, 'enrol'=>'metagroup'), 'id ASC');
        if (!$enrols) {
            return;
        }

        if ($preventrecursion) {
            return;
        }

        $preventrecursion = true;

        try {
            foreach ($enrols as $enrol) {
                self::sync_with_parent_course($enrol, $userid);
            }
        } catch (Exception $e) {
            $preventrecursion = false;
            throw $e;
        }

        $preventrecursion = false;
    }

    /**
     * Synchronise user enrolments in given instance as fast as possible.
     *
     * All roles are removed if the metagroup plugin disabled.
     *
     * @static
     * @param stdClass $instance
     * @param int $userid
     * @return void
     */
    protected static function sync_with_parent_course(stdClass $instance, $userid) {
        global $DB, $CFG;

        $plugin = enrol_get_plugin('metagroup');

        if ($instance->customint1 == $instance->courseid) {
            // can not sync with self!!!
            return;
        }

        $context = context_course::instance($instance->courseid);

        // list of enrolments in parent course (we ignore metagroup enrols in parents completely)
        list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
        $params['userid'] = $userid;
        $params['parentcourse'] = $instance->customint1;
        $params['parentgroup'] = $instance->customint2;
        $sql = "SELECT ue.*
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON (e.id = ue.enrolid AND e.enrol <> 'metagroup' AND e.courseid = :parentcourse AND e.enrol $enabled)
                  JOIN {groups_members} grp on grp.groupid=:parentgroup and grp.userid=ue.userid
                  WHERE ue.userid = :userid
                 ";
        $parentues = $DB->get_records_sql($sql, $params);
        // current enrolments for this instance
        $ue = $DB->get_record('user_enrolments', array('enrolid'=>$instance->id, 'userid'=>$userid));

        // first deal with users that are not enrolled in parent
        if (empty($parentues)) {
            self::user_not_supposed_to_be_here($instance, $ue, $context, $plugin);
            return;
        }

        if (!$parentcontext = context_course::instance($instance->customint1, IGNORE_MISSING)) {
            // Weird, we should not get here.
            return;
        }

        $skiproles = $plugin->get_config('nosyncroleids', '');
        $skiproles = empty($skiproles) ? array() : explode(',', $skiproles);
        $syncall   = $plugin->get_config('syncall', 1);

        // roles in parent course (metagroup enrols must be ignored!)
        $parentroles = array();
        list($ignoreroles, $params) = $DB->get_in_or_equal($skiproles, SQL_PARAMS_NAMED, 'ri', false, -1);
        $params['contextid'] = $parentcontext->id;
        $params['userid'] = $userid;
        $select = "contextid = :contextid AND userid = :userid AND component <> 'enrol_metagroup' AND roleid $ignoreroles";
        foreach($DB->get_records_select('role_assignments', $select, $params) as $ra) {
            $parentroles[$ra->roleid] = $ra->roleid;
        }

        // roles from this instance
        $roles = array();
        $ras = $DB->get_records('role_assignments', array('contextid'=>$context->id, 'userid'=>$userid, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
        foreach($ras as $ra) {
            $roles[$ra->roleid] = $ra->roleid;
        }
        unset($ras);

        // do we want users without roles?
        if (!$syncall and empty($parentroles)) {
            self::user_not_supposed_to_be_here($instance, $ue, $context, $plugin);
            return;
        }

        // is parent enrol active? (we ignore enrol starts and ends, sorry it would be too complex)
        $parentstatus = ENROL_USER_SUSPENDED;
        foreach ($parentues as $pue) {
            if ($pue->status == ENROL_USER_ACTIVE) {
                $parentstatus = ENROL_USER_ACTIVE;
                break;
            }
        }

        // enrol user if not enrolled yet or fix status
        if ($ue) {
            if ($parentstatus != $ue->status) {
                $plugin->update_user_enrol($instance, $userid, $parentstatus);
                $ue->status = $parentstatus;
            }
        } else {
            $plugin->enrol_user($instance, $userid, NULL, 0, 0, $parentstatus);
            $ue = new stdClass();
            $ue->userid = $userid;
            $ue->enrolid = $instance->id;
            $ue->status = $parentstatus;
        }

        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        // only active users in enabled instances are supposed to have roles (we can reassign the roles any time later)
        if ($ue->status != ENROL_USER_ACTIVE or $instance->status != ENROL_INSTANCE_ENABLED) {
            if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
                // Always keep the roles.
            } else if ($roles) {
                role_unassign_all(array('userid'=>$userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
            }
            return;
        }

        // add new roles
        foreach ($parentroles as $rid) {
            if (!isset($roles[$rid])) {
                role_assign($rid, $userid, $context->id, 'enrol_metagroup', $instance->id);
            }
        }

        if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
            // Always keep the roles.
            return;
        }

        // remove roles
        foreach ($roles as $rid) {
            if (!isset($parentroles[$rid])) {
                role_unassign($rid, $userid, $context->id, 'enrol_metagroup', $instance->id);
            }
        }
    }

    /**
     * Deal with users that are not supposed to be enrolled via this instance
     * @static
     * @param stdClass $instance
     * @param stdClass $ue
     * @param context_course $context
     * @param enrol_metagroup $plugin
     * @return void
     */
    protected static function user_not_supposed_to_be_here($instance, $ue, context_course $context, $plugin) {
        if (!$ue) {
            // Not enrolled yet - simple!
            return;
        }

        $userid = $ue->userid;
        $unenrolaction = $plugin->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);

        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            // Purges grades, group membership, preferences, etc. - admins were warned!
            $plugin->unenrol_user($instance, $userid);

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $plugin->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
            }
            role_unassign_all(array('userid'=>$userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));

        } else {
            debugging('Unknown unenrol action '.$unenrolaction);
        }
    }
}

/**
 * Gets course id from short name
 * @param $shortname string
 * @return course object  course id or false if none found
 */
function get_course_from_shortname($shortname){
    global $DB;
    return $DB->get_record('course', array('shortname'=>$shortname));
}

/**
 * @param $course course object
 * @param $metacourse_id    course id
 * @param $metacourse_group     group id
 * @return bool     whether enrolment exists
 */
function enrolment_exists($course, $metacourse_id, $metacourse_group){
    global $DB;
    $count = $DB->count_records('enrol', array('courseid'=>$course->id, 'customint1'=>$metacourse_id, 'customint2'=>$metacourse_group));
    if ($count)
        return true;
    else
        return false;
}

/**
 * Gets course shortname using the idnumber, if not found return input
 * @param $shortname string
 * @return course object or false if none found
 */
function get_course_from_idnumber_or_shortname($idnumber) {
    global $DB;
    $course = $DB->get_record('course', array('idnumber'=>$idnumber));
    if ($course) {
        return $course;
    } else {
        return $DB->get_record('course', array('shortname'=>$idnumber));
    }
}

/**
 * Sync all metagroup course links.
 *
 * @param int $courseid one course, empty mean all
 * @param bool $verbose verbose CLI output
 * @return int 0 means ok, 1 means error, 2 means plugin disabled
 */
function enrol_metagroup_sync($courseid = NULL, $verbose = false) {
    global $CFG, $DB;

    // purge all roles if metagroup sync disabled, those can be recreated later here in cron
    if (!enrol_is_enabled('metagroup')) {
        if ($verbose) {
            mtrace('Metagroup sync plugin is disabled, unassigning all plugin roles and stopping.');
        }
        role_unassign_all(array('component'=>'enrol_metagroup'));
        return 2;
    }

    // unfortunately this may take a long time, execution can be interrupted safely
    core_php_time_limit::raise();
    raise_memory_limit(MEMORY_HUGE);

    if ($verbose) {
        mtrace('Starting user enrolment synchronisation...');
    }

    $instances = array(); // cache instances

    $metagroup = enrol_get_plugin('metagroup');

    $unenrolaction = $metagroup->get_config('unenrolaction', ENROL_EXT_REMOVED_SUSPENDNOROLES);
    $skiproles     = $metagroup->get_config('nosyncroleids', '');
    $skiproles     = empty($skiproles) ? array() : explode(',', $skiproles);
    $syncall       = $metagroup->get_config('syncall', 1);

    $allroles = get_all_roles();

    // iterate through all not enrolled yet users
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $sql = "SELECT grp.*, e.* FROM mdl_enrol e JOIN mdl_groups_members grp ON (e.enrol='metagroup' AND e.customint2=grp.groupid $onecourse) LEFT
            JOIN mdl_user_enrolments ue ON (ue.userid=grp.userid AND ue.enrolid=e.id)
            WHERE ue.id is NULL";

    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ue) {
        if (!isset($instances[$ue->id])) {
            $instances[$ue->id] = $ue;
        }
        $instance = $instances[$ue->id];

        $metagroup->enrol_user($instance, $ue->userid, $ue->status);
        if ($verbose) {
            mtrace("  enrolling: $ue->userid ==> $instance->courseid");
        }
    }
    $rs->close();


    // unenrol as necessary - ignore enabled flag, we want to get rid of existing enrols in any case
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal(explode(',', $CFG->enrol_plugins_enabled), SQL_PARAMS_NAMED, 'e');
    $params['courseid'] = $courseid;
    $sql = "SELECT ue.*, e.*  FROM mdl_user_enrolments ue join mdl_enrol e ON (e.id=ue.enrolid AND e.enrol='metagroup' $onecourse)
            LEFT JOIN mdl_groups_members grp ON (e.customint2=grp.groupid AND grp.userid=ue.userid)
            WHERE grp.id is NULL";
    $rs = $DB->get_recordset_sql($sql, $params);    
    foreach($rs as $ue) {
        if (!isset($instances[$ue->enrolid])) {
            $instances[$ue->enrolid] = $ue;
        }
        $instance = $instances[$ue->enrolid];

        if ($unenrolaction == ENROL_EXT_REMOVED_UNENROL) {
            $metagroup->unenrol_user($instance, $ue->userid);
            if ($verbose) {
                mtrace("  unenrolling: $ue->userid ==> $instance->courseid");
            }

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPEND) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                if ($verbose) {
                    mtrace("  suspending: $ue->userid ==> $instance->courseid");
                }
            }

        } else if ($unenrolaction == ENROL_EXT_REMOVED_SUSPENDNOROLES) {
            if ($ue->status != ENROL_USER_SUSPENDED) {
                $metagroup->update_user_enrol($instance, $ue->userid, ENROL_USER_SUSPENDED);
                $context = context_course::instance($instance->courseid);
                role_unassign_all(array('userid'=>$ue->userid, 'contextid'=>$context->id, 'component'=>'enrol_metagroup', 'itemid'=>$instance->id));
                if ($verbose) {
                    mtrace("  suspending and removing all roles: $ue->userid ==> $instance->courseid");
                }
            }
        }
    }
    $rs->close();

    // now assign all necessary roles
    $enabled = explode(',', $CFG->enrol_plugins_enabled);
    foreach($enabled as $k=>$v) {
        if ($v === 'metagroup') {
            continue; // no metagroup sync of metagroup roles
        }
        $enabled[$k] = 'enrol_'.$v;
    }
    $enabled[] = ''; // manual assignments are replicated too

    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    list($enabled, $params) = $DB->get_in_or_equal($enabled, SQL_PARAMS_NAMED, 'e');
    $params['coursecontext'] = CONTEXT_COURSE;
    $params['courseid'] = $courseid;
    $params['activeuser'] = ENROL_USER_ACTIVE;
    $params['enabledinstance'] = ENROL_INSTANCE_ENABLED;
    $sql = "SELECT DISTINCT pra.roleid, pra.userid, c.id AS contextid, e.id AS enrolid, e.courseid
              FROM {role_assignments} pra
              JOIN {user} u ON (u.id = pra.userid AND u.deleted = 0)
              JOIN {context} pc ON (pc.id = pra.contextid AND pc.contextlevel = :coursecontext AND pra.component $enabled)
              JOIN {enrol} e ON (e.customint1 = pc.instanceid AND e.enrol = 'metagroup' $onecourse AND e.status = :enabledinstance)
              JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = u.id AND ue.status = :activeuser)
              JOIN {context} c ON (c.contextlevel = pc.contextlevel AND c.instanceid = e.courseid)
         LEFT JOIN {role_assignments} ra ON (ra.contextid = c.id AND ra.userid = pra.userid AND ra.roleid = pra.roleid AND ra.itemid = e.id AND ra.component = 'enrol_metagroup')
              WHERE ra.id IS NULL";

    if ($ignored = $metagroup->get_config('nosyncroleids')) {
        list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
        $params = array_merge($params, $xparams);
        $sql = "$sql AND pra.roleid $notignored";
    }

    $rs = $DB->get_recordset_sql($sql, $params);
    foreach($rs as $ra) {
        role_assign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->enrolid);
        if ($verbose) {
            mtrace("  assigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
        }
    }
    $rs->close();


    // remove unwanted roles - include ignored roles and disabled plugins too
    $onecourse = $courseid ? "AND e.courseid = :courseid" : "";
    $params = array();
    $params['coursecontext'] = CONTEXT_COURSE;
    $params['courseid'] = $courseid;
    $params['activeuser'] = ENROL_USER_ACTIVE;
    $params['enabledinstance'] = ENROL_INSTANCE_ENABLED;
    if ($ignored = $metagroup->get_config('nosyncroleids')) {
        list($notignored, $xparams) = $DB->get_in_or_equal(explode(',', $ignored), SQL_PARAMS_NAMED, 'ig', false);
        $params = array_merge($params, $xparams);
        $notignored = "AND pra.roleid $notignored";
    } else {
        $notignored = "";
    }

    $sql = "SELECT ra.roleid, ra.userid, ra.contextid, ra.itemid, e.courseid
              FROM {role_assignments} ra
              JOIN {enrol} e ON (e.id = ra.itemid AND ra.component = 'enrol_metagroup' AND e.enrol = 'metagroup' $onecourse)
              JOIN {context} pc ON (pc.instanceid = e.customint1 AND pc.contextlevel = :coursecontext)
         LEFT JOIN {role_assignments} pra ON (pra.contextid = pc.id AND pra.userid = ra.userid AND pra.roleid = ra.roleid AND pra.component <> 'enrol_metagroup' $notignored)
         LEFT JOIN {user_enrolments} ue ON (ue.enrolid = e.id AND ue.userid = ra.userid AND ue.status = :activeuser)
             WHERE pra.id IS NULL OR ue.id IS NULL OR e.status <> :enabledinstance";

    if ($unenrolaction != ENROL_EXT_REMOVED_SUSPEND) {
        $rs = $DB->get_recordset_sql($sql, $params);
        foreach($rs as $ra) {
            role_unassign($ra->roleid, $ra->userid, $ra->contextid, 'enrol_metagroup', $ra->itemid);
            if ($verbose) {
                mtrace("  unassigning role: $ra->userid ==> $ra->courseid as ".$allroles[$ra->roleid]->shortname);
            }
        }
        $rs->close();
    }

    if ($verbose) {
        mtrace('...user enrolment synchronisation finished.');
    }

    return 0;
}
