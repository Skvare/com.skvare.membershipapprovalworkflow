# Workflow states and transitions

## The four statuses

| Status | Core or added by this extension | `is_admin` | Meaning |
|---|---|---|---|
| **Pending** | Core (default CiviCRM status) | Yes | Membership exists but has not been reviewed. No `start_date`/`end_date` yet. |
| **Under Review** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have started reviewing the application. |
| **Approved/Pending Payment** | Added by this extension (`managed/MembershipStatus.mgd.php`) | Yes | Staff have approved the application; waiting on payment. |
| **Current** | Core (default CiviCRM status) | No | Active membership. Reached via the approval dropdown ("Approved") or automatically when a linked contribution completes. |

Both custom statuses have `start_event = NULL` and `end_event = NULL`, same
as core's own `is_admin` statuses (Cancelled, Deceased). This is what keeps
CiviCRM's date-based status calculator from ever assigning or changing them
on its own - they only ever move via this extension's code.

## Allowed transitions (`Utils::getAllowedActions()`)

This is the single source of truth for what the "Membership Approval"
dropdown offers, given a membership's current status:

```
Pending  ──────────────►  Under Review
                                │
                                ├──────────────►  Approved/Pending Payment ──────►  Approved (Current)
                                │
                                └──────────────►  Approved (Current)
```

- **Pending -> Under Review.** This is the *only* action offered from
  Pending. (A membership cannot skip directly from Pending to Approved or
  Approved/Pending Payment.)
- **Under Review -> Approved/Pending Payment**, or **Under Review ->
  Approved.** Both are offered; staff choose whichever fits (e.g. skip
  straight to Approved for a comped/no-payment-required membership).
- **Approved/Pending Payment -> Approved.** The only action offered.
  (This transition also happens automatically - see below.)
- **Current, Grace, Expired, Cancelled, Deceased, anything else -> no
  action offered.** The workflow is considered finished once a membership
  reaches Current; the "Membership Approval" link doesn't even appear (see
  `architecture.md` - `hook_civicrm_links`).

> **If you're reading this after touching `getAllowedActions()`:** the
> Pending row used to also offer `Approved/Pending Payment` and `Approved`
> directly. Confirm which behavior is actually wanted before assuming this
> table is still accurate - it reflects the code as of this writing, not
> necessarily any documented product decision.

## Automatic transition: Approved/Pending Payment -> Approved

Choosing an action moves a membership manually via the dropdown
(`Utils::applyAction()`). One transition also happens **without** any
manual action: when a contribution linked to an `Approved/Pending Payment`
membership is marked `Completed` (online payment, offline check clearing,
or a back-office "Record Payment"), `Utils::handleContributionCompleted()`
(triggered from `hook_civicrm_post` on `Contribution`) moves the membership
to Current automatically, with `start_date` set to the contribution's
`receive_date`.

This is the only place a membership can reach Current without a human
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
