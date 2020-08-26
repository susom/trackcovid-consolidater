<?php
namespace Stanford\TrackCovidConsolidator;
/** @var TrackCovidConsolidator $module */

class RCRecord {

    public function __construct($rc_vars) {
        foreach($rc_vars as $key => $value){
            $this->{$key} = $value;
        }
            // [record_id] => 10
            // [redcap_event_name] => baseline_visit_pcr_arm_1
// [redcap_repeat_instrument] => 
// [redcap_repeat_instance] => 
            // [name] => Aria Hudetz
            // [birthdate] => 
            // [apexmrn] => 
// [name_first] => 
            // [name_last] => 
            // [dob] => 
            // [mrn_ucsf] => 
// [zsfg_name_first] => 
            // [zsfg_name_last] => 
            // [zsfg_dob] => 
            // [mrn_zsfg] => 
            // [name_2] => Aria Hudetz
            // [testdate] => 
            // [testdate_serology] => 
// [name_fu] => 
// [name_report] => 
            // [mrn_all_sites] => 30287767
// [date_scheduled] => 
// [date_collected] => 
            // [pcr_id] => 24686411
            // [igg_id] => 24686410
        
        self::cleanData();
    }

    public function cleanData() {
        //consolidate MRN if its missing from the main MRN var
        if( empty($this->mrn_all_sites) ){
            $this->mrn_all_sites = !empty($this->mrn_ucsf) ? $this->mrn_ucsf : null ;
            $this->mrn_all_sites = !empty($this->mrn_apexmrn) ? $this->mrn_apexmrn : $this->mrn_all_sites ;
            $this->mrn_all_sites = !empty($this->mrn_zsfg) ? $this->mrn_zsfg : $this->mrn_all_sites ;
        }

        // REMOVE FORWARD SLASHES
        if( !empty($this->pcr_id) ){
            $this->pcr_id = str_replace("\\","",$this->pcr_id);
        }
        if( !empty($this->igg_id) ){
            $this->igg_id = str_replace("\\","",$this->igg_id);
        }

        // CONSOLIDATE BIRTHDAYs?
        if( empty($this->birthdate) ){
            $this->birthdate = !empty($this->dob) ? $this->dob : null;
            $this->birthdate = !empty($this->zsfg_dob) ? $this->zsfg_dob : $this->birthdate;
        }
    }

    public function getMRN(){
        return $this->mrn_all_sites;
    }

    public function getBirthDate(){
        $bday = date( "Y-m-d" , strtotime($this->birthdate));
        return $bday;
    }

    public function getDateCollected(){
        $dt = date( "Y-m-d" , strtotime($this->date_collected));
        return $dt;
    }

    public function getDateScheduled(){
        $dt = date( "Y-m-d" , strtotime($this->date_scheduled));
        return $dt;
    }


}