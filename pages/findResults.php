<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

use REDCap;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$org = isset($_GET['org']) && !empty($_GET['org']) ? $_GET['org'] : null;


// If we don't receive a project to process or an organization, we can't continue.
if (is_null($pid)) {
    $module->emError("A project ID must be included to run this script");
    return false;
} else if (is_null($org)) {
    $module->emError("An organization must be associated with data so the loader can process the data.");
    return false;
}

// These are Stanford locations. If the org is STANFORD, then the locations need to be one of these so that
// the lab results
$LOCATIONS = array(
    'CHART'             => array(1,2),
    'TRACK PROTO'       => array(1),
    'TRACK REP'         => array(4,7,8,9,10)
);

// Since we are doing bulk loading, we need to know where we are loading the data to.
$dbtable = 'track_covid_project_records';
$db_phi_table = 'track_covid_mrn_dob';
$results_table = 'track_covid_found_results';

const LOCATION_COLLECTED = 1;
$collection_headers = 'record_id,redcap_event_name,date_collected,location,pcr_id,igg_id';
$pcr_field_list = 'lra_pcr_result,lra_pcr_date,lra_pcr_assay_method,lra_pcr_match_methods___1,lra_pcr_match_methods___2,' .
                    'lra_pcr_match_methods___3,lra_pcr_match_methods___4,lra_pcr_match_methods___5';
$ab_field_list = 'lra_ab_result,lra_ab_date,lra_ab_assay_method,lra_ab_match_methods___1,lra_ab_match_methods___2,' .
                    'lra_ab_match_methods___3,lra_ab_match_methods___4,lra_ab_match_methods___5';
$autoload_field_list = $pcr_field_list . ',' . $ab_field_list;
$redcap_headers = $collection_headers . ',' . $autoload_field_list;
$autoloader_fields = array('lra_pcr_result', 'lra_pcr_date', 'lra_pcr_assay_method', 'lra_pcr_match_methods',
                            'lra_ab_result', 'lra_ab_date', 'lra_ab_assay_method', 'lra_ab_match_methods');
$unmatched_mrns = array();

// We will filter samples based on the location they were taken.  If we are processing Stanford
// data, we only want to look for samples that were processed by Stanford. We are using testing
// locations to determine which samples were processed where. The way the testing sites are
// noted is different for each project.
$chart_pid = $module->getSystemSetting('chart-pid');
$proto_pid = $module->getSystemSetting('proto-pid');
$genpop_pid = $module->getSystemSetting('genpop-pid');
if ($pid == $chart_pid) {
    $this_proj = "CHART";
    $dag_name = "chart";
} else if ($pid == $proto_pid) {
    $this_proj = "TRACK PROTO";
    $dag_name = "proto";
} else if ($pid == $genpop_pid) {
    $this_proj = "TRACK REP";
    $dag_name = "genpop";
} else {
    $module->emError("This is not a TrackCovid project ($pid).  Please Disable this EM on this project");
    return false;
}

$allowable_orgs = array("STANFORD","UCSF");
if (!in_array($org, $allowable_orgs)) {
    $module->emError("This is not a valid organization $org for project $pid");
    return false;
}

/**
 * This section stores the record id, dob and mrn in a table so we can join with appt/lab data in different events
 */
if ($org == 'STANFORD') {
    $mrn_field = $module->getProjectSetting('stanford-mrn');
    $birthdate_field = $module->getProjectSetting('stanford-birth-date');
} else {
    $mrn_field = $module->getProjectSetting('ucsf-mrn');
    $birthdate_field = $module->getProjectSetting('ucsf-birth-date');
}
$baseline_event = $module->getProjectSetting('baseline-event');
$module->emDebug("This is the MRN field $mrn_field and this is the birth date field $birthdate_field");

// Clear out all the database tables before we begin so we have consistent data
$module->truncateDb($db_phi_table);
$module->truncateDb($dbtable);
$module->truncateDb($results_table);

// Store the record_id, birth_date and mrn in a table track_covid_mrn_dob
$phi_fields = array('record_id', 'redcap_event_name', $mrn_field, $birthdate_field);
$filter = "[". $mrn_field . "] <> ''";
$records = getProjectRecords($phi_fields, $filter, $baseline_event);

// Load the database with the record_id/mrn/dob combination so we can cross-reference this table across events
if (empty($records)) {
    $module->emDebug("There are no records in project " . $pid . ". Skipping processing");
    return true;
}

$status = $module->pushDataIntoDB($db_phi_table, 'record_id,redcap_event_name,mrn,dob', $records);
$module->emDebug("Loaded " . count($records) . " demographics records into track_covid_mrn_dob table");

// Make sure all MRNs are 8 character and lpad with '0' if not
$status = $module->updateMRNsTo8Char($db_phi_table);

/**
 * Now loop over all configs and look for lab results
 */

// Retrieve configs and see how many different sets of lab results we need to find.
$configs = $module->getSubSettings('lab-fields');

