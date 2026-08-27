<div class="crm-block crm-form-block crm-membershipapprovalworkflow-approve-form-block">

  <div class="crm-section">
    <div class="label">{ts}Current status{/ts}</div>
    <div class="content">{$currentStatusLabel}</div>
  </div>

  {if $hasActions}
    <table class="form-layout">
      {include file="CRM/common/formButtons.tpl" location="top"}
      <tr class="crm-membershipapprovalworkflow-form-block-approval_action">
        <td class="label">{$form.approval_action.label}</td>
        <td>{$form.approval_action.html}</td>
      </tr>
    </table>
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  {else}
    <div class="messages status no-popup">
      {icon icon="fa-info-circle"}{/icon}
      {ts}No approval action is available for the current membership status.{/ts}
    </div>
    <div class="crm-submit-buttons">
      <a href="{$backUrl}" class="button"><span>{ts}Back{/ts}</span></a>
    </div>
  {/if}

</div>
