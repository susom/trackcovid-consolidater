<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

$action = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;

$module->emDebug("action $action");

if ($action == 'load') {
    $module->emDebug("About to run Stanford appointment loader");
    $status = $module->loadStanfordApptData();
    $module->emDebug("Back from appointment loader: " . $status);
    print $status;
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

        <title>Initiate TrackCovid Appointment Loader</title>
    </header>
    <body>
        <container>
            <div style="width: 90%; padding: 20px">
                <h3 style="color:blue">Automated loader for Stanford Appointment data to load appointments into TrackCovid projects.</h3>
            </div>

            <div style="padding: 20px">
                <form method="post">
                    <input class="btn-lg" type="button" id="submit" value="Load Stanford Appointment Results" />
                </form>
            </div>

            <div style="padding: 20px">
                <label id="status" />
            </div>

        </container>

    </body>
</html>

<script>

    $(function () {
        $("#submit").bind("click", function () {
            TrackCovid.loadConfig();
        });
    });


    var TrackCovid = TrackCovid || {};

    // Make the API call back to the server to load the new config\
    TrackCovid.loadConfig = function() {

        // Add a busy cursor
        $("body").css("cursor", "progress");

        $.ajax({
                type: "POST",
                data: {
                    "action"        : "load"
                },
                success:function(status) {
                    // Return the cursor back to normal
                    $("body").css("cursor", "default");

                    // Display results of load
                    if (status === "1") {
                        $("#status").text("Successfully loaded appointment data").css({"color": "red"});
                    } else {
                        $("#status").text("Problem loading appointment data. Please contact the REDCap team.").css({"color": "red"});
                    }
                }
        }).done(function (status) {
            console.log("Done from TrackCovid Appointment Loader");
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.log("Failed in TrackCovid Appointment Loader");
        });

    };

</script>
