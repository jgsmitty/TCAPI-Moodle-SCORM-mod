<?php
/*
 * This is a contribute file created to extend the Moodle SCORM module
 * Jamie Smith - jamie.g.smith@gmail.com
 * 
 * Objectives of this library is to provide functions that handle incoming
 * TCAPI requests from the local/tcapi webservice.
 * Incoming statements will be stored by sco->id and user->id in the tracks table.
 * Activity/state requests will be stored/retrieved by using the associated tracks table entry
 * by sco->id, user->id, attempt, element('cmi.suspend_data') as value.
 * cmi.core.total_time will be aggregated based on time lapsed between state requests but
 * may be overridden by a statement result that specifies 'duration'.
 * TODO: Decide the most effective and efficient way to determin cmi.core.total.time since not all content will report state data and results the same.
 * Maybe using state requests and compare to duration taking the largest number of the two??
 * 
 * All modules that participate in using the TCAPI will be able to capture the
 * incoming requests and override the normal api protocol for storage and retrieval
 * of statements and activity/states.
 *
 */

function scorm_tcapi_fetch_activity_state($params, $response) {
	global $CFG, $DB, $USER;
	if (isset($params['actor']) && isset($params['actor']->moodle_user))
		$userid = $params['actor']->moodle_user;
	else
		$userid = $USER->id;
	if (isset($params['moodle_mod_id']))
		$scoid = $params['moodle_mod_id'];
	else
		throw new invalid_parameter_exception('Module id not provided.');
	require_once($CFG->dirroot.'/mod/scorm/locallib.php');
	$response = '';
	if (isset($params['stateId']) && $params['stateId'] == 'resume'
		&& ($sco = scorm_get_sco($scoid)) && ($attempt = scorm_get_last_attempt($sco->scorm, $userid))) {
	    if ($trackdata = scorm_get_tracks($scoid, $USER->id, $attempt)) {
	    	// if the activity status is 'failed' and additional attempts are allowed, create a new attempt and return empty state data
	        if (($trackdata->status == 'failed') && ($scorm = $DB->get_record_select('scorm','id = ?',array($sco->scorm)))) {
	        	if (($attempt < $scorm->maxattempt) || ($scorm->maxattempt == 0)) {
	        		$newattempt = $attempt+1;
	        		if (scorm_insert_track($USER->id, $scorm->id, $scoid, $newattempt, 'x.start.time', time()))
	        			return '';
	        	}
	        }
	    }	
		if (($tracktest = $DB->get_record_select('scorm_scoes_track', 'userid=? AND scormid=? AND scoid=? AND attempt=? AND element=\'cmi.suspend_data\'', array($userid, $sco->scorm, $scoid, $attempt))))
			$response = $tracktest->value;			
	}
	else
		throw new invalid_parameter_exception('Parameters invalid or Scorm/Sco not found.');
	return $response;
}

function scorm_tcapi_store_activity_state($params, $response) {
	global $CFG, $USER, $SESSION;
	if (isset($params['actor']) && isset($params['actor']->moodle_user))
		$userid = $params['actor']->moodle_user;
	else
		$userid = $USER->id;
	if (isset($params['moodle_mod_id']))
		$scoid = $params['moodle_mod_id'];
	else
		throw new invalid_parameter_exception('Module id not provided.');		
	require_once($CFG->dirroot.'/mod/scorm/locallib.php');
	if (isset($params['stateId']) && $params['stateId'] == 'resume'
		&& isset($params['content']) && ($sco = scorm_get_sco($scoid)) && ($attempt = scorm_get_last_attempt($sco->scorm, $userid))) {
	    // if the activity is considered complete, do not store updated state data
		if (($trackdata = scorm_get_tracks($scoid, $USER->id, $attempt))
	    	&& (($trackdata->status == 'completed') || ($trackdata->status == 'passed') || ($trackdata->status == 'failed'))) {
			return $response;
	    }
	    else
			scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.suspend_data', $params['content']);
	}
	else 
		throw new invalid_parameter_exception('Parameters invalid or Scorm/Sco not found.');
	return $response;
}

