# com.skvare.membershipapprovalworkflow

Adds a staff-driven membership approval workflow on top of CiviCRM's normal
membership statuses, so status is never set by manually editing a
membership record.

> Looking for how it works internally (hooks, renewal edge cases, the
> email template)? See [`docs/membershipapprovalworkflow/`](docs/membershipapprovalworkflow/README.md).

## What it does

- Every new membership (immediate payment or pay-later, front-end or
  back-office) starts as **Pending**. It never activates on its own.
- Two new statuses are added: **Under Review** and **Approved/Pending
  Payment**. Both are administrative statuses (like core's own
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
  - Pending -> Under Review
  - Under Review -> Approved/Pending Payment or Approved
  - Approved/Pending Payment -> Approved
  - Anything else (Current, Grace, Expired, ...) -> no action shown
- Choosing **Approved** sets status to Current and start date to today.
- When staff move a membership out of **Under Review** into **Approved**
  or **Approved/Pending Payment**, the member is emailed a notification
  (customizable from Administer > Communications > Message Templates).
- When a contribution linked to an "Approved/Pending Payment" membership
  is completed (online payment, offline check, or a back-office "Record
  Payment"), the membership automatically moves to **Current** with its
  start date set to the payment's receive date.
- The **New** status is deactivated on install (and reactivated on
  uninstall) so it can never be assigned - existing statuses that would
  have landed on New now land on Current instead, which also means a
  membership renewal never resets to New.
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
