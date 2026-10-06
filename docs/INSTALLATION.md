# Installation

## 1. Copy the registrar module

Copy:

```text
modules/registrars/irnic/
```

to:

```text
<WHMCS_ROOT>/modules/registrars/irnic/
```

## 2. Activate the registrar

In WHMCS, open Domain Registrars and activate **IRNIC Registrar**.

## 3. Configure credentials

Enter:

- Deposit Code
- API Token
- Endpoint

Recommended endpoint:

```text
https://epp.nic.ir/submit
```

Credentials must exist only in WHMCS configuration, not in the repository.

## 4. Configure `.ir`

Assign the IRNIC registrar to the `.ir` TLD and configure prices for registration, renewal and transfer according to your business rules.

To offer 1–5 year periods, configure those periods in WHMCS as well; the module rejects any `regperiod` outside 1 through 5.

## 5. Additional Domain Field

Registration requires an IRNIC NIC Handle.

Accepted value example:

```text
xx12345-irnic
```

Do not use an email address in place of a NIC Handle.

The module recognizes common labels such as:

- `شناسه ایرنیک`
- `شناسه شما در ایرنیک`
- `ایمیل یا شناسه شما در ایرنیک`
- `IRNIC Handle`
- `NIC Handle`

For new installations, using the unambiguous label `IRNIC Handle` or `شناسه ایرنیک` is recommended.

## 6. Module Log

WHMCS Module Log can be enabled temporarily during troubleshooting. The module itself keeps entries compact and does not intentionally log secrets.
