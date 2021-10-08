<?php
namespace Stanford\TrackCovidConsolidator;

require_once "emLoggerTrait.php";

use REDCap;
use DateTime;
use Exception;
use ExternalModules\ExternalModules;

class TrackCovidConsolidator extends \ExternalModules\AbstractExternalModule {

	use emLoggerTrait;

	private $institution;
	private $db_results_table = 'track_covid_result_match';
	private $db_results_table_headers = array('TRACKCOVID_ID', 'PAT_ID', 'PAT_MRN_ID', 'PAT_NAME', 'BIRTH_DATE',
                                                'SPEC_TAKEN_INSTANT', 'RESULT_INSTANT', 'COMPONENT_ID', 'COMPONENT_NAME',
                                                'COMPONENT_ABBR', 'ORD_VALUE', 'TEST_CODE', 'RESULT', 'MPI_ID',
                                                'COHORT', 'ENTITY', 'METHOD_DESC');
	private $db_result_header_order = array();

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

    /**
     * Parse external known CSV and transfer data to redcap db
     *
     * @param $db_results_table
     * @param $filename
     * @return bool
     */
    /*
	public function parseCSVtoDB($db_results_table, $filename){

        $status = false;
        $this->emDebug("DB Table: $db_results_table, and filename $filename");

		//HOW MANY POSSIBLE INSITUTIONS?
		$this->emDebug("Loading data for " . $this->institution . " with file: " . $filename);
        $this->truncateDb($db_results_table);

        //LOAD CSV TO DB
        $header_row  = true;
        $this->emDebug("About to open file $filename");
        if (($handle = fopen($filename, "r")) !== FALSE) {
            $sql_value_array 	= array();
            while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                if($header_row){

                    $headers = $data;
                    $this->emDebug("These are the headers from the data file from " . $this->institution . ": " . json_encode($headers));
                    $header_row = false;
                    $this->db_result_header_order = array();
                } else {
                    $sql_value_array = $this->organizeColumnsToDBTable($data, $headers, $sql_value_array);
                }
            }

            $this->emDebug("Finished reading file");

            // STUFF THIS CSV INTO TEMPORARY RC DB TABLE 'track_covid_result_match'
            $header_list = implode(',',$this->db_results_table_headers);
            $status = $this->pushDataIntoDB($db_results_table, $header_list, $sql_value_array);

            fclose($handle);
            $this->emDebug("Closed file $filename");

        } else {
            $this->emDebug("Could not open file $filename");
        }

		return $status;
	}
    */
    /*
    private function organizeColumnsToDBTable($row, $header, $sql_value_array) {

	    // Loop over the columns we want
	    if (empty($this->db_result_header_order)) {
            $this->db_result_header_order = array();

            // Find the column in the file which corresponds to the DB column
            foreach ($this->db_results_table_headers as $column) {
                $this->db_result_header_order[$column] = null;
                for ($ncount = 0; $ncount < count($header); $ncount++) {
                    if (strtoupper($column) == strtoupper($header[$ncount])) {
                        $this->db_result_header_order[$column] = $ncount;
                        break;
                    }
                }
            }
            $this->emDebug("These are the header column numbers: " . json_encode($this->db_result_header_order));
        }

        // Loop over this row and retrieve the column data that we need
        $reordered_row = array();
        $keep = true;
        //$cutoff_date = date('Y-m-d', strtotime("-6 weeks"));
        $cutoff_date = date('Y-m-d', strtotime("-9 months"));
        foreach($this->db_result_header_order as $column => $value) {

            if (is_null($value)) {
                $reordered_row[] = '';
            } else {
                if ($column == 'BIRTH_DATE') {
                    $found_value = date("Y-m-d", strtotime($row[$value]));
                } else if (($column == 'SPEC_TAKEN_INSTANT') or ($column == 'RESULT_INSTANT')) {
                    $found_value = date("Y-m-d H:i:s", strtotime($row[$value]));
                    if ($found_value < $cutoff_date) {
                        $keep = false;
                    }
                } else {
                    $found_value = $row[$value];
                }

                $reordered_row[] = $found_value;

            }
        }

        if ($keep) {
            array_push($sql_value_array, '("' . implode('","', $reordered_row) . '")');
        }

        return $sql_value_array;
    }
    */

