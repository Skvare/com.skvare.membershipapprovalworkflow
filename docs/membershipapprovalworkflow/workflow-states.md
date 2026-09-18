# Workflow states and transitions

## The statuses

| Status | Core or added by this extension | `is_admin` | Meaning |
|---|---|---|---|
| **Pending** | Core (default CiviCRM status) | Yes | Membership exists but has not been reviewed, and no payment has been received yet (pay-later). No `start_date`/`end_date` yet. |
| **Pending Approval/Payment Received** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Membership exists but has not been reviewed - however payment *has* already been received (paid at signup instead of pay-later). No `start_date`/`end_date` yet. |
| **Under Review** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have started reviewing the application. |
| **Approved/Pending Payment** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have approved the application; waiting on payment. |
| **Denied** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff rejected the application while it was Under Review. Terminal - no further approval action is offered. |
| **Not Fulfilled** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | An Approved/Pending Payment membership whose payment never came through. Terminal - no further approval action is offered. |
| **Current** | Core (default CiviCRM status) | No | Active membership. Reached via the approval dropdown ("Approved"/"Current") or automatically when a linked contribution completes. |
| **Suspended** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | A Current membership manually suspended by staff. Terminal - no further approval action is offered. |
| **Removed** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | A Current membership manually removed by staff. Terminal - no further approval action is offered. |
| **Expired** | Core (default CiviCRM status) | No | A Current membership past its `end_date` (core's date-based calculator), or manually set from Current via the approval dropdown. Can be reactivated straight back to Current. |
| **Cancelled by Member** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | A Current membership the member themselves chose to cancel. Terminal - no further approval action is offered. |

Every custom status has `start_event = NULL` and `end_event = NULL`, same
as core's own `is_admin` statuses (Cancelled, Deceased). This is what keeps
CiviCRM's date-based status calculator from ever assigning or changing them
on its own - they only ever move via this extension's code.

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
                                       ├──►  Under Review ──┬──►  Approved/Pending Payment ──┬──►  Approved (Current)
Pending Approval/Payment Received    ─┤                    ├──►  Approved (Current)          ├──►  Not Fulfilled
                                       │                    ├──►  Denied                      └──►  Under Review
                                       └──►  Current        └──►  Not Fulfilled

Current ──┬──►  Suspended
          ├──►  Removed
          ├──►  Cancelled by Member
          ├──►  Under Review
          └──►  Expired ──►  Current
```

- **Pending -> Under Review or Current**, or **Pending Approval/Payment
  Received -> Under Review or Current.** Staff can either route the
  application through review as usual, or skip straight to activating it.
- **Under Review -> Denied, Not Fulfilled, plus exactly one of
  Approved/Pending Payment or Approved - never both.**
  `getAllowedActions($currentStatusName, $paymentReceived)` decides which
  of the latter two via `hasReceivedPayment($membershipId)`: if the most
  recently received contribution linked to the membership (via
  `MembershipPayment`) is `Completed`, only **Approved** is offered (no
  reason to route through a "pending payment" holding status for money
  that's already in); otherwise only **Approved/Pending Payment** is
  offered (staff can't activate a membership nothing has been paid for yet
  - even a comped/$0 membership needs a completed $0 contribution to reach
  Approved directly). **Denied** and **Not Fulfilled** are terminal - no
  further approval action is offered afterwards.
- **Approved/Pending Payment -> Approved, Not Fulfilled, or back to Under
  Review.** Approved also happens automatically - see below. **Not
  Fulfilled** is terminal.
- **Current -> Suspended, Removed, Expired, Cancelled by Member, or back to
  Under Review.** All are manual actions on the approval dropdown; Expired
  can also still happen automatically via CiviCRM's date-based status
  calculator once `end_date` passes (core status, not owned by this
  extension - see below). **Suspended**, **Removed**, and **Cancelled by
  Member** are terminal.
- **Expired -> Current.** Reactivates the membership directly, the same as
  the manual "Approved" action elsewhere in the workflow.
- **Grace, Denied, Not Fulfilled, Suspended, Removed, Cancelled by Member,
  Deceased, anything else -> no action offered.** The "Membership Approval"
  link doesn't even appear for these (see `architecture.md` -
  `hook_civicrm_links`).

## Automatic transitions

Choosing an action moves a membership manually via the dropdown
(`Utils::applyAction()`). Two transitions also happen **without** any
manual action, both from `Utils::handleContributionCompleted()`
(triggered from `hook_civicrm_post` on `Contribution`, when a linked
contribution's new status is `Completed`):

- **Pending -> Pending Approval/Payment Received.** See above. This
  applies uniformly, including to a renewal of a previously `Expired`
  membership of the same type - such renewals go through the full review
  queue like any first-time application; there is no longer an exemption
  that routes them straight to `Current` (see `signup-scenarios.md` -
  Scenario 3).
- **Approved/Pending Payment -> Approved (Current).** When a contribution
  linked to an `Approved/Pending Payment` membership is marked `Completed`
  (online payment, offline check clearing, or a back-office "Record
  Payment"), `Utils::markCurrentOnPayment()` moves the membership to
  Current automatically, with `start_date` set to the contribution's
  `receive_date`.

This second bullet is the only place a membership can reach Current
without a human explicitly choosing "Approved" on the approval screen.

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

## Inherited (organization) memberships

An inherited membership (one with `owner_membership_id` set) is never
touched directly by this workflow:

- `Utils::forcePendingOnCreate()` skips it on creation - core's own
  `createRelatedMemberships()` sets its status from the owner membership.
- `hook_civicrm_links` doesn't offer the "Membership Approval" action on
  it at all - only the primary (owner) membership gets the link.

Approving the *owner* membership propagates to its inherited memberships
automatically, because every workflow status change in this extension goes
through the standard `Membership.update` API4 action (see `architecture.md`).
