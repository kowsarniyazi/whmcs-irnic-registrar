# Testing checklist

Use a non-critical domain first.

## Static checks

```bash
php -l modules/registrars/irnic/irnic.php
php -l modules/registrars/irnic/lib/Client.php
```

## Functional checks

1. Open a pending `.ir` domain and save two nameservers.
2. Reload the domain page and verify the staged nameservers remain available.
3. Attempt registration with an invalid NIC Handle; the module must reject it before sending a chargeable Create request.
4. Attempt registration with a valid NIC Handle and funded representative account.
5. Verify successful registration response.
6. Test renewal for 1 year.
7. Test renewal for 5 years.
8. Verify a period outside 1–5 years is rejected.
9. Change nameservers on an existing domain.
10. Request the transfer PIN / EPP code.
11. Test incoming transfer using a valid transfer PIN.

## Important EPP outcomes

Common examples encountered during integration:

- `1000` — command completed successfully
- `1001` — command completed successfully; action pending
- `2104` — billing failure
- `2200` — authentication error
- `2303` — object does not exist

Always preserve the returned `svTRID` when opening a support case with IRNIC.
