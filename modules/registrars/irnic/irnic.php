<?php

/*
 * WHMCS IRNIC Registrar Module
 *
 * Public release:
 * - No API token or Deposit Code is hard-coded.
 * - Credentials are read only from WHMCS registrar settings.
 * - Successful routine operations are intentionally not written to Module Log.
 *
 * License: GPL-3.0-or-later
 */

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/Client.php';


/*
|--------------------------------------------------------------------------
| Module Metadata
|--------------------------------------------------------------------------
*/

function irnic_MetaData()
{
    return [
        'DisplayName' => 'IRNIC Registrar',
        'APIVersion' => '1.1',
    ];
}


/*
|--------------------------------------------------------------------------
| Module Configuration
|--------------------------------------------------------------------------
*/

function irnic_getConfigArray()
{
    return [
        'depositCode' => [
            'FriendlyName' => 'Deposit Code',
            'Type' => 'password',
            'Size' => '50',
            'Description' => '16-digit IRNIC Deposit Code',
        ],

        'irnicToken' => [
            'FriendlyName' => 'API Token',
            'Type' => 'password',
            'Size' => '100',
            'Description' => 'IRNIC API Token',
        ],

        'endpoint' => [
            'FriendlyName' => 'Endpoint',
            'Type' => 'text',
            'Size' => '80',
            'Default' => 'https://epp.nic.ir/submit',
            'Description' => 'IRNIC EPP API endpoint',
        ],
    ];
}


/*
|--------------------------------------------------------------------------
| Common Helpers
|--------------------------------------------------------------------------
*/

function irnic_buildClient($params)
{
    $endpoint = !empty($params['endpoint'])
        ? trim((string) $params['endpoint'])
        : 'https://epp.nic.ir/submit';

    return new IrnicClient(
        $endpoint,
        isset($params['depositCode']) ? $params['depositCode'] : '',
        isset($params['irnicToken']) ? $params['irnicToken'] : ''
    );
}


function irnic_getDomainName($params)
{
    if (!empty($params['domainname'])) {
        return strtolower(
            rtrim(trim((string) $params['domainname']), '.')
        );
    }

    $sld = isset($params['sld'])
        ? trim((string) $params['sld'])
        : '';

    $tld = isset($params['tld'])
        ? ltrim(trim((string) $params['tld']), '.')
        : '';

    if ($sld === '' || $tld === '') {
        throw new Exception('Unable to determine domain name.');
    }

    return strtolower($sld . '.' . $tld);
}


function irnic_getPeriodYears($params)
{
    $period = isset($params['regperiod'])
        ? (int) $params['regperiod']
        : 0;

    if ($period < 1 || $period > 5) {
        throw new Exception(
            'IRNIC registration/renewal period must be between 1 and 5 years.'
        );
    }

    return $period;
}


/*
|--------------------------------------------------------------------------
| NIC Handle
|--------------------------------------------------------------------------
|
| Registration requires a real IRNIC NIC Handle, not an email address.
| We validate the local format before any registration request is sent.
| IRNIC performs the authoritative existence/permission validation.
|
|--------------------------------------------------------------------------
*/

function irnic_getNicHandle($params)
{
    if (
        !isset($params['additionalfields']) ||
        !is_array($params['additionalfields']) ||
        empty($params['additionalfields'])
    ) {
        throw new Exception(
            'IRNIC NIC Handle is required. Enter a handle such as xx12345-irnic.'
        );
    }

    $fields = $params['additionalfields'];

    $knownLabels = [
        'ایمیل یا شناسه شما در ایرنیک',
        'شناسه شما در ایرنیک',
        'شناسه ایرنیک',
        'IRNIC Handle',
        'NIC Handle',
        'IRNIC',
    ];

    // 1) Prefer the expected additional-field labels.
    foreach ($fields as $actualKey => $actualValue) {
        $key = irnic_cleanAdditionalFieldLabel($actualKey);
        $value = trim((string) $actualValue);

        if ($value === '') {
            continue;
        }

        foreach ($knownLabels as $knownLabel) {
            if (
                $key === irnic_cleanAdditionalFieldLabel($knownLabel)
            ) {
                return irnic_validateNicHandle($value);
            }
        }
    }

    // 2) Accept a likely IRNIC-labelled field only when its value is a valid handle.
    foreach ($fields as $actualKey => $actualValue) {
        $key = irnic_cleanAdditionalFieldLabel($actualKey);
        $value = trim((string) $actualValue);

        if ($value === '') {
            continue;
        }

        $looksLikeIrnicField =
            stripos($key, 'irnic') !== false ||
            stripos($key, 'nic handle') !== false ||
            (function_exists('mb_strpos') && mb_strpos($key, 'ایرنیک') !== false) ||
            (function_exists('mb_strpos') && mb_strpos($key, 'شناسه') !== false);

        if ($looksLikeIrnicField && irnic_isValidNicHandle($value)) {
            return strtolower($value);
        }
    }

    // 3) Last-resort value scan for a valid IRNIC handle.
    foreach ($fields as $actualValue) {
        $value = trim((string) $actualValue);

        if (irnic_isValidNicHandle($value)) {
            return strtolower($value);
        }
    }

    throw new Exception(
        'A valid IRNIC NIC Handle was not found. Email addresses are not accepted for registration. Example: xx12345-irnic'
    );
}


