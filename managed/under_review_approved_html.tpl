<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
 <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
 <title></title>
    {literal}
      <style type="text/css"> body {} .ReadMsgBody {width: 100%;} .ExternalClass {width:100%;} body {padding:0; margin:0; color:black; font-size:1em; font-family:Arial, Helvetica, sans-serif; } span.yshortcuts { color:#000000; background-color:transparent; border:none;} span.yshortcuts:hover, span.yshortcuts:active, span.yshortcuts:focus {color:#000000; background-color:transparent; border:none;} h1, h2, h3 {color:#4e384e;} .landing_page_link {display: inline-block;padding: 10px 30px;line-height: inherit;text-decoration: none;cursor: pointer;color: #fff !important;background-color: #cb612a;border: 0;margin: .2rem;min-width: 9rem;text-align: center;font-weight: 400;font-size: 1rem;text-transform: uppercase;text-decoration: none;} .pay_now{ background: #eeeeee;border: 1px solid #cccccc;padding: 5px 10px;}
      </style>
    {/literal}
</head>
<body>

{capture assign=labelStyle}style="padding: 4px; border-bottom: 1px solid #999; background-color: #f7f7f7;"{/capture}
{capture assign=valueStyle}style="padding: 4px; border-bottom: 1px solid #999;"{/capture}

{assign var="greeting" value="{contact.email_greeting_display}"}{if $greeting}<p>{$greeting},</p>{/if}


{if $newStatusName eq 'Approved/Pending Payment'}
<p>
  {ts 1=$membershipTypeName}Good news - your %1 membership application has been reviewed and approved. It is now marked "Approved/Pending Payment"; please complete payment to activate it.{/ts}
</p>
  <p>
  <div class="pay_now">
    Credit Card or Bank Transfer (ACH/EFT): Click Pay Now<br/>
    <a href="https://www.naatp.org/civicrm/my-dashboard?id={contact.contact_id}&{contact.checksum}" title="" class="landing_page_link" >Pay Now</a>
  </div>
  </p>
{else}
<p>
  {ts 1=$membershipTypeName}Good news - your %1 membership application has been reviewed, approved, and is now active.{/ts}
</p>
{/if}


<table style="width:100%; max-width:500px; border: 1px solid #999; margin: 1em 0em 1em; border-collapse: collapse;">
  <tr>
    <th colspan="2" style="text-align: left; padding: 4px; border-bottom: 1px solid #999; background-color: #eee;">
      {ts}Membership Information{/ts}
    </th>
  </tr>
  <tr>
    <td {$labelStyle}>{ts}Membership Type{/ts}</td>
    <td {$valueStyle}>{$membershipTypeName}</td>
  </tr>
  <tr>
    <td {$labelStyle}>{ts}Status{/ts}</td>
    <td {$valueStyle}>{$newStatusName}</td>
  </tr>
  {if $membershipStartDate}
  <tr>
    <td {$labelStyle}>{ts}Start Date{/ts}</td>
    <td {$valueStyle}>{$membershipStartDate|crmDate}</td>
  </tr>
  {/if}
  {if $membershipEndDate}
  <tr>
    <td {$labelStyle}>{ts}End Date{/ts}</td>
    <td {$valueStyle}>{$membershipEndDate|crmDate}</td>
  </tr>
  {/if}
</table>

<p>{ts}Thank you,{/ts}<br />{domain.name}</p>

</body>
</html>
