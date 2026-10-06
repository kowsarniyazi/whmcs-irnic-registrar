# Authentication

## Why the module uses two credential locations

IRNIC's current gateway is reached over HTTPS and receives EPP XML as the raw request body.

The working model has two distinct layers:

### 1. HTTP API authentication

```http
Authorization: Bearer <API_TOKEN>
Content-Type: application/xml
Accept: application/xml
```

### 2. EPP/domain authentication

```xml
<domain:authInfo>
    <domain:pw>DEPOSIT_CODE</domain:pw>
</domain:authInfo>
```

## How this was established

The final behavior was derived from a combination of:

1. Standard EPP message concepts.
2. IRNIC-specific EPP XML structures.
3. Controlled differential tests against the current IRNIC HTTP endpoint.

Earlier authentication variants were rejected with authentication errors. Adding the API Token as an HTTP Bearer token moved the response from HTTP authentication failure into normal EPP-level processing. Keeping the API Token in the HTTP header while placing the Deposit Code in `domain:pw` then produced a successful EPP result for Domain Check.

This distinction matters:

- **Bearer authentication is not an EPP protocol feature.**
- EPP defines the command/message model.
- IRNIC's HTTP gateway adds the HTTP authentication layer around EPP XML.

## Never commit credentials

Do not commit:

- API Token
- Deposit Code
- real transfer PINs
- customer NIC Handles when they are personal/customer data
- raw request dumps containing `Authorization`
- WHMCS database credentials

If a real API Token is ever committed, rotate it.
