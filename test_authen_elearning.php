<?php
/**
 * Moodle frontpage.
 *
 * @package    core
 * @copyright  1999 onwards Martin Dougiamas (http://dougiamas.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    define('AJAX_SCRIPT', true);
    define('REQUIRE_CORRECT_ACCESS', true);
    define('NO_MOODLE_COOKIES', true);

    if (!file_exists('./config.php')) {
        header('Location: install.php');
        die;
    }

    require_once('config.php');
    require_once($CFG->dirroot .'/course/lib.php');
    require_once($CFG->libdir .'/filelib.php');    
    require_once($CFG->libdir.'/gdlib.php');
    require_once($CFG->libdir.'/adminlib.php');
    require_once($CFG->dirroot.'/user/editadvanced_form.php');
    require_once($CFG->dirroot.'/user/editlib.php');
    require_once($CFG->dirroot.'/user/profile/lib.php');
    require_once($CFG->dirroot.'/user/lib.php');
    global $DB, $CFG;


    $user_id = $_REQUEST['id'];
    $firstname = $_REQUEST['firstname'];
    $lastname = $_REQUEST['lastname'];
    $user_email = $_REQUEST['email'];
    $course_id = $_REQUEST['eid'];
    $user_token = '';
    $serviceshortname = moodle_mobile_app;

    /////////////////////////////////////////////////////////////////////////
        
        if (!$CFG->enablewebservices) {
            echo 'web service still disable';
            throw new moodle_exception('enablewsdescription', 'webservice');
        }

        //check if the service exists and is enabled
        $service = $DB->get_record('external_services', array('shortname' => $serviceshortname, 'enabled' => 1));
        if (empty($service)) {
            // will throw exception if no token found
            throw new moodle_exception('servicenotavailable', 'webservice');
        }

        if ($service->restrictedusers) {
            $authoriseduser = $DB->get_record('external_services_users',
                array('externalserviceid' => $service->id, 'userid' => $user_id));

            if (empty($authoriseduser)) {
                throw new moodle_exception('usernotallowed', 'webservice', '', $serviceshortname);
            }

            if (!empty($authoriseduser->validuntil) and $authoriseduser->validuntil < time()) {
                throw new moodle_exception('invalidtimedtoken', 'webservice');
            }

            if (!empty($authoriseduser->iprestriction) and !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)) {
                throw new moodle_exception('invalidiptoken', 'webservice');
            }
        }


    ///////////////////////////////////////////////////////////////////////////

    $xml = new SimpleXMLElement("<?xml version='1.0' encoding='iso-8859-1'?><response/>");
    
    if (!empty($user_id) && !empty($firstname) && !empty($lastname) && !empty($user_email) && !empty($course_id)) {
        $sql = " SELECT * FROM {user} WHERE email = '$user_email'";
        $user = $DB->get_records_sql($sql);

        //user exists --> enrolled user
        if (!empty($user)){
            foreach ($user as $id => $row) {
                $real_user_id = $row->id;
            }

            $sql = "SELECT ue.enrolid, 
                            ue.userid,
                            en.id, 
                            en.courseid, 
                            crs.id, 
                            crs.shortname
                    FROM {user_enrolments} ue
                    INNER JOIN {enrol} en
                    ON ue.enrolid = en.id
                    INNER JOIN {course} crs
                    ON en.courseid = crs.id
                    WHERE ue.userid = $real_user_id 
                    AND crs.shortname = '$course_id'";

            $user_enrolled = $DB->get_records_sql($sql);

            if (!empty($user_enrolled)) {
                foreach ($user_enrolled as $key => $row) {
                    $user_id = $row->userid;
                    $course_id = $row->shortname;
                }

                ////////////////////// create token ///////////////////
                $tokenssql = "SELECT t.id, t.sid, t.token, t.validuntil, t.iprestriction
                                FROM {external_tokens} t
                                WHERE t.userid = ? AND t.externalserviceid = ? AND t.tokentype = ? AND t.sid = ?
                                ORDER BY t.timecreated ASC";
                
                $tokens = $DB->get_records_sql($tokenssql, array($user_id, $service->id, EXTERNAL_TOKEN_PERMANENT, $course_id));
                
                if (count($tokens) > 0) {
                    $token = array_pop($tokens);
                } 

                else {
                    if ($serviceshortname == MOODLE_OFFICIAL_MOBILE_SERVICE) {
                            // if service doesn't exist, dml will throw exception
                        $service_record = $DB->get_record('external_services', array('shortname'=>$serviceshortname, 'enabled'=>1), '*', MUST_EXIST);

                            // Create a new token.
                        $token = new stdClass;
                        $token->token = md5(uniqid(rand(), 1));
                        $token->sid = $course_id;
                        $token->userid = $user_id;
                        $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
                        $token->contextid = context_system::instance()->id;
                        $token->creatorid = $user_id;
                        $token->timecreated = time();
                        $token->externalserviceid = $service_record->id;
                            // MDL-43119 Token valid for 3 months (12 weeks).
                        $token->validuntil = $token->timecreated + 12 * WEEKSECS;
                        $token->id = $DB->insert_record('external_tokens', $token);

                        $params = array(
                            'objectid' => $token->id,
                            'relateduserid' => $user_id,
                            'other' => array(
                            'auto' => true
                            )
                        );

                        $event = \core\event\webservice_token_created::create($params);
                        $event->add_record_snapshot('external_tokens', $token);
                        $event->trigger();
                    } 
                    else {
                        throw new moodle_exception('cannotcreatetoken', 'webservice', '', $serviceshortname);
                    }
                }

                // log token access
                $DB->set_field('external_tokens', 'lastaccess', time(), array('id'=>$token->id));

                $params = array(
                    'objectid' => $token->id,
                );

                $event = \core\event\webservice_token_sent::create($params);
                $event->add_record_snapshot('external_tokens', $token);
                $event->trigger();

                $usertoken = new stdClass;
                $usertoken->token = $token->token;
                $json_encode_token = json_encode($usertoken);

                $json_user_token = json_decode($json_encode_token, true);
                $user_token = $json_user_token['token'];
            }

            else{
                //for un_enroll user
                $sql = "SELECT en.id
                        FROM {course} crs
                        INNER JOIN {enrol} en
                        ON crs.id = en.courseid
                        WHERE crs.shortname = '$course_id'";

                $enroll = $DB->get_records_sql($sql);

                foreach ($enroll as $key => $row) {
                    $enrol_id = $row->id;
                    break;
                }

                $crs_enroll = new stdClass;
                $crs_enroll->status = 0;
                $crs_enroll->enrolid = $enrol_id;
                $crs_enroll->userid = $real_user_id;
                $crs_enroll->timestart = time();
                $crs_enroll->timeend = 0;
                $crs_enroll->modifierid = 2;
                $crs_enroll->timecreated = time();
                $crs_enroll->timemodified = time();

                $crs_enroll->id = $DB->insert_record('user_enrolments', $crs_enroll);

                $tokenssql = "SELECT t.id, t.sid, t.token, t.validuntil, t.iprestriction
                FROM {external_tokens} t
                WHERE t.userid = ? AND t.externalserviceid = ? AND t.tokentype = ? AND t.sid = ?
                ORDER BY t.timecreated ASC";
                
                $tokens = $DB->get_records_sql($tokenssql, array($user_id, $service->id, EXTERNAL_TOKEN_PERMANENT, $course_id));
                
                if (count($tokens) > 0) {
                    $token = array_pop($tokens);
                } 

                else {
                    if ($serviceshortname == MOODLE_OFFICIAL_MOBILE_SERVICE) {
                            // if service doesn't exist, dml will throw exception
                        $service_record = $DB->get_record('external_services', array('shortname'=>$serviceshortname, 'enabled'=>1), '*', MUST_EXIST);

                            // Create a new token.
                        $token = new stdClass;
                        $token->token = md5(uniqid(rand(), 1));
                        $token->sid = $course_id;
                        $token->userid = $user_id;
                        $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
                        $token->contextid = context_system::instance()->id;
                        $token->creatorid = $user_id;
                        $token->timecreated = time();
                        $token->externalserviceid = $service_record->id;
                            // MDL-43119 Token valid for 3 months (12 weeks).
                        $token->validuntil = $token->timecreated + 12 * WEEKSECS;
                        $token->id = $DB->insert_record('external_tokens', $token);

                        $params = array(
                            'objectid' => $token->id,
                            'relateduserid' => $user_id,
                            'other' => array(
                            'auto' => true
                            )
                        );

                        $event = \core\event\webservice_token_created::create($params);
                        $event->add_record_snapshot('external_tokens', $token);
                        $event->trigger();
                    } 
                    else {
                        throw new moodle_exception('cannotcreatetoken', 'webservice', '', $serviceshortname);
                    }
                }

                // log token access
                $DB->set_field('external_tokens', 'lastaccess', time(), array('id'=>$token->id));

                $params = array(
                    'objectid' => $token->id,
                );

                $event = \core\event\webservice_token_sent::create($params);
                $event->add_record_snapshot('external_tokens', $token);
                $event->trigger();

                $usertoken = new stdClass;
                $usertoken->token = $token->token;
                $json_encode_token = json_encode($usertoken);

                $json_user_token = json_decode($json_encode_token, true);
                $user_token = $json_user_token['token'];
            }
        }
        else{   //for user not existing
            /////////////////// create new user
            $ex_email = explode("@", $user_email);
			$password_md5 = 'Password1!';

            $new_user = new stdClass;
            $new_user->auth = 'manual';
            $new_user->confirmed = 1;
            $new_user->mnetthostid = 1;
            $new_user->username = $ex_email[0];
            $new_user->password = $password_md5;
            $new_user->firstname = $firstname;
            $new_user->lastname = $lastname;
            $new_user->email = $user_email;
            $new_user->timecreated = time();

            $new_user->id = $DB->insert_record('user', $new_user);

            $sql = "SELECT en.id
                    FROM {course} crs
                    INNER JOIN {enrol} en
                    ON crs.id = en.courseid
                    WHERE crs.shortname = '$course_id'";

            /////////////////// enroll course for new user
            $sql = " SELECT * FROM {user} WHERE email = '$user_email'";
            $user = $DB->get_records_sql($sql);

            //user exists --> enrolled user
            foreach ($user as $id => $row) {
                $real_user_id = $row->id;
            }

            $enroll = $DB->get_records_sql($sql);

            foreach ($enroll as $key => $row) {
                $enrol_id = $row->id;
                break;
            }

            $crs_enroll = new stdClass;
            $crs_enroll->status = 0;
            $crs_enroll->enrolid = $enrol_id;
            $crs_enroll->userid = $real_user_id;
            $crs_enroll->timestart = time();
            $crs_enroll->timeend = 0;
            $crs_enroll->modifierid = 2;
            $crs_enroll->timecreated = time();
            $crs_enroll->timemodified = time();

            $crs_enroll->id = $DB->insert_record('user_enrolments', $crs_enroll);

            /////////////////// create token for new user
            $tokenssql = "SELECT t.id, t.sid, t.token, t.validuntil, t.iprestriction
                            FROM {external_tokens} t
                            WHERE t.userid = ? 
                            AND t.externalserviceid = ? 
                            AND t.tokentype = ? 
                            AND t.sid = ?
                            ORDER BY t.timecreated ASC";
                
                $tokens = $DB->get_records_sql($tokenssql, array($user_id, $service->id, EXTERNAL_TOKEN_PERMANENT, $course_id));
                
                if (count($tokens) > 0) {
                    $token = array_pop($tokens);
                } 

                else {
                    if ($serviceshortname == MOODLE_OFFICIAL_MOBILE_SERVICE) {
                            // if service doesn't exist, dml will throw exception
                        $service_record = $DB->get_record('external_services', array('shortname'=>$serviceshortname, 'enabled'=>1), '*', MUST_EXIST);

                            // Create a new token.
                        $token = new stdClass;
                        $token->token = md5(uniqid(rand(), 1));
                        $token->sid = $course_id;
                        $token->userid = $user_id;
                        $token->tokentype = EXTERNAL_TOKEN_PERMANENT;
                        $token->contextid = context_system::instance()->id;
                        $token->creatorid = $user_id;
                        $token->timecreated = time();
                        $token->externalserviceid = $service_record->id;
                            // MDL-43119 Token valid for 3 months (12 weeks).
                        $token->validuntil = $token->timecreated + 12 * WEEKSECS;
                        $token->id = $DB->insert_record('external_tokens', $token);

                        $params = array(
                            'objectid' => $token->id,
                            'relateduserid' => $user_id,
                            'other' => array(
                            'auto' => true
                            )
                        );

                        $event = \core\event\webservice_token_created::create($params);
                        $event->add_record_snapshot('external_tokens', $token);
                        $event->trigger();
                    } 
                    else {
                        throw new moodle_exception('cannotcreatetoken', 'webservice', '', $serviceshortname);
                    }
                }

                // log token access
                $DB->set_field('external_tokens', 'lastaccess', time(), array('id'=>$token->id));

                $params = array(
                    'objectid' => $token->id,
                );

                $event = \core\event\webservice_token_sent::create($params);
                $event->add_record_snapshot('external_tokens', $token);
                $event->trigger();

                $usertoken = new stdClass;
                $usertoken->token = $token->token;
                $json_encode_token = json_encode($usertoken);

                $json_user_token = json_decode($json_encode_token, true);
                $user_token = $json_user_token['token'];
        }
    }

    if(!empty($user_token)) {
        $result = $xml->addChild('result');
        $code = $result->addChild('code', '00');
        $retText = $result->addChild('retText', 'data success');
        $token = $result->addChild('token', $user_token);
    }

    else{
        $result = $xml->addChild('result');
        $code = $result->addChild('code', '01');
        $retText = $result->addChild('retText', 'data fail');
    }

    Header('Content-type: text/xml; charset=UTF-8');
    echo $xml->asXML();
?>
