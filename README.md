# TrackCovidConsolidator

An EM to match and load CSV lab results from UCSF and Stanford into various TrackCovid Redcap project records.
Right now, the Stanford process will bring over new data in realtime using REDCap to STARR link.
This data is written to a file in the REDCap temp directory, loaded into Redcap and then the file
is delete.

The Stanford process is scheduled on a cron job to run every Sunday evening at 7pm. To run the process
manually, go to the Control Center and use the webpage in the External Modules section called
'TrackCovid: Load Stanford Lab Data'.

To initiate the process for UCSF data, there is an External Module project link in each enabled project.
The link connects to a webpage where the user can select a file located on their local machine to upload
and sends the data to the Redcap server which will create a file in the Redcap temp directory. Once
the file is created, the loader will match and upload the lab results to the TrackCovid projects.

The appointment and lab data brought over from STARR are now filtered to the last 60 days.

# System Setup

The system setup consists of the following:

    1) Enter the URL to retrieve STARR data
    2) Select the pid of the Chart Redcap project
    3) Select the pid of the GenPop Redcap project
    4) Select the pid of the Proto Redcap project
    5) Enter list of sunet IDs allowed to run the UCSF automated loader

# Project Setup

The project setup consists of the following:

    1) DoB field where the Stanford patients' data is stored
    2) MRN field where the Stanford patients' data is stored
    3) DoB field where the UCSF patients' data is stored
    4) MRN field where the UCSF patients' data is stored
    5) Event where the above fields are stored

Additionally, there are sub-settings where the following fields are listed:

    1) Date Collected - Redcap field to use for the date when the sample was taken
    2) Location Collected - Redcap field to use for the loation where the sample was taken
    3) PCR Sample ID - Redcap field to use for the PCR sample ID
    4) IgG Sample ID - Redcap field to use for the IgG sample ID

These sub-setting fields can be repeated and the loader will try to match on the different versions
of fields.  For instance, GenPop does not consistently fill-in the date collected field so we also
try to match the date_scheduled field. The Proto project may use different fields when positive tests are
redone by the other organization.

# Processing data to REDCap

Several tables were created in the REDCap Mysql Server to buffer the lab result data from the CSV files
and which hold project data.  These tables are used for easy query and matching of lab results to
participants.  Having data in DB tables also helps reporting after the matching is completed.

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

This table will hold MRN/DOB data so we can join against all events even though the MRN/DoB data is only in
the one event.

<pre>
create table track_covid_mrn_dob (
    record_id               varchar(10) not null,
    redcap_event_name       varchar(50),
    mrn                     varchar(10) not null,
    dob                     varchar(10)
);
</pre>

This table will hold the matched results.

<pre>
create table track_covid_found_results (
    record_id                   varchar(10) not null,
    redcap_event_name           varchar(50) not null,
    lra_pcr_result              varchar(10),
    lra_pcr_date                varchar(20),
    lra_pcr_match_methods___1   int,
    lra_pcr_match_methods___2   int,
    lra_pcr_match_methods___3   int,
    lra_pcr_match_methods___4   int,
    lra_pcr_match_methods___5   int,
    lra_ab_result               varchar(10),
    lra_ab_date                 varchar(20),
    lra_ab_match_methods___1    int,
    lra_ab_match_methods___2    int,
    lra_ab_match_methods___3    int,
    lra_ab_match_methods___4    int,
    lra_ab_match_methods___5    int
);
</pre>

# WorkFlow

    1) Create a file in the REDCap temp directory to hold the data.  The Stanford file is named
    Stanford_mmddyyyy.csv in the temp directoy.  The UCSF file will always be named UCSF_data.csv.
    2) Read in the csv files and push the data into the DB table 'track_covid_result_match'.
    3) Retrieve the MRN/DoB from the project and push the data to the DB table 'track_covid_mrn_dob'.
    4) Retrieve REDCap records where the location collected was a Stanford location when
    loading Stanford data or the location collected was not a Stanford location when loading
    UCSF data. UCSF locations also include samples where the location taken is blank.  UCSF
    does not always fill-in the location collected so we are assuming blanks are UCSF. These
    records are pushed to DB table 'track_covid_project_records'.
    5) Match results using the criteria of MRN/sample_id/lab_type when sample_id is present.
    When sample_id is not present, the matching criteria is MRN/DoB/date_collected/lab_type.
    Once results are found, the results are pushed to DB table 'track_covid_found_results'.
    6) A difference is performed between entries in table 'track_covid_project_records' which
    holds the current Redcap data and DB table 'track_covid_found_results' which holds the
    data of the matched results.  If any lab result data is different than the project data,
    the data in REDCap will be updated.
    7) Missing results will be analyzed for:
        a) Labs taken > 7 days previously where matches were not found
        b) Labs that would match if MRN was entered or was corrected
        c) Labs that would match if DoB was entered or was corrected
        d) Labs that would match if date collected was entered or was corrected
