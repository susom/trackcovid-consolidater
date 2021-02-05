<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */

use REDCap;

$action = isset($_GET['action']) && !empty($_GET['action']) ? $_GET['action'] : null;
$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;

$chart_pid = $module->getSystemSetting('chart-pid');
$genpop_pid = $module->getSystemSetting('genpop-pid');
if (($pid != $chart_pid) && ($pid != $genpop_pid)) {
    $module->emError("This module should only be run on the Chart study and not pid $pid");
    print true;
    return;
}

$module->emDebug("Action is $action, and pid is $pid");

if ($action == "calc") {

    // Retrieve the comma separated list of records to skip - usually because those records are locked
    // and will stop the save process
    $skip_list = $module->getProjectSetting('skip-covid-window');
    $list_of_skip_records = strtolower(str_replace(' ', '', $skip_list));
    $skip_records = explode(',', $list_of_skip_records);
    $module->emDebug("List of records to skip: " . json_encode($skip_records));

    if ($pid == $genpop_pid) {
        $baseline_event = 'baseline_visit_arm_1';
        $wk2_event_name = 'followup_visit_1_arm_1';
        $saved_date_collected = 'date_of_visit';
        $saved_reservation_date = 'reservation_date';
    } else if ($pid == $chart_pid) {
        $baseline_event = 'baseline_arm_1';
        $wk2_event_name = 'wk2_arm_1';
        $saved_date_collected = 'date_collected';
        $saved_reservation_date = 'reservation_date';
    }
    $baseline_event_id = REDCap::getEventIdFromUniqueEvent($baseline_event);

    // Store the record_id, birth_date and mrn in a table track_covid_mrn_dob
    $params = array(
        'return_format' => 'array',
        'fields' => array('record_id', 'redcap_event_name', $saved_date_collected, $saved_reservation_date),
        'events' => $baseline_event
    );
    $records = REDCap::getData($params);
    $module->emDebug("This is the count of the baseline records: " . count($records));

    // If there are no records, just return
    if (empty($records)) {
        $module->emDebug("There are no records in project " . $pid . ". Skipping visit window processing");
        return true;
    }

    // Retrieve the records in wk2 so we can compare if the dates have changed. If not,
    // the windows do not need to be updated
    if ($pid)
    $params = array(
        'return_format' => 'array',
        'fields' => array('record_id', 'redcap_event_name', 'visit_window_lower', 'visit_window_upper'),
        'events' => $wk2_event_name
    );
    $wk2_dates = REDCap::getData($params);
    $module->emDebug("This is the count of the wk2 records: " . count($wk2_dates));
    $wk2_event_id = REDCap::getEventIdFromUniqueEvent($wk2_event_name);

    $lower = -$module->getProjectSetting("lower-limit");
    $upper = $module->getProjectSetting("upper-limit");
    $schedule_json = $module->getProjectSetting("window-schedule");
    $module->emDebug("Lower Limit: " . $lower . ", Upper Limit: " . $upper . ", json schedule: " . $schedule_json);

    $followup_days = json_decode($schedule_json);
    foreach($followup_days as $ndays => $event) {
        $module->emDebug("Ndays: $ndays and event name: $event");
    }

    // Loop over all records to set visit windows
    $ncnt = 0;
    foreach ($records as $record) {

        // Extract the baseline date for each record
        $record_id = $record[$baseline_event_id]['record_id'];
        $date_collected = $record[$baseline_event_id][$saved_date_collected];
        $appointment_date = $record[$baseline_event_id][$saved_reservation_date];

        // First use date_collected but if that is not set, use reservation date
        if (!empty($date_collected)) {
            $use_date = $date_collected;
        } else if (!empty($appointment_date)) {
            $use_date = $appointment_date;
        } else {
            $use_date = null;
        }

        // If we found a date to use as baseline, calculate the visit windows
        if (!is_null($use_date) and (is_null($skip_records) or !in_array($record_id, $skip_records))) {
            $this_record = calculateWindowLimits($record_id, $use_date, $wk2_dates, $wk2_event_id, $lower, $upper, $followup_days, $wk2_event_name);
            if (!empty($this_record)) {
                $save_data = '[' . $this_record . ']';
                $return = REDCap::saveData('json', $save_data);
                //$module->emDebug("Just saved $record_id, return: " . json_encode($return));
            }
        }
        $ncnt++;
        if (($ncnt % 50) == 0) {
            $module->emDebug("Just processed record $record_id for a total of $ncnt records");
        }
    }

    $module->emDebug("Done saving window dates");

    print true;
    return;
}

function calculateWindowLimits($record_id, $baseline_date, $wk2_dates, $wk2_event_id, $lower, $upper, $followup_days, $wk2_event_name) {

    global $module;

    $data_to_save = '';
    foreach($followup_days as $followup => $event_name) {
        $this_record = array();

        $lower_date = date('Y-m-d', strtotime($baseline_date . ' + ' . ($followup+$lower) . ' days'));
        $upper_date = date('Y-m-d', strtotime($baseline_date . ' + ' . ($followup+$upper) . ' days'));

        $this_record['record_id'] = $record_id;
        $this_record['redcap_event_name'] = $event_name;
        $this_record['visit_window_lower'] = $lower_date;
        $this_record['visit_window_upper'] = $upper_date;

        if (!empty($data_to_save)) {
            $data_to_save .= ',';
        }

        if ($this_record['redcap_event_name'] == $wk2_event_name) {
            if (($this_record['visit_window_lower'] == $wk2_dates[$record_id][$wk2_event_id]['visit_window_lower'])
                and ($this_record['visit_window_upper'] == $wk2_dates[$record_id][$wk2_event_id]['visit_window_upper'])) {
                break;
            }
        }

        $data_to_save .= json_encode($this_record);
    }

    return $data_to_save;
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

        <title>Calculate Window Limits</title>
    </header>
    <body>
        <container>
            <div style="width: 90%; padding: 20px">
                <h3 style="color: blue">Calculates window limits</h3>
            </div>

            <table>
                <tbody>
                    <tr>
                        <td style="padding: 20px">
                            <label><b>Perform calculation</b></label>
                        </td>
                        <td style="padding: 20px">
                            <input class="btn-md" type="button" id="calc" value="Calculate window limits" />
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
        $("#calc").bind("click", function () {

            TrackCovid.calcWindows();
        });
    });

    var TrackCovid = TrackCovid || {};

    // Make the API call back to the server to load the new config\
    TrackCovid.calcWindows = function() {

        // Set progress ball cursor
        $("body").css("cursor", "progress");

        $.ajax({
            type: "GET",
            data: {
                "action"        : "calc"
            },
            success:function(status) {
                // Return the cursor to the default
                $("body").css("cursor", "default");

                // Show the status of load
                if (status === "1") {
                    $("#status").text("Successfully calculated window dates.").css({"color": "red"});
                } else {
                    $("#status").text("Problem calculating window dates. Please contact the REDCap team.").css({"color": "red"});
                }
            }
        }).done(function (status) {
            console.log("Done from TrackCovid Consolidator for calculating window dates ");
        }).fail(function (jqXHR, textStatus, errorThrown) {
            console.log("Failed in TrackCovid Consolidator for calculating window dates");
        });

    };

</script>
