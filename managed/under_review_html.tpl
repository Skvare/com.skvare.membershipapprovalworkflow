<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
 <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
 <title></title>
    {literal}
      <style type="text/css"> body {} .ReadMsgBody {width: 100%;} .ExternalClass {width:100%;} body {padding:0; margin:0; color:black; font-size:1em; font-family:Arial, Helvetica, sans-serif; } span.yshortcuts { color:#000000; background-color:transparent; border:none;} span.yshortcuts:hover, span.yshortcuts:active, span.yshortcuts:focus {color:#000000; background-color:transparent; border:none;} h1, h2, h3 {color:#4e384e;}
      </style>
    {/literal}
</head>
<body>

{capture assign=labelStyle}style="padding: 4px; border-bottom: 1px solid #999; background-color: #f7f7f7;"{/capture}
{capture assign=valueStyle}style="padding: 4px; border-bottom: 1px solid #999;"{/capture}

{assign var="greeting" value="{contact.email_greeting_display}"}{if $greeting}<p>{$greeting},</p>{/if}

<p>
  {ts 1=$membershipTypeName}Thank you for your %1 membership application. It is now under review by our staff; we'll email you again as soon as a decision has been made.{/ts}
</p>

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
    <td {$valueStyle}>{ts}Under Review{/ts}</td>
  </tr>
</table>

<p>{ts}Thank you,{/ts}<br />{domain.name}</p>

</body>
</html>
