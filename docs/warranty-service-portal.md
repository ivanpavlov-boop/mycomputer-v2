# Warranty, RMA And Service Portal

The Service Portal handles warranty claims, service requests, returns, DOA cases and replacement requests.

## Data Model

- `service_tickets`: main workflow record.
- `service_ticket_files`: private uploaded photos, PDFs, invoices and warranty cards.
- `service_ticket_messages`: public customer/admin messages and internal support notes.

Ticket types:

- `warranty_claim`
- `service_request`
- `return_request`
- `doa_request`
- `replacement_request`

Statuses:

- `new`
- `awaiting_review`
- `awaiting_customer`
- `approved`
- `rejected`
- `awaiting_service`
- `in_diagnosis`
- `awaiting_parts`
- `repaired`
- `replaced`
- `refunded`
- `completed`
- `closed`

## Customer API

Authenticated endpoints:

- `GET /api/v1/account/service`
- `POST /api/v1/account/service`
- `GET /api/v1/account/service/{ticket}`
- `POST /api/v1/account/service/{ticket}/messages`
- `POST /api/v1/account/service/{ticket}/files`
- `POST /api/v1/account/service/{ticket}/close`
- `GET /api/v1/account/service/order-products/{order}`

Only the ticket owner or an active member of the related B2B company can access a ticket.

## Warranty Validation

When a ticket references an order and product, the service validates that the product belongs to the customer's purchased order. Warranty expiry is calculated from the order purchase date plus the product `warranty_months` value.

## Admin Workflow

Filament resource:

- `ServiceTicketResource`

Admins and support staff can review tickets, assign technicians, update status, record diagnosis, record work performed, record parts used, set repair date, issue replacement/refund status and add internal notes.

Dashboard widget:

- `ServiceTicketStats`

## Integrations

ERP sync jobs are staged for:

- `service_ticket_created`
- `service_ticket_closed`
- `replacement_issued`
- `refund_issued`

Email automation queues:

- `ticket_created`
- `ticket_approved`
- `ticket_rejected`
- `ticket_status_changed`
- `awaiting_customer`
- `repair_completed`
- `replacement_completed`
- `refund_completed`

Analytics events:

- `ticket_created`
- `ticket_closed`
- `repair_completed`
- `replacement_completed`
- `refund_completed`

## Frontend

Nuxt routes:

- `/account/service`
- `/account/service/new`
- `/account/service/{id}`

Components:

- `ServiceTicketCard`
- `ServiceTicketTimeline`
- `ServiceTicketMessages`
- `ServiceTicketFiles`
- `WarrantyStatusBadge`

## Storage And Security

Uploads are stored under `storage/app/service-ticket-files` on the local disk. Public API responses do not expose private file paths.

Allowed upload types:

- `jpg`
- `jpeg`
- `png`
- `webp`
- `pdf`

Maximum upload size: 5 MB.
