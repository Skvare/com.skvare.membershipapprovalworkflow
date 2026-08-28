{contact.email_greeting_display},

{if $newStatusName eq 'Approved/Pending Payment'}
{ts 1=$membershipTypeName}Good news - your %1 membership application has been reviewed and approved. It is now marked "Approved/Pending Payment"; please complete payment to activate it.{/ts}

  Click here to pay : https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}
{else}
{ts 1=$membershipTypeName}Good news - your %1 membership application has been reviewed, approved, and is now active.{/ts}
{/if}

{ts}Membership Type{/ts}: {$membershipTypeName}
{ts}Status{/ts}: {$newStatusName}
{if $membershipStartDate}{ts}Start Date{/ts}: {$membershipStartDate|crmDate}
{/if}{if $membershipEndDate}{ts}End Date{/ts}: {$membershipEndDate|crmDate}
{/if}
{ts}Thank you,{/ts}
{domain.name}
