<?php
namespace Stanford\TrackCovidConsolidator;

require_once "emLoggerTrait.php";
include_once 'RCRecord.php';
include_once 'CSVRecord.php';

use REDCap;

class TrackCovidConsolidator extends \ExternalModules\AbstractExternalModule {

	use emLoggerTrait;

	private $institution;
	private $nomatches;
	private $CSVRecords;
	private $RCRecords;

    public function __construct() {
		parent::__construct();
		// Other code to run when object is instantiated
	}
	
	public function redcap_module_system_enable( $version ) {}
	public function redcap_module_project_enable( $version, $project_id ) {}
	public function redcap_module_save_configuration( $project_id ) {}

	/**
     * Parse external known CSV and transfer data to redcap db
     * @return na
     */
	public function parseCSVtoDB($filename, $exclude_columns){
		//Path of the file stored under pathinfo 
		$filepath = pathinfo($filename); 
		$basename =  $filepath['basename']; 

		//HOW MANY POSSIBLE INSITUTIONS?
		$this->institution = strpos(strtoupper($basename), "UCSF") !== false ? "UCSF" : "STANFORD";

		$sql 	= "SELECT * FROM  track_covid_result_match WHERE csv_file = '$basename'" ;
		$q 		= $this->query($sql, array());

		if($q->num_rows){
			//CSV's DATA alreay in DB so USE THAT
			while ($data = db_fetch_assoc($q)) {
				//push all row data into array in mem
				$new_row = new \Stanford\TrackCovidConsolidator\CSVRecord($data,$this->institution);
				$this->CSVRecords[]=  $new_row;
			}
		}else{
			//LOAD CSV TO DB
			$header_row  = true;
			if (($handle = fopen($filename, "r")) !== FALSE) {
				$sql_value_array 	= array();
				$all_values	 		= array();
				while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
					if($header_row){
						// prep headers as sql column headers
						foreach($exclude_columns as $exclude_key){
							unset($data[$exclude_key]);
						}

						// adding extra column to determine which file the data came from
						array_push($data, "csv_file");

						$headers 	= implode(",",$data);
						print_r($headers);
						$header_row = false;
					}else{
						// Data
						foreach($exclude_columns as $exclude_key){
							unset($data[$exclude_key]);
						}

						// adding extra column to determine which csv file the data came from
						array_push($data, $basename);

						// prep data for SQL INSERT
						array_push($sql_value_array, '("'. implode('","', $data) . '")');
						
						//push all row data into array in mem
						$new_row = new \Stanford\TrackCovidConsolidator\CSVRecord($data,$this->institution);
						$this->CSVRecords[]=  $new_row;
					}
				}

				// STUFF THIS CSV INTO TEMPORARY RC DB TABLE 'track_covid_result_match'
				try {
					$sql = "INSERT INTO track_covid_result_match (".$headers.") VALUES " . implode(',',$sql_value_array) . " ON DUPLICATE KEY UPDATE TRACKCOVID_ID=TRACKCOVID_ID" ;
					$q = $this->query($sql, array());

					$this->discardCSV($filename);

					return true;
				} catch (\Exception $e) {
					$msg = $e->getMessage();
					$this->emDebug($msg);
					throw $e;
				}
				fclose($handle);
			}
		}
		
		return;
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
    public function truncateDb() {
	    $q = $this->query('TRUNCATE track_covid_result_match', []);
        return;
	}

	/**
     * LOAD PROJECT RECORDS 
     */
    public function loadProjectRecords() {
		$this->emDebug("Institution", $this->institution);
		
		// MRN (maybe available)
		// DOB (maybe)
		// LastNAME (maybe)
		// SAMPLE DATE
		// SAMPLE ID

		//TODO Which RC Vars can I count on to have the info consistently?
		$filter = "";
		$params = array(
			'return_format' => 'json',
			'fields'        => array( REDCap::getRecordIdField(), "mrn_all_sites", "pcr_id", "igg_id", "birthdate", "mrn_ucsf", "apexmrn", "mrn_zsfg", "date_collected", "date_scheduled",  "dob", "zsfg_dob", "testdate", "testdate_serology", "name", "name_first","name_last", "zsfg_name_first", "zsfg_name_last", "name_2", "name_fu","name_report"  ),
			'filterLogic'   => $filter    
		);
        $q 			= REDCap::getData($params);
		$records 	= json_decode($q, true);
		
		foreach($records as $record){
			$this->RCRecords[$record[REDCap::getRecordIdField()]] =  new \Stanford\TrackCovidConsolidator\RCRecord($record) ;
			$this->emDebug($record);
		}

		// MATCH AND SAVE
		$this->matchRecords();

		return;
	}

