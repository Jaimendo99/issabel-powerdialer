<div class="gc-module"><div class="gc-heading"><h2>Extensiones de puestos</h2></div>
<p>Estas extensiones pertenecen a los puestos de trabajo. Cada agente elige una al iniciar su sesión; las estadísticas permanecen asociadas a su usuario.</p>
<form method="post" action="{$action_url|escape:'html'}" class="gc-form"><input type="hidden" name="csrf_token" value="{$csrf_token|escape:'html'}" /><input type="hidden" name="idempotency_key" value="{$idempotency_key|escape:'html'}" />
<label>Extensión SIP <input name="sip_extension" required="required" pattern="[0-9]{1,20}" maxlength="20" /></label>
<label>Nombre del puesto <input name="label" required="required" maxlength="80" placeholder="Puesto 1" /></label>
<label><input type="checkbox" name="active" value="1" checked="checked" /> Disponible</label>
<button type="submit">Guardar puesto</button></form>
<table class="gc-table"><thead><tr><th>Extensión</th><th>Puesto</th><th>Disponible</th></tr></thead><tbody>{foreach from=$seats item=seat}<tr><td>{$seat.sip_extension|escape:'html'}</td><td>{$seat.label|escape:'html'}</td><td>{if $seat.active}Sí{else}No{/if}</td></tr>{foreachelse}<tr><td colspan="3">No hay extensiones configuradas.</td></tr>{/foreach}</tbody></table></div>
