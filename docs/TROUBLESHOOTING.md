# Troubleshooting

## HTTP 401 / EPP 2200

Check:

- API Token
- `Authorization: Bearer <TOKEN>`
- Deposit Code
- endpoint hostname
- TLS/certificate validation

## EPP 2104 — Billing failure

The request reached IRNIC but billing could not be completed.

Check representative balance/account billing status.

## EPP 2303 — Object does not exist

Usually means the requested domain object does not yet exist in the registry.

For a pre-registration domain, this can be expected.

## NIC Handle rejected locally

The module expects a NIC Handle such as:

```text
xx12345-irnic
```

An email address is deliberately not accepted as a replacement.

Local validation checks the format. IRNIC remains authoritative for whether the handle exists and has permission for the requested operation.

## Nameservers disappear before registration

The module includes lightweight pending-nameserver storage (`mod_irnic_pending_nameservers`) so pre-registration values can survive until registration.

## Support diagnostics

When reporting a registry-side failure, include:

- operation
- domain
- HTTP status
- EPP result code
- EPP message
- `clTRID`
- `svTRID`

Never include the API Token, Deposit Code, or transfer PIN.