// Process each config retrieving these fields from the REDCap project and looking for results in the database
// The field order is:  0) date_of_visit, 1) location_collected, 2) pcr_id, 3) igg_id
foreach($configs as $fields => $list) {

    $field_array = explode(',', $list['fields']);

    // Create a filter for the organization whose results we are looking at
    $filter = createRecordFilter($org, $LOCATIONS[$this_proj], $field_array);
    $module->emDebug("Filter: $filter");

    // Retrieve these fields from the REDCap project
    $module->truncateDb($dbtable);

    $loader_return_fields = explode(',',$autoload_field_list);
    $all_return_fields = array_merge(array("record_id", "redcap_event_name"), $field_array, $loader_return_fields);
    $all_retrieval_fields = array_merge(array("record_id", "redcap_event_name"), $field_array, $autoloader_fields);

    $records = getProjectRecords($all_retrieval_fields, $filter, null, $all_return_fields);

    // Load the database with the redcap record_id/event_names
    if (empty($records)) {
        $module->emDebug("There are no records that need processing for this config: " . $list['fields']);
    } else {

        // Push the current project's data into the table
        $status = $module->pushDataIntoDB($dbtable, $redcap_headers, $records);
        if (!status) {
            $module->emError("Error when pushing data to table $dbtable for project $pid");
            $status = false;
        }

        // Now both database tables are loaded.  Match the redcap records with the results
        $data_to_save = matchRecords($results_table, $pcr_field_list, $ab_field_list);

        // Save the results that we found from the matches
        if (empty($data_to_save)) {
            $module->emDebug("There are no records to save for project $pid, for config " . $list['fields']);
        } else {
            $status = saveResults($data_to_save);
            if ($status) {
                $module->emDebug("Successfully saved updated lab data for project $pid for config: " . $list['fields']);
            } else {
                $module->emError("Error with updates for project $pid, for config " . $list['fields']);
            }
        }
    }
}

if ($status) {
    $return_fields = array_merge(array("record_id", "redcap_event_name"), $loader_return_fields);
    $retrieval_fields = array_merge(array("record_id", "redcap_event_name"), $autoloader_fields);

    // Figure out which results were not used in matching records and store them in a new project
    $status = reportChanges($this_proj, $dag_name, $results_table, $retrieval_fields,
        $return_fields, $autoload_field_list, $org);
}

print $status;


/**
 * Create a filter for the REDCap records we want based on organization
 * This is necessary because I'm not sure we don't have overlap MRNs
 *
 * @param $org
 * @param $org_options
 * @param $fields
 * @return string|null
 */
function createRecordFilter($org, $org_options, $fields) {

    global $module;

    $filter = null;
    foreach($org_options as $this_org) {

        if ($org == 'STANFORD') {

            // Make sure we only retrieve samples that we taken and processed by Stanford
            $org_filter = '[' . $fields[LOCATION_COLLECTED] . '] = ' . $this_org;
            if (is_null($filter)) {
                $filter = $org_filter;
            } else {
                $filter .= ' or ' . $org_filter;
            }
        } else if ($org == 'UCSF') {

            // Only bring in records where the tests were performed by UCSF.  Since we know which
            // sites are Stanford, we just need to look for samples that were NOT performed at
            // Stanford.
            // TODO: We need to add the project to the filter for UCSF since there is a row in the CSV
            // file that tells us which study each result belongs to.

            $org_filter = '[' . $fields[LOCATION_COLLECTED] . '] != ' . $this_org;
            if (is_null($filter)) {
                $filter = $org_filter;
            } else {
                $filter .= ' and ' . $org_filter;
            }
        }
    }

    return $filter;
}

/**
 * Retrieve current project data and load into the database table so we can manipulate it
 */
