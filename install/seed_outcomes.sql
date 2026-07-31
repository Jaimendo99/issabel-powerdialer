USE `gestion_clientes`;

INSERT INTO gc_outcome
  (campaign_id, code, label, display_order, active, resulting_client_state,
   terminal, requires_callback, mark_phone_invalid, advance_to_next)
SELECT NULL, s.code, s.label, s.display_order, 1, s.resulting_client_state,
       s.terminal, s.requires_callback, s.mark_phone_invalid, s.advance_to_next
FROM (
  SELECT 'PENDING' code, 'Pendiente' label, 10 display_order, 'PENDING' resulting_client_state, 0 terminal, 0 requires_callback, 0 mark_phone_invalid, 1 advance_to_next
  UNION ALL SELECT 'CALLBACK','Volver a llamar',20,'CALLBACK',0,1,0,1
  UNION ALL SELECT 'INTERESTED','Interesado',30,'INTERESTED',0,0,0,1
  UNION ALL SELECT 'NOT_INTERESTED','No interesado',40,'NOT_INTERESTED',1,0,0,1
  UNION ALL SELECT 'SALE','Venta',50,'SALE',1,0,0,1
  UNION ALL SELECT 'INVALID_NUMBER','Número inválido',60,'NO_CONTACT',0,0,1,0
  UNION ALL SELECT 'NO_CONTACT','Sin contacto',70,'NO_CONTACT',0,0,0,1
  UNION ALL SELECT 'CLOSED_OTHER','Cerrado - otro',80,'CLOSED_OTHER',1,0,0,1
) s
WHERE NOT EXISTS (SELECT 1 FROM gc_outcome o WHERE o.campaign_id IS NULL AND o.code=s.code);