    /**
     * Push data into a database table for easier querying. The database tables are either:
     *      track_covid_result_match - which holds the lab results from Stanford or UCSF
     * OR   trackcovid_project_table - which holds the REDCap project data which will be
     *                                 used to match results
     *
     * @param $db_table
     * @param $headers
     * @param $data_array
     */
    /*
	public function pushDataIntoDB($db_table, $headers, $data_array) {

	    $status = false;

        $header_array = explode(',',$headers);
	    $subarray = array();
	    $ncnt = 0;

        foreach ($data_array as $one_row) {
            $subarray[] = $one_row;

            // Save every 1000 records
            if (($ncnt % 1000) == 0) {
                try {
                    // Insert the data into the whichever database table is specified
                    if ($db_table == $this->db_results_table) {
                        $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $subarray) .
                            " ON DUPLICATE KEY UPDATE " . $header_array[0] . '=' . $header_array[0];
                    } else {
                        $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $subarray);
                    }

                    $q = $this->query($sql, array());
                    $this->emDebug("Finished inserting into ". count($subarray) . " database ($db_table) with status $q with running total of $ncnt");

                    $subarray = array();
                    $status = true;

                } catch (\Exception $e) {
                    $msg = $e->getMessage();
                    $this->emError("Exception thrown while loading database $db_table: " . $msg);
                    $status = false;
                    return $status;
                }

            }
            $ncnt++;
        }

        // If there are unsaved records, save them now
        if (!empty($subarray)) {
            try {
                // Insert the data into the whichever database table is specified
                if ($db_table == $this->db_results_table) {
                    $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $subarray) .
                        " ON DUPLICATE KEY UPDATE " . $header_array[0] . '=' . $header_array[0];
                } else {
                    $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $subarray);
                }

                $q = $this->query($sql, array());
                $this->emDebug("Finished inserting final ". count($subarray) . " into database ($db_table) with status $q with running total of $ncnt");

                $status = true;

            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $this->emError("Exception thrown while loading database $db_table: " . $msg);
                $status = false;
                return $status;
            }
        }

        return $status;
    }
    */

	/**
     * Once CSV DATA is handled for THIS project... it still needs to live for the other projects.
     */
    /*
    private function discardCSV($filename) {
		unlink($filename);
	}
    */

	/**
     * Wipe the database (before loading new set of CSV data)
     */
    /*
    public function truncateDb($table_name) {
        $this->emDebug("Truncating table: " . $table_name);

        $sql = 'TRUNCATE ' . $table_name;
	    $q = $this->query($sql, []);
        return;
	}
    */

	/**
     * Process new CSV in the REDCAP temp folder , this method is for the CRON
     * We don't know if there is overlap between Stanford MRNs and UCSF MRNs so to be safe, we will process them
     * separately.  We will load the Stanford data into the temp db table, process all the projects/configurations
     * (Proto has different locations where they keep lab data) and then move to the UCSF data.
     */
    public function loadStanfordData() {

        $this->emDebug("Starting the load process for Stanford labs");

	    // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be written
        // to the temporary directory in REDCap.
        // **** Switch this when not debugging ****//
        $filename = APP_PATH_TEMP . 'Stanford.csv';
        //$filename = $this->getStanfordTrackCovidResults($irb_pid);

        if ($filename == false) {
            $this->emError("Could not retrieve Stanford lab results for " . date('Y-m-d'));
        } else {
            $this->emDebug("Successfully retrieved Stanford lab results");

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = "Stanford";

            $status = $this->processAllProjects();

            /*
            // Delete the file after the lab results were loaded
            $this->discardCSV($filename);
            */
        }

        return $status;
	}