function getProjectRecords($fields, $filter, $event_id=null, $return_fields=null) {
    global $module;

    // If the fields we are expecting in return are the same fields that we are asking for, set them to the same
    // This won't be the case when checkboxes are involved,  We will return how many options there are for each
    // checkbox.
    $project_data = true;
    if (is_null($return_fields)) {
        $return_fields = $fields;
        $project_data = false;
    }

    // These are unwanted characters that might be entered in the pcr_id, igg_id field that we want to strip out
    $unwanted = array('/', '\\', '"', ',', ' ');
    $replace_unwanted = array('','','','', '');

    /**
      * We are retrieving record_id, mrn and dob into its own table so we can join against each event.
      * For each event which will match to a lab result, these are the fields we are retrieving
      * The field order is:  0) date_of_visit, 1) location_collected, 2) pcr_id, 3) igg_id
      * And the loader fields are the same for each project:
      *                      0) lra_pcr_result, 1) lra_pcr_date, 2) lra_pcr_assay_method, 3) lra_pcr_match_methods,
      *                      4) lra_ab_result,  5) lra_ab_date,  6) lra_ab_assay_method,  7) lra_ab_match_methods
      * When the match fields come back, there will be 5 options because they are checkboxes:
      *                      1) lra_pcr_match_methods___1/lra_ab_match_methods___1 = MRN
      *                      2) lra_pcr_match_methods___2/lra_ab_match_methods___2 = Sample ID
      *                      3) lra_pcr_match_methods___3/lra_ab_match_methods___3 = DOB
      *                      4) lra_pcr_match_methods___4/lra_ab_match_methods___4 = Last Name
      *                      5) lra_pcr_match_methods___5/lra_ab_match_methods___5 = Sample Date
      */
    $params = array(
        'return_format' => 'json',
        'events'        => $event_id,
        'fields'        => $fields,
        'filterLogic'   => $filter
    );

    $module->emDebug("In getProjectRecords, getData params: " . json_encode($params));
    $q = REDCap::getData($params);

    // Replace all backslashs by blanks otherwise we can't load into the database
    // Sometimes there are backslashs put in the sample id fields and we want to delete them.
    $records = json_decode($q, true);
    $module->emDebug("There were " . count($records) . " records retrieved from getData");

    $data_to_save = array();
    foreach($records as $record) {
        $one_record = array();
        foreach($return_fields as $field => $value) {

            if ($project_data) {
                // Field 2 is the date the sample was collected.  They are in different formats so we need to make sure
                // they are in the same format so they can load.
                // Field 4 is pcr_id and field 5 is igg_id.  Some of the values have '/' around the value.  We want to
                // strip off the '/' if it is there.
                // Return field order:
                // 0:"record_id", 1:"redcap_event_name", 2:"date_of_visit", 3:"reservation_participant_location",
                // 4:"pcr_id", 5:"igg_id", "lra_pcr_result", "lra_pcr_date", "lra_pcr_assay_method",
                // "lra_pcr_match_methods___1", "lra_pcr_match_methods___2",
                // "lra_pcr_match_methods___3", "lra_pcr_match_methods___4", "lra_pcr_match_methods___5",
                // "lra_ab_result", "lra_ab_date", "lra_ab_assay_method",
                // "lra_ab_match_methods___1",  "lra_ab_match_methods___2", "lra_ab_match_methods___3",
                // "lra_ab_match_methods___4", "lra_ab_match_methods___5"

                if ($field == 2) {
                    if (!empty($record[$value])) {
                        $one_record[] = date('Y-m-d', strtotime($record[$value]));
                    } else {
                        $one_record[] = $record[$value];
                    }
                } else if (($field == 4) or ($field == 5)) {
                    $one_record[] = str_replace($unwanted, $replace_unwanted, $record[$value]);
                } else {
                    $one_record[] = trim($record[$value]);
                }
            } else {
                $one_record[] = trim($record[$value]);
            }
        }

        array_push($data_to_save, '("' . implode('","', $one_record) . '")');
    }

    return $data_to_save;
}

/**
 * Find matches between CSV Data (buffered in DB table 'track_covid_result_match') and records in this project.
 * There are 4 possible match combinations:
 *          1) MRN/sample ID for PCR, 2) MRN/sample ID for IGG,
 *          3) MRN/DOB/Contact date for PCR, 4) MRN/DOB/Contact date for IGG
 */
