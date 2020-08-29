# TrackCovidConsolidator

An EM to consolidate CSV data from UCSF / Stanford into Various TrackCovid Redcap Project Records
Right now, there is a path to call the Stanford process which will bring over a new data file in realtime
using REDCap to STARR link.  To initiate this process, call trackcovid-consolidater->loadStanfordData.

There is another path to load the UCSF data file.  This process can be initiated by calling
trackcovid-consolidater->loadUCSFData($filename).  The filename and path needs to be specified.

# Processing data to REDCap

TODO : Need to figure out where to get daily data from (CRON checks of BOX?)

Create a table in the REDCap Mysql Server to buffer the lab result data from the CSV files

<pre>
create table track_covid_result_match
(
    TRACKCOVID_ID      varchar(100) not null,
    PAT_ID             varchar(20)  null,
    PAT_MRN_ID         varchar(10)  not null,
    PAT_NAME           varchar(100) not null,
    BIRTH_DATE         date         null,
    SPEC_TAKEN_INSTANT datetime     null,
    RESULT_INSTANT     datetime     null,
    COMPONENT_ID       int          null,
    COMPONENT_NAME     varchar(50)  null,
    COMPONENT_ABBR     varchar(5)   null,
    ORD_VALUE          varchar(20)  null,
    TEST_CODE          varchar(10)  null,
    RESULT             varchar(10)  null,
    MPI_ID             varchar(50) null,
    constraint track_covid_result_match_TRACKCOVID_ID_uindex
        unique (TRACKCOVID_ID)
);
</pre>

Also, create a table in the REDCap Mysql Server to buffer the Redcap records for each project
to make it easier to query.

<pre>
create table track_covid_project_records (
    record_id                   varchar(10),
    redcap_event_name           varchar(50),
    dob                         date,
    mrn                         varchar(10),
    date_collected              date,
    location                    int,
    pcr_id                      varchar(20),
    igg_id                      varchar(20),
    lra_pcr_result              int,
    lra_pcr_date                datetime,
    lra_pcr_match_methods___1   int,
    lra_pcr_match_methods___2   int,
    lra_pcr_match_methods___3   int,
    lra_pcr_match_methods___4   int,
    lra_pcr_match_methods___5   int,
    lra_ab_result               int,
    lra_ab_date                 datetime,
    lra_ab_match_methods___1    int,
    lra_ab_match_methods___2    int,
    lra_ab_match_methods___3    int,
    lra_ab_match_methods___4    int,
    lra_ab_match_methods___5    int,
        constraint track_covid_project_records_uindex
            unique (record_id, redcap_event_name)
);
</pre>

This table will hold MRN/DOB data so we can join against all events even though the data is only in
the Baseline event.
<pre>

create table track_covid_mrn_dob (
    record_id               varchar(10) not null,
    redcap_event_name       varchar(50),
    mrn                     varchar(10) not null,
    dob                     varchar(10)
);

</pre>

# WorkFlow
1. Install EM onto all relevant REDCap Projects
1. On each project run CRON to kick off **processData** script
1. This will find any *NEW* CSV and import the data into the buffer MYSQL table (aabove)
1. Then run through all the records in the project looking for MRN + Visit Time combinations for Matches
1. Then update those records/create new record?

