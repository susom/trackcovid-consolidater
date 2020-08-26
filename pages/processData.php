<?php
namespace Stanford\TrackCovidConsolidator;
/** @var TrackCovidConsolidator $module */

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';
?>
<div class="pt-3 pb-5">    
    <h2>This page is a placeholder</h2>
    <p>This page does not need a UI, as the script should run via CRON</p>

    <br><br>
    
    <h3>Possible work flow</h3>
    <ol>
    <li><input type='checkbox' checked/> Find new CSV in Temp Folder</li>
    <li><input type='checkbox' checked/> Check if DB table 'track_covid_result_match' has that CSV data</li>
    <li><input type='checkbox' checked/> If not in DB load CSV to DB table.  If data in DB use that data.</li>
    <li><input type='checkbox' checked/> Delete CSV</li>
    <li><input type='checkbox' checked/> Once the cron is complete (matched records of all the related against all CSV data), Truncate the dB table</li>
    <li><input type='checkbox' /> Output file , for non-matches?</li>
    </ol>


    <br><br>

    <h3>Found CSV files in RedCap/Temp folder</h3>
    <?php
    // CSVs' will stream to Redcap/www/temp,  parse and copy to sql 'track_covid_result_match'
    $redcap_temp        = __DIR__ . "/../../../temp/";
    $exclude_columns    = array(11,12); //11th an 12th columns are DUPE

    // find csv files in temp folder and parse and match for all records
    $files = glob($redcap_temp . "*.csv");
    foreach($files as $filepath) {
        if ($handle = fopen($filepath, "r")) {
            $module->parseCSVtoDB( $filepath , $exclude_columns );

            echo "<pre>";
            print_r($filepath);
            echo "</pre>";
        }
    }

    // MATCH CURRENT PROJECT RECORDS AGAINST ALL THE RECORDS
    // SEEMS IT WOULD MAKE MORE SENSE TO MATCH CSV RECORDS TO REDCAP RECORDS NOT THE OTHER DIRECTION
    // MATCH AND SAVE 
    $module->loadProjectRecords();
    ?>
</div>
<script type="text/javascript">

    VCG = {
        currentSize: 0,
        growing: false,
        duplicateCount: 0,
        checksumMethod: <?php echo json_encode($module->getChecksumMethod()); ?>,
        validChars: <?php echo json_encode($module->getValidChars()); ?>,

        validateCode: function(code) {
        }
    };


$(document).ready(function(){

});
</script>