function matchRecords($results_table,$pcr_field_list, $ab_field_list) {

    global $module, $org, $this_proj;

    $module->emDebug("In matchRecords: org = $org, and project = $this_proj");

    $pcr_result_array = array();
    $pcr_result_array_2 = array();
    $ab_result_array = array();
    $ab_result_array_2 = array();

    // First we are going to match as many records as we can on MRN/sample_id for PCR values
    $sql =
        'select pr.record_id, pr.redcap_event_name, ' .
            ' case rm.ORD_VALUE ' .
            '       when "Not Detected"                 then 0 ' .
            '       when "NOT DETECTED"                 then 0 ' .
            '       when "COVID 19 RNA: Not detected"   then 0 ' .
            '       when "NOT DET"                      then 0 ' .
            '       when "Negative"                     then 0 ' .
            '       when "Detected"                     then 1 ' .
            '       when "DETECTED"                     then 1 ' .
            '       else                                2' .
            ' end as lra_pcr_result, ' .
            ' rm.spec_taken_instant as lra_pcr_date, ' .
            ' rm.method_desc as lra_pcr_assay_method, ' .
            ' 1 as lra_pcr_match_methods___1, ' .
            ' 1 as lra_pcr_match_methods___2, ' .
            ' 0 as lra_pcr_match_methods___3, ' .
            ' 0 as lra_pcr_match_methods___4, ' .
            ' 0 as lra_pcr_match_methods___5 ' .
        ' from track_covid_result_match rm join track_covid_mrn_dob mrn ' .
                ' on rm.pat_mrn_id = mrn.mrn ' .
            ' join track_covid_project_records pr ' .
                ' on mrn.record_id = pr.record_id and rm.mpi_id = pr.pcr_id ' .
        ' where (rm.mpi_id is not null and rm.mpi_id != "") ' .
        ' and rm.COMPONENT_ABBR = "PCR" ';
    $module->emDebug("PCR MRN/MPI_ID query: " . $sql);

    $q = db_query($sql);
    while ($results = db_fetch_assoc($q)) {
        array_push($pcr_result_array, '("'. implode('","', $results) . '")');
    }
    $module->emDebug("The number of PCR matches on MRN/Sample ID: " . count($pcr_result_array));

    // Now we are going to match as many records as we can on MRN/sample_id for IgG values
    $sql =
        'select pr.record_id, pr.redcap_event_name, ' .
                ' case rm.ORD_VALUE ' .
                    ' when "Negative"    then 0 ' .
                    ' when "NEG"         then 0 ' .
                    ' when "Positive"    then 1 ' .
                    ' when "POS"         then 1 ' .
                    ' else               2 ' .
                ' end as lra_ab_result, ' .
                ' rm.spec_taken_instant as lra_ab_date,' .
                ' rm.method_desc as lra_ab_assay_method, ' .
                ' 1 as lra_ab_match_methods___1, ' .
                ' 1 as lra_ab_match_methods___2, ' .
                ' 0 as lra_ab_match_methods___3, ' .
                ' 0 as lra_ab_match_methods___4, ' .
                ' 0 as lra_ab_match_methods___5 ' .
            ' from track_covid_result_match rm join track_covid_mrn_dob mrn ' .
                    ' on rm.pat_mrn_id = mrn.mrn ' .
                ' join track_covid_project_records pr ' .
                    ' on mrn.record_id = pr.record_id ' .
    ' and ((rm.mpi_id like "E%" and rm.mpi_id = pr.igg_id) or (substr(rm.mpi_id, 1,8) = pr.igg_id)) ' .
        ' where (rm.mpi_id is not null and rm.mpi_id != "") ' .
        ' and rm.COMPONENT_ABBR = "IGG"';
    $module->emDebug("IGG MRN/MPI_ID query : " . $sql);

    $q = db_query($sql);
    while ($results = db_fetch_assoc($q)) {
        array_push($ab_result_array, '("'. implode('","', $results) . '")');
    }
    $module->emDebug("The number of IGG matches on MRN/Sample ID are: " . count($ab_result_array));

    // Now we are going to look for matches for results that do not have a sample id and
    // we will match on MRN/DoB/Encounter Date for PCR tests
    $sql =
        'select pr.record_id, pr.redcap_event_name, ' .
                ' case rm.ORD_VALUE ' .
                '      when "Not Detected"                 then 0 ' .
                '      when "NOT DETECTED"                 then 0 ' .
                '      when "COVID 19 RNA: Not de"         then 0 ' .
                '      when "NOT DET"                      then 0 ' .
                '      when "Negative"                     then 0 ' .
                '      when "Detected"                     then 1 ' .
                '      when "DETECTED"                     then 1 ' .
                '      when "TEST NOT PERFORMED"           then 98 '.
                '      else                                2' .
                ' end as lra_pcr_result, ' .
            ' rm.spec_taken_instant as lra_pcr_date, ' .
            ' rm.method_desc as lra_ab_assay_method, ' .
            ' 1 as lra_pcr_match_methods___1, ' .
            ' 0 as lra_pcr_match_methods___2, ' .
            ' 1 as lra_pcr_match_methods___3, ' .
            ' 0 as lra_pcr_match_methods___4, ' .
            ' 1 as lra_pcr_match_methods___5 ' .
        ' from track_covid_result_match rm join track_covid_mrn_dob mrn ' .
                ' on rm.pat_mrn_id = mrn.mrn and DATE(rm.birth_date) = DATE(mrn.dob) ' .
            ' join track_covid_project_records pr on pr.record_id = mrn.record_id ' .
        ' where DATE(pr.date_collected) = DATE(rm.SPEC_TAKEN_INSTANT) ' .
        ' and rm.COMPONENT_ABBR = "PCR" ' .
        ' and rm.birth_date is not null ';
    if ($org == 'UCSF') {
        $sql .= ' and (rm.cohort = "' . $this_proj . '")';
    }
    $module->emDebug("PCR results on MRN/DOB/Encounter Date query: " . $sql);

    $q = db_query($sql);
    while ($results = db_fetch_assoc($q)) {
        array_push($pcr_result_array_2, '("'. implode('","', $results) . '")');
    }
    $module->emDebug("The number of PCR matches on MRN/DoB/Encounter Date is: " . count($pcr_result_array_2));


    // This query is for results with a sample id so we match on MRN/DoB/Encounter for IgG results
    $sql =
        ' select pr.record_id, pr.redcap_event_name, ' .
                ' case rm.ORD_VALUE ' .
                    ' when "Negative"    then 0 ' .
                    ' when "NEG"         then 0 ' .
                    ' when "Positive"    then 1 ' .
                    ' when "POS"         then 1 ' .
                    ' else               2 ' .
                ' end as lra_ab_result, ' .
                ' rm.spec_taken_instant as lra_ab_date, ' .
                ' rm.method_desc as lra_ab_assay_method, ' .
                ' 1 as lra_ab_match_methods___1, ' .
                ' 0 as lra_ab_match_methods___2, ' .
                ' 1 as lra_ab_match_methods___3, ' .
                ' 0 as lra_ab_match_methods___4, ' .
                ' 1 as lra_ab_match_methods___5 ' .
        ' from track_covid_result_match rm join track_covid_mrn_dob mrn ' .
                    ' on rm.pat_mrn_id = mrn.mrn and DATE(rm.birth_date) = DATE(mrn.dob) ' .
                ' join track_covid_project_records pr on pr.record_id = mrn.record_id ' .
        ' where DATE(pr.date_collected) = DATE(rm.SPEC_TAKEN_INSTANT) ' .
        ' and rm.COMPONENT_ABBR = "IGG" ' .
        ' and rm.birth_date is not null ';
    if ($org == 'UCSF') {
        $sql .= ' and (rm.cohort = "' . $this_proj . '")';
    }

    $module->emDebug("MRN/DOB/Contact Date for IgG query: " . $sql);

    $q = db_query($sql);
    while ($results = db_fetch_assoc($q)) {
        array_push($ab_result_array_2, '("'. implode('","', $results) . '")');
    }
    $module->emDebug("The number of IGG matches on MRN/DoB/Encounter Date is: " . count($ab_result_array_2));

    // We have results for PCR and IgG, now we want to merge them for the same record ID/redcap_event_name
    // We are creating a copy of the records so we can easily tell which records have changed.
    $all_results = merge_all_results(array_merge($pcr_result_array, $pcr_result_array_2),
                                    array_merge($ab_result_array, $ab_result_array_2),
                                    $results_table,$pcr_field_list, $ab_field_list);
    $module->emDebug("The number of records to update is: " . count($all_results));

    return $all_results;
}