    /**
     * Process all projects for this location for lab result data
     */
    private function processAllProjects() {

        $status = false;
        $pid = 39;

        $this_url = $this->getUrl('pages/findResults.php', true, true) .
                '&org=' . $this->institution . '&pid=' . $pid;
        $this->emDebug("Calling findResults at URL " . $this_url);

        // Go into project context and process data for this project
        $client = new Client();
        $resp = $client->get($this_url);
        if ($resp->getStatusCode() == 200) {
            $status = true;
            $this->emDebug("Processing lab results for was successful");
        } else {
            $status = false;
            $this->emError("Processing lab results failed");
        }

        return $status;
    }

    /**
     * This function retrieves the lab results and downloads it to the Redcap temp directory.
     * The format of the filename is 'Stanford_<mmddyyyy>.csv'.
     *
     * @param $proj_id
     * @return mixed
     */
    private function getStanfordTrackCovidResults($proj_id)
    {
        // Use the REDCap to STARR Link EM to retrieve TrackCovid results
        try {
            $RSL = \ExternalModules\ExternalModules::getModuleInstance('redcap_to_starr_link');
            $filename = $RSL->getStanfordTrackCovidResults($proj_id);
            if ($filename == false) {
                $this->emError("Could not retrieve Stanford Lab Results");
            } else {
                $this->emDebug("Successfully retrieved Stanford Lab Results");
            }
        } catch (Exception $ex) {
            $this->emError("Could not instantiate REDCap to STARR link to retrieve Stanford data");
        }

        return $filename;
    }

    /**
     * This function can be called to initiate processing of the UCSF lab results.  We need to know where
     * the file reside and it must be somewhere accessible to REDCap.
     *
     * @param $filename
     */
    public function loadUCSFData() {

        $this->emDebug("Starting loader for UCSF data to run lab results");
        $status = false;

        $filename = APP_PATH_TEMP . 'UCSF_data.csv';
        if ($filename == null) {
            $this->emError("Could not retrieve UCSF lab results for " . date('Y-m-d'). ' Filename is ' . $filename);
        } else {
            $this->emDebug("Starting to load UCSF lab results from file: " . $filename);

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
            $this->emDebug("This is the institution (should be UCSF: " . $this->institution . ")");

            /*
            $this->parseCSVtoDB($this->db_results_table, $filename);
            $status = $this->updateMRNsTo8Char($this->db_results_table);
            $this->emDebug("Loaded UCSF data from file $filename");
            */
            $status = $this->processAllProjects();
            $this->emDebug("Finished processing lab results for file $filename");

            // Delete the file
            $this->discardCSV($filename);
        }

        return $status;
    }


    public function updateMRNsTo8Char($db_table) {

        if ($db_table == 'track_covid_result_match') {
            // Update the MRNs to make sure they are all 8 characters
            $sql = 'update ' . $db_table . ' set pat_mrn_id = lpad(pat_mrn_id, 8, "0") where length(pat_mrn_id) < 8';
        } else {
            // Update the MRNs to make sure they are all 8 characters
            $sql = 'update ' . $db_table . ' set mrn = lpad(mrn, 8, "0") where length(mrn) < 8';
        }

        $this->emDebug("DB table is $db_table and update sql is " . $sql);
        $q = db_query($sql);
        if ($q) {
            $this->emDebug("Successfully updated MRNs to 8 characters: " . $q);
            $status = true;
        } else {
            $this->emError("Error while updating MRNs to 8 characters: " . $q);
            $status = false;
        }

        return $status;

    }

    /**
     * Process new CSV in the REDCAP temp folder which holds appointment data - this method is for the CRON
     */
    public function loadStanfordApptData() {

        // Retrieve the chart pid so we can check the IRB
        $irb_pid = $this->getSystemSetting('chart-pid');
        $status = false;

        // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be writtne
        // to the temporary directory in REDCap.
        // Switch this when not debugging
        try {
            $RSL = \ExternalModules\ExternalModules::getModuleInstance('redcap_to_starr_link');
            $filename = $RSL->getStanfordTrackCovidAppts($irb_pid);
            if ($filename == false) {
                $this->emError("Could not retrieve Stanford appointment data for " . date('Y-m-d'));
            } else {
                $this->emDebug("Successfully retrieved Stanford appointment data");

                // Load all projects that have the checkbox set in the EM config
                $status = $this->processAppointments();

                // Delete the appointment file after processing
                $this->discardCSV($filename);

            }
        } catch (Exception $ex) {
            $this->emDebug("Exception: " . json_encode($ex));
            $this->emError("Could not instantiate REDCap to STARR link to retrieve Stanford Appointment data");
        }

        return $status;
    }

