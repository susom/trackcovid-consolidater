<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

use REDCap;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$filename = isset($_GET['filename']) && !empty($_GET['filename']) ? $_GET['filename'] : null;

$module->emDebug("This is project ID $pid and filename $filename");

// If we don't receive a project to process, we can't continue.
if (is_null($pid)) {
    $module->emError("A project ID must be included to run this script");
    return false;
}

// See if this project wants the appointments loaded
$load_appt = $module->getProjectSetting('load-appointments');
$module->emDebug("This is the retrieved load-appointment value $load_appt");
if ($load_appt <> 1) {
    $module->emDebug("Project $pid does not want appointments loaded - exiting");
    return true;
}

// Determine which project we are loading
$chart_pid = $module->getSystemSetting('chart-pid');
$proto_pid = $module->getSystemSetting('proto-pid');
$genpop_pid = $module->getSystemSetting('genpop-pid');
if ($pid == $chart_pid) {
    $this_proj = "CHART";
    $dag_name = "chart";
} else if ($pid == $proto_pid) {
    $this_proj = "TRACK";
    $dag_name = "proto";
} else if ($pid == $genpop_pid) {
    $this_proj = "TRACK2";
    $dag_name = "genpop";
} else {
    $module->emError("This is not a TrackCovid project ($pid).  Please Disable this EM on this project");
    return false;
}

/**
 * This section stores the record id, dob and mrn in a table so we can join with appt/lab data in different events
 */
$mrn_field = $module->getProjectSetting('stanford-mrn');
$birthdate_field = $module->getProjectSetting('stanford-birth-date');
$baseline_event_id = $module->getProjectSetting('baseline-event');

// Store the record_id, birth_date and mrn in a table track_covid_mrn_dob
$params = array(
    'return_format' => 'array',
    'fields'        => array('record_id', 'redcap_event_name', $mrn_field, $birthdate_field),
    'filterLogic'   => "[". $mrn_field . "] <> ''",
    'events'        => $baseline_event_id
);
$records = REDCap::getData($params);
$module->emDebug("This is the count of the MRN/DoB records: " . json_encode(count($records)));
$record_mrns = array();
foreach($records as $record_id => $info) {
    $record_mrns[$info[$baseline_event_id][$mrn_field]] = $record_id;
}

// If there are no records, just return
if (empty($records)) {
    $module->emDebug("There are no records in project " . $pid . ". Skipping processing");
    return true;
}

// Retrieve the list of appointments from the Redcap project in all events.
// Retrieve in array format so we can compare to see if the value has changed
$params = array(
    'return_format' => 'array',
    'fields'        => array('record_id', 'redcap_event_name', 'lra_date_scheduled', 'lra_appt_status')
);
$appt_records = REDCap::getData($params);
$module->emDebug("This is the project appointment date count: " . json_encode(count($appt_records)));

// Retrieve the list of events (in project order)
//***  BIG NOTE:
// For GenPop, we need to delete the first event (the Screening event) because there are no appointments in that
// event. We need visit 0 to be the Baseline appointment.
// For Chart, there are Bonus events that won't correctly correspond respond to the event number so I have
// to manually set Bonus Event 1 to be event 11, Bonus Event 2 to be 12, etc.  But Chart does use Visit 0 which
// will correctly correspond to Baseline.
$event_list = REDCap::getEventNames(true, false);
if ($pid == $genpop_pid) {
    $screening_event = array_shift($event_list);
}
$events = array_values($event_list);
$event_ids = array_keys($event_list);
$module->emDebug("These are the events only: " . json_encode($events));

// Read in appointment file
$appointment_data = readAppointmentData($filename);
$module->emDebug("These are the number of appointments from STARR: " . json_encode(count($appointment_data)));
/**
 * Now loop over all participants to find their appointments.
 */

// Process each patient in the list and see if they are in the appointment list
// These are location specific.  For instance, I want to get rid of TRACK2 first before getting rid of TRACK so
// I don't have a dangling 2 leftover.
$unwanted_chars = array(' ', '-', 'CHARTT', 'XTRACK2', 'VISIT', 'STUDY', '#', 'VIST', 'F/U', 'VIIST', 'CHRT', 'COVID', '2GIFT');
$replace_chars = array('', '', '', '', '', '', '', '', '', '', '', '', '');

$unwanted_chars2 = array('XTRACK', 'VISI', 'APPOINTMENT', 'WEEK', ',', 'VIISIT', 'CXHART', 'VIUSIT', 'CHART', 'TRACK2', 'TRACK');
$replace_chars2 = array('', '', '', '', '', '', '', '', '', '', '');

$update_visits = array();
$found_events = array();
$overall_count = 0;