/**
 *  This function takes the PCR results and IgG results and merges them together for the same record ID
 *  and redcap_event_name.  We will end up with a table with the most recent results. It will help to
 *  track changes between loads and to overwrite previously entered lab values if needed.
 *  Not sure this is the easiest way to do this but I am making a table of all the recent lab results,
 *  <table track_covid_found_results>, and we have a table of the starting lab values that were previously
 *  loaded <table track_covid_project_records>.
 *
 * @param $all_pcr_results
 * @param $all_ab_results
 * @return array - Array of all the results per record/redcap_event_id
 */
function merge_all_results($all_pcr_results, $all_ab_results, $results_table, $pcr_field_list, $ab_field_list) {

    global $module;

    $temp_table = 'track_covid_temp';
    $rc_events = 'record_id,redcap_event_name';
    $headers_pcr = $rc_events . ',' . $pcr_field_list;
    $headers_ab = $rc_events . ',' . $ab_field_list;
    $lra_all = $rc_events . ',' . $pcr_field_list . ',' . $ab_field_list;

    // It will be easier to find differences between what is currently in the database and what we are
    // going to load and to also find which records we do not have data for and make sure all those results
    // are blank, I'm going to add these results to another table and do my manipulations there.
    $module->truncateDb($results_table);

    // First copy over the records/events that we are looking for results for
    $sql = 'insert into track_covid_found_results (record_id, redcap_event_name) ' .
                ' select record_id, redcap_event_name from track_covid_project_records ' .
                    ' where (date_collected != "" and date_collected is not null)';
    $q = db_query($sql);
    $num_rows = db_num_rows($q);
    $module->emDebug("Inserted rows into track_covid_found_results: " . $num_rows);

    if (!empty($all_pcr_results)) {
        // Now put together the SQL to load this PCR data into a temp table so we can merge into the results table
        // based on record_id and redcap_event_name
        $module->truncateDb($temp_table);
        $status = $module->pushDataIntoDB($temp_table, $headers_pcr, $all_pcr_results);
        if (!$status) {
            $module->emError("Could not load data into table $temp_table with headers $headers_pcr");
            return false;
        }

        // Now that the data is loaded into the database, query for PCR values.
        $sql =
            'UPDATE track_covid_found_results fr ' .
            ' INNER JOIN ' .
            ' track_covid_temp temp ON fr.record_id = temp.record_id and fr.redcap_event_name = temp.redcap_event_name ' .
            ' SET ' .
            ' fr.lra_pcr_date = temp.lra_pcr_date, ' .
            ' fr.lra_pcr_result = temp.lra_pcr_result, ' .
            ' fr.lra_pcr_assay_method = temp.lra_pcr_assay_method, ' .
            ' fr.lra_pcr_match_methods___1 = temp.lra_pcr_match_methods___1, ' .
            ' fr.lra_pcr_match_methods___2 = temp.lra_pcr_match_methods___2, ' .
            ' fr.lra_pcr_match_methods___3 = temp.lra_pcr_match_methods___3, ' .
            ' fr.lra_pcr_match_methods___4 = temp.lra_pcr_match_methods___4, ' .
            ' fr.lra_pcr_match_methods___5 = temp.lra_pcr_match_methods___5 ';
        $q = db_query($sql);
        $num_rows = db_num_rows($q);
        $module->emDebug("Merged PCR data into track_covid_found_results: " . $num_rows);
    }

    if (!empty($all_ab_results)) {
        // Now put together the SQL to merge this IgG data into a temp table so we can merge into the results table
        // based on record_id and redcap_event_name for IGG data
        $module->truncateDb($temp_table);
        $status = $module->pushDataIntoDB($temp_table, $headers_ab, $all_ab_results);
        if (!$status) {
            $module->emError("Could not load data into $temp_table for IgG project data");
            return false;
        }

        // Now that the IgG data from the project are loaded, match the values with the loaded data from the csv.
        $sql =
            'UPDATE track_covid_found_results fr ' .
            ' INNER JOIN ' .
            ' track_covid_temp temp ON fr.record_id = temp.record_id and fr.redcap_event_name = temp.redcap_event_name ' .
            ' SET ' .
            ' fr.lra_ab_date = temp.lra_ab_date, ' .
            ' fr.lra_ab_result = temp.lra_ab_result, ' .
            ' fr.lra_ab_assay_method = temp.lra_ab_assay_method, ' .
            ' fr.lra_ab_match_methods___1 = temp.lra_ab_match_methods___1, ' .
            ' fr.lra_ab_match_methods___2 = temp.lra_ab_match_methods___2, ' .
            ' fr.lra_ab_match_methods___3 = temp.lra_ab_match_methods___3, ' .
            ' fr.lra_ab_match_methods___4 = temp.lra_ab_match_methods___4, ' .
            ' fr.lra_ab_match_methods___5 = temp.lra_ab_match_methods___5 ';
        $q = db_query($sql);
        $num_rows = db_num_rows($q);
        $module->emDebug("Merged rows of IGG into track_covid_found_results: " . $num_rows);
    }

    // Now download the <track_covid_found_results> table and prepare it to load into Redcap
    $sql = 'select fr.* from track_covid_found_results fr join track_covid_project_records pr' .
            '          on pr.record_id = fr.record_id and pr.redcap_event_name = fr.redcap_event_name ' .
            ' where ((pr.lra_ab_result <> fr.lra_ab_result) or (pr.lra_pcr_result <> fr.lra_pcr_result)' .
            '       or (pr.lra_ab_date <> fr.lra_ab_date) or (pr.lra_pcr_date <> fr.lra_pcr_date) ' .
            '       or (pr.lra_ab_assay_method <> fr.lra_ab_assay_method) or (pr.lra_pcr_assay_method <> fr.lra_pcr_assay_method))';
    $q = db_query($sql);

    // Create json objects that we can easily load into redcap.
    $lra_headers = explode(',',$lra_all);
    $final_results = array();
    while ($results = db_fetch_assoc($q)) {
        $final_results[] = array_combine($lra_headers, $results);
    }

    return $final_results;
}

