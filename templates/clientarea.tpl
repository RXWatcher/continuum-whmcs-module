{* templates/clientarea.tpl *}
<div style="font-family:system-ui,-apple-system,sans-serif; max-width:640px;">
  {if $error}
    <div style="padding:1rem; border:1px solid #fecaca; background:#fef2f2; border-radius:8px; color:#b91c1c;">
      {$error|escape:'html'}
    </div>
  {else}
    <div style="border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

      <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.25rem; background:#111827; color:#fff;">
        <div>
          <div style="font-size:1.05rem; font-weight:600;">Your Silo account</div>
          {if $username}<div style="font-size:0.85rem; opacity:0.75; margin-top:0.15rem;">Username: <strong>{$username|escape:'html'}</strong></div>{/if}
        </div>
        {if $status == 'active'}
          <span style="font-size:0.8rem; background:#065f46; padding:0.25rem 0.6rem; border-radius:999px;">&#9679; Active</span>
        {elseif $status == 'suspended'}
          <span style="font-size:0.8rem; background:#7f1d1d; padding:0.25rem 0.6rem; border-radius:999px;">&#9679; Suspended</span>
        {else}
          <span style="font-size:0.8rem; background:#374151; padding:0.25rem 0.6rem; border-radius:999px;">{$status|escape:'html'}</span>
        {/if}
      </div>

      <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:0.75rem 1.5rem; padding:1.25rem;">
        {if $stream_limit !== null}<div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Streams</div><div style="font-weight:600;">{if $active_streams !== null}{$active_streams|intval} / {/if}{$stream_limit|intval}</div></div>{/if}
        {if $transcode_limit !== null}<div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Transcodes</div><div style="font-weight:600;">{$transcode_limit|intval}</div></div>{/if}
        {if $profile_limit !== null}<div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Profile slots</div><div style="font-weight:600;">{if $profiles_used !== null}{$profiles_used|intval} / {/if}{$profile_limit|intval}</div></div>{/if}
        <div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Quality</div><div style="font-weight:600;">{$quality|escape:'html'}</div></div>
        {if $downloads !== null}<div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Downloads</div><div style="font-weight:600;">{$downloads|escape:'html'}</div></div>{/if}
        <div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Last seen</div><div style="font-weight:600;">{$last_seen_relative|escape:'html'}</div></div>
        {if $member_since}<div><div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em;">Member since</div><div style="font-weight:600;">{$member_since|escape:'html'}</div></div>{/if}
      </div>

      <div style="padding:0 1.25rem 1.25rem;">
        <div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:0.25rem;">Libraries</div>
        <div>{foreach from=$library_names item=name name=libs}{$name|escape:'html'}{if !$smarty.foreach.libs.last}, {/if}{foreachelse}&mdash;{/foreach}</div>

        {if $profile_names}
          <div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em; margin:0.9rem 0 0.25rem;">Profiles</div>
          <div>{foreach from=$profile_names item=p name=pr}{$p|escape:'html'}{if !$smarty.foreach.pr.last}, {/if}{/foreach}</div>
        {/if}

        {if $now_watching}
          <div style="font-size:0.75rem; color:#6b7280; text-transform:uppercase; letter-spacing:0.03em; margin:0.9rem 0 0.25rem;">Watching now</div>
          <ul style="margin:0; padding-left:1.1rem;">
            {foreach from=$now_watching item=w}<li>{$w|escape:'html'}</li>{/foreach}
          </ul>
        {/if}
      </div>

      <div style="padding:1rem 1.25rem; border-top:1px solid #e5e7eb;">
        <a href="{$login_url|escape:'html'}" target="_blank" rel="noopener" style="display:inline-block; padding:0.6rem 1.2rem; background:#111827; color:#fff; text-decoration:none; border-radius:6px; font-weight:600;">
          Sign in to Silo &rarr;
        </a>
      </div>

    </div>
  {/if}
</div>