function irnic_cleanAdditionalFieldLabel($value)
{
    $value = html_entity_decode(
        strip_tags((string) $value),
        ENT_QUOTES,
        'UTF-8'
    );

    $value = str_replace(
        ['*', "\xC2\xA0"],
        ['', ' '],
        $value
    );

    $value = preg_replace('/\s+/u', ' ', $value);

    return trim((string) $value);
}


function irnic_isValidNicHandle($value)
{
    $value = strtolower(trim((string) $value));

    if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return (bool) preg_match(
        '/^[a-z0-9][a-z0-9-]*-irnic$/i',
        $value
    );
}


function irnic_validateNicHandle($value)
{
    $value = strtolower(trim((string) $value));

    if (!irnic_isValidNicHandle($value)) {
        throw new Exception(
            'Invalid IRNIC NIC Handle. Use the NIC Handle itself (for example xx12345-irnic), not an email address.'
        );
    }

    return $value;
}


/*
|--------------------------------------------------------------------------
| Nameserver Helpers
|--------------------------------------------------------------------------
*/

function irnic_getNameserversFromParams($params)
{
    $nameservers = [];

    for ($i = 1; $i <= 5; $i++) {
        $key = 'ns' . $i;

        if (
            !isset($params[$key]) ||
            trim((string) $params[$key]) === ''
        ) {
            continue;
        }

        $nameserver = strtolower(
            rtrim(trim((string) $params[$key]), '.')
        );

        if (!in_array($nameserver, $nameservers, true)) {
            $nameservers[] = $nameserver;
        }
    }

    return $nameservers;
}


function irnic_validateNameservers(array $nameservers)
{
    if (count($nameservers) < 2) {
        throw new Exception(
            'At least two nameservers are required.'
        );
    }

    if (count($nameservers) > 4) {
        throw new Exception(
            'IRNIC supports a maximum of four nameservers.'
        );
    }

    foreach ($nameservers as $nameserver) {
        if (
            !filter_var(
                $nameserver,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME
            )
        ) {
            throw new Exception(
                'Invalid nameserver: ' . $nameserver
            );
        }
    }
}


function irnic_nameserversToWhmcs(array $nameservers)
{
    $result = [];

    for ($i = 1; $i <= 5; $i++) {
        $result['ns' . $i] =
            isset($nameservers[$i - 1])
                ? $nameservers[$i - 1]
                : '';
    }

    return $result;
}



/*
|--------------------------------------------------------------------------
| Transfer PIN Helper
|--------------------------------------------------------------------------
|
| WHMCS passes the incoming transfer code in $params['eppcode'].
| IRNIC calls this value the transfer PIN and expects it in:
|
| <extension><pin pinCode="..." /></extension>
|
|--------------------------------------------------------------------------
*/

function irnic_getTransferPin($params)
{
    $pin = isset($params['eppcode'])
        ? trim((string) $params['eppcode'])
        : '';

    if ($pin === '') {
        throw new Exception(
            'IRNIC transfer PIN is required.'
        );
    }

    if (strlen($pin) > 128) {
        throw new Exception(
            'IRNIC transfer PIN is too long.'
        );
    }

    if (preg_match('/[\x00-\x1F\x7F]/', $pin)) {
        throw new Exception(
            'IRNIC transfer PIN contains invalid characters.'
        );
    }

    return $pin;
}