/**
 * Store records where matches were found between this project's records and the CSV file with lab results
 *
 * @param $data_to_save
 * @return bool
 */
function saveResults($data_to_save) {

    global $module;

    $status = true;
    $return = REDCap::saveData('json', json_encode($data_to_save));

    if(!empty($return["errors"])){
        $module->emError("Error saving lab matches " . json_encode($return["errors"]));
        $status = false;
    } else {
        $module->emDebug("Successfully saved data with item count: " . $return['item_count']);
    }
    return $status;
}

/**
 * We are going to report items that each project is interested in. So far, these are the checks:
 *      1) Indicate labs that were taken > 7 days that don't have results
 *      2) How many records can be matched if the MRN was present based on DoB/Date Collected only
 *      3) How many records can be matched if the MRN was present based on sample_id only
 *      4) How many total cumulative positives (AB and PCR) and how many incremental positives are there?
 */
function reportChanges($project, $dag_name, $results_table, $retrieval_fields,
                       $return_fields, $autoload_field_list, $org) {

    global $module;
    $status = true;

    // Retrieve the final list of autoloaded results so we can compare against the results in the file
    // and see which results are leftover results
    $module->truncateDb($results_table);
    $filter = "[lra_pcr_result] <> '' or [lra_ab_result] <> ''";
    $params = array(
        'return_format' => 'json',
        'fields'        => $retrieval_fields,
        'filterLogic'   => $filter
    );

    $q = REDCap::getData($params);
    $records = json_decode($q, true);

    $data_to_save = array();
    foreach($records as $record) {
        $one_record = array();
        $one_record[] = $record['record_id'];
        $one_record[] = $record['redcap_event_name'];
        $one_record[] = $record['lra_pcr_result'];
        $one_record[] = $record['lra_pcr_date'];
        $one_record[] = $record['lra_pcr_assay_method'];
        $one_record[] = $record['lra_pcr_match_methods___1'];
        $one_record[] = $record['lra_pcr_match_methods___2'];
        $one_record[] = $record['lra_pcr_match_methods___3'];
        $one_record[] = $record['lra_pcr_match_methods___4'];
        $one_record[] = $record['lra_pcr_match_methods___5'];
        $one_record[] = $record['lra_ab_result'];
        $one_record[] = $record['lra_ab_date'];
        $one_record[] = $record['lra_ab_assay_method'];
        $one_record[] = $record['lra_ab_match_methods___1'];
        $one_record[] = $record['lra_ab_match_methods___2'];
        $one_record[] = $record['lra_ab_match_methods___3'];
        $one_record[] = $record['lra_ab_match_methods___4'];
        $one_record[] = $record['lra_ab_match_methods___5'];
        array_push($data_to_save, '("' . implode('","', $one_record) . '")');
    }
    //$module->emDebug("retrieved lra data: " . json_encode($data_to_save));


    // Push the current project's data into the results table
    $headers = "record_id,redcap_event_name," . $autoload_field_list;
    $status = $module->pushDataIntoDB($results_table, $headers, $data_to_save);
    if (!status) {
        $module->emError("Error when pushing data to table $results_table for project $project");
        return false;
    }

    $status_dontcare = unmatchedLabResults($project, $dag_name, $org);
    $status_dontcare = unmatchedMRNs($project, $dag_name, $org);

    return $status;
}

