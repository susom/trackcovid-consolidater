<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

// This is just a dummy file to put in project mode
$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$org = isset($_GET['org']) && !empty($_GET['org']) ? $_GET['org'] : null;

// Lab Data will be stored in a file in the temp directory
$results = retrieveStanfordData($pid);
if ($results == false) {
    $module->emError("Could not retrieve Stanford lab results for " . date('Y-m-d'));
} else {
    $module->emDebug("Successfully retrieved Stanford lab results. Num of records = " . count($results));

    // Setup the fields to retrieve REDCap project data to match lab results
    list($mrn_field, $birthdate_field, $baseline_event_id, $event_ids)
        = $module->setUpLoader($pid, $org);

    // Retrieve the MRNs and DoBs for each record so the data can be matched up
    $mrn_records = $module->retrieveMrnsAndDob($pid, $mrn_field, $birthdate_field, $baseline_event_id);

    // Match the project data with the lab data and save the lab results
    $status = $module->matchLabResults($mrn_records, $event_ids, $org, $results);
}

print true;
return;

/**
 * Retrieve the labs from the RtoS Link Streaming service.  The results will come back directly and not
 * be stored in a file.
 *
 * @param $pid
 * @return array|false
 */
function retrieveStanfordData($pid) {

    global $module;

    // These parameters are needed for REDCap to STARR link to retrieve stanford lab results
    $arm = 1;
    $query_name = 'all_lab_tests';
    $fields = array('pat_mrn_id', 'pat_name', 'birth_date', 'spec_taken_instant',
        'component_abbr', 'ord_value', 'mpi_id', 'method_desc');

    // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be written
    // to the temporary directory in REDCap.
    try {
        $rtsl = \ExternalModules\ExternalModules::getModuleInstance('redcap_to_starr_link');
        $response = $rtsl->streamData($pid, $query_name, $arm, $fields);
    } catch (Exception $ex) {
        $module->emError("Exception thrown finding Redcap to STARR Link");
        return false;
    }

    // The return data is in csv format.  Split on rows.
    $results = str_getcsv($response, PHP_EOL);

    // Take out the headers of the stream before returning the actual lab data
    $start_lab_results = false;
    $only_lab_results = array();
    foreach($results as $result) {
        $one_result = str_getcsv($result, ',');
        if ($one_result[0] === "pat_mrn_id") {
            $start_lab_results = true;
        } else if ($start_lab_results) {
            $only_lab_results[] = $one_result;
        }
    }

    return $only_lab_results;
}