	/**
     * Find matches bewtween CSV Data (buffered in DB table 'track_covid_result_match') and records in this project
     */
    public function matchRecords() {
		foreach($this->RCRecords as $record){
			$record_mrn = $record->getMRN();
			$checks 	= array(); //soft verifcation
			// 1, 1-MRN
			// 2, 2-Sample ID
			// 3, 3-DOB
			// 4, 4-Last Name
			// 5, 5-Sample Date
			if($record_mrn){
				foreach($this->CSVRecords as $csvrecord){
					if($record_mrn == $csvrecord->getMRN()){
						$checks[1] = 1;

						// MATCHING ON NON UNIQUE DATA TO ADD WEIGHT TO VERIFCATION.  
						// IDEALLY MRN + SAMPLEID WILL BE UNIQUE FOR STANFORD
						if($csvrecord->getSpecDate() == $record->getDateCollected() || $csvrecord->getSpecDate() == $record->getDateScheduled() ){
							$checks[5] = 1;
						}

						if($csvrecord->getBirthDate() == $record->getBirthDate()){
							$checks[3] = 1;
						}

						if(strpos($csvrecord->getLastName(), $record->name) !== false){
							$checks[4] = 1;
						}

						if( $csvrecord->getResult() == "NEG" || $csvrecord->getResult() == "POS" ){
							// cant trust the component, go with the result type
							$lab = "IGG";
						}else{
							$lab = "PCR";
						}

						if($csvrecord->getInstitution() == "STANFORD"){
							// Stanford match on [pat_mrn_id] and [pat_id] (which will actually be [mpi_id] which is either the [pcr_id] or [igg_id]).  
							if($csvrecord->getSampleID() == $record->pcr_id || $csvrecord->getSampleID() == $record->igg_id){
								$checks[2] = 1;
							}

							if($checks[1] && $checks[2] || 1){ //MATCH AT LEAST MRN + SAMPLEID 
								$this->saveResult($lab, $record, $csvrecord, $checks); 
							}
						}

						if($csvrecord->getInstitution() == "UCSF"){
							// UCSF match on [pat_mrn_id], [spec_taken_instance] and [component_abbr]

							if($checks[1] && $checks[5]){ //MATCH AT LEAST MRN + SAMPLEID 
								$this->saveResult($lab, $record, $csvrecord, $checks); 
							}
						}
					}
				}
			}
		}

		return;
	}

	/**
     * Store records where matches found between Project Records and CSV
     */
    public function saveResult($lab, $rc, $csv, $checks) {
		$primary_id = REDCap::getRecordIdField();
		$data = array(
			$primary_id				=> $rc->{$primary_id},
			"redcap_event_name"  	=> $rc->redcap_event_name,
		);

		// WHICH TEST AND CONFIRM WITH RESULTS
		$key_result 	= $lab == "IGG" ? "lra_ab_result"		: "lra_pcr_result";
		$key_date 		= $lab == "IGG" ? "lra_ab_date"			: "lra_pcr_date";
		$key_methods 	= $lab == "IGG" ? "lra_ab_match_methods": "lra_pcr_match_methods";

		$data[$key_result] 	= $csv->getResult();
		$data[$key_date] 	= $csv->getSpecDate();

		foreach($checks as $key => $val){
			$funky_name 		= $key_methods . "___" . $key;
            $data[$funky_name] 	= 1;
		}
		
		print_rr($data);
        $r = REDCap::saveData('json', json_encode(array($data)) );

		if(empty($r["errors"])){
			$this->emDebug("error saving " . $r["errors"]);
		}
		return $r;
	}

	/**
     * process new CSV in the REDCAP temp folder , this method is for the CRON
     */
    public function processData() {
	    $this->emDebug("Process CSV DATA for this project");


        //get all projects that are enabled for this module
        $enabled 	= ExternalModules::getEnabledProjects($this->PREFIX);

        //get the noAuth api endpoint for Cron job.
        $url 		= $this->getUrl('pages/processData.php', true, true);

        while($proj = $enabled->fetch_assoc()){

            $pid = $proj['project_id'];
            $this->emDebug("Processing data for pid " . $pid . ' :  url is '.$url);

            $this_url = $url . '&pid=' . $pid;

            //fire off the reset process
            $resp = http_get($this_url);

		}
		
		//TODO , after all the projects have run through this processData, we can then discard the CSVs?
		$this->emDebug("All the projects that have this module have run ProcessData.php, can now discard the CSVs?");
		$this->truncateDb();  //DATA matched , can delete the data now
        return;
	}
}