foreach($records as $record => $list) {

    $mrn = $list[$baseline_event_id][$mrn_field];
    $visit_num = null;

    if (!empty($appointment_data[$mrn])) {
        foreach ($appointment_data[$mrn] as $appt) {

            $orig_appt_note = $appt['appt_note'];
            $appointment_date = $appt['appt_date'];
            $appt_status = $appt['appt_status'];
            $first_replace = str_replace($unwanted_chars, $replace_chars, strtoupper($orig_appt_note));
            $appt_visit = str_replace($unwanted_chars2, $replace_chars2, $first_replace);

            // Try to figure out which visit event this appointment belongs
            if (!is_numeric($appt_visit)) {
                $two_char = substr($appt_visit, 0, 2);
                if (!is_numeric($two_char)) {
                    $one_char = substr($appt_visit, 0, 1);

                    // These bonus visits are for chart.
                    //  Bonus visit 1 = 11, Bonus visit 2 = 12, Bonus visit 3 = 13, Bonus visit 4 = 14
                    if (!is_numeric($one_char)) {
                        if (strpos($appt_visit, 'B1') !== false) {
                            $visit_num = 11;
                        } else if (strpos($appt_visit, 'BONUS1') !== false) {
                            $visit_num = 11;
                        } else if (strpos($appt_visit, 'B2') !== false) {
                            $visit_num = 12;
                        } else if (strpos($appt_visit, 'BONUS2') !== false) {
                            $visit_num = 12;
                        } else if (strpos($appt_visit, 'B3') !== false) {
                            $visit_num = 13;
                        } else if (strpos($appt_visit, 'BONUS3') !== false) {
                            $visit_num = 13;
                        } else if (strpos($appt_visit, 'B4') !== false) {
                            $visit_num = 14;
                        } else if (strpos($appt_visit, 'BONUS4') !== false) {
                            $visit_num = 14;
                        } else {
                            $module->emDebug("*** NOT FOUND: This is the orig note: $orig_appt_note, appt visit number: " . $appt_visit);
                            $visit_num = null;
                        }
                    } else {
                        $visit_num = $one_char;
                    }
                } else {
                    $visit_num = $two_char;
                }
            } else {
                $visit_num = $appt_visit;
            }

            // If we found a visit number and it seems valid, determine if we should add it to the update array
            if (($visit_num != null) and ($events[$visit_num] != null)) {
                $one_event = array();

                // If we don't find this MRN in a record, don't save anything
                if (!empty($record_mrns[$mrn])) {

                    // If we already have a date for this record/event, don't try to save it again
                    if (empty($found_events[$mrn]) or (!in_array($events[$visit_num], $found_events[$mrn]))) {
                        $new_appt_date = date("Y-m-d H:i:s", strtotime($appointment_date));
                        $saved_appt_date = $appt_records[$record_mrns[$mrn]][$event_ids[$visit_num]]['lra_date_scheduled'];
                        $saved_appt_status = $appt_records[$record_mrns[$mrn]][$event_ids[$visit_num]]['lra_appt_status'];;
                        if ($appt_status == 'Scheduled') {
                            $new_appt_status = 1;
                        } else if ($appt_status == 'Completed') {
                            $new_appt_status = 2;
                        } else {
                            $new_appt_status = 99;
                        }

                        // If the new appointment date is the same as the already saved date, no need to re-save.
                        if (($saved_appt_date != $new_appt_date) or ($saved_appt_status != $new_appt_status)) {
                            $one_event['record_id'] = $record_mrns[$mrn];
                            $one_event['redcap_event_name'] = $events[$visit_num];
                            $one_event['lra_date_scheduled'] = $new_appt_date;
                            $one_event['lra_appt_status'] = $new_appt_status;
                            $update_visits[] = $one_event;
                            $found_events[$mrn][] = $events[$visit_num];
                            $overall_count++;

                            // Save every 20 records so we don't lose all data if something goes wrong with the save
                            if (($overall_count%20) == 0) {
                                $module->emDebug("Number of appointment records to update: " . count($update_visits) . ", with running total of " . $overall_count);
                                $return = REDCap::saveData('json', json_encode($update_visits, true));
                                $module->emDebug("Return from appointment saveData at overall total $overall_count: " . json_encode($return));
                                $update_visits = array();
                            }
                        }
                    }
                }
            }
        }

    }
}

if (!empty($update_visits)) {
    $module->emDebug("Number of appointment records to update: " . count($update_visits));
    $return = REDCap::saveData('json', json_encode($update_visits, true));
    $module->emDebug("Return from appointment saveData: " . json_encode($return));
}

$status = true;
print $status;
return;

function readAppointmentData($filename) {
    global $module;

    // Read in the appointment file which is in csv format. The header for the file is:
    //          mrn, appt_when, appt_status, visit_type, appt_note, rank_order
    // We are going to rearrange the data to make the matching easier.  We are putting the data in
    // the following format:
    //      "mrn" =>  array(
    //                      "appt_note"     => "note",
    //                      "appt_date"     => "date,
    //                      "appt_status"   => "status"
    //                  );
    $row_number = 1;
    $appointment_data = array();
    if (($handle = fopen($filename, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($row_number == 1) {
                $header = $data;
                $module->emDebug("Header for appt data: " . json_encode($header));
                $row_number++;
            } else {
                 $appointment_data["$data[0]"][] =
                    array("appt_note" => $data[4],
                        "appt_date" => $data[1],
                        "appt_status" => $data[2]
                    );
            }
        }
        fclose($handle);
    } else {
        $module->emError("Error opening appointment data file at: " . $filename);
    }

    $module->emDebug("Leaving readApptData");
    return $appointment_data;
}
