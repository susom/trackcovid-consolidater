<?php

namespace Stanford\TrackCovidConsolidator;
/** @var TrackCovidConsolidator $module */

class CSVRecord {
    private $csv_data;
    private $institution;

    public function __construct($rowdata, $inst) {
        $this->institution  = $inst;
        $this->csv_data     = $rowdata;
        // [0] => TRACKCOVID_ID
        // [1] => PAT_ID (stanford only = IGG_id or PCR_id need to confirm against RESULT)
        // [2] => PAT_MRN_ID (this should be solid)
        // [3] => PAT_NAME (LASTNAME, FIRSTNAME M)
        // [4] => BIRTH_DATE (mm/dd/yy)
        // [5] => SPEC_TAKEN_INSTANT (mm/dd/yy G:i)
        // [6] => RESULT_INSTANT
        // [7] => COMPONENT_ID
        // [8] => COMPONENT_NAME
        // [9] => COMPONENT_ABBR (Stanford IGG/PCR) (UCSF COV2IGGRES/SARSCOV2)
        // [10] => ORD_VALUE
        // [13] => TEST_CODE
        // [14] => RESULT (NEG/POS/NOTD/D?)

// [0] => 676097569
            // [1] => Z3749020
            // [2] => 30287767
            // [3] => HUDETZ,ARIA A
            // [4] => 4/1/85
            // [5] => 8/10/20 9:57
// [6] => 8/11/20 22:30
// [7] => 1230303017
// [8] => SARS-COV-2 IGG RESULT
            // [9] => COV2IGGRES
// [10] => Negative
// [13] => LABCOV2IGG
            // [14] => NEG
    }
    

        
    // UCSF match on [pat_mrn_id], [spec_taken_instance] and [component_abbr]
    public function getMRN(){
        $mrn = $this->csv_data[2];
        return $mrn;
    }

    public function getLastName(){
        $name       = $this->csv_data[3];
        $split      = explode(",",$name);
        $lastname   = $split[0];
        return strtoupper($lastname);
    }

    public function getBirthDate(){
        $bday = date( "Y-m-d" , strtotime($this->csv_data[4]));
        return $bday;
    }

    public function getSpecDate(){
        $test_date =  date( "Y-m-d" , strtotime($this->csv_data[5]));
        return $test_date;
    }

    public function getComponent(){
        //IGG (COV2IGGRES <- UCSF) , PCR(SARSCOV2)
        $comp =  $this->csv_data[9];
        return strtoupper($comp);
    }

    public function getSampleID(){
        //HEADER says "pat_id" , only relevant to stanford
        $sampleid = $this->csv_data[1];
        return $sampleid;
    }

    public function getInstitution(){
        $inst = $this->institution;
        return $inst;
    }

    public function getResult(){
        $result = $this->csv_data[14];
        return strtoupper($result);
    }
}