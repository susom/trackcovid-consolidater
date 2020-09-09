<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;
$org = isset($_POST['org']) && !empty($_POST['org']) ? $_POST['org'] : null;

$module->emDebug("action is $action and org is $org");

if ($action == "load" and $org == 'ucsf') {
    $module->emDebug("About to run UCSF load");
    $status = $module->loadUCSFData();
    $module->emDebug("Sending back this: " . $status);
    print $status;
    return;
} else if ($action == 'load' and $org == 'stanford') {
    $module->emDebug("About to run Stanford load");
    $status = $module->loadStanfordData();
    $module->emDebug("Sending back this: " . $status);
    print $status;
    return;
} else if ($action <> '' or $org <> '') {
    $status = false;
    $module->emError("Error in request for action $action and org $org");
    return;
}

$module->emDebug("Did not exit");

?>

<html>
    <header>

        <script src="https://code.jquery.com/jquery-3.5.0.js"></script>

        <title>Initiate TrackCovid Cron</title>
    </header>
    <body>
        <h4>To start the TrackCovid data loading process</h4>

        <form method="post">
            <input class="btn-lg btn-dark btn-block mt-5" type="submit" onclick="loadStanfordData()"> Match Stanford lab Results</input>
        </form>

        <form method="post">
            <input class="btn-lg btn-dark btn-block mt-5" type="submit" onclick="loadUCSFData()"> Match UCSF lab Results</input>
        </form>

        <p id="status" name="status"></p>

    </body>
</html>

<script>

    function loadStanfordData() {
        var org = 'stanford';
        TrackCovid.loadConfig(org);
    }

    function loadUCSFData() {
        var org = 'ucsf';
        TrackCovid.loadConfig(org);
    }

    var TrackCovid = TrackCovid || {};

    // Make the API call back to the server to load the new config\
    TrackCovid.loadConfig = function(org) {

        $.ajax({
                type: "POST",
                data: {
                    "org"           : org,
                    "action"        : "load"
                },
                success:function(status) {
                    if (status === "1") {
                        alert("In status ==== 1");
                        $("p").text("Successfully loaded data for " + org).css("color: red");
                    } else {
                        alert("In status !== 1");
                        $("#status").text("Problem loading data for " + org).css("color: red");
                    }
                }
        }).done(function (status) {
            console.log("Done from TrackCovid Consolidator for org " + org + ": " + status);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.log("Failed in TrackCovid Consolidator for org " + org);
        });

    };
</script>
