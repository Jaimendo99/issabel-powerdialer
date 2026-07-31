PHP_BIN ?= php

.PHONY: check test install-smoke

check:
	./tests/php54-compat.sh

test: check
	$(PHP_BIN) tests/run.php

install-smoke:
	./tests/install-smoke.sh
