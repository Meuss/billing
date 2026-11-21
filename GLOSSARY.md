# Billing

A single-freelancer tool for tracking work and costs incurred for clients and invoicing them in CHF.

## Language

### Work

**Client**:
A person or organization the freelancer works for and invoices.
_Avoid_: Customer, account

**Archived Client**:
A Client the freelancer no longer works for; hidden from day-to-day use but kept for history.

**Project**:
A body of work for one Client that groups Tasks. Either active or archived.

**Archived Project**:
A Project the freelancer no longer works on; kept for history.

**Task**:
A unit of work inside a Project, invoiced as a whole. Has a status: todo, in progress, done, or billed. It becomes in progress when its first Time Entry is logged.
_Avoid_: Job, ticket

**Billed Task**:
A Task that has been put on an Invoice. It takes no further Time Entries; further work goes in a new Task. Leaving the Invoice returns it to done.

**Time Entry**:
One period of work on a Task, with a start and an end on 5-minute marks and an optional description. Its duration is in 15-minute steps.
_Avoid_: Hours, log, timesheet row

**Default Rate**:
The freelancer's standard hourly rate, used unless the Project has its own.

**Project Rate**:
An hourly rate set on a Project that replaces the Default Rate for its Tasks.

**Expense**:
A cost the freelancer paid on a Client's behalf (e.g. a license or third-party bill) to be passed on to that Client. Recorded in CHF, with a cost and a billed amount.
_Avoid_: Product, external cost, disbursement

**Billed Expense**:
An Expense that has been put on an Invoice. Leaving the Invoice returns it to unbilled.

**Unbilled Work**:
A Client's done Tasks and unbilled Expenses that are not yet on any Invoice.

### Invoicing

**Invoice**:
The document requesting payment from a Client for Tasks, Expenses and other items. Status: draft, sent, paid, overdue, or cancelled.
_Avoid_: Bill

**Draft Invoice**:
An Invoice still being prepared. The only editable state; it has no Invoice Number yet.

**Invoice Line**:
One row of an Invoice: a Task Line, an Expense Line, or an Item Line. Its price is fixed when it is added.

**Task Line**:
An Invoice Line for one Task: its total hours times the applicable rate, with its Time Entries listed beneath.

**Expense Line**:
An Invoice Line for one Expense, showing its billed amount.

**Item Line**:
A free-text Invoice Line with a description, quantity and unit price; may be negative.

**Discount**:
A single reduction on an Invoice's subtotal, as a percentage or a fixed CHF amount.
_Avoid_: Rebate

**Rounding**:
The adjustment that brings an Invoice's total to the nearest 5 centimes.

**Invoice Note**:
Optional free text printed above an Invoice's lines, e.g. the period covered.

**Invoice Number**:
The sequential identifier of an Invoice, in the form `YYYY-NNN`, restarting each year. Assigned when the Invoice is sent and never reused.

**Sent Date**:
The date the Invoice was sent to the Client; never earlier than the previous Invoice's.

**Due Date**:
The date payment is expected: 30 days after the Sent Date.

**Paid Invoice**:
An Invoice whose full amount has been received, on a recorded payment date.

**Overdue Invoice**:
A sent, unpaid Invoice past its Due Date. Never set by hand.

**Cancelled Invoice**:
A sent Invoice that has been voided. It keeps its number, and its Tasks and Expenses become available to invoice again.

**Sender**:
The freelancer's own identity as printed on Invoices: name, address, contact details and IBAN.

**QR-bill**:
The Swiss payment slip attached to an Invoice (planned).
