<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

use REDCap;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$filename = isset($_GET['filename']) && !empty($_GET['filename']) ? $_GET['filename'] : null;

// If we don't receive a project to process, we can't continue.
if (is_null($pid)) {
    $module->emError("A project ID must be included to run this script");
    return false;
} else if (is_null($filename)) {
    $module->emError("A vaccination filename must be included to run this script");
    return false;
}


// See if this project wants the vaccines loaded
$load_vac = $module->getProjectSetting('load-vaccines');
$module->emDebug("This is the retrieved load-vaccines value $load_vac for project $pid");
if ($load_vac <> 1) {
    $module->emDebug("Project $pid does not want vaccines loaded - exiting");
    return true;
}


// We are storing the vaccine dates in the same event where the MRN is saved
$mrn_field = $module->getProjectSetting('stanford-mrn');
$baseline_event_id = $module->getProjectSetting('baseline-event');
$baseline_event_name = REDCap::getEventNames(true, false, $baseline_event_id);

$record_mrns = retrieveMRNs($mrn_field, $baseline_event_id);
if (empty($record_mrns)) {
    // If there are no records, just return
    $module->emDebug("There are no MRN records in project " . $pid . ". Skip vaccination processing");
    return true;
}

$vac_data = retrieveVaccinationData($filename);
if (empty($vac_data)) {
    // If no vaccinations were found, skip processing
    $module->emDebug("There are no vaccinations found from STARR in project $pid. Skip vaccination processing");
    return true;
}

$vax_data_to_save = array();
$ncnt = 0;
foreach($vac_data as $mrn => $dates) {

    // If this person is in this project, save the vax dates
    if (!is_null($record_mrns[$mrn]) and !empty($record_mrns[$mrn])) {
        $vax_record['record_id'] = $record_mrns[$mrn];
        $vax_record['redcap_event_name'] = $baseline_event_name;
        $vax_record['lra_vax_date_1'] = $dates['vax_1_date'];
        $vax_record['lra_vax_date_2'] = $dates['vax_2_date'];
        $vax_data_to_save[] = $vax_record;
        $ncnt++;

        // Save every 10 records so it doesn't take too long
        if (($ncnt % 10) == 0) {;
            $return = REDCap::saveData('json', json_encode($vax_data_to_save));
            $module->emDebug("Return from vaccination saveData for number of records $ncnt: " . json_encode($return));
            $vax_data_to_save = array();
        }
    }
}

// Save any remaining records
if (!is_null($vax_data_to_save)) {
    $return = REDCap::saveData('json', json_encode($vax_data_to_save));
    $module->emDebug("Return from vaccination saveData for number of records $ncnt: " . json_encode($return));
}

$status = true;
print $status;
return;


/**
 * @param $filename
 * @return array
 */
function retrieveVaccinationData($filename) {
    global $module;

    // Read in the vaccination file which is in csv format. The header for the file is:
    //          mrn, first_vax_date, second_vax_date
    // We are going to rearrange the data to make the matching easier.  We are putting the data in
    // the following format:
    //      "mrn" =>  array(
    //                      "vax_1_date"     => "first_vax_date",
    //                      "vax_2_date"     => "second_vax_date
    //                  );
    $row_number = 1;
    $vax_data = array();
    if (($handle = fopen($filename, "r")) !== FALSE) {
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            if ($row_number == 1) {
                $header = $data;
                $module->emDebug("Header for vax data: " . json_encode($header));
                $row_number++;
            } else {
                 $vax_data["$data[0]"] =
                    array("vax_1_date"          => $data[1],
                          "vax_2_date"          => $data[2]
                    );
            }
        }
        fclose($handle);
    } else {
        $module->emError("Error opening appointment data file at: " . $filename);
    }

    return $vax_data;
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
