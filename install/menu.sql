-- Template only. Confirm the production menu.db schema before applying.
-- sqlite3 /var/www/db/menu.db < install/menu.sql
INSERT OR IGNORE INTO menu (id, IdParent, Link, Name, Type, order_no)
VALUES ('gestion_clientes', '', '?menu=gestion_clientes', 'Gestión de Clientes', 'module', 900);
INSERT OR IGNORE INTO menu (id, IdParent, Link, Name, Type, order_no)
VALUES ('gestion_clientes_workspace', 'gestion_clientes', '?menu=gestion_clientes&action=workspace', 'Mi cartera', 'module', 10);
INSERT OR IGNORE INTO menu (id, IdParent, Link, Name, Type, order_no)
VALUES ('gestion_clientes_dashboard', 'gestion_clientes', '?menu=gestion_clientes&action=dashboard', 'Dashboard', 'module', 20);
