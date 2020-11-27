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

// Retrieve the list of events (in project order)
//***  BIG NOTE:
// For GenPop, we need to delete the first event (the Screening event) because there are no appointments in that
// event. We need visit 0 to be the Baseline appointment.
// For Chart, there are Bonus events that won't correctly correspond respond to the event number so I have
// to manually set Bonus Event 1 to be event 11, Bonus Event 2 to be 12, etc.  But Chart does use Visit 0 which
// will correctly correspond to Baseline.
$event_list = REDCap::getEventNames(true, false);
$module->emDebug("Events: " . json_encode($event_list));

$events = array();
$event_ids = array();
foreach($event_list as $event_id => $event_name){
    $fields = REDCap::getValidFieldsByEvents($pid, $event_id);
    if (in_array('reservation_datetime', $fields)) {
        $events[] = $event_name;
        $event_ids[] = $event_id;
    }
}

$module->emDebug("Event names: " . json_encode($events));
$module->emDebug("Event id: " . json_encode($event_ids));

// Retrieve the field which holds the MRN
$mrn_field = $module->getProjectSetting('stanford-mrn');
$baseline_event_id = $module->getProjectSetting('baseline-event');

/**
 * Retrieve the testing locations from project
 */
$slots_event_name = 'slots_arm_1';
$sites_event_name = 'testing_sites_arm_2';
$scheduler_pid = $module->getSystemSetting('scheduler-pid');

// Retrieve all the data we need
$sites = retrieveTestingSites($scheduler_pid, $sites_event_name);
$record_mrns = retrieveMRNs($mrn_field, $baseline_event_id);
// If there are no records, just return
if (empty($record_mrns)) {
    $module->emDebug("There are no MRN records in project " . $pid . ". Skipping processing");
    return true;
}

// Retrieve appointment records in Redcap
$appt_records = retrieveApptRecords();
if (empty($appt_records)) {
    $module->emDebug("There are no appointment records in Redcap project " . $pid . ". Skipping processing");
    return true;
} else {
    $module->emDebug("These are the number of appointments in the REDCap project $pid: " . count($appt_records));
}

// Read in appointment file
$appointment_file_data = readAppointmentData($filename);
if (empty($appointment_file_data)) {
    $module->emDebug("There are no appointments in the STARR file for project " . $pid . ". Skipping processing");
    return true;
} else {
    $module->emDebug("These are the number of appointments from STARR: " . count($appointment_file_data));
}

// Add these appointments to the scheduler date/time so this timeslot does not get overbooked
// This only has to happen once for each data file so only perform if this is Chart

