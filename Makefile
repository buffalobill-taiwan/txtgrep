PHP ?= php
PORT ?= 8083

.PHONY: setup index serve test lint

setup: ; $(PHP) bin/setup.php
index: ; $(PHP) bin/index.php
serve: ; $(PHP) -S 127.0.0.1:$(PORT) -t public
test: ; $(PHP) tests/run.php
lint: ; $(PHP) tests/run.php