/*
|--------------------------------------------------------------------------
| Result / Logging Helpers
|--------------------------------------------------------------------------
|
| To keep tblmodulelog small:
| - successful operations are not logged
| - routine GetNameservers calls are not logged
| - expected pre-registration conditions are not logged
| - only important remote failures are logged, and only compact metadata
|
|--------------------------------------------------------------------------
*/

function irnic_isEppSuccess($result)
{
    if (empty($result['success'])) {
        return false;
    }

    return in_array(
        isset($result['eppCode']) ? (string) $result['eppCode'] : '',
        ['1000', '1001'],
        true
    );
}


function irnic_logRemoteFailure(
    $action,
    $domain,
    $result,
    array $extra = []
) {
    $response = [
        'httpCode' => isset($result['httpCode'])
            ? $result['httpCode']
            : null,
        'eppCode' => isset($result['eppCode'])
            ? $result['eppCode']
            : null,
        'message' => isset($result['message'])
            ? $result['message']
            : null,
        'clTRID' => isset($result['clTRID'])
            ? $result['clTRID']
            : null,
        'svTRID' => isset($result['svTRID'])
            ? $result['svTRID']
            : null,
    ];

    logModuleCall(
        'irnic',
        $action,
        array_merge(
            ['domain' => $domain],
            $extra
        ),
        $response,
        null
    );
}


function irnic_formatRemoteError($prefix, $result)
{
    $message =
        $prefix .
        ' HTTP: ' .
        (isset($result['httpCode']) ? $result['httpCode'] : 'NONE') .
        ' / EPP: ' .
        (isset($result['eppCode']) ? $result['eppCode'] : 'NONE') .
        ' - ' .
        (isset($result['message']) ? $result['message'] : 'Unknown error');

    if (!empty($result['svTRID'])) {
        $message .= ' / svTRID: ' . $result['svTRID'];
    }

    return $message;
}


/*
|--------------------------------------------------------------------------
| Pending Nameserver Storage
|--------------------------------------------------------------------------
|
| WHMCS does not persist the registrar NS fields for an object that does not
| yet exist at IRNIC. We stage one row per domain_id and delete it when no
| longer needed. The table existence check is cached per PHP request.
|
|--------------------------------------------------------------------------
*/

function irnic_pendingTableReady($createIfMissing = false)
{
    static $ready = null;

    if ($ready === true) {
        return true;
    }

    if ($ready === null) {
        $ready = Capsule::schema()->hasTable(
            'mod_irnic_pending_nameservers'
        );
    }

    if (!$ready && $createIfMissing) {
        try {
            Capsule::schema()->create(
                'mod_irnic_pending_nameservers',
                function ($table) {
                    $table->unsignedInteger('domain_id')->primary();
                    $table->string('domain', 255)->nullable();
                    $table->string('ns1', 255)->nullable();
                    $table->string('ns2', 255)->nullable();
                    $table->string('ns3', 255)->nullable();
                    $table->string('ns4', 255)->nullable();
                    $table->string('ns5', 255)->nullable();
                    $table->dateTime('updated_at')->nullable();
                }
            );

            $ready = true;
        } catch (Throwable $e) {
            // A concurrent request may have created it first.
            $ready = Capsule::schema()->hasTable(
                'mod_irnic_pending_nameservers'
            );

            if (!$ready) {
                throw $e;
            }
        }
    }

    return (bool) $ready;
}


function irnic_savePendingNameservers(
    $params,
    array $nameservers
) {
    $domainId = isset($params['domainid'])
        ? (int) $params['domainid']
        : 0;

    if ($domainId <= 0) {
        throw new Exception(
            'WHMCS Domain ID is missing.'
        );
    }

    if (!irnic_pendingTableReady(true)) {
        throw new Exception(
            'Unable to initialize pending nameserver storage.'
        );
    }

    // Keep the table bounded if old abandoned orders remain forever.
    Capsule::table(
        'mod_irnic_pending_nameservers'
    )
        ->where(
            'updated_at',
            '<',
            date('Y-m-d H:i:s', time() - 15552000) // 180 days
        )
        ->delete();

    Capsule::table(
        'mod_irnic_pending_nameservers'
    )->updateOrInsert(
        ['domain_id' => $domainId],
        [
            'domain' => irnic_getDomainName($params),
            'ns1' => isset($nameservers[0]) ? $nameservers[0] : null,
            'ns2' => isset($nameservers[1]) ? $nameservers[1] : null,
            'ns3' => isset($nameservers[2]) ? $nameservers[2] : null,
            'ns4' => isset($nameservers[3]) ? $nameservers[3] : null,
            'ns5' => isset($nameservers[4]) ? $nameservers[4] : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]
    );
}


