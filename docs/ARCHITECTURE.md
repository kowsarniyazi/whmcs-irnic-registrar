# Architecture

## Request flow

```text
WHMCS
  |
  | registrar function
  v
irnic.php
  |
  | validation / WHMCS parameter mapping
  v
lib/Client.php
  |
  | HTTPS POST + raw EPP XML
  v
https://epp.nic.ir/submit
```

## Responsibilities

### `irnic.php`

WHMCS-facing adapter:

- reads module configuration
- reads WHMCS domain parameters
- validates registration/renewal periods
- extracts and validates NIC Handle
- stages pending nameservers when needed
- maps Client results into WHMCS registrar responses
- writes only compact failure logs

### `lib/Client.php`

IRNIC-facing protocol client:

- normalizes `.ir` domain names
- validates endpoint and credentials
- builds EPP XML
- sends HTTPS requests
- parses EPP result codes and transaction IDs
- parses nameservers and dates
- implements registration, renewal, nameserver update, transfer, and transfer-PIN request

## Logging policy

The module intentionally avoids noisy logging.

Logged:
- failed register
- failed renew
- failed nameserver update
- failed transfer
- failed transfer-PIN request

Not logged:
- successful routine operations
- routine GetNameservers
- raw Authorization header
- API Token
- Deposit Code
- transfer PIN
