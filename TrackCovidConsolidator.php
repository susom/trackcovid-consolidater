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
	private $db_results_table_headers = array('TRACKCOVID_ID', 'PAT_ID', 'PAT_MRN_ID', 'PAT_NAME', 'BIRTH_DATE',
                                                'SPEC_TAKEN_INSTANT', 'RESULT_INSTANT', 'COMPONENT_ID', 'COMPONENT_NAME',
                                                'COMPONENT_ABBR', 'ORD_VALUE', 'TEST_CODE', 'RESULT', 'MPI_ID',
                                                'COHORT', 'ENTITY', 'METHOD_DESC');
	private $db_result_header_order = array();

    protected $irb_pid = 19520;

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
        foreach($this->db_result_header_order as $column => $value) {
            if (is_null($value)) {
                $reordered_row[] = '';
            } else {
                if ($column == 'BIRTH_DATE') {
                    $found_value = date("Y-m-d", strtotime($row[$value]));
                } else if (($column == 'SPEC_TAKEN_INSTANT') or ($column == 'RESULT_INSTANT')) {
                    $found_value = date("Y-m-d H:i:s", strtotime($row[$value]));
                } else {
                    $found_value = $row[$value];
                }
                $reordered_row[] = $found_value;
                //$this->emDebug("Column $column, value $value, row data $row[$value], converted value $found_value");

            }
        }

        array_push($sql_value_array, '("'. implode('","', $reordered_row) . '")');

        return $sql_value_array;
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

	    $status = false;

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

        return $status;
    }

	/**
     * Once CSV DATA is handled for THIS project... it still needs to live for the other projects.
     */
    public function discardCSV($filename) {
		unlink($filename);
	}

	/**
     * Wipe the database (before loading new set of CSV data)
     */
    public function truncateDb($table_name) {
        $this->emDebug("Truncating table: " . $table_name);

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

        global $irb_pid;

	    $this->emDebug("Process CSV DATA for this project");
	    $status = false;

	    // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be writtne
        // to the temporary directory in REDCap.
        // **** Switch this when not debugging ****//
        //$filename = APP_PATH_TEMP . 'Stanford_10012020.csv';
        $filename = $this->getStanfordTrackCovidResults($irb_pid);

        if ($filename == false) {
            $this->emError("Could not retrieve Stanford lab results for " . date('Y-m-d'));
        } else {
            $this->emDebug("Successfully retrieved Stanford lab results");

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
            $this->parseCSVtoDB($this->db_results_table, $filename);

            // Make sure all MRNs are 8 characters
            $status = $this->updateMRNsTo8Char($this->db_results_table);

            $status = $this->processAllProjects();

            // TODO: Should the file be deleted from the temp directory?
            $this->discardCSV($filename);
        }

        return $status;
	}

    /**
     * Process all projects for this location
     */

    public function processAllProjects() {

        $status = false;

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
            $status = http_get($this_url);
            if ($status == false) {
                $this->emError("Processing for project $pid failed");
            } else {
                $this->emDebug("Processing for project $pid was successful");
            }
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

        $this->emDebug("In loadUCSFData");
        $status = false;

        $filename = APP_PATH_TEMP . 'UCSF_data.csv';
        if ($filename == null) {
            $this->emError("Could not retrieve UCSF lab results for " . date('Y-m-d'). ' Filename is ' . $filename);
        } else {
            $this->emDebug("Starting to load UCSF lab results from file: " . $filename);

            // Load the Stanford data into the database in table track_covid_result_match
            $this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
            $this->emDebug("This is the institution (should be UCSF: " . $this->institution . ")");

            $this->parseCSVtoDB($this->db_results_table, $filename);
            $status = $this->updateMRNsTo8Char($this->db_results_table);
            $this->emDebug("Loaded UCSF data from file $filename");
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
    /*
    public function loadStanfordApptData() {

        global $irb_pid;

        $this->emDebug("Process appointment data");
        $status = false;
        */

        // Retrieve the Stanford lab data from Redcap to STARR Link EM.  The data file will be writtne
        // to the temporary directory in REDCap.
        // Switch this when not debugging
        //$filename = APP_PATH_TEMP . 'Stanford_10012020.csv';

        /*
        $filename = $this->getStanfordTrackCovidAppts($irb_pid);

        if ($filename == false) {
            $this->emError("Could not retrieve Stanford appointment data for " . date('Y-m-d'));
        } else {
            $this->emDebug("Successfully retrieved Stanford appointment data");

            $status = $this->processAppointments($filename);

            $this->discardCSV($filename);
        }

        return $status;
    }
    */
}
