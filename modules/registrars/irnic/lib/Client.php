<?php

/*
 * IRNIC API client used by the WHMCS registrar module.
 *
 * Authentication:
 *   HTTP: Authorization: Bearer <API_TOKEN>
 *   EPP : <domain:authInfo><domain:pw>DEPOSIT_CODE</domain:pw></domain:authInfo>
 *
 * No credential is hard-coded in this file.
 *
 * License: GPL-3.0-or-later
 */

final class IrnicClient
{
    const EPP_NS = 'urn:ietf:params:xml:ns:epp-1.0';
    const DOMAIN_NS = 'http://epp.nic.ir/ns/domain-1.0';

    private $endpoint;
    private $depositCode;
    private $token;

    public function __construct(
        $endpoint,
        $depositCode,
        $token
    ) {
        $this->endpoint = $this->validateEndpoint($endpoint);
        $this->depositCode = $this->validateDepositCode($depositCode);
        $this->token = $this->validateToken($token);
    }


    /*
    |--------------------------------------------------------------------------
    | Domain Check
    |--------------------------------------------------------------------------
    |
    | Kept as an internal capability for future availability integration.
    | No admin "Test Domain Check" button is exposed by irnic.php.
    |
    |--------------------------------------------------------------------------
    */

    public function domainCheck($domain)
    {
        $domain = $this->normalizeDomain($domain);

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<check>' .
                        '<domain:check xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            $this->buildAuthInfo() .
                        '</domain:check>' .
                    '</check>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('CHECK')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['available'] = null;
        $result['domain'] = $domain;

        if (!$result['success']) {
            return $result;
        }

        $xmlResponse = $this->loadXml($response['body']);

        if ($xmlResponse === null) {
            return $result;
        }

        $xmlResponse->registerXPathNamespace(
            'domain',
            self::DOMAIN_NS
        );

        $nodes = $xmlResponse->xpath(
            '//domain:chkData/domain:cd/domain:name'
        );

        if (!empty($nodes)) {
            foreach ($nodes as $node) {
                $returnedDomain = strtolower(
                    trim((string) $node)
                );

                if ($returnedDomain !== $domain) {
                    continue;
                }

                $attributes = $node->attributes();

                if (isset($attributes['avail'])) {
                    $result['available'] =
                        ((string) $attributes['avail'] === '1');
                }

                break;
            }
        }

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | Register Domain
    |--------------------------------------------------------------------------
    */

    public function registerDomain(
        $domain,
        $periodYears,
        $nicHandle,
        array $nameservers
    ) {
        $domain = $this->normalizeDomain($domain);
        $periodMonths = $this->yearsToMonths($periodYears);
        $nicHandle = $this->validateNicHandle($nicHandle);
        $nameservers = $this->normalizeNameservers($nameservers);

        $nsXml = $this->buildNameserversXml($nameservers);
        $handleXml = $this->xmlEscape($nicHandle);

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<create>' .
                        '<domain:create xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            '<domain:period unit="m">' .
                                $periodMonths .
                            '</domain:period>' .
                            '<domain:ns>' .
                                $nsXml .
                            '</domain:ns>' .
                            '<domain:contact type="holder">' .
                                $handleXml .
                            '</domain:contact>' .
                            '<domain:contact type="admin">' .
                                $handleXml .
                            '</domain:contact>' .
                            '<domain:contact type="tech">' .
                                $handleXml .
                            '</domain:contact>' .
                            '<domain:contact type="bill">' .
                                $handleXml .
                            '</domain:contact>' .
                            '<domain:agreement>true</domain:agreement>' .
                            $this->buildAuthInfo() .
                        '</domain:create>' .
                    '</create>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('CREATE')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;

        if ($result['success']) {
            $dates = $this->parseCreateDates(
                $response['body']
            );

            $result['registrationDate'] =
                $dates['registrationDate'];

            $result['expiryDate'] =
                $dates['expiryDate'];
        }

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | Domain Info
    |--------------------------------------------------------------------------
    */

    public function domainInfo($domain)
    {
        $domain = $this->normalizeDomain($domain);

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<info>' .
                        '<domain:info xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            $this->buildAuthInfo() .
                        '</domain:info>' .
                    '</info>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('INFO')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;
        $result['nameservers'] = [];
        $result['expiryDate'] = null;
        $result['registrationDate'] = null;

        if (!$result['success']) {
            return $result;
        }

        $xmlResponse = $this->loadXml(
            $response['body']
        );

        if ($xmlResponse === null) {
            return $result;
        }

        $xmlResponse->registerXPathNamespace(
            'domain',
            self::DOMAIN_NS
        );

        $this->appendNameserversFromXpath(
            $xmlResponse,
            '//domain:infData/domain:ns/domain:hostAttr/domain:hostName',
            $result['nameservers']
        );

        $this->appendNameserversFromXpath(
            $xmlResponse,
            '//domain:infData/domain:ns/domain:hostObj',
            $result['nameservers']
        );

        $expiryNodes = $xmlResponse->xpath(
            '//domain:infData/domain:exDate'
        );

        if (!empty($expiryNodes)) {
            $result['expiryDate'] =
                $this->normalizeDate(
                    (string) $expiryNodes[0]
                );
        }

        $creationNodes = $xmlResponse->xpath(
            '//domain:infData/domain:crDate'
        );

        if (!empty($creationNodes)) {
            $result['registrationDate'] =
                $this->normalizeDate(
                    (string) $creationNodes[0]
                );
        }

        return $result;
    }


    public function getNameservers($domain)
    {
        return $this->domainInfo($domain);
    }


    /*
    |--------------------------------------------------------------------------
    | Save Nameservers
    |--------------------------------------------------------------------------
    |
    | IRNIC update order is ADD, REM, CHG.
    | authInfo belongs inside domain:chg.
    |
    |--------------------------------------------------------------------------
    */

    public function saveNameservers(
        $domain,
        array $nameservers
    ) {
        $domain = $this->normalizeDomain($domain);
        $desired = $this->normalizeNameservers($nameservers);

        $currentInfo = $this->domainInfo($domain);

        if (!$currentInfo['success']) {
            return $currentInfo;
        }

        $current = isset($currentInfo['nameservers'])
            ? array_values(
                array_unique(
                    array_map(
                        'strtolower',
                        $currentInfo['nameservers']
                    )
                )
            )
            : [];

        $add = array_values(
            array_diff($desired, $current)
        );

        $remove = array_values(
            array_diff($current, $desired)
        );

        if (empty($add) && empty($remove)) {
            return [
                'success' => true,
                'httpCode' => 200,
                'eppCode' => '1000',
                'message' => 'Nameservers are already up to date.',
                'clTRID' => null,
                'svTRID' => null,
                'headers' => '',
                'body' => '',
                'nameservers' => $desired,
                'addedNameservers' => [],
                'removedNameservers' => [],
            ];
        }

        $changeXml = '';

        if (!empty($add)) {
            $changeXml .=
                '<domain:add>' .
                    '<domain:ns>' .
                        $this->buildNameserversXml($add) .
                    '</domain:ns>' .
                '</domain:add>';
        }

        if (!empty($remove)) {
            $changeXml .=
                '<domain:rem>' .
                    '<domain:ns>' .
                        $this->buildNameserversXml($remove) .
                    '</domain:ns>' .
                '</domain:rem>';
        }

        // IRNIC update examples place authInfo directly under domain:update.
        $changeXml .=
            $this->buildAuthInfo();

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<update>' .
                        '<domain:update xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            $changeXml .
                        '</domain:update>' .
                    '</update>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('UPDATE')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;
        $result['nameservers'] = $desired;
        $result['addedNameservers'] = $add;
        $result['removedNameservers'] = $remove;

        return $result;
    }




    /*
    |--------------------------------------------------------------------------
    | Request Transfer PIN / EPP Code
    |--------------------------------------------------------------------------
    |
    | IRNIC calls this operation "opening the transfer lock".
    |
    | The API does not return the PIN in the response. On success, IRNIC sends
    | the transfer PIN by email to the current domain holder.
    |
    |--------------------------------------------------------------------------
    */

    public function requestTransferPin($domain)
    {
        $domain = $this->normalizeDomain($domain);

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<update>' .
                        '<domain:update xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            $this->buildAuthInfo() .
                        '</domain:update>' .
                    '</update>' .
                    '<extension>' .
                        '<pin op="req" />' .
                    '</extension>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('GETEPP')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | Transfer Domain
    |--------------------------------------------------------------------------
    |
    | IRNIC transfer request:
    |
    | - domain:update
    | - new holder/admin/tech/bill contact handles
    | - domain:authInfo containing the Deposit Code
    | - extension pinCode containing the transfer PIN from WHMCS eppcode
    |
    | The current holder must still approve the transfer through IRNIC's
    | confirmation flow.
    |
    |--------------------------------------------------------------------------
    */

    public function transferDomain(
        $domain,
        $nicHandle,
        $transferPin
    ) {
        $domain = $this->normalizeDomain($domain);
        $nicHandle = $this->validateNicHandle($nicHandle);
        $transferPin = $this->validateTransferPin($transferPin);

        $handleXml = $this->xmlEscape($nicHandle);
        $pinXml = $this->xmlEscape($transferPin);

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<update>' .
                        '<domain:update xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            '<domain:chg>' .
                                '<domain:contact type="holder">' .
                                    $handleXml .
                                '</domain:contact>' .
                                '<domain:contact type="admin">' .
                                    $handleXml .
                                '</domain:contact>' .
                                '<domain:contact type="tech">' .
                                    $handleXml .
                                '</domain:contact>' .
                                '<domain:contact type="bill">' .
                                    $handleXml .
                                '</domain:contact>' .
                            '</domain:chg>' .
                            $this->buildAuthInfo() .
                        '</domain:update>' .
                    '</update>' .
                    '<extension>' .
                        '<pin pinCode="' .
                            $pinXml .
                        '" />' .
                    '</extension>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('TRANSFER')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | Renew Domain
    |--------------------------------------------------------------------------
    */

    public function renewDomain(
        $domain,
        $periodYears
    ) {
        $domain = $this->normalizeDomain($domain);
        $periodMonths = $this->yearsToMonths($periodYears);

        // IRNIC requires the current registry expiry date in Renew.
        $info = $this->domainInfo($domain);

        if (!$info['success']) {
            return $info;
        }

        if (empty($info['expiryDate'])) {
            throw new Exception(
                'Unable to determine current IRNIC domain expiry date.'
            );
        }

        $xml =
            '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' .
            '<epp xmlns="' . self::EPP_NS . '">' .
                '<command>' .
                    '<renew>' .
                        '<domain:renew xmlns:domain="' . self::DOMAIN_NS . '">' .
                            '<domain:name>' .
                                $this->xmlEscape($domain) .
                            '</domain:name>' .
                            '<domain:curExpDate>' .
                                $this->xmlEscape($info['expiryDate']) .
                            '</domain:curExpDate>' .
                            '<domain:period unit="m">' .
                                $periodMonths .
                            '</domain:period>' .
                            $this->buildAuthInfo() .
                        '</domain:renew>' .
                    '</renew>' .
                    '<clTRID>' .
                        $this->xmlEscape($this->makeClTRID('RENEW')) .
                    '</clTRID>' .
                '</command>' .
            '</epp>';

        $response = $this->sendRequest($xml);
        $result = $this->parseBaseResponse($response);

        $result['domain'] = $domain;

        return $result;
    }


    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    private function sendRequest($xml)
    {
        $ch = curl_init();

        if ($ch === false) {
            throw new Exception(
                'Unable to initialize cURL.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_URL => $this->endpoint,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $xml,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $this->token,
                    'User-Agent: IRNIC_EPP_Client_Sample',
                    'Content-Type: application/xml',
                    'Accept: application/xml',
                ],
            ]
        );

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);

            curl_close($ch);

            throw new Exception(
                'IRNIC cURL Error (' .
                $errno .
                '): ' .
                $error
            );
        }

        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );

        $headerSize = (int) curl_getinfo(
            $ch,
            CURLINFO_HEADER_SIZE
        );

        curl_close($ch);

        return [
            'httpCode' => $httpCode,
            'headers' => substr(
                $rawResponse,
                0,
                $headerSize
            ),
            'body' => substr(
                $rawResponse,
                $headerSize
            ),
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Response Parsing
    |--------------------------------------------------------------------------
    */

    private function parseBaseResponse(array $response)
    {
        $result = [
            'success' => false,
            'httpCode' => isset($response['httpCode'])
                ? (int) $response['httpCode']
                : 0,
            'eppCode' => null,
            'message' => null,
            'clTRID' => null,
            'svTRID' => null,
            'headers' => isset($response['headers'])
                ? $response['headers']
                : '',
            'body' => isset($response['body'])
                ? $response['body']
                : '',
        ];

        $xml = $this->loadXml($result['body']);

        if ($xml === null) {
            $result['message'] =
                'Unable to parse IRNIC XML response.';

            return $result;
        }

        $xml->registerXPathNamespace(
            'epp',
            self::EPP_NS
        );

        $resultNodes = $xml->xpath(
            '//epp:response/epp:result'
        );

        if (!empty($resultNodes)) {
            $attributes = $resultNodes[0]->attributes();

            if (isset($attributes['code'])) {
                $result['eppCode'] =
                    (string) $attributes['code'];
            }
        }

        $messageNodes = $xml->xpath(
            '//epp:response/epp:result/epp:msg'
        );

        if (!empty($messageNodes)) {
            $result['message'] =
                trim((string) $messageNodes[0]);
        }

        $clNodes = $xml->xpath(
            '//epp:response/epp:trID/epp:clTRID'
        );

        if (!empty($clNodes)) {
            $result['clTRID'] =
                trim((string) $clNodes[0]);
        }

        $svNodes = $xml->xpath(
            '//epp:response/epp:trID/epp:svTRID'
        );

        if (!empty($svNodes)) {
            $result['svTRID'] =
                trim((string) $svNodes[0]);
        }

        $result['success'] =
            $result['httpCode'] === 200 &&
            in_array(
                (string) $result['eppCode'],
                ['1000', '1001'],
                true
            );

        return $result;
    }


    private function parseCreateDates($body)
    {
        $result = [
            'registrationDate' => null,
            'expiryDate' => null,
        ];

        $xml = $this->loadXml($body);

        if ($xml === null) {
            return $result;
        }

        $xml->registerXPathNamespace(
            'domain',
            self::DOMAIN_NS
        );

        $creationNodes = $xml->xpath(
            '//domain:creData/domain:crDate'
        );

        if (!empty($creationNodes)) {
            $result['registrationDate'] =
                $this->normalizeDate(
                    (string) $creationNodes[0]
                );
        }

        $expiryNodes = $xml->xpath(
            '//domain:creData/domain:exDate'
        );

        if (!empty($expiryNodes)) {
            $result['expiryDate'] =
                $this->normalizeDate(
                    (string) $expiryNodes[0]
                );
        }

        return $result;
    }


    private function appendNameserversFromXpath(
        $xml,
        $xpath,
        array &$nameservers
    ) {
        $nodes = $xml->xpath($xpath);

        if (empty($nodes)) {
            return;
        }

        foreach ($nodes as $node) {
            $host = strtolower(
                rtrim(trim((string) $node), '.')
            );

            if (
                $host !== '' &&
                !in_array($host, $nameservers, true)
            ) {
                $nameservers[] = $host;
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | XML Builders / Validation
    |--------------------------------------------------------------------------
    */

    private function buildAuthInfo()
    {
        return
            '<domain:authInfo>' .
                '<domain:pw>' .
                    $this->xmlEscape($this->depositCode) .
                '</domain:pw>' .
            '</domain:authInfo>';
    }


    private function buildNameserversXml(array $nameservers)
    {
        $xml = '';

        foreach ($nameservers as $nameserver) {
            $xml .=
                '<domain:hostAttr>' .
                    '<domain:hostName>' .
                        $this->xmlEscape($nameserver) .
                    '</domain:hostName>' .
                '</domain:hostAttr>';
        }

        return $xml;
    }


    private function normalizeNameservers(array $nameservers)
    {
        $clean = [];

        foreach ($nameservers as $nameserver) {
            $nameserver = strtolower(
                rtrim(trim((string) $nameserver), '.')
            );

            if ($nameserver === '') {
                continue;
            }

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

            if (!in_array($nameserver, $clean, true)) {
                $clean[] = $nameserver;
            }
        }

        if (count($clean) < 2) {
            throw new Exception(
                'IRNIC requires at least two nameservers.'
            );
        }

        if (count($clean) > 4) {
            throw new Exception(
                'IRNIC supports a maximum of four nameservers.'
            );
        }

        return array_values($clean);
    }


    private function normalizeDomain($domain)
    {
        $domain = strtolower(
            rtrim(trim((string) $domain), '.')
        );

        if ($domain === '') {
            throw new Exception(
                'Domain name is empty.'
            );
        }

        if (
            preg_match('/[^\x20-\x7E]/', $domain) &&
            function_exists('idn_to_ascii')
        ) {
            $ascii = idn_to_ascii(
                $domain,
                IDNA_DEFAULT,
                defined('INTL_IDNA_VARIANT_UTS46')
                    ? INTL_IDNA_VARIANT_UTS46
                    : 0
            );

            if ($ascii !== false) {
                $domain = strtolower($ascii);
            }
        }

        if (
            !filter_var(
                $domain,
                FILTER_VALIDATE_DOMAIN,
                FILTER_FLAG_HOSTNAME
            )
        ) {
            throw new Exception(
                'Invalid domain name: ' . $domain
            );
        }

        if (
            substr($domain, -3) !== '.ir'
        ) {
            throw new Exception(
                'This registrar module only accepts .ir domains.'
            );
        }

        return $domain;
    }


    private function validateNicHandle($value)
    {
        $value = strtolower(
            trim((string) $value)
        );

        if (
            !preg_match(
                '/^[a-z0-9][a-z0-9-]*-irnic$/i',
                $value
            )
        ) {
            throw new Exception(
                'Invalid IRNIC NIC Handle. Example: xx12345-irnic'
            );
        }

        return $value;
    }



    private function validateTransferPin($transferPin)
    {
        $transferPin = trim((string) $transferPin);

        if ($transferPin === '') {
            throw new Exception(
                'IRNIC transfer PIN is empty.'
            );
        }

        if (strlen($transferPin) > 128) {
            throw new Exception(
                'IRNIC transfer PIN is too long.'
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $transferPin)) {
            throw new Exception(
                'IRNIC transfer PIN contains invalid control characters.'
            );
        }

        return $transferPin;
    }


    private function yearsToMonths($years)
    {
        $years = (int) $years;

        if ($years < 1 || $years > 5) {
            throw new Exception(
                'IRNIC registration/renewal period must be between 1 and 5 years.'
            );
        }

        return $years * 12;
    }


    private function validateEndpoint($endpoint)
    {
        $endpoint = trim((string) $endpoint);

        $parts = parse_url($endpoint);

        if (
            !is_array($parts) ||
            !isset($parts['scheme'], $parts['host']) ||
            strtolower($parts['scheme']) !== 'https' ||
            strtolower($parts['host']) !== 'epp.nic.ir'
        ) {
            throw new Exception(
                'Invalid IRNIC endpoint. HTTPS epp.nic.ir is required.'
            );
        }

        return $endpoint;
    }


    private function validateDepositCode($depositCode)
    {
        $depositCode = preg_replace(
            '/\s+/u',
            '',
            trim((string) $depositCode)
        );

        if (
            !preg_match('/^\d{16}$/', $depositCode)
        ) {
            throw new Exception(
                'IRNIC Deposit Code must contain exactly 16 digits.'
            );
        }

        return $depositCode;
    }


    private function validateToken($token)
    {
        $token = trim((string) $token);

        if ($token === '') {
            throw new Exception(
                'IRNIC API Token is empty.'
            );
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $token)) {
            throw new Exception(
                'IRNIC API Token contains invalid control characters.'
            );
        }

        return $token;
    }


    private function makeClTRID($action)
    {
        try {
            $random = substr(
                bin2hex(random_bytes(4)),
                0,
                8
            );
        } catch (Throwable $e) {
            $random = substr(
                md5(uniqid('', true)),
                0,
                8
            );
        }

        return
            'WHMCS-' .
            strtoupper($action) .
            '-' .
            date('YmdHis') .
            '-' .
            $random;
    }


    private function xmlEscape($value)
    {
        return htmlspecialchars(
            (string) $value,
            ENT_XML1 | ENT_QUOTES,
            'UTF-8'
        );
    }


    private function loadXml($body)
    {
        if (trim((string) $body) === '') {
            return null;
        }

        $previous =
            libxml_use_internal_errors(true);

        $xml = simplexml_load_string(
            $body,
            'SimpleXMLElement',
            LIBXML_NONET
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml === false
            ? null
            : $xml;
    }


    private function normalizeDate($date)
    {
        $date = trim((string) $date);

        if ($date === '') {
            return null;
        }

        if (
            preg_match(
                '/^(\d{4}-\d{2}-\d{2})/',
                $date,
                $matches
            )
        ) {
            return $matches[1];
        }

        return null;
    }
}
