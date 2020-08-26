<?php
namespace Stanford\TrackCovidConsolidator;

require_once "emLoggerTrait.php";
include_once 'RCRecord.php';
include_once 'CSVRecord.php';

use REDCap;

class TrackCovidConsolidator extends \ExternalModules\AbstractExternalModule {

	use emLoggerTrait;

	private $indeterminate_tests;
	private $negative_tests;
	private $positive_tests;
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
		$this->truncateDb(); //CLEAR DB , STORE ONLY CURRENT CSV

		//HOW MANY POSSIBLE INSITUTIONS?
		$this->institution = strpos(strtoupper($filename), "UCSF") !== false ? "UCSF" : "STANFORD";
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

					$headers 	= implode(",",$data);
					$header_row = false;
				}else{
					// Data
					foreach($exclude_columns as $exclude_key){
						unset($data[$exclude_key]);
					}

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
				return true;
			} catch (\Exception $e) {
				$msg = $e->getMessage();
				$this->emDebug($msg);
				throw $e;
			}
			fclose($handle);
		}
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

		$filter = "";
		$params = array(
			'return_format' => 'json',
			'fields'        => array( REDCap::getRecordIdField(), "mrn_all_sites", "pcr_id", "igg_id", "birthdate", "mrn_ucsf", "apexmrn", "mrn_zsfg", "date_collected", "date_scheduled",  "dob", "zsfg_dob", "testdate", "testdate_serology", "name", "name_first","name_last", "zsfg_name_first", "zsfg_name_last", "name_2", "name_fu","name_report"  ),
			'filterLogic'   => $filter    //TODO filter does not work with repeating events??
		);
        $q 			= REDCap::getData($params);
		$records 	= json_decode($q, true);
		
		foreach($records as $record){
			$this->RCRecords[$record[REDCap::getRecordIdField()]] =  new \Stanford\TrackCovidConsolidator\RCRecord($record) ;
			$this->emDebug($record);
		}
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
		
        $r = REDCap::saveData('json', json_encode(array($data)) );

		if(empty($r["errors"])){
			return true;
		}
		return $r["errors"];
	}
}