function irnic_getPendingNameservers($params)
{
    if (!irnic_pendingTableReady(false)) {
        return [];
    }

    $domainId = isset($params['domainid'])
        ? (int) $params['domainid']
        : 0;

    if ($domainId <= 0) {
        return [];
    }

    $row = Capsule::table(
        'mod_irnic_pending_nameservers'
    )
        ->where('domain_id', $domainId)
        ->first();

    if (!$row) {
        return [];
    }

    $nameservers = [];

    for ($i = 1; $i <= 5; $i++) {
        $key = 'ns' . $i;

        if (
            isset($row->$key) &&
            trim((string) $row->$key) !== ''
        ) {
            $nameservers[] = strtolower(
                rtrim(trim((string) $row->$key), '.')
            );
        }
    }

    return array_values(array_unique($nameservers));
}


function irnic_deletePendingNameservers($params)
{
    if (!irnic_pendingTableReady(false)) {
        return;
    }

    $domainId = isset($params['domainid'])
        ? (int) $params['domainid']
        : 0;

    if ($domainId <= 0) {
        return;
    }

    Capsule::table(
        'mod_irnic_pending_nameservers'
    )
        ->where('domain_id', $domainId)
        ->delete();
}


function irnic_isPendingStatus($params)
{
    $status = isset($params['status'])
        ? strtolower(trim((string) $params['status']))
        : '';

    return in_array(
        $status,
        [
            'pending',
            'pending registration',
        ],
        true
    );
}


/*
|--------------------------------------------------------------------------
| Register Domain
|--------------------------------------------------------------------------
*/