function scorm_tcapi_store_statement($params, $statementObject) {
	global $CFG, $USER, $DB, $SESSION;
	if (isset($params['actor']) && isset($params['actor']->moodle_user))
		$userid = $params['actor']->moodle_user;
	else
		$userid = $USER->id;
	if (isset($params['moodle_mod_id']))
		$scoid = $params['moodle_mod_id'];
	else
		throw new invalid_parameter_exception('Module id not provided.');
	require_once($CFG->dirroot.'/mod/scorm/locallib.php');
	if (($sco = scorm_get_sco($scoid)) && ($attempt = scorm_get_last_attempt($sco->scorm, $userid)))
	{
		$usertrack = scorm_get_tracks($scoid, $userid, $attempt);
		
	    // if the activity is considered complete, only update the time if it doesn't yet exist
		$attempt_complete = ($usertrack && (($usertrack->status == 'completed') || ($usertrack->status == 'passed') || ($usertrack->status == 'failed')));
			
		$statement = $statementObject->statement;
		$statementRow = $statementObject->statementRow;
		// check that the incoming statement refers to the sco identifier and not a child
		if (isset($statement->activity)) {
			$sco_activity = $statement->activity;
			if (!empty($statement->activity->grouping_id) && ($lrs_activity = $DB->get_record_select('tcapi_activity','id = ?',array($statement->activity->grouping_id))))
				$sco_activity = $lrs_activity;
			if ($sco->identifier == $sco_activity->activity_id) {
				// check for existing cmi.core.lesson_status
				// set default to 'incomplete'
				// check statement->verb and set cmi.core.lesson_status as appropriate
				$cmiCoreLessonStatus = (empty($usertrack->status) || $usertrack->status == 'notattempted') ? 'incomplete' : $usertrack->status;
				if (in_array(strtolower($statementRow->verb),array('completed','passed','mastered','failed'))) {
					$complStatus = (strtolower($statementRow->verb) !== 'failed') ? 'completed' : 'incomplete';
					if (!$attempt_complete)
						scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.completion_status', $complStatus);
					$cmiCoreLessonStatus = strtolower($statementRow->verb);
					if (!$attempt_complete)
						scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.success_status', $cmiCoreLessonStatus);
					// check if any result was reported	
					if (isset($statementObject->resultRow)) {
						$result = $statementObject->resultRow;
						// if a duration was reported, add to any existing total_time
						if (isset($result->duration))
						{
							if ($usertrack->total_time == '00:00:00')
								$total_time = $result->duration;
							elseif (!$attempt_complete)
								$total_time = scorm_tcapi_add_time($result->duration, $usertrack->total_time);
							if (isset($total_time))
								scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.total_time', $total_time);
						}
						
						if (isset($result->score) && !$attempt_complete)
						{
							$score = json_decode($result->score);
							if (isset($score->raw))
								scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.score.raw', $score->raw);
							if (isset($score->min))
								scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.score.min', $score->min);
							if (isset($score->max))
								scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.score.max', $score->max);
							if (isset($score->scaled))
								scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.score.scaled', $score->scaled);
						}
						
					}
				}
				if ($attempt_complete)
					return $statementObject->statementId;
					
				scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.lesson_status', $cmiCoreLessonStatus);
				
				// set cmi.core.exit to suspend if status is incomplete, else remove the track entry
				if ($cmiCoreLessonStatus == 'incomplete')
					scorm_insert_track($userid, $sco->scorm, $scoid, $attempt, 'cmi.core.exit', 'suspend');
				elseif ($track = $DB->get_record('scorm_scoes_track', array('userid'=>$userid, 'scormid'=>$sco->scorm, 'scoid'=>$scoid, 'attempt'=>$attempt, 'element'=>'cmi.core.exit')))
					$DB->delete_records_select('scorm_scoes_track', 'id = ?', array($track->id));
			}
		}
	}
	else 
		throw new invalid_parameter_exception('Parameters invalid or Scorm/Sco not found.');
	
	
	return $statementObject->statementId;	
}


function scorm_tcapi_add_time($a, $b) {
    $aes = explode(':', $a);
    $bes = explode(':', $b);
    $aseconds = explode('.', $aes[2]);
    $bseconds = explode('.', $bes[2]);
    $change = 0;

    $acents = 0;  //Cents
    if (count($aseconds) > 1) {
        $acents = $aseconds[1];
    }
    $bcents = 0;
    if (count($bseconds) > 1) {
        $bcents = $bseconds[1];
    }
    $cents = $acents + $bcents;
    $change = floor($cents / 100);
    $cents = $cents - ($change * 100);
    if (floor($cents) < 10) {
        $cents = '0'. $cents;
    }

    $secs = $aseconds[0] + $bseconds[0] + $change;  //Seconds
    $change = floor($secs / 60);
    $secs = $secs - ($change * 60);
    if (floor($secs) < 10) {
        $secs = '0'. $secs;
    }

    $mins = $aes[1] + $bes[1] + $change;   //Minutes
    $change = floor($mins / 60);
    $mins = $mins - ($change * 60);
    if ($mins < 10) {
        $mins = '0' .  $mins;
    }

    $hours = $aes[0] + $bes[0] + $change;  //Hours
    if ($hours < 10) {
        $hours = '0' . $hours;
    }

    if ($cents != '0') {
        return $hours . ":" . $mins . ":" . $secs . '.' . $cents;
    } else {
        return $hours . ":" . $mins . ":" . $secs;
    }
}

?>