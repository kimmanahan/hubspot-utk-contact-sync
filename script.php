<?php
/*
 * Sync HubSpot contact data into the local hsid table.
 *
 * What this script does
 * 1. Connects to MySQL
 * 2. Pulls up to 100 hubspotId values from hsid
 * 3. Calls HubSpot Contacts API using each hubspotId (UTK)
 * 4. If HubSpot returns an error, deletes that row from hsid
 * 5. If HubSpot returns a contact, tries to extract vid + email
 * 6. Updates the matching hsid row with vid and email
 *
 * Notes
 * - Replace DB credentials and HUBSPOT_TOKEN before running
 * - This keeps the original behavior, but makes it safer and easier to read
 */

declare(strict_types=1);

/*
 * Database connection settings
 */
$host = 'xx';
$username = 'xx';
$password = 'xx';
$database = 'xx';

/*
 * HubSpot private app token
 */
const HUBSPOT_TOKEN = 'YOUR_TOKEN_HERE';

/*
 * How many records to process per run
 */
const BATCH_SIZE = 100;

/*
 * Open MySQL connection
 */
$mysqli = new mysqli($host, $username, $password, $database);

if ($mysqli->connect_error) {
    print 'Connection Error';
    exit;
}

/*
 * Pull a batch of hubspotId values to process. Table should be set up to store to query from
 * Ordered so the script works through the table in a stable way.
 */
$selectSql = "
    SELECT hubspotId
    FROM hsid
    ORDER BY email ASC, hsid.key DESC
    LIMIT " . BATCH_SIZE;

$selectResult = $mysqli->query($selectSql);

if ($selectResult === false) {
    echo 'Error running select query: ' . $mysqli->error;
    $mysqli->close();
    exit;
}

/*
 * Prepared statements:
 * - delete invalid HubSpot IDs
 * - update valid rows with vid + email
 */
$deleteStmt = $mysqli->prepare('DELETE FROM hsid WHERE hubspotId = ?');
$updateStmt = $mysqli->prepare('UPDATE hsid SET vid = ?, email = ? WHERE hubspotId = ?');

if ($deleteStmt === false || $updateStmt === false) {
    echo 'Error preparing statements: ' . $mysqli->error;
    $mysqli->close();
    exit;
}

/*
 * Process each hubspotId in the batch
 */
foreach ($selectResult as $row) {
    $hubtk = (string)($row['hubspotId'] ?? '');

    if ($hubtk === '') {
        continue;
    }

    /*
     * Call HubSpot contact profile endpoint using the UTK/hubspotId
     */
    $curl = curl_init();

    curl_setopt_array($curl, [
        CURLOPT_URL => 'https://api.hubapi.com/contacts/v1/contact/utk/' . rawurlencode($hubtk) . '/profile',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . HUBSPOT_TOKEN,
            'Content-Type: application/json',
        ],
    ]);

    $responseBody = curl_exec($curl);

    if ($responseBody === false) {
        /*
         * Skip this record if the API call failed at the network/cURL level
         */
        curl_close($curl);
        continue;
    }

    $httpCode = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    $apiResult = json_decode($responseBody, true);

    if (!is_array($apiResult)) {
        /*
         * Skip invalid JSON responses
         */
        continue;
    }

    /*
     * If HubSpot returns an error or non-200 response,
     * delete the invalid hubspotId from hsid.
     */
    if (
        $httpCode >= 400 ||
        (isset($apiResult['status']) && $apiResult['status'] === 'error')
    ) {
        $deleteStmt->bind_param('s', $hubtk);
        $deleteStmt->execute();
        continue;
    }

    /*
     * Try to extract vid from top-level HubSpot response
     */
    $vid = isset($apiResult['vid']) ? (int)$apiResult['vid'] : 0;

    /*
     * Try to find the best available email from the response.
     * Priority:
     * 1. Top-level properties.email.value
     * 2. Identity profiles/identities values that contain '@'
     * 3. Fallback source-label values that happen to contain email
     */
    $email = extractBestEmail($apiResult);

    /*
     * Update local row only if we found a valid vid and email
     */
    if ($vid > 0 && isValidEmailCandidate($email)) {
        $updateStmt->bind_param('iss', $vid, $email, $hubtk);
        $updateStmt->execute();

        if ($updateStmt->error) {
            echo 'Update failed for hubspotId ' . $hubtk . ': ' . $updateStmt->error . PHP_EOL;
        }
    }
}

/*
 * Cleanup
 */
$deleteStmt->close();
$updateStmt->close();
$mysqli->close();

/**
 * Find the best email candidate from a HubSpot contact payload.
 *
 * @param array<string, mixed> $contact
 */
function extractBestEmail(array $contact): string
{
    $candidates = [];

    /*
     * Preferred: standard contact property email.value
     */
    if (isset($contact['properties']['email']['value']) && is_string($contact['properties']['email']['value'])) {
        $candidates[] = $contact['properties']['email']['value'];
    }

    /*
     * Original script was pulling from identities arrays.
     * Search all identity values instead of assuming fixed indexes [0], [1], [3].
     */
    if (isset($contact['identity-profiles']) && is_array($contact['identity-profiles'])) {
        foreach ($contact['identity-profiles'] as $profile) {
            if (!is_array($profile)) {
                continue;
            }

            if (isset($profile['identities']) && is_array($profile['identities'])) {
                foreach ($profile['identities'] as $identity) {
                    if (isset($identity['value']) && is_string($identity['value'])) {
                        $candidates[] = $identity['value'];
                    }
                }
            }
        }
    }

    /*
     * Fallback: search all property versions' source-label values
     * in case email was stored there in an unusual way.
     */
    if (isset($contact['properties']) && is_array($contact['properties'])) {
        foreach ($contact['properties'] as $property) {
            if (!is_array($property) || !isset($property['versions']) || !is_array($property['versions'])) {
                continue;
            }

            foreach ($property['versions'] as $version) {
                if (isset($version['source-label']) && is_string($version['source-label'])) {
                    $candidates[] = $version['source-label'];
                }
            }
        }
    }

    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);

        if (isValidEmailCandidate($candidate)) {
            return $candidate;
        }
    }

    return '';
}

/**
 * Simple email check matching the original behavior.
 */
function isValidEmailCandidate(string $value): bool
{
    return $value !== '' && str_contains($value, '@');
}
?>
