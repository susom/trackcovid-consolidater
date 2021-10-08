<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;
$org = isset($_POST['org']) && !empty($_POST['org']) ? $_POST['org'] : null;
$content = isset($_POST['content']) && !empty($_POST['content']) ? $_POST['content'] : null;
$pid = $module->getProjectId();
$module->emDebug("PID: " . $pid . "; action: " . $action . "; org: " . $org);

if ($action == "load" and $org == 'ucsf') {

    // First check to see if this person has permission to run the loader
    $list_of_users = $module->getSystemSetting('allowed-users-for-ucsf-loader');
    $list_of_users = strtolower(str_replace(' ', '', $list_of_users));

    $allowed_users = explode(',', $list_of_users);
    if (!in_array(USERID, $allowed_users)) {
        $msg = "User " . USERID . " is not approved to run the loader - exiting...";
        $module->emError($msg);
        print $msg;
        return;
    } else {
        $module->emDebug("User " . USERID . " is loading UCSF data");
    }

    // Since the UCSF data has a bunch of lines before and after the data, clean it up and only
    // send the portion of data that contains labs
    $results = cleanUCSFData($content);

    list($mrn_field, $birthdate_field, $baseline_event_id, $event_ids)
        = $module->setUpLoader($pid, $org);

    $mrn_records = $module->retrieveMrnsAndDob($pid, $mrn_field, $birthdate_field, $baseline_event_id);

    $status = $module->matchLabResults($mrn_records, $event_ids, $org, $results);
    $module->emDebug("Finished processing");

    $status = true;
    print $status;
    return;

} else if ($action == "load" and $org == 'stanford') {

} else if ($action <> '' or $org <> '') {
    $status = false;
    $module->emError("Error in request for action $action and org $org");
    return;
}

function cleanUCSFData($content) {

    global $module;

    /*
     * The UCSF data has a bunch of rows before and after the lab values.  So we are going to save
     * data where there is a value in the first column.
     */

    // Parse each line
    $results_raw = explode("\r\n", $content);

    $cleaned_content = array();
    $header_row = true;
    $header = array();
    foreach($results_raw as $line) {


        // Parse each field
        $fields = explode(',', $line);

        // If the second column is not empty, we found a lab result
        if (!empty($fields[1])) {
            if ($header_row) {
                $header = $fields;
                $header_row = false;
            } else {
                $cleaned_content[] = $fields;
            }
        }
    }

    // The results array has the following headers
    // OrdID	TestID	ResultID	Result	ResUnits	QAFlag	QADeltaFlag	PriID	ReqNum	EncNum	Accn	MRN	BDate	PatSex	ContID	PatType	PatLocID	OrdHospID	LabID	LabDeptID	Wrkstn	DiagCode	PhysID	PhysName	Admit	Order	OrderTech	Collect	CollectTech	Receive	ReceiveTech	BencRecTm	Result2	Result3	ResultTech	Discharge	Add on	CPP	CPPTech	PRLKU	PRLKUTech	CustomTime3	CustomTime3Tech	REMP	REMPTech	CPCB	CPCBTech	CPMB	CPMBTech	CPZ	CPZTech	PAAU	PAAUTech	CustomTime9	CustomTime9Tech	MAAU	MAAUTech	Custom Date 11	Custom Date 11Tech	Custom Date 12	Custom Date 12Tech	Custom Date 13	Custom Date 13Tech	Custom Date 14	Custom Date 14Tech	Custom Date 15	Custom Date 15Tech	Custom Date 16	Custom Date 16Tech	Custom Date 17	Custom Date 17Tech	Custom Date 18	Custom Date 18Tech	Custom Date 19	Custom Date 19Tech	Custom 1	Custom 2	Custom 3	Custom 4	Custom 5	Custom 6	Custom 7	Custom 8	Custom 9	Custom 10	Custom 11	Custom 12	Custom 13	Custom 14	Custom 15	OrderComments	ResultComments
    // This columns we are interested in are: MRN, BDate, ContID (sampleID), Collect (collection date), ResultID (Type COVG), Result (NEG)
    // Save only the data we need:
    //      0 = MRN
    //      1 = Birth Date
    //      2 = Sample ID
    //      3 = sentDate
    //      4 = Result Type (COVG or PCR)
    //      5 = Result (for COVG values are NEG or POS)

    $req_columns = array('MRN', 'BDate', 'ContID', 'ResultID', 'Result', 'Collect');
    $file_columns = array();
    foreach($req_columns as $next_field) {
        $file_columns[] = array_search($next_field, $header);
    }

    // Now save the lab data in this format
    $lab_data = array();
    foreach($cleaned_content as $row) {
        $this_lab = array();
        for($ncol = 0; $ncol < count($file_columns); $ncol++) {

            // This is a kluge for now because the column PhysName has a comma which is throwing off the column count
            if ($ncol == (count($file_columns))-1) {
                $this_lab[lower($req_columns[$ncol])] = $row[$file_columns[$ncol]+1];
            } else {
                $this_lab[lower($req_columns[$ncol])] = $row[$file_columns[$ncol]];
            }
        }
        $lab_data[] = $this_lab;
    }

    return $lab_data;
}


?>

<html>
    <header>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous"/>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/css/select2.min.css"/>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/css/bootstrap-datetimepicker.min.css">

        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/js/select2.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.15.1/moment.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/js/bootstrap-datetimepicker.min.js"></script>

        <title>Process UCSF Data</title>
    </header>
    <body>
        <container>
            <div style="width: 90%; padding: 20px">
                <h3 style="color: blue">Automated loader for UCSF data file to load TrackCovid projects.</h3>
            </div>

            <table>
                <tbody>
                    <tr>
                        <td style="padding: 20px">
                            <label><b>First, select the UCSF data file: </b></label>
                        </td>
                        <td style="padding: 20px">
                            <input class="btn-md" type="file" id="fileUpload" />
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px">
                            <label><b>Next, start the loader</b></label>
                        </td>
                        <td style="padding: 20px">
                            <input class="btn-md" type="button" id="upload" value="Load UCSF Lab Results" />
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 20px">
                            <label id="status" />
                        </td>
                    </tr>
                </tbody>
            </table>

        </container>

    </body>
</html>

<script type="text/javascript">

    $(function () {
        $("#upload").bind("click", function () {

            if (typeof (FileReader) != "undefined") {
                var reader = new FileReader();
                reader.onload = function (e) {
                    var content = e.target.result;
                    TrackCovid.loadConfig('ucsf', content);
                }
                reader.readAsText($("#fileUpload")[0].files[0]);
            } else {
                alert("This browser does not support HTML5.");
            }
        });
    });

    var TrackCovid = TrackCovid || {};

    // Make the API call back to the server to load the new config\
    TrackCovid.loadConfig = function(org, content) {

        // Set progress ball cursor
        $("body").css("cursor", "progress");

        $.ajax({
            type: "POST",
            data: {
                "org"           : org,
                "action"        : "load",
                "content"       : content
            },
            success:function(status) {
                // Return the cursor to the default
                $("body").css("cursor", "default");

                // Show the status of load
                if (status === "1") {
                    $("#status").text("Successfully loaded " + org.toUpperCase() + " data").css({"color": "red"});
                } else {
                    $("#status").text("Problem loading " + org.toUpperCase() + " data. Please contact the REDCap team.").css({"color": "red"});
                }
            }
        }).done(function (status) {
            console.log("Done from TrackCovid Consolidator for org " + org + ": " + status);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.log("Failed in TrackCovid Consolidator for org " + org);
        });

    };

</script>
