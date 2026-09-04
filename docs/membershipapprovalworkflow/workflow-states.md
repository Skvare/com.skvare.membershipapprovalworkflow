# Workflow states and transitions

## The five statuses

| Status | Core or added by this extension | `is_admin` | Meaning |
|---|---|---|---|
| **Pending** | Core (default CiviCRM status) | Yes | Membership exists but has not been reviewed, and no payment has been received yet (pay-later). No `start_date`/`end_date` yet. |
| **Pending Approval/Payment Received** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Membership exists but has not been reviewed - however payment *has* already been received (paid at signup instead of pay-later). No `start_date`/`end_date` yet. |
| **Under Review** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have started reviewing the application. |
| **Approved/Pending Payment** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have approved the application; waiting on payment. |
| **Current** | Core (default CiviCRM status) | No | Active membership. Reached via the approval dropdown ("Approved") or automatically when a linked contribution completes. |

All four custom statuses have `start_event = NULL` and `end_event = NULL`,
same as core's own `is_admin` statuses (Cancelled, Deceased). This is what
keeps CiviCRM's date-based status calculator from ever assigning or
changing them on its own - they only ever move via this extension's code.

### How a membership picks Pending vs Pending Approval/Payment Received

Every brand-new membership is force-set to plain `Pending` on creation
(`Utils::forcePendingOnCreate()`), regardless of how it was submitted. If a
contribution linked to it (via `MembershipPayment`) is then marked
`Completed` while it's still sitting in `Pending` - i.e. payment came in
before staff ever touched it - `Utils::handleContributionCompleted()`
(triggered from `hook_civicrm_post` on `Contribution`) bumps it to `Pending
Approval/Payment Received` instead. A pay-later membership whose
contribution is never completed just stays `Pending`. See
`Utils::markPendingApprovalPaymentReceived()`.

## Allowed transitions (`Utils::getAllowedActions()`)

This is the single source of truth for what the "Membership Approval"
dropdown offers, given a membership's current status:

```
Pending                              ─┐
                                       ├──►  Under Review ──┬──►  Approved/Pending Payment ──►  Approved (Current)
Pending Approval/Payment Received    ─┘         (no completed  │
                                                  payment yet)   │
                                                                 └──►  Approved (Current)
                                                              (payment already completed)
```

- **Pending -> Under Review**, or **Pending Approval/Payment Received ->
  Under Review.** This is the *only* action offered from either - a
  membership cannot skip directly from either to Approved or
  Approved/Pending Payment.
- **Under Review -> exactly one of Approved/Pending Payment or Approved -
  never both.** `getAllowedActions($currentStatusName, $paymentReceived)`
  decides which via `hasReceivedPayment($membershipId)`: if the most
  recently received contribution linked to the membership (via
  `MembershipPayment`) is `Completed`, only **Approved** is offered (no
  reason to route through a "pending payment" holding status for money
  that's already in); otherwise only **Approved/Pending Payment** is
  offered (staff can't activate a membership nothing has been paid for
  yet - even a comped/$0 membership needs a completed $0 contribution to
  reach Approved directly).
- **Approved/Pending Payment -> Approved.** The only action offered.
  (This transition also happens automatically - see below.)
- **Current, Grace, Expired, Cancelled, Deceased, anything else -> no
  action offered.** The workflow is considered finished once a membership
  reaches Current; the "Membership Approval" link doesn't even appear (see
  `architecture.md` - `hook_civicrm_links`).

## Automatic transitions

Choosing an action moves a membership manually via the dropdown
(`Utils::applyAction()`). Two transitions also happen **without** any
manual action, both from `Utils::handleContributionCompleted()`
(triggered from `hook_civicrm_post` on `Contribution`, when a linked
contribution's new status is `Completed`):

- **Pending -> Pending Approval/Payment Received.** See above - *unless*
  the contact has any other `Expired` membership of the same type
  (`Utils::isRenewalOfExpiredMembership()`). This is an intentional policy:
  any returning member of that type is treated as a renewal and skips the
  review queue entirely, even if there is no direct contribution, date, or
  renewal-record link between the two membership rows. It goes straight to
  **Current** instead (see `signup-scenarios.md` - Scenario 3).
- **Approved/Pending Payment -> Approved (Current).** When a contribution
  linked to an `Approved/Pending Payment` membership is marked `Completed`
  (online payment, offline check clearing, or a back-office "Record
  Payment"), `Utils::markCurrentOnPayment()` moves the membership to
  Current automatically, with `start_date` set to the contribution's
  `receive_date`.

Both the Expired-renewal exception above and the second bullet reach
Current via the same `Utils::markCurrentOnPayment()` helper. Together,
these are the only places a membership can reach Current without a human
explicitly choosing "Approved" on the approval screen.

## What "Approved" actually sets

Both the manual "Approved" action and the automatic payment-completion path
set:

- `status_id` = Current
- dates computed by `CRM_Member_BAO_MembershipType::getDatesForMembershipType()`
  (`Utils::datesForStart()`), which preserves the membership's original
  `join_date` and computes `start_date`/`end_date` from whichever date is
  passed in (today, for a manual approval; the contribution's `receive_date`,
  for automatic payment completion) - this is what keeps Grace/Expired
  working correctly afterwards.
- `is_override = 0`, `status_override_end_date = ''` - any prior manual
  status override is cleared.
- `skipStatusCal = TRUE` - the status is set explicitly, not recalculated.

## Inherited (organization) memberships

An inherited membership (one with `owner_membership_id` set) is never
touched directly by this workflow:

- `Utils::forcePendingOnCreate()` skips it on creation - core's own
  `createRelatedMemberships()` sets its status from the owner membership.
- `hook_civicrm_links` doesn't offer the "Membership Approval" action on
  it at all - only the primary (owner) membership gets the link.

Approving the *owner* membership propagates to its inherited memberships
automatically, because every status change in this extension goes through
the standard `Membership.create` API (see `architecture.md`).
