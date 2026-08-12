PHP_BIN ?= php

.PHONY: check test install-smoke shell-check db-test verify

check:
	./tests/php54-compat.sh

test: check
	$(PHP_BIN) tests/run.php

install-smoke:
	./tests/install-smoke.sh

shell-check:
	sh -n bin/backup.sh bin/verify_backup.sh bin/health_alert.sh bin/production_check.sh install/install-operations.sh install/install.sh install/uninstall.sh

db-test:
	./tests/mariadb-lifecycle.sh

verify: shell-check test install-smoke db-test
	git diff --check
