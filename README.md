# com.skvare.membershipapprovalworkflow

Adds a staff-driven membership approval workflow on top of CiviCRM's normal
membership statuses, so status is never set by manually editing a
membership record.

> Looking for how it works internally (hooks, renewal edge cases, the
> email template)? See [`docs/membershipapprovalworkflow/`](docs/membershipapprovalworkflow/README.md).

## What it does

- Every new membership (immediate payment or pay-later, front-end or
  back-office) starts as **Pending**. It never activates on its own.
- Three new statuses are added: **Under Review**, **Approved/Pending
  Payment**, and **Pending Approval/Payment Received** (used instead of
  plain Pending when payment comes in before staff have reviewed the
  application). All three are administrative statuses (like core's own
  Cancelled/Deceased) - CiviCRM's automatic status calculator, the nightly
  membership-status cron job, and the renewal/order-complete flow will
  never assign or change them on their own.
- A **Membership Approval** link is added to the row-action menu on the
  contact's Membership tab (`civicrm/contact/view/membership`), separate
  from the normal "Edit"/"Renew" links, and only on a contact's *primary*
  membership - not on memberships inherited from an organization. It
  shows only the next valid action(s) for the membership's current
  status, and a "workflow sequence" help block showing where that status
  sits in the process:
  - Pending or Pending Approval/Payment Received -> Under Review
  - Under Review -> Approved/Pending Payment **or** Approved (Current) -
    exactly one of the two is offered, based on whether payment has
    already been received
  - Approved/Pending Payment -> Approved
  - Anything else (Current, Grace, Expired, ...) -> no action shown
- Choosing **Approved** sets status to Current and start date to today.
- Each of the three status-changing actions above (moved to Under Review;
  approved, pending payment; approved and Current) sends the member an
  email notification, customizable from Administer > Communications >
  Message Templates, and independently switchable on/off from
  **Administer > CiviMember > Membership Approval Workflow Notifications**
  (`civicrm/admin/membershipapprovalworkflow`).
- When a contribution linked to an "Approved/Pending Payment" membership
  is completed (online payment, offline check, or a back-office "Record
  Payment"), the membership automatically moves to **Current** with its
  start date set to the payment's receive date. A membership still sitting
  in plain "Pending" whose payment completes is bumped to "Pending
  Approval/Payment Received" instead - or, if the contact already held an
  Expired membership of the same type, straight to Current - see
  `docs/membershipapprovalworkflow/signup-scenarios.md`.
- The **New** status is deactivated while the extension is enabled and its
  prior active/inactive state is restored on disable or uninstall. Existing
  statuses that would
  have landed on New now land on Current instead, which also means a
  membership renewal never resets to New.
  Installations upgraded from 1.0.0 retain that release's documented
  uninstall behavior and restore New as active, because 1.0.0 did not retain
  its prior state.
- All changes are applied through the standard `Membership.create` API,
  so CiviCRM core's own inherited-membership sync
  (`CRM_Member_BAO_Membership::createRelatedMemberships()`) automatically
  keeps an organization's membership and its related individual
  memberships in sync - no custom sync code needed here.
- Grace/Expired and other date-based status rules are untouched and keep
  working normally once a membership reaches Current, including through a
  renewal (see `docs/membershipapprovalworkflow/renewal-edge-cases.md` for
  the CiviCRM core quirks this had to work around).

This is an [extension for CiviCRM](https://docs.civicrm.org/sysadmin/en/latest/customize/extensions/), licensed under [AGPL-3.0](LICENSE.txt).

## Getting Started

Install/enable the extension, then open any contact's Membership tab -
new memberships will already show as Pending, and the "Membership
Approval" action link will appear in each primary membership row's action
menu.

## Known Issues

- Org -> individual sync relies on the membership type having a
  relationship type configured for inherited memberships (standard
  CiviCRM setup) - this extension does not create that configuration.
