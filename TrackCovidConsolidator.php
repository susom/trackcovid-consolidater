<?php
namespace Stanford\TrackCovidConsolidator;

require_once "emLoggerTrait.php";

use Exception;
use ExternalModules\ExternalModules;
use function Stanford\RedcapToStarrLink\checkIRBAndSetupRequest;

class TrackCovidConsolidator extends \ExternalModules\AbstractExternalModule {

	use emLoggerTrait;

	private $institution;
	private $db_results_table = 'track_covid_result_match';

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

                    $headers = implode(",",$data);
                    $this->emDebug("These are the headers from the data file from " . $this->institution . ": " . json_encode($headers));
                    $header_row = false;
                }else{
                    array_push($sql_value_array, '("'. implode('","', $data) . '")');
                }
            }

            $this->emDebug("Finished reading file");

            // STUFF THIS CSV INTO TEMPORARY RC DB TABLE 'track_covid_result_match'
            $this->pushDataIntoDB($db_results_table, $headers, $sql_value_array);

            fclose($handle);
            $this->emDebug("Closed file $filename");
            $status = true;

        } else {
            $this->emDebug("Could not open file $filename");
        }

		return $status;
	}

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
	public function pushDataIntoDB($db_table, $headers, $data_array) {

        try {
            $header_array = explode(',',$headers);

            // Insert the data into the whichever database table is specified
            if ($db_table == $this->db_results_table) {
                $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $data_array) .
                    " ON DUPLICATE KEY UPDATE " . $header_array[0] . '=' . $header_array[0];
            } else {
                $sql = "INSERT INTO " . $db_table . " (" . $headers . ") VALUES " . implode(',', $data_array);
            }
            //$this->emDebug("This is the sql: " . $sql);

            $q = $this->query($sql, array());
            $this->emDebug("Finished inserting into database ($db_table) with status $q");

            $status = true;

        } catch (\Exception $e) {
            $msg = $e->getMessage();
            $this->emError("Exception thrown while loading database $db_table: " . $msg);
        }
    }

	/**
     * Once CSV DATA is handled for THIS project... it still needs to live for the other projects.
     */
    public function discardCSV($filename) {
		//TODO  rename or DELETE?
		$this->emDebug("all CSV data for " . $filename . " is buffered into DB, can delete.. or rename? then other projects can use the data from the RC table?");

		// $r = rename($filename, $filename ."_bak");
		// unlink($filename);

        return;
	}

	/**
     * Wipe the database (before loading new set of CSV data)
     */
    public function truncateDb($table_name) {
        $sql = 'TRUNCATE ' . $table_name;
	    $q = $this->query($sql, []);
        return;
	}

	/**
     * Process new CSV in the REDCAP temp folder , this method is for the CRON
     * We don't know if there is overlap between Stanford MRNs and UCSF MRNs so to be safe, we will process them
     * separately.  We will load the Stanford data into the temp db table, process all the projects/configurations
     * (Proto has different locations where they keep lab data) and then move to the UCSF data.
     */
    public function loadStanfordData() {
	    $this->emDebug("Process CSV DATA for this project");

	    // We need to check the IRB and privacy report before retrieving data.  All the projects
        // are under the same IRB so it doesn't matter which project IRB number we are checking, just
        // retrieve one of them.
        $irb_pid = $this->getSystemSetting('chart-pid');

	    // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be writtne
        // to the temporary directory in REDCap.
        // **** Switch this when not debugging ****//
        //$filename = APP_PATH_TEMP . 'Stanford_08302020.csv';
        $filename = $this->getStanfordTrackCovidResults($irb_pid);

        if ($filename == false) {
            $this->emError("Could not retrieve Stanford lab results for " . date('Y-m-d'));
        } else {
            $this->emDebug("Successfully retrieved Stanford lab results");

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
            $this->parseCSVtoDB($this->db_results_table, $filename);
            $this->processAllProjects();

            // TODO: Should the file be deleted from the temp directory?

        }
	}

    /**
     * Process all projects for this location
     */

    public function processAllProjects() {

        //get all projects that are enabled for this module
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);
        $this->emDebug("Enabled Projects: " . json_encode($enabled));

        // Loop over each project that has this module enabled
        while($proj = $enabled->fetch_assoc()) {

            $pid = $proj['project_id'];

            // Create the API URL to this project.
            $this_url = $this->getUrl('pages/findResults.php?pid=' . $pid, true, true) .
                '&org=' . $this->institution;
            $this->emDebug("Calling cron ProcessCron for project $pid at URL " . $this_url);

            // Go into project context and process data for this project
            $resp = http_get($this_url);
            if ($resp == false) {
                $this->emError("Processing for project $pid failed");
            } else {
                $this->emDebug("Processing for project $pid was successful");
            }
        }
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
    public function loadUCSFData($filename) {

        if ($filename == null) {
            $this->emError("Could not retrieve UCSF lab results for " . date('Y-m-d'). ' Filename is ' . $filename);
        } else {
            $this->emDebug("Starting to load UCSF lab results");

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
            $this->parseCSVtoDB($this->db_results_table, $filename);
            $this->processAllProjects();
        }
    }

}