if ($pid == $chart_pid) {
    $slots = retrieveTestingSlots($scheduler_pid, $slots_event_name);
    addToScheduler($appointment_file_data, $slots, $scheduler_pid);
}

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
foreach($record_mrns as $mrn => $record) {

    $visit_num = null;
    if (!empty($appointment_file_data[$mrn])) {

        foreach ($appointment_file_data[$mrn] as $appt) {

            $orig_appt_note = $appt['appt_note'];
            $appointment_date = $appt['appt_date'];
            $appt_location = $appt['appt_location'];
            $appt_status = $appt['appt_status'];
            $appt_update_datetime = $appt['appt_update_time'];
            $first_replace = str_replace($unwanted_chars, $replace_chars, strtoupper($orig_appt_note));
            $appt_visit = str_replace($unwanted_chars2, $replace_chars2, $first_replace);

            if ($appt_status != 'Canceled') {

                // Find which visit number this appointment was made
                $visit_num = findVisitEventNumber($appt_visit);

                // If we found a visit number and it seems valid, determine if we should add it to the update array
                if (($visit_num != null) and (!empty($events[$visit_num]))) {
                    $one_event = array();

                    // If we don't find this MRN in a record, don't save anything
                    if (!empty($record_mrns[$mrn])) {

                        // If we already have a date for this record/event, don't try to save it again
                        if (empty($found_events[$mrn]) or (($events[$visit_num] != '') and !in_array($events[$visit_num], $found_events[$mrn]))) {
                            $new_appt_datetime = date("Y-m-d H:i:s", strtotime($appointment_date));
                            $new_appt_date = date("Y-m-d", strtotime($appointment_date));
                            $saved_appt_datetime = $appt_records[$record_mrns[$mrn]][$event_ids[$visit_num]]['reservation_datetime'];
                            $saved_appt_location = $appt_records[$record_mrns[$mrn]][$event_ids[$visit_num]]['reservation_participant_location'];;
                            $new_appt_location = retrieveSchedulerLocationNumber($appt_location);

                            // If the new appointment date is the same as the already saved date, no need to re-save.
                            if (($saved_appt_datetime != $new_appt_datetime) or ($saved_appt_location != $new_appt_location)) {
                                $one_event['record_id'] = $record_mrns[$mrn];
                                $one_event['redcap_event_name'] = $events[$visit_num];
                                $one_event['reservation_datetime'] = $new_appt_datetime;
                                $one_event['reservation_date'] = $new_appt_date;
                                $one_event['reservation_participant_location'] = $new_appt_location;
                                $one_event['reservation_created_at'] = $appt_update_datetime;
                                $update_visits[] = $one_event;
                                $found_events[$mrn][] = $events[$visit_num];
                                $overall_count++;

                                // Save every 20 records so we don't lose all data if something goes wrong with the save
                                if (($overall_count % 10) == 0) {
                                    $module->emDebug("Number of appointment records to update: " . count($update_visits) . ", with running total of " . $overall_count);
                                    $return = REDCap::saveData('json', json_encode($update_visits));
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
}

if (!empty($update_visits)) {
    $module->emDebug("Number of appointment records to update: " . count($update_visits));
    $return = REDCap::saveData('json', json_encode($update_visits));
    $module->emDebug("Return from appointment saveData: " . json_encode($return));
}

$status = true;
print $status;
return;

function addToScheduler($appts, $slots, $scheduler_pid) {

    global $module;

    $ncnt = 0;
    $update_slots = array();
    foreach($appts as $mrn => $person_appts) {
        foreach ($person_appts as $appt_key => $one_appt) {

            // Find the location index that belongs to this appt
            $appt_locn = retrieveSchedulerLocationNumber($one_appt['appt_location']);
            $appt_datetime = date("Y-m-d H:i:s", strtotime($one_appt['appt_date']));
            $compare = $appt_locn . '-' . $appt_datetime;

            $record_id = $slots[$compare];
            if (!empty($record_id)) {

                // If this record is not created yet, create an entry to save
                if (empty($update_slots[$record_id])) {
                    $record = array();
                    $record['record_id'] = $record_id;
                    $record['redcap_event_name'] = 'slots_arm_1';

                    // If this appointment is canceled, there are no booked slots yet
                    if ($one_appt['appt_status'] == 'Canceled') {
                        $record['number_of_external_booked_slots'] = 0;
                    } else {
                        $record['number_of_external_booked_slots'] = 1;
                    }

                    $update_slots[$record_id] = $record;
                } else {
                    if ($one_appt['appt_status'] != 'Canceled') {
                        $update_slots[$record_id]['number_of_external_booked_slots']++;
                    }
                }
            }

            $ncnt++;
        }
    }

    $update_all_slots = array();
    foreach($update_slots as $id => $each_slot) {
        $update_all_slots[] = $each_slot;
    }

    // Save this data to the Shared Scheduler to the Slots Form
    if (!empty($update_all_slots)) {
        $module->emDebug("Number of external appts for the Shared Scheduler: " . count($update_all_slots));
        $return = REDCap::saveData($scheduler_pid, 'json', json_encode($update_all_slots));
        $module->emDebug("Return from Shared Scheduler saveData: " . json_encode($return));
    }

}

function retrieveSchedulerLocationNumber($appt_locn) {

    global $module;

    switch (trim($appt_locn)) {
        case 'FAIR OAKS ¿ REDWOOD CITY':
            $visit_location_number = 7;
            break;
        case 'CONTRA COSTA ¿ ANTIOCH':
            $visit_location_number = 13;
            break;
        case 'CONTRA COSTA ¿ CONCORD':
            $visit_location_number = 10;
            break;
        case 'CONTRA COSTA ¿ RICHMOND':
            $visit_location_number = 12;
            break;
        //case 'COVID CTRU 300P':
        //    $visit_location_number = 8;
        //    break;
        case 'MEXICAN HERITAGE PLAZA ¿ SAN JOSE':
            $visit_location_number = 9;
            break;
        case 'SHC VALLEYCARE ER - PLEASANTON':
            $visit_location_number = 4;
            break;
        case 'SOUTH COUNTY - GILROY':
            $visit_location_number = 11;
            break;
        case 'TENT 4':
            $visit_location_number = 8;
            break;
        default:
            if (trim($appt_locn) != 'COVID CTRU 300P') {
                $module->emDebug("This location is not our list: " . $appt_locn);
            }
            $visit_location_number = null;
    }

    return $visit_location_number;
}

function findVisitEventNumber($appt_visit) {

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
                    //$module->emDebug("*** NOT FOUND: This is the orig note: $orig_appt_note, appt visit number: " . $appt_visit);
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

    return $visit_num;
}

/**
 * @param $filename
 * @return array
 */
function readAppointmentData($filename) {
    global $module;

    // Read in the appointment file which is in csv format. The header for the file is:
    //          mrn, appt_when, appt_status, visit_type, appt_note, appt_location, rank_order
    // We are going to rearrange the data to make the matching easier.  We are putting the data in
    // the following format:
    //      "mrn" =>  array(
    //                      "appt_note"     => "note",
    //                      "appt_date"     => "date,
    //                      "appt_status"   => "status"
    //                      "appt_location  => "location"
    //                  );
    $row_number = 1;
    $appointment_data = array();
    $module->emDebug("File: " . $filename);
    if (($handle = fopen($filename, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($row_number == 1) {
                $header = $data;
                $module->emDebug("Header for appt data: " . json_encode($header));
                $row_number++;
            } else {
                 $appointment_data["$data[0]"][] =
                    array("appt_note"       => $data[4],
                        "appt_date"         => $data[1],
                        "appt_status"       => $data[2],
                        "appt_location"     => $data[5],
                        "appt_create_time"  => $data[6],
                        "appt_update_time"  => $data[7]
                    );
            }
        }
        fclose($handle);
    } else {
        $module->emError("Error opening appointment data file at: " . $filename);
    }

    return $appointment_data;
}

/**
 * Retrieve the list of testing sites.
 *
 * @param $sched_pid
 * @param $sites_event_name
 * @return array
 */
function retrieveTestingSites($sched_pid, $sites_event_name) {

    global $module;

    // Retrieve the sites from the redcap database pid $sched_pid
    $event_id = REDCap::getEventIdFromUniqueEvent($sites_event_name);

    $filter = "[" . $sites_event_name . "][site_affiliation] = '1'";
    $params = array(
        'project_id'    => $sched_pid,
        'return_format' => 'json',
        'events'        => $sites_event_name,
        'filterLogic'   => $filter
    );

    // Retrieve the sites that are run by Stanford
    $data = REDCap::getData($params);
    $data_array = json_decode($data, true);
    $site_list = array();
    foreach ($data_array as $site_num => $site) {
        $site_list[$site['record_id']] = $site['title'];
    }
    $module->emDebug("Number of sites: " . count($site_list));

    return $site_list;
}

function retrieveTestingSlots($scheduler_pid, $slots_event_name) {

    global $module;

    // Retrieve the sites from the redcap database pid $sched_pid
    $event_id = REDCap::getEventIdFromUniqueEvent($slots_event_name);

    $fields = array('record_id', 'redcap_event_name', 'location', 'start');
    $cutoff_date = date('Y-m-d', strtotime("-3 days"));
    $filter = "[" . $slots_event_name . "][start] >= '$cutoff_date'";
    $module->emDebug("Cutoff date: " . $cutoff_date);
    $params = array(
        'project_id'    => $scheduler_pid,
        'return_format' => 'json',
        'fields'        => $fields,
        'events'        => $slots_event_name,
        'filterLogic'   => $filter
    );

    // Retrieve the sites that are run by Stanford
    $data = REDCap::getData($params);
    $slot_list = json_decode($data, true);
    $module->emDebug("Number of slots: " . count($slot_list));


    // We are going to partition based on location so it doesn't take as long to loop
    $slots = array();
    foreach ($slot_list as $each => $slot) {
        $slot_num = $slot['location'];
        $slot_datetime = date("Y-m-d H:i:s", strtotime($slot['start']));
        $record_id = $slot['record_id'];
        $slots[$slot_num . "-" . $slot_datetime] = $record_id;
    }

    return $slots;
}

/**
 * This function retrieves the list of MRNs in the redcap trackcovid project so we can match the appointments
 *
 * @return array
 */
function retrieveMRNs($mrn_field, $baseline_event_id) {

    global $module;

    //This section retrieves the record id and mrn in a table so we can join with appt data in different events
    $params = array(
        'return_format' => 'array',
        'fields'        => array('record_id', 'redcap_event_name', $mrn_field),
        'filterLogic'   => "[". $mrn_field . "] <> ''",
        'events'        => $baseline_event_id
    );
    $records = REDCap::getData($params);
    $record_mrns = array();
    foreach($records as $record_id => $info) {
        $record_mrns[$info[$baseline_event_id][$mrn_field]] = $record_id;
    }

    $module->emDebug("This is the count of the MRN/DoB records: " . count($records));
    return $record_mrns;
}

function retrieveApptRecords() {

    global $module;

    // Retrieve the list of appointments from the Redcap project in all events.
    // Retrieve in array format so we can compare to see if the value has changed
    $params = array(
        'return_format' => 'array',
        'fields'        => array('record_id', 'redcap_event_name', 'reservation_participant_location', 'reservation_datetime',
                                'reservation_date', 'reservation_created_at')
    );
    $appt_records = REDCap::getData($params);
    $module->emDebug("This is the project appointment date count: " . count($appt_records));

    return $appt_records;
}
