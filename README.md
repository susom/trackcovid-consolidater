# TrackCovidConsolidator

An EM to consolidate CSV data from UCSF / Stanford into Various TrackCovid Redcap Project Records

# Processing data to REDCap

TODO : Need to figure out where to get daily data from (CRON checks of BOX?)

Create a table in the REDCap Mysql Server to buffer the data from CSV

<pre>
create table track_covid_result_match
(
    TRACKCOVID_ID      varchar(100) not null,
    PAT_MRN_ID         int          not null,
    PAT_NAME           varchar(50)  not null,
    BIRTH_DATE         date         null,
    SPEC_TAKEN_INSTANT datetime     null,
    RESULT_INSTANT     datetime     null,
    COMPONENT_ID       int          null,
    COMPONENT_NAME     varchar(50)  null,
    COMPONENT_ABBR     varchar(5)   null,
    ORD_VALUE          varchar(20)  null,
    PAT_ID             varchar(20)  null,
    TEST_CODE          varchar(10)  null,
    RESULT             varchar(10)  null,
    csv_file           varchar(255) null,
    constraint track_covid_result_match_TRACKCOVID_ID_uindex
        unique (TRACKCOVID_ID)
);

</pre>

# WorkFlow
1. Install EM onto all relevant REDCap Projects
1. On each project run CRON to kick off **processData** script
1. This will find any *NEW* CSV and import the data into the buffer MYSQL table (aabove)
1. Then run through all the records in the project looking for MRN + Visit Time combinations for Matches
1. Then update those records/create new record?