/**
 * This function will find results with MRNs that match an MRN in the project but the result does not match
 *
 * @param $project
 * @param $dag_name
 * @param $org
 * @return bool
 */
function unmatchedLabResults($project, $dag_name, $org) {

    global $module, $pid;

    $unmatched_table = "track_covid_unmatched";
    $unmatched_headers = array("pat_mrn_id", "pat_name", "birth_date", "spec_taken_instant",
                                "component_abbr", "ord_value", "mpi_id", "cohort");

    // Retrieve the project where the Unmatched Records will be stored
    $unmatched_project = $module->getSystemSetting('unmatched');
    if (empty($unmatched_project)) {
        $module->emDebug("Project for unmatched results is not selected so skipping processing");
        return true;
    }

    $module->truncateDb($unmatched_table);

    // This is the query for matched MRNs but unmatched dates
    $sql =
        "select rm.pat_mrn_id, rm.pat_name, rm.birth_date, rm.spec_taken_instant, " .
                " rm.component_abbr, rm.ord_value, rm.mpi_id, rm.cohort " .
            " from track_covid_result_match rm, track_covid_mrn_dob mrn " .
            " where rm.COHORT in ('" . $project . "', 'OTHER') " .
            " and rm.pat_mrn_id = mrn.mrn " .
            " and rm.COMPONENT_ABBR = 'PCR' " .
            " and rm.SPEC_TAKEN_INSTANT not in " .
            "       (select lra_pcr_date " .
            "           from track_covid_found_results proj " .
            "           where proj.record_id = mrn.record_id " .
            "           and mrn.mrn = rm.pat_mrn_id " .
            "           and proj.lra_pcr_date is not null) " .
        " order by rm.pat_mrn_id, SPEC_TAKEN_INSTANT";

    $debug_msg = "These are the results from PCR query with matched MRNs and unmatched dates for project " . $project;
    $error_msg = "Error loading results from PCR query with matched MRNs and unmatched dates for project " . $project;
    $status = runQueryAndLoadDB($sql, $unmatched_table, $unmatched_headers, $debug_msg, $error_msg);


    // This is the IGG query for matched MRNs but unmatched result dates
    $sql =
        "select rm.pat_mrn_id, rm.pat_name, rm.birth_date, rm.spec_taken_instant, " .
        " rm.component_abbr, rm.ord_value, rm.mpi_id, rm.cohort " .
        " from track_covid_result_match rm, track_covid_mrn_dob mrn " .
        " where rm.COHORT in ('" . $project . "', 'OTHER') " .
        " and rm.COMPONENT_ABBR = 'IGG' " .
        " and rm.pat_mrn_id = mrn.mrn " .
        " and rm.SPEC_TAKEN_INSTANT not in " .
        "       (select lra_ab_date " .
        "           from track_covid_found_results proj " .
        "           where proj.record_id = mrn.record_id " .
        "           and mrn.mrn = rm.pat_mrn_id " .
        "           and proj.lra_ab_date is not null) " .
        " order by rm.pat_mrn_id, SPEC_TAKEN_INSTANT";

    $debug_msg = "These are the results from IGG query with matched MRNs and unmatched dates for project " . $project;
    $error_msg = "Error loading results from IGG query with matched MRNs and unmatched dates for project " . $project;
    $status = runQueryAndLoadDB($sql, $unmatched_table, $unmatched_headers, $debug_msg, $error_msg);

    $record_id = createNewRecordID($project, $org);
    $module->emDebug("This is the new record id: " . $record_id);

    // Retrieve all unique rows and load them into the Redcap project
    $sql =
        "with xxx as   ( " .
        "    select distinct * " .
        "       from track_covid_unmatched " .
        "       where spec_taken_instant > date('2020-05-05 00:00:00') " .
        ") " .
        "select '" . $record_id . "', 'unmatched_results', " .
        "   row_number() over (order by spec_taken_instant, pat_mrn_id) as redcap_repeat_instance, " .
        "   pat_mrn_id, pat_name, birth_date, spec_taken_instant, " .
        "   component_abbr, ord_value, mpi_id, cohort from xxx";
    $q = db_query($sql);

    // Create json objects that we can easily load into redcap.
    $redcap_headers = array("record_id", "redcap_repeat_instrument", "redcap_repeat_instance");
    $all_headers = array_merge($redcap_headers, $unmatched_headers);

    $unmatched = array();
    while ($results = db_fetch_assoc($q)) {
        $unmatched[] = array_combine($all_headers, $results);
    }

    // Save the record with the correct DAG
    $return = REDCap::saveData($unmatched_project, "json", json_encode($unmatched),
                                'normal', 'YMD', 'flat', $dag_name);
    $module->emDebug("Return from saveData: " . json_encode($return));

    return true;
}


