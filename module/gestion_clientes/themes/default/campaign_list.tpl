<div class="gc-module">
  <div class="gc-heading"><h2>{$title|default:'Campañas'|escape:'html'}</h2><a class="button" href="{$create_url|escape:'html'}">Nueva campaña</a></div>
  <nav class="gc-actions"><a href="{$import_url|escape:'html'}">Importar</a><a href="{$assignment_url|escape:'html'}">Asignar</a><a href="{$mapping_url|escape:'html'}">Agentes</a><a href="{$seats_url|escape:'html'}">Puestos</a><a href="{$callbacks_url|escape:'html'}">Callbacks</a><a href="{$audit_url|escape:'html'}">Auditoría</a></nav>
  {if $message|default:''}<div class="gc-alert gc-alert-info">{$message|escape:'html'}</div>{/if}
  <form class="gc-filters" method="get" action="{$action_url|default:''|escape:'html'}">
    <input type="hidden" name="menu" value="gestion_clientes" /><input type="hidden" name="action" value="campaign_list" />
    <label>{$label_search|default:'Buscar'|escape:'html'} <input type="text" name="q" value="{$filters.q|default:''|escape:'html'}" /></label>
    <label>{$label_status|default:'Estado'|escape:'html'} <select name="status"><option value="">{$label_all|default:'Todos'|escape:'html'}</option>{foreach from=$statuses|default:array() item=s}<option value="{$s.value|default:''|escape:'html'}"{if $filters.status|default:'' == $s.value|default:''} selected="selected"{/if}>{$s.label|default:''|escape:'html'}</option>{/foreach}</select></label>
    <button type="submit">{$label_filter|default:'Filtrar'|escape:'html'}</button>
  </form>
  <div class="gc-table-wrap"><table class="gc-table"><thead><tr><th>{$label_name|default:'Nombre'|escape:'html'}</th><th>{$label_status|default:'Estado'|escape:'html'}</th><th>{$label_progress|default:'Progreso'|escape:'html'}</th><th>{$label_timezone|default:'Zona horaria'|escape:'html'}</th><th>{$label_actions|default:'Acciones'|escape:'html'}</th></tr></thead><tbody>
  {foreach from=$campaigns|default:array() item=row}<tr><td>{$row.name|default:''|escape:'html'}</td><td><span class="gc-badge">{$row.status_label|default:$row.status|default:''|escape:'html'}</span></td><td>{$row.managed|default:0|escape:'html'} / {$row.total|default:0|escape:'html'} ({$row.progress|default:'0%'|escape:'html'})</td><td>{$row.timezone|default:''|escape:'html'}</td><td><a href="{$row.edit_url|default:'#'|escape:'html'}">{$label_edit|default:'Editar'|escape:'html'}</a> · <a href="{$row.workspace_url|default:'#'|escape:'html'}">{$label_view|default:'Ver'|escape:'html'}</a></td></tr>{foreachelse}<tr><td colspan="5" class="gc-empty">{$label_empty|default:'No hay campañas para mostrar.'|escape:'html'}</td></tr>{/foreach}
  </tbody></table></div>
</div>