    /**
     * Send the filename to the page which will process and load the appointments
     *
     * @return bool
     */
    private function processAppointments() {

        $status = false;

        //get all projects that are enabled for this module
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        $this->emDebug("Enabled Projects: " . json_encode($enabled));

        // Loop over each project that has this module enabled
        while($proj = $enabled->fetch_assoc()) {

            $pid = $proj['project_id'];

            // Create the API URL to this project.
            $appt_url = $this->getUrl("pages/findAppointments.php?pid=" . $pid, true, true);
            $this->emDebug("Calling in project context to process appointments for project $pid at URL " . $appt_url);

            // Go into project context and process data for this project
            $client = new Client();
            $resp = $client->get($appt_url);
            if ($resp->getStatusCode() == 200) {
                $status = true;
            } else {
                $status = false;
                $this->emError("Processing appointments for project $pid failed");
            }
        }

        return $status;
    }

    public function fillInApptWindowLimits() {

        // Retrieve Chart pid
        $chart_pid = $this->getSystemSetting('chart-pid');
        $this->emDebug("Process and load window calculations for Chart: $chart_pid");

        // Generate the URL
        $this_url = $this->getUrl('pages/CalculateWindowDates.php?pid=' . $chart_pid, true, true);
        $status = http_get($this_url);
        if ($status == false) {
            $this->emError("Processing visit windows for project $chart_pid failed");
        } else {
            $this->emDebug("Processing visit windows for project $chart_pid was successful");
        }

        return $status;
    }


    /**
     * Process new CSV in the REDCAP temp folder which holds vaccination data - this method is for the CRON
     */
    public function loadStanfordVaxData() {

        // Retrieve the chart pid so we can check the IRB
        $irb_pid = $this->getSystemSetting('chart-pid');
        $status = false;

        // Retrieve the Stanford vaccination data from Redcap to STARR Link EM.  The data file will be
        // written to the temporary directory in REDCap.
        // Switch this when not debugging
        try {
            $RSL = ExternalModules::getModuleInstance('redcap_to_starr_link');
            $filename = $RSL->getStanfordTrackCovidVax($irb_pid);

            if ($filename == false) {
                $this->emError("Could not retrieve Stanford vaccination data for " . date('Y-m-d'));
            } else {
                $this->emDebug("Successfully retrieved Stanford vaccination data");

                // Load all projects that have the checkbox set in the EM config
                $status = $this->processVaccinations();

                // Delete the vaccinations file after processing
                $this->discardCSV($filename);

            }
        } catch (Exception $ex) {
            $this->emError("Could not instantiate REDCap to STARR link to retrieve Stanford Vaccination data");
        }

        return $status;
    }

    /**
     * Send the filename to the page which will process and load the vaccinations
     *
     * @param $filename
     * @return bool
     */
    private function processVaccinations() {

        $status = false;

        //get all projects that are enabled for this module
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        // Loop over each project that has this module enabled
        while($proj = $enabled->fetch_assoc()) {

            $pid = $proj['project_id'];

            // Create the API URL to this project.
            $this_url = $this->getUrl('pages/findVaccines.php?pid=' . $pid, true, true);
            $this->emDebug("Calling cron to process vaccinations for project $pid at URL " . $this_url);

            // Go into project context and process data for this project
            $client = new Client();
            $resp = $client->get($this_url);
            if ($resp->getStatusCode() == 200) {
                $status = true;
                $this->emError("Processing vaccination dates for project $pid was successful");
            } else {
                $status = false;
                $this->emError("Processing vaccination dates for project $pid failed");
            }
        }

        return $status;
    }

