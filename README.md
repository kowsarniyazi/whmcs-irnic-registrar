# WHMCS IRNIC Registrar

A clean WHMCS registrar module for `.ir` domains using IRNIC's current HTTP/EPP gateway.

> This repository contains **no API token, Deposit Code, customer NIC Handle, site name, database credential, or production secret**.

## Features

- `.ir` domain registration
- Renewal from 1 to 5 years
- Nameserver read/update
- Incoming domain transfer
- Request transfer PIN / EPP code
- IRNIC NIC Handle validation before registration/transfer
- Bearer-token HTTP authentication
- IRNIC Deposit Code carried in EPP `authInfo`
- TLS certificate and hostname verification
- Minimal WHMCS Module Log usage
- Temporary nameserver staging for domains that do not yet exist at IRNIC

## Directory structure

```text
modules/
└── registrars/
    └── irnic/
        ├── irnic.php
        └── lib/
            └── Client.php
docs/
├── ARCHITECTURE.md
├── AUTHENTICATION.md
├── INSTALLATION.md
├── TESTING.md
└── TROUBLESHOOTING.md
```

## Requirements

- WHMCS with registrar-module support
- PHP with cURL and SimpleXML
- An active IRNIC reseller/representative account
- IRNIC API Token
- IRNIC Deposit Code
- HTTPS access to `https://epp.nic.ir/submit`

## Installation

Copy:

```text
modules/registrars/irnic/
```

into the matching WHMCS path, then activate **IRNIC Registrar** from WHMCS Domain Registrars.

Configure the credentials in WHMCS. Do **not** put them in source files.

See [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Authentication model

The working integration separates two authentication layers:

```text
HTTP layer
Authorization: Bearer <API_TOKEN>

EPP/domain layer
<domain:authInfo>
    <domain:pw>DEPOSIT_CODE</domain:pw>
</domain:authInfo>
```

See [docs/AUTHENTICATION.md](docs/AUTHENTICATION.md).

## Security

- API Token is never hard-coded.
- Deposit Code is never hard-coded.
- Endpoint validation prevents credentials being sent to an arbitrary host.
- TLS peer/host verification is enabled.
- Raw Authorization headers are not written to WHMCS Module Log.
- Routine successful reads are intentionally not logged.
- Transfer PIN is not logged.

See [SECURITY.md](SECURITY.md).

## Current scope

The repository intentionally avoids implementing unverified IRNIC operations merely to increase feature count. Features should be added only after their current API behavior is verified.

## Reference / inspiration

The project was implemented independently against WHMCS registrar conventions, IRNIC EPP message structures, and live endpoint behavior. The following archived GPL project was also reviewed for feature/organization ideas:

- `pejman-zeynalkheyri/WHMCS-IRNIC`

No production credentials from any environment are included in this repository.

## Status

Recommended release state until all chargeable flows are verified against a funded production representative account:

```text
v1.0.0-rc1
```
## Author

Developed by **Kowsar Niyazi**  
Company: **NovinHost**

GitHub: `kowsarniyazi`
After successful production tests for registration, renewal, nameserver update, transfer and transfer-PIN request, tag a stable `v1.0.0`.

## License

GPL-3.0-or-later.
