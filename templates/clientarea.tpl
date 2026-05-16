{* templates/clientarea.tpl *}
<div style="padding:1rem; border:1px solid #e5e7eb; border-radius:6px; font-family: system-ui, sans-serif;">
  <h3 style="margin-top:0;">Your Continuum service</h3>
  {if $error}
    <p style="color:#b91c1c;">{$error}</p>
  {else}
    <table cellspacing="0" cellpadding="4" style="margin-bottom:1rem; width:100%;">
      <tr><td>Status</td><td>{if $status == 'active'}<span style="color:#16a34a;">&#9679;</span> Active{elseif $status == 'suspended'}<span style="color:#dc2626;">&#9679;</span> Suspended in Continuum{else}{$status}{/if}</td></tr>
      <tr><td>Stream limit</td><td>{$stream_limit} concurrent</td></tr>
      <tr><td>Quality</td><td>{$quality}</td></tr>
      <tr><td>Libraries</td><td>{foreach from=$library_names item=name name=libs}{$name}{if !$smarty.foreach.libs.last}, {/if}{/foreach}</td></tr>
      <tr><td>Last seen</td><td>{$last_seen_relative}</td></tr>
    </table>
    <a href="{$login_url}" target="_blank" style="display:inline-block; padding:0.6rem 1.2rem; background:#111827; color:white; text-decoration:none; border-radius:6px;">
      Sign in to Continuum &rarr;
    </a>
  {/if}
</div>