function unmatchedMRNs($project, $dag_name, $org) {

    global $module, $pid;

    $unmatched_table = "track_covid_unmatched";
    $unmatched_headers = array("pat_mrn_id", "pat_name", "birth_date", "spec_taken_instant",
        "component_abbr", "ord_value", "mpi_id", "cohort");

    // Retrieve the project where the Unmatched Records will be stored
    $unmatched_project = $module->getSystemSetting('unmatched');
    if (empty($unmatched_project)) {
        $module->emDebug("Project for unmatched results is not selected so skipping processing");
        return true;
    }

    $module->truncateDb($unmatched_table);

    // Find the PCR results that don't have an MRN in our project - this will usually indicate that
    // the MRN in the Redcap project is incorrect
    $sql =
        "select distinct rm.pat_mrn_id, rm.pat_name, rm.birth_date, rm.spec_taken_instant, " .
        "        rm.component_abbr, rm.ord_value, rm.mpi_id, rm.cohort " .
        "    from track_covid_result_match rm " .
        "    where rm.pat_mrn_id not in (select mrn from track_covid_mrn_dob) " .
        "    and rm.COHORT = '" . $project . "'" .
        "    and rm.COMPONENT_ABBR = 'PCR' " .
        "order by rm.pat_mrn_id, rm.SPEC_TAKEN_INSTANT";

    $debug_msg = "These are the results from PCR query with unmatched MRNs for project " . $project;
    $error_msg = "Error loading results from PCR query with unmatched MRNs for project " . $project;
    $status = runQueryAndLoadDB($sql, $unmatched_table, $unmatched_headers, $debug_msg, $error_msg);


    // Find the IGG results that don't have an MRN in our project - this will usually indicate that
    // the MRN in the Redcap project is incorrect
    $sql =
        "select distinct rm.pat_mrn_id, rm.pat_name, rm.birth_date, rm.spec_taken_instant, " .
        "        rm.component_abbr, rm.ord_value, rm.mpi_id, rm.cohort " .
        "    from track_covid_result_match rm " .
        "    where rm.pat_mrn_id not in (select mrn from track_covid_mrn_dob) " .
        "    and rm.COHORT = '" . $project . "'" .
        "    and rm.COMPONENT_ABBR = 'IGG' " .
        "order by rm.pat_mrn_id, rm.SPEC_TAKEN_INSTANT";

    $debug_msg = "These are the results from IGG query with unmatched MRNs for project " . $project;
    $error_msg = "Error loading results from IGG query with unmatched MRNs for project " . $project;
    $status = runQueryAndLoadDB($sql, $unmatched_table, $unmatched_headers, $debug_msg, $error_msg);

    $record_id = createNewRecordID($project, $org);
    $record_id .= 'noMRNs';
    $module->emDebug("This is the new record id: " . $record_id);

    // Retrieve all unique rows and load them into the Redcap project
    $sql =
        "with xxx as   ( " .
        "    select distinct * " .
        "       from track_covid_unmatched " .
        "       where spec_taken_instant > date('2020-05-05 00:00:00') " .
        ") " .
        "select '" . $record_id . "', 'unmatched_results', " .
        "   row_number() over (order by spec_taken_instant, pat_mrn_id) as redcap_repeat_instance, " .
        "   pat_mrn_id, pat_name, birth_date, spec_taken_instant, " .
        "   component_abbr, ord_value, mpi_id, cohort from xxx";
    $q = db_query($sql);

    // Create json objects that we can easily load into redcap.
    $redcap_headers = array("record_id", "redcap_repeat_instrument", "redcap_repeat_instance");
    $all_headers = array_merge($redcap_headers, $unmatched_headers);

    $unmatched = array();
    while ($results = db_fetch_assoc($q)) {
        $unmatched[] = array_combine($all_headers, $results);
    }

    // Save the record with the correct DAG
    $return = REDCap::saveData($unmatched_project, "json", json_encode($unmatched));
    $module->emDebug("Return from saveData from unmatched MRNs: " . json_encode($return));

    return true;
}


function runQueryAndLoadDB($sql, $dbtable, $headers, $debug_msg, $error_msg) {

    global $module;
    $status = true;
    $unmatched = array();

    // Run the query passed in
    $q = db_query($sql);
    while ($results = db_fetch_assoc($q)) {
        array_push($unmatched, '("'. implode('","', $results) . '")');
    }
    $module->emDebug($debug_msg . " " . count($unmatched));

    $header_list = implode(',', $headers);
    $status = $module->pushDataIntoDB($dbtable, $header_list, $unmatched);
    if (!$status) {
        $module->emError($error_msg);
        $status = false;
    }

    return $status;
}

function createNewRecordID($project, $org) {

    $project_name = str_replace(' ', '_', $project);
    return $project_name . "_" . $org . date('_Ymd_Gis');
}
