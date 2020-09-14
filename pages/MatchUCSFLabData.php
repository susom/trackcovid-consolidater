<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;
$org = isset($_POST['org']) && !empty($_POST['org']) ? $_POST['org'] : null;
$content = isset($_POST['content']) && !empty($_POST['content']) ? $_POST['content'] : null;

if ($action == "load" and $org == 'ucsf') {

    // Create a temporary file in the /tmp directory
    $ucsfDataFile = APP_PATH_TEMP . "UCSF_data.csv";
    $fileStatus = file_put_contents($ucsfDataFile, $content);
    if (!$fileStatus) {
        $this->emError("Could not create file $ucsfDataFile in Redcap temp directory");
        print false;
        return;
    } else {
        $module->emDebug("About to run UCSF load");
        $status = $module->loadUCSFData();
        $module->emDebug("Sending back this: " . $status);
        print $status;
        return;
    }
} else if ($action <> '' or $org <> '') {
    $status = false;
    $module->emError("Error in request for action $action and org $org");
    return;
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

            // Set progress ball cursor
            $("body").css("cursor", "progress");

            var regex = /^([a-zA-Z0-9\s_\\.\-:])+(.csv|.txt)$/;
            if (regex.test($("#fileUpload").val().toLowerCase())) {
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
            } else {
                alert("Please upload a valid CSV file.");
            }
        });
    });

    var TrackCovid = TrackCovid || {};

    // Make the API call back to the server to load the new config\
    TrackCovid.loadConfig = function(org, content) {

        $.ajax({
            type: "POST",
            data: {
                "org"           : org,
                "action"        : "load",
                "content"       : content
            },
            success:function(status) {
                $("body").css("cursor", "default");
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