    /* ----- */
    public function loaderProjectFields($org) {

        // These are the fields we need to process and store the lab data
        if ($org == 'stanford') {
            $project_fields = array('stanford_date_lab', 'stanford_pcr_id', 'stanford_igg_id');
            $autoloader_fields = array('lra_pcr_result', 'lra_pcr_date', 'lra_pcr_assay_method', 'lra_pcr_match_methods',
                'lra_ab_result', 'lra_ab_date', 'lra_ab_match_methods', 'lra_ab_assay_method');
        } else {
            $project_fields = array('ucsf_date_lab', 'ucsf_pcr_id', 'ucsf_igg_id');
            $autoloader_fields = array('lra_pcr_result', 'lra_pcr_date', 'lra_pcr_assay_method', 'lra_pcr_match_methods',
                'lra_ab_result_2', 'lra_ab_date_2', 'lra_ab_assay_method_2', 'lra_ab_match_methods_2');
        }

        return array($project_fields, $autoloader_fields);

    }

    public function setUpLoader($pid, $org) {

        // If we don't receive a project to process or an organization, we can't continue.
        $allowable_orgs = array("stanford","ucsf");
        if (is_null($org)) {
            $this->emError("An organization must be associated with data so the loader can process the data.");
            return false;
        } else if (!in_array($org, $allowable_orgs)) {
            $this->emError("This is not a valid organization $org for project $pid");
            return false;
        }

        /**
         * Retrieve the config data so we know where to pull patient data in the project
         */
        $birthdate_field = $this->getProjectSetting('birth-date');
        if ($org == 'stanford') {
            $mrn_field = $this->getProjectSetting('stanford-mrn');
        } else {
            $mrn_field = $this->getProjectSetting('ucsf-mrn');
        }
        $baseline_event_id = $this->getProjectSetting('screening-event');
        $this->emDebug("This is the MRN field $mrn_field and this is the birth date field $birthdate_field and baseline event $baseline_event_id");

        $eventids_to_load = $this->getProjectSetting('lab-event-list');
        $event_ids = explode(",", $eventids_to_load);
        $this->emDebug("Event list: " . json_encode($event_ids) . ", and event where mrn is $baseline_event_id");

        return array($mrn_field, $birthdate_field, $baseline_event_id, $event_ids);
    }


    public function retrieveMrnsAndDob($pid, $mrn_field, $birthdate_field, $baseline_event_id) {

        // Retrieve the record_id, birth_date and mrn in a table track_covid_mrn_dob
        $phi_fields = array(REDCap::getRecordIdField(),$mrn_field, $birthdate_field);
        $filter = "[". $mrn_field . "] <> ''";
        $mrn_records = $this->getProjectRecords($phi_fields, $filter, $baseline_event_id);
        if (empty($mrn_records)) {
            $this->emDebug("There are no records in project " . $pid . ". Skipping processing");
            return true;
        }

        // Rearrange to make it easier to find data
        $rearranged = array();
        $record_id_field = REDCap::getRecordIdField();
        foreach($mrn_records as $patient) {
            $one_patient =  array();
            $one_patient['mrn']  = $patient[$mrn_field];
            $one_patient['bdate'] = $patient[$birthdate_field];
            $one_patient[$record_id_field] = $record_id = $patient[$record_id_field];

            $rearranged[$record_id] = $one_patient;
        }

        return $rearranged;
    }

