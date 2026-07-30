# Legal Review Checklist

The Bulgarian Terms and Privacy foundation from Commerce Phase 1E.2A is merged,
deployed and staging verified in the closed state. The first final Bulgarian
content is approved by the project owner and bound to exact source hashes in
`LEGAL_CONTENT_APPROVAL_2026-07-30.json`. Legal-counsel review is not claimed,
and runtime/public-commerce activation remains a separate decision.

## Confirmed Operator Facts

- Legal name: `„Тандем компютърс“ ЕООД`
- EIK: `202410637`
- Address: `гр. Перник, ул. „Г. С. Раковски“ №3/6А`
- Contact email: `sales@mycomputer.bg`
- Brands: `MyComputer.bg`, `COMPUTER2U`
- Staging domain: `computer2u.eu`
- Future production domain: `mycomputer.bg`

## Facts Deliberately Not Invented

- VAT registration number, telephone, working hours and legal representative
  are not published because approved evidence is not present.
- No DPO is claimed.
- No courier, bank, payment provider, leasing provider, warehouse or distinct
  return address is invented.
- Delivery, recipient and retention wording uses verified categories and
  transparent criteria where exact operational facts are not configured.
- Card, leasing, abandoned-Cart recovery and non-essential analytics remain
  disabled by default.

## Approval Steps

1. Verify the exact source hashes and approval record after merge/deploy.
2. Run `php artisan commerce:release-preflight --json`.
3. Obtain separate explicit operational approval before setting
   `LEGAL_CONTENT_APPROVED=true`.
4. Keep public commerce closed until its own separately approved release step.
5. Keep CART-023 open until that operational release is complete.

Project-owner approval is not legal advice, lawyer certification or regulatory
approval. Never place placeholder personal data, bank details, secrets or
unverified business claims in the public pages.
