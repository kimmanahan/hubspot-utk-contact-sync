from pathlib import Path

sql = """-- =====================================================

-- HubSpot UTK → Contact Sync Tables

-- =====================================================

-- Core table: stores UTK → contact mapping

create table hsid (

    hubspotId varchar(255) not null primary key,

    vid int null,

    email varchar(255) null,

    created_at timestamp default current_timestamp,

    updated_at timestamp default current_timestamp on update current_timestamp,

    index idx_email (email),

    index idx_vid (vid)

);

-- Optional: raw ingestion table (for auditing / pipelines)

create table hsid_raw (

    hubspotId varchar(255) not null,

    source varchar(50),

    inserted_at timestamp default current_timestamp,

    index idx_hubspotId (hubspotId)

);

-- Optional: error tracking table

create table hsid_errors (

    hubspotId varchar(255),

    error_message varchar(500),

    response_code int,

    created_at timestamp default current_timestamp,

    index idx_hubspotId (hubspotId)

);

"""

path = Path("/mnt/data/hubspot_utk_tables.sql")

path.write_text(sql)

path