function irnic_RegisterDomain($params)
{
    try {
        $domain = irnic_getDomainName($params);
        $period = irnic_getPeriodYears($params);

        // Strict format validation before sending a chargeable Create request.
        $nicHandle = irnic_getNicHandle($params);

        $nameservers =
            irnic_getNameserversFromParams($params);

        if (count($nameservers) < 2) {
            $staged = irnic_getPendingNameservers($params);

            if (count($staged) >= 2) {
                $nameservers = $staged;
            }
        }

        irnic_validateNameservers($nameservers);

        $client = irnic_buildClient($params);

        $result = $client->registerDomain(
            $domain,
            $period,
            $nicHandle,
            $nameservers
        );

        if (irnic_isEppSuccess($result)) {
            // 1000 means completed now. 1001 may still be pending at IRNIC.
            if ((string) $result['eppCode'] === '1000') {
                irnic_deletePendingNameservers($params);
            }

            return [];
        }

        irnic_logRemoteFailure(
            'RegisterDomainFailure',
            $domain,
            $result,
            [
                'period' => $period,
                'nicHandle' => $nicHandle,
                'nameservers' => $nameservers,
            ]
        );

        return [
            'error' => irnic_formatRemoteError(
                'IRNIC registration failed.',
                $result
            ),
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}




/*
|--------------------------------------------------------------------------
| Get EPP / Transfer PIN
|--------------------------------------------------------------------------
|
| WHMCS calls this function when the client/admin requests the EPP code for
| an outbound transfer.
|
| IRNIC does not return the PIN in the API response. A successful request
| causes IRNIC to email the transfer PIN to the current domain holder.
|
|--------------------------------------------------------------------------
*/

function irnic_GetEPPCode($params)
{
    try {
        $domain = irnic_getDomainName($params);
        $client = irnic_buildClient($params);

        $result = $client->requestTransferPin($domain);

        if (irnic_isEppSuccess($result)) {
            /*
             * Do not return an eppcode value.
             *
             * WHMCS treats success-without-code as a registrar workflow where
             * the code is delivered directly to the registrant. That matches
             * IRNIC: the transfer PIN is emailed to the current domain holder.
             */
            return [];
        }

        irnic_logRemoteFailure(
            'GetEPPCodeFailure',
            $domain,
            $result
        );

        return [
            'error' => irnic_formatRemoteError(
                'Unable to request IRNIC transfer PIN.',
                $result
            ),
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Transfer Domain
|--------------------------------------------------------------------------
|
| IRNIC incoming transfer is not a standard <transfer> EPP command.
| According to IRNIC API documentation, the request is created with
| domain:update, the new contact handles, authInfo and an extension PIN.
|
| The current holder must approve the request via the IRNIC email flow.
|
|--------------------------------------------------------------------------
*/

function irnic_TransferDomain($params)
{
    try {
        $domain = irnic_getDomainName($params);

        // The new holder/contact must be a valid IRNIC NIC Handle.
        $nicHandle = irnic_getNicHandle($params);

        // WHMCS supplies the transfer code as eppcode.
        $transferPin = irnic_getTransferPin($params);

        $client = irnic_buildClient($params);

        $result = $client->transferDomain(
            $domain,
            $nicHandle,
            $transferPin
        );

        if (irnic_isEppSuccess($result)) {
            return [];
        }

        irnic_logRemoteFailure(
            'TransferDomainFailure',
            $domain,
            $result,
            [
                'nicHandle' => $nicHandle,
                // Do not log the transfer PIN.
            ]
        );

        return [
            'error' => irnic_formatRemoteError(
                'IRNIC transfer request failed.',
                $result
            ),
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}

/*
|--------------------------------------------------------------------------
| Renew Domain
|--------------------------------------------------------------------------
*/

function irnic_RenewDomain($params)
{
    try {
        $domain = irnic_getDomainName($params);
        $period = irnic_getPeriodYears($params);

        $client = irnic_buildClient($params);

        $result = $client->renewDomain(
            $domain,
            $period
        );

        if (irnic_isEppSuccess($result)) {
            return [];
        }

        irnic_logRemoteFailure(
            'RenewDomainFailure',
            $domain,
            $result,
            ['period' => $period]
        );

        return [
            'error' => irnic_formatRemoteError(
                'IRNIC renewal failed.',
                $result
            ),
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Get Nameservers
|--------------------------------------------------------------------------
|
| This function intentionally creates no module log entries.
|
|--------------------------------------------------------------------------
*/

function irnic_GetNameservers($params)
{
    try {
        // Before registration, serve the staged values without an IRNIC API call.
        if (irnic_isPendingStatus($params)) {
            $staged = irnic_getPendingNameservers($params);

            if (!empty($staged)) {
                return irnic_nameserversToWhmcs($staged);
            }

            return irnic_nameserversToWhmcs([]);
        }

        $domain = irnic_getDomainName($params);
        $client = irnic_buildClient($params);

        $result = $client->getNameservers($domain);

        if (
            isset($result['eppCode']) &&
            (string) $result['eppCode'] === '2303'
        ) {
            return irnic_nameserversToWhmcs(
                irnic_getPendingNameservers($params)
            );
        }

        if (empty($result['success'])) {
            return [
                'error' => irnic_formatRemoteError(
                    'Unable to get nameservers.',
                    $result
                ),
            ];
        }

        // If an earlier registration returned 1001, successful Info proves it exists now.
        irnic_deletePendingNameservers($params);

        return irnic_nameserversToWhmcs(
            isset($result['nameservers'])
                ? $result['nameservers']
                : []
        );

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Save Nameservers
|--------------------------------------------------------------------------
*/

function irnic_SaveNameservers($params)
{
    try {
        $domain = irnic_getDomainName($params);

        $nameservers =
            irnic_getNameserversFromParams($params);

        irnic_validateNameservers($nameservers);

        // Always keep a local copy until a remote update definitely succeeds.
        irnic_savePendingNameservers(
            $params,
            $nameservers
        );

        // No useless EPP Info/Update call for an unregistered pending domain.
        if (irnic_isPendingStatus($params)) {
            return [];
        }

        $client = irnic_buildClient($params);

        $result = $client->saveNameservers(
            $domain,
            $nameservers
        );

        // Object not found means this is still effectively pre-registration.
        if (
            isset($result['eppCode']) &&
            (string) $result['eppCode'] === '2303'
        ) {
            return [];
        }

        if (irnic_isEppSuccess($result)) {
            irnic_deletePendingNameservers($params);
            return [];
        }

        irnic_logRemoteFailure(
            'SaveNameserversFailure',
            $domain,
            $result,
            ['nameservers' => $nameservers]
        );

        return [
            'error' => irnic_formatRemoteError(
                'Unable to update nameservers.',
                $result
            ),
        ];

    } catch (Throwable $e) {
        return [
            'error' => $e->getMessage(),
        ];
    }
}
