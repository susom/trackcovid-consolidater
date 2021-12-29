<?php
namespace Stanford\TrackCovidConsolidator;

require_once "emLoggerTrait.php";

use REDCap;
use DateTime;
use Exception;
use ExternalModules\ExternalModules;

class TrackCovidConsolidator extends \ExternalModules\AbstractExternalModule {

	use emLoggerTrait;

    // These fields are used to load data for each institution
    private $stanford_project_fields = array('stanford_date_lab', 'stanford_pcr_id', 'stanford_igg_id');
    private $stanford_autoloader_fields = array('lra_pcr_result', 'lra_pcr_date',
                                                'lra_pcr_assay_method', 'lra_pcr_match_methods',
                                                'lra_ab_result', 'lra_ab_date',
                                                'lra_ab_match_methods', 'lra_ab_assay_method');
    private $ucsf_project_fields = array('ucsf_date_lab', 'ucsf_pcr_id', 'ucsf_igg_id');
    private $ucsf_autoloader_fields = array('lra_pcr_result', 'lra_pcr_date', 'lra_pcr_assay_method', 'lra_pcr_match_methods',
    'lra_ab_result_2', 'lra_ab_date_2', 'lra_ab_assay_method_2', 'lra_ab_match_methods_2');


    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}

	/**
     * Retrieve lab data in csv format.  The results will come back in memory instead of streaming to a
     * file. This method is called by the CRON job. This v2 study is different than v1 in that samples
     * are processed by both institutions instead of just one so we look at the stanford fields only
     * when we process stanford data and vice versa.
     */
    public function loadStanfordData() {

        $this->emDebug("Starting the load process for Stanford labs");

        // Retrieve the project id where the labs will be loaded
        $pid = $this->getSystemSetting('project');
        $org = 'stanford';

        // Make an API call so we get into project context since this run by the cron
        $project_context_url = $this->getUrl('pages/findResults_v2.php', true, true) .
                                "&pid=" . $pid . "&org=" . $org;
        $this->emDebug("URL: $project_context_url");
        $status = http_get($project_context_url);

        return $status;
	}


    /**
     * This function is used by Stanford and UCSF to retrieve the fields in the project that are needed
     * to match labs with the retrieved STARR labs.
     *
     * @param $org
     * @return array|null[]
     */
    public function loadProjectFields($org) {

        // These are the fields we need to process and store the lab data
        if ($org == 'stanford') {
            return array($this->stanford_project_fields, $this->stanford_autoloader_fields);
        } else if ($org == 'ucsf') {
            return array($this->ucsf_project_fields, $this->ucsf_autoloader_fields);
        } else {
            return array(null, null);
        }
    }

    /**
     * This function is called by both Stanford and UCSF to find the REDCap fields that we need to
     * retrieve to match lab results.
     *
     * @param $pid
     * @param $org
     * @return array|false
     */
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

        //Retrieve the config data so we know where to pull patient data in the project
        $birthdate_field = $this->getProjectSetting('birth-date');
        if ($org == 'stanford') {
            $mrn_field = $this->getProjectSetting('stanford-mrn');
        } else {
            $mrn_field = $this->getProjectSetting('ucsf-mrn');
        }
        $baseline_event_id = $this->getProjectSetting('screening-event');

        $eventids_to_load = $this->getProjectSetting('lab-event-list');
        $event_ids = explode(",", $eventids_to_load);
        $this->emDebug("These are the event_ids: " . json_encode($event_ids));

        return array($mrn_field, $birthdate_field, $baseline_event_id, $event_ids);
    }

    /**
     * This function is called by Stanford and UCSF to retrieve the DoB and MRN from the REDCap
     * project so it can be matched to the lab results.
     *
     * @param $pid
     * @param $mrn_field
     * @param $birthdate_field
     * @param $baseline_event_id
     * @return array|bool
     */
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
     * This function is called by Stanford and UCSF.  The REDCap project fields will be retrieved for
     * each event that needs lab results.
     *
     * @param $fields
     * @param $filter
     * @param null $event_id
     * @return mixed
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

        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        return $records;
    }

    /**
     * This function is called by Stanford and UCSF.  The REDCap records in each event that have labs taken,
     * will be retrieved and will try to match with the lab results.
     *
     * @param $mrn_records
     * @param $event_ids
     * @param $org
     * @param $results
     * @return bool|void
     */
    public function matchLabResults($mrn_records, $event_ids, $org, $results) {

        // Get the fields that we need to retrieve from the project and fields that we will
        // load into the project
        list($retrieve_fields, $store_fields) = $this->loadProjectFields($org);
        if (is_null($retrieve_fields) or is_null($store_fields)) {
            $this->emError("Could not find fields to retrieve or store lab result data");
            return false;
        }

        // Loop over all events where there are lab results
        foreach($event_ids as $event_id) {

            // Retrieve the lab data for this event. Only retrieve records if there is
            // a date or sample id for pcr or igg.  No need to process if one of those value
            // are not entered.
            $filter = "([" . $retrieve_fields[0] . "] <> '') or ([" . $retrieve_fields[1] ."] <> '') or " .
                        "([" . $retrieve_fields[2] . "] <> '')";

            $redcap_records = $this->getProjectRecords(array_merge(array(REDCap::getRecordIdField()), $retrieve_fields), $filter, $event_id);
            $this->emDebug("These are the number of result records for event id $event_id: " . count($redcap_records));

            if ($org == 'ucsf') {
                $results = $this->matchAndSaveRecordsThisEventUcsf($mrn_records, $redcap_records, $results, $event_id);
            } else if ($org == 'stanford') {
                $results = $this->matchAndSaveRecordsThisEventStanford($mrn_records, $redcap_records, $results, $event_id);
            }
        }

        // For UCSF datasets, convert the date from mm/dd/yy to yyyy-mm-dd
        if ($org == 'ucsf') {
            $converted_results = array();
            foreach ($results as $one_result) {
                $one_result[9] = date("Y-m-d", strtotime($one_result[9]));
                $converted_results[] = $one_result;
            }
            $results = $converted_results;
        }

        // These are left over labs so write them to the Unmatched project
        $this->emDebug("There are " . count($results) . " records that are unmatched");
        $status = $this->saveUnmatchedLabResults($org, $results);

        return $status;

    }

    /**
     * This function is called by Stanford and UCSF.  The unmatched labs will be stored in a second
     * REDCap project based on DAGS so only UCSF can see their data and Stanford can see their data.
     *
     * @param $org
     * @param $results
     * @return bool|void
     */
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
            $one_unmatched_result['record_id'] = $record_name;
            $one_unmatched_result['redcap_repeat_instance'] = $ncount++;
            $one_unmatched_result['redcap_repeat_instrument'] = 'unmatched_results';

            // Put the data into json format
            if ($org == 'stanford') {
                $one_unmatched_result['mrn'] =                          $one_result[0];
                $one_unmatched_result['bdate'] =                        $one_result[2];
                $one_unmatched_result['contid'] =                       $one_result[6];
                $one_unmatched_result['result'] =                       $one_result[5];
                $one_unmatched_result['resultid'] =                     $one_result[4];
                $one_unmatched_result['collect'] =                      $one_result[3];
                $one_unmatched_result['assay'] =                        $one_result[7];
            } else {
                //  0=Test, 1=Result, 2=Sample ID, 3=Accession Number, 4=MRN, 5=LastName,FirstName, 6=???
                //  7=DEP, 8=ProviderID, 9=Date, 10=Time, 11=???, 12=Platform
                $one_unmatched_result['mrn'] =                          $one_result[4];
                $one_unmatched_result['contid'] =                       $one_result[2];
                $one_unmatched_result['result'] =                       $one_result[1];
                $one_unmatched_result['resultid'] =                     $one_result[0];
                $one_unmatched_result['collect'] =                      $one_result[9];
                $one_unmatched_result['assay'] =                        $one_result[12];
            }

            $one_unmatched_result['unmatched_results_complete'] = 2;
            $all_unmatched_results[] = $one_unmatched_result;
        }

        $status = true;
        if (!empty($all_unmatched_results)) {
            $response = REDCap::saveData($um_pid, 'json', json_encode($all_unmatched_results), 'overwrite', null, null, $org);
            $this->emDebug("Response from save: " . json_encode($response));
            if (!empty($response["errors"])) {
                $this->emError("Error saving data: " . json_encode($response));
                $status = false;
            }
        }
        return $status;
    }

    /**
     * This function is only used by UCSF to match their labs with the lab results.  Since there are differences
     * in the file setup between Stanford and UCSF, they will be handled separately.
     *
     * @param $mrn_records
     * @param $redcap_records
     * @param $results
     * @param $event_id
     * @return array
     * @throws Exception
     */
    private function matchAndSaveRecordsThisEventUcsf($mrn_records, $redcap_records, $results, $event_id) {

        // These are unwanted characters that might be entered in the pcr_id, igg_id field that we want to strip out
        $unwanted = array('/', '\\', '"', ',', ' ');
        $replace_unwanted = array('','','','', '');

        $event_name = REDCap::getEventNames(true, false, $event_id);

        // Loop over all results and see if we can match it in this event
        // This results array is in the following format:
        //  0=Test, 1=Result, 2=Sample ID, 3=Accession Number, 4=MRN, 5=LastName,FirstName, 6=???
        //  7=DEP, 8=ProviderID, 9=Date, 10=Time, 11=???, 12=Platform
        $all_matches = array();
        $not_matched = array();
        foreach($results as $one_result) {

            // These are lab result values
            $lab_mrn = $one_result[4];
            $lab_sample_id = $one_result[2];
            $lab_sentdate = date("Y-m-d", strtotime($one_result[9]));
            $lab_resultid = $one_result[0];
            $lab_result = $one_result[1];
            $lab_assay = $one_result[12];
            $results_matched = array();

            foreach($redcap_records as $record) {

                // These are REDCap records. Some Sample IDs have '/'.  Get rid of them so we can match them
                // Make sure the MRN is 8 characters otherwise lpad with 0 to 8 characters
                $pcr_id = str_replace($unwanted, $replace_unwanted, $record['ucsf_pcr_id']);
                $ab_id = str_replace($unwanted, $replace_unwanted, $record['ucsf_igg_id']);
                $sent_date = $record['ucsf_date_lab'];
                $record_id = $record['record_id'];
                $mrn =  str_pad($mrn_records[$record_id]["mrn"], "0", 8, STR_PAD_LEFT);
                $found = false;

                // This is the correct person, see if we can match
                if ($mrn == $lab_mrn) {

                    if ($pcr_id == $lab_sample_id and $lab_resultid == 'CVD19R') {
                        $results_matched['lra_pcr_result'] = ($lab_result == 'DET' ? 1 : ($lab_result == 'NOTD' ? 0 : 98));
                        $results_matched['lra_pcr_date'] = $lab_sentdate;
                        $results_matched['lra_pcr_match_methods___1'] = $results_matched['lra_pcr_match_methods___2'] = 1;
                        $results_matched['lra_pcr_assay_method'] = $lab_assay;
                        $found = true;
                    } else if ($ab_id == $lab_sample_id and $lab_resultid == 'COVG') {
                        $results_matched['lra_ab_result_2'] = ($lab_result == 'POS' ? 1 : ($lab_result == 'NEG' ? 0 : 98));
                        $results_matched['lra_ab_date_2'] = $lab_sentdate;
                        $results_matched['lra_ab_match_methods_2___1'] = $results_matched['lra_ab_match_methods_2___2'] = 1;
                        $results_matched['lra_ab_assay_method_2'] = $lab_assay;
                        $found = true;
                    }

                    break;
                } // mrns don't match
            }

            // We've gone through all the records in this event and it is not matched
            if ($found) {
                $results_matched['record_id'] = $record_id;
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

    /**
     * This function is only used by Stanford.  It matches the labs and saves the results into REDCap.
     *
     * @param $mrn_records
     * @param $redcap_records
     * @param $results
     * @param $event_id
     * @return array
     */
    private function matchAndSaveRecordsThisEventStanford($mrn_records, $redcap_records, $results, $event_id) {

        // These are unwanted characters that might be entered in the pcr_id, igg_id field that we want to strip out
        $unwanted = array('/', '\\', '"', ',', ' ');
        $replace_unwanted = array('','','','', '');

        $event_name = REDCap::getEventNames(true, false, $event_id);

        // Loop over all results and see if we can match it in this event
        // This results array is in the following format:
        //  0=mrn, 1=name, 2=bdate, 3=taken date, 4=component, 5=result, 6=mpi_id, 7=assay
        $all_matches = array();
        $not_matched = array();
        foreach($results as $one_result) {

            // These are lab result values
            $lab_mrn        = $one_result[0];
            $lab_dob        = $one_result[2];
            $lab_sentdate   = $one_result[3];
            $lab_component  = $one_result[4];
            $lab_result     = $one_result[5];
            $lab_sample_id  = $one_result[6];
            $lab_assay      = $one_result[7];
            $results_matched = array();

            foreach($redcap_records as $record) {

                // These are REDCap records. Some Sample IDs have '/'.  Get rid of them so we can match them
                // Make sure the MRN is 8 characters otherwise lpad with 0 to 8 characters
                $pcr_id = str_replace($unwanted, $replace_unwanted, $record['stanford_pcr_id']);
                $ab_id = str_replace($unwanted, $replace_unwanted, $record['stanford_igg_id']);
                $sent_date = $record['stanford_date_lab'];
                $record_id = $record['record_id'];
                $mrn =  str_pad($mrn_records[$record_id]["mrn"], "0", 8, STR_PAD_LEFT);
                $dob = $mrn_records[$record_id]['bdate'];
                $found = false;

                // This is the correct person, see if we can match
                if ($mrn == $lab_mrn) {
                    if (($pcr_id == $lab_sample_id) and ($lab_component == 'PCR')) {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['lra_pcr_result'] = ($lab_result == 'Detected' ? 1 : ($lab_result == 'Not Detected' ? 0 : 98));
                        $results_matched['lra_pcr_date'] = $lab_sentdate;
                        $results_matched['lra_pcr_assay_method'] = $lab_assay;
                        $results_matched['lra_pcr_match_methods___1'] = $results_matched['lra_pcr_match_methods___2'] = 1;
                        $results_matched['lra_pcr_match_methods___3'] = $results_matched['lra_pcr_match_methods___4'] =
                                        $results_matched['lra_pcr_match_methods___5'] = 0;
                        $found = true;
                    } else if (($ab_id == $lab_sample_id) and ($lab_component == 'IGG')) {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['lra_ab_result'] = ($lab_result == 'Positive' ? 1 : ($lab_result == 'Negative' ? 0 : 98));
                        $results_matched['lra_ab_date'] = $lab_sentdate;
                        $results_matched['lra_ab_assay_method'] = $lab_assay;
                        $results_matched['lra_ab_match_methods___1'] = $results_matched['lra_ab_match_methods___2'] = 1;
                        $results_matched['lra_ab_match_methods___3'] = $results_matched['lra_ab_match_methods___4'] =
                            $results_matched['lra_ab_match_methods___5'] = 0;
                        $found = true;
                    } else if ($dob == $lab_dob and $sent_date == $lab_sentdate and $lab_component == 'PCR') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['lra_pcr_result'] = ($lab_result == 'Detected' ? 1 : ($lab_result == 'Not Detected' ? 0 : 98));
                        $results_matched['lra_pcr_date'] = $lab_sentdate;
                        $results_matched['lra_pcr_assay_method'] = $lab_assay;
                        $results_matched['lra_pcr_match_methods___1'] = $results_matched['lra_pcr_match_methods___3'] =
                                            $results_matched['lra_pcr_match_methods___5'] = 1;
                        $results_matched['lra_pcr_match_methods___2'] = $results_matched['lra_pcr_match_methods___4'] = 0;
                        $found = true;
                    } else if ($dob == $lab_dob and $sent_date == $lab_sentdate and $lab_component == 'IGG') {
                        $results_matched['record_id'] = $record_id;
                        $results_matched['lra_ab_result'] = ($lab_result == 'Positive' ? 1 : ($lab_result == 'Negative' ? 0 : 98));
                        $results_matched['lra_ab_date'] = $lab_sentdate;
                        $results_matched['lra_ab_assay_method'] = $lab_assay;
                        $results_matched['lra_ab_match_methods___1'] = $results_matched['lra_ab_match_methods___3'] =
                                        $results_matched['lra_ab_match_methods___5'] = 1;
                        $results_matched['lra_ab_match_methods___2'] = $results_matched['lra_ab_match_methods___4'] = 0;
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
            //$this->emDebug("Saving this data: " . json_encode($all_matches));
            $response = REDCap::saveData('json', json_encode($all_matches), 'overwrite');
            $this->emDebug("Response from save: " . json_encode($response) . " for event " . $event_name);
        } else {
            $this->emDebug("No results to save for event $event_name");
        }

        // Return the unmatched results so we can test other events
        return $not_matched;
    }


    public function convertCsvToArrays($org, $response)
    {
        // The return data is in csv format.  Split on rows.
        $results = str_getcsv($response, PHP_EOL);

        // Take out the headers of the stream before returning the actual lab data
        $start_lab_results = false;
        $lab_results = array();
        foreach ($results as $result) {
            $one_result = str_getcsv($result, ',');
            if (($org === 'stanford') and ($one_result[0] === "pat_mrn_id")) {
                $start_lab_results = true;
            } else if ($org === 'ucsf') {
                $start_lab_results = true;
                if ($one_result[0] !== "Test") {
                    $lab_results[] = $one_result;
                }
            } else if ($start_lab_results) {
                $lab_results[] = $one_result;
            }
        }

        return $lab_results;
    }

}
