-- Template for the verified one-entry production layout. Confirm the menu table
-- schema and the existing Call Center parent id before applying on another host.
-- sqlite3 /var/www/db/menu.db < install/menu.sql
INSERT OR IGNORE INTO menu (id, IdParent, Link, Name, Type, order_no)
VALUES ('gestion_clientes', 'call_center', '', 'Gestión de Clientes', 'module', 10);

-- The module selects the supervisor or agent landing page after authenticating.
-- Do not create separate workspace/dashboard menu rows: production exposes one
-- Call Center -> Gestión de Clientes entry and grants that resource through ACL.
