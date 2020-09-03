<?php
namespace Stanford\TrackCovidConsolidator;
/** @var \Stanford\TrackCovidConsolidator\TrackCovidConsolidator $module */


?>

<html>
<header>
    <title>Initiate TrackCovid Cron</title>
</header>
<body>
    <h4>To start the TrackCovid data loading process</h4>
    <form method="post">
        <button type="submit" value="submitData" onclick="<?php $module->loadStanfordData(); ?>">Match Stanford lab Results</button>
    </form>

    <!--
    <form method="post">
        <button type="submit" value="submitData" onclick="< ?php $module->loadUCSFData(); ? >">Match UCSF lab Results</button>
    </form>
    -->

</body>
</html>