    /**
     * Retrieve current project data and load into the database table so we can manipulate it
     */
    public function getProjectRecords($fields, $filter, $event_id=null) {

        /**
         * We are retrieving record_id, mrn and dob into its own table so we can join against each event.
         * For each event which will match to a lab result, these are the fields we are retrieving
         * The field order is:  0) date_sent_to_lab, 2) pcr_id, 3) igg_id
         * And the loader fields are the same for each project:
         *                      0) lra_pcr_result, 1) lra_pcr_date, 2) lra_pcr_assay_method, 3) lra_pcr_match_methods,
         *                      4) lra_ab_result (_2),  5) lra_ab_date (_2),  6) lra_ab_assay_method (_2),  7) lra_ab_match_methods (_2)
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
            'filterLogic'   => $filter,
            'fields'        => $fields
        );
        $this->emDebug("Params to retrieve: " . json_encode($params));

        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        return $records;
    }

    public function matchLabResults($mrn_records, $event_ids, $org, $results) {

        // Get the fields that we need to retrieve from the project and fields that we will
        // load into the project
        list($retrieve_fields, $store_fields) = $this->loaderProjectFields($org);

        // Loop over all events where there are lab results
        foreach($event_ids as $event_id) {

            // Retrieve the lab data for this event. Only retrieve records if there is
            // a date or sample id for pcr or igg.  No need to process if one of those value
            // are not entered.
            $filter = "([" . $retrieve_fields[0] . "] <> '') or ([" . $retrieve_fields[1] ."] <> '') or " .
                        "([" . $retrieve_fields[2] . "] <> '')";

            $this->emDebug("This is the filter: " . $filter);
            $redcap_records = $this->getProjectRecords(array_merge(array(REDCap::getRecordIdField()), $retrieve_fields), $filter, $event_id);
            $this->emDebug("These are the number of result records for event id $event_id: " . count($redcap_records));

            $results = $this->matchAndSaveRecordsThisEvent($mrn_records, $redcap_records, $results, $event_id);

        }

        // These are left over labs so write them to the Unmatched project
        $this->emDebug("There are " . count($results) . " records that are unmatched");
        $status = $this->saveUnmatchedLabResults($org, $results);

        $status = true;
        return $status;

    }


    public function saveUnmatchedLabResults($org, $results) {

        // Retrieve the project id where the Unmatched project is
        $um_pid = $this->getSystemSetting('unmatched');
        if (empty($um_pid)) {
            return;
        }

        // Make a record name based on organization and date/time
        $record_name = $org . '_' . date('Ymd_His');

        $all_unmatched_results = array();
        $ncount = 1;
        foreach($results as $one_result) {
            $one_unmatched_result = array();
            $one_unmatched_result = $one_result;
            $one_unmatched_result['record_id'] = $record_name;
            $one_unmatched_result['redcap_repeat_instance'] = $ncount++;
            $one_unmatched_result['redcap_repeat_instrument'] = 'unmatched_results';
            $one_unmatched_result['unmatched_results_complete'] = 2;

            $all_unmatched_results[] = $one_unmatched_result;
        }

        $params = array(
            'project_id'        => $um_pid,
            'dataFormat'        => 'json',
            'data'              => json_encode($all_unmatched_results),
            'overwriteBehavior' => 'overwrite',
            'dataAccessGroup'   => $org
        );

        $status = true;
        if (!empty($all_unmatched_results)) {
            //$response = REDCap::saveData($params);
            $response = REDCap::saveData($um_pid, 'json', json_encode($all_unmatched_results), 'overwrite', null, null, $org);
            $this->emDebug("Response from save: " . json_encode($response));
            if (!empty($response["errors"])) {
                $this->emError("Error saving data: " . json_encode($response));
                $status = false;
            }
        }
        return $status;
    }


    private function matchAndSaveRecordsThisEvent($mrn_records, $redcap_records, $results, $event_id) {

        // These are unwanted characters that might be entered in the pcr_id, igg_id field that we want to strip out
        $unwanted = array('/', '\\', '"', ',', ' ');
        $replace_unwanted = array('','','','', '');

        $event_name = REDCap::getEventNames(true, false, $event_id);

        // Loop over all results and see if we can match it in this event
        // This results array is in the following format:
        //  0=mrn, 1=bdate, 2=contid, 3=resultid, 4=result, 5=collect
        $results_matched = array();
        $all_matches = array();
        $not_matched = array();
        foreach($results as $one_result) {

            // These are lab result values
            $lab_mrn = $one_result["mrn"];
            $lab_dob = $one_result["bdate"];
            $lab_sample_id = $one_result["contid"];
            $lab_sentdate = $one_result["collect"];
            $lab_resultid = $one_result["resultid"];
            $lab_result = $one_result["result"];
            $lab_datetime = new DateTime($lab_sentdate);
            $lab_date = date_format($lab_datetime, 'Y-m-d');

            foreach($redcap_records as $record) {

                // These are REDCap records. Some Sample IDs have '/'.  Get rid of them so we can match them
                // Make sure the MRN is 8 characters otherwise lpad with 0 to 8 characters
                $pcr_id = str_replace($unwanted, $replace_unwanted, $record['ucsf_pcr_id']);
                $ab_id = str_replace($unwanted, $replace_unwanted, $record['ucsf_igg_id']);
                $sent_date = $record['ucsf_date_lab'];
                $record_id = $record['record_id'];
                $mrn =  str_pad($mrn_records[$record_id]["mrn"], "0", 8, STR_PAD_LEFT);
                $dob = $mrn_records[$record_id]['bdate'];
                $found = false;

                // This is the correct person, see if we can match
                if ($mrn == $lab_mrn) {
                    if ($pcr_id == $one_result['contid'] and $lab_resultid = 'PCR') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['redcap_event_name'] = $event_name;
                        $results_matched['lra_pcr_result'] = ($lab_result = 'NOTD' ? 0 : 1);
                        $results_matched['lra_pcr_date'] = $lab_sentdate;
                        $results_matched['lra_pcr_match_methods___1'] = $results_matched['lra_pcr_match_methods___2'] = 1;
                        $found = true;
                    } else if ($ab_id = $one_result['contid'] and $lab_resultid = 'COVG') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['redcap_event_name'] = $event_name;
                        $results_matched['lra_ab_result_2'] = ($lab_result = 'NEG' ? 0 : 1);
                        $results_matched['lra_ab_date_2'] = $lab_sentdate;
                        $results_matched['lra_ab_match_methods_2___1'] = $results_matched['lra_ab_match_methods_2___2'] = 1;
                        $found = true;
                    } else if ($sent_date == $lab_dob and $sent_date == $lab_sentdate and $lab_resultid = 'PCR') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['redcap_event_name'] = $event_name;
                        $results_matched['lra_pcr_result'] = ($lab_result = 'NOTD' ? 0 : 1);
                        $results_matched['lra_pcr_result'] = $lab_result;
                        $results_matched['lra_pcr_date'] = $lab_sentdate;
                        $results_matched['lra_pcr_match_methods___1'] = $results_matched['lra_pcr_match_methods___3'] =
                            $results_matched['lra_pcr_match_methods___5'] = 1;
                        $found = true;
                    } else if ($sent_date == $lab_dob and $sent_date == $lab_sentdate and $lab_resultid = 'COVG') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['redcap_event_name'] = $event_name;
                        $results_matched['lra_ab_result_2'] = ($lab_result = 'NEG' ? 0 : 1);
                        $results_matched['lra_ab_date_2'] = $lab_sentdate;
                        $results_matched['lra_ab_match_methods_2___1'] = $results_matched['lra_ab_match_methods_2___3'] =
                            $results_matched['lra_ab_match_methods_2___5'] = 1;
                        $results_matched['lra_ab_match_methods_2___2'] = $results_matched['lra_ab_match_methods_2___4'] = 0;
                        $found = true;
                    }

                    break;
                } // mrns don't match
            }

            // We've gone through all the records in this event and it is not matched
            if ($found) {
                $results_matched['redcap_event_name'] = $event_name;
                $all_matches[] = $results_matched;
            } else {
                $not_matched[] = $one_result;
            }

        } // Done processing all lab results

        // Save all the matched results we found
        if (!empty($all_matches)) {
            $response = REDCap::saveData('json', json_encode($all_matches), 'overwrite');
            $this->emDebug("Response from save: " . json_encode($response));
        } else {
            $this->emDebug("No results to save for event $event_name");
        }

        // Return the unmatched results so we can test other events
        return $not_matched;
    }


}
