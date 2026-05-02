# HubSpot UTK → Contact Sync

Resolves anonymous HubSpot tracking IDs (UTK) into identified contacts (VID + email) and stores them locally.

## What this does

- Takes HubSpot tracking IDs (UTK) stored in a local table (`hsid`)
- Calls HubSpot Contacts API
- Resolves UTK → contact (VID + email)
- Updates local table with enriched data
- Deletes invalid or stale UTKs

## Why this exists

HubSpot tracks users anonymously via UTK cookies, but:

- UTK ≠ contact
- You can't easily bulk resolve UTKs via HubSpot UI
- Attribution and analytics require identified users

This script bridges:
