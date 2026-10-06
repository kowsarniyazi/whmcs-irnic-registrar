# Security Policy

## Secrets

Never commit or publish:

- IRNIC API Token
- IRNIC Deposit Code
- transfer PINs
- WHMCS database credentials
- raw Authorization headers

## Transport

The module requires an HTTPS IRNIC endpoint and verifies both the TLS peer and hostname.

## Logging

The module is designed to avoid storing credentials or full raw requests in WHMCS Module Log.

## Reporting

If you discover a security issue in your deployment, rotate affected credentials before sharing diagnostic material.
