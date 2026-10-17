.DEFAULT_GOAL := help

.PHONY: help install check test test-backend test-frontend test-tools build assemble package fixtures-up fixtures-down test-e2e

help:
	@printf '%s\n' \
	  'install        Install locked backend, frontend and browser-test dependencies' \
	  'check          Type-check Angular and browser contracts' \
	  'test           Run backend, frontend and build/release unit tests' \
	  'build          Build the production Angular UI' \
	  'assemble       Assemble backend sources into dist/runtime' \
	  'package        Build a complete release from committed sources into dist/release' \
	  'fixtures-up    Build UI and start the fixed disposable test environment' \
	  'test-e2e       Run all five browser profiles against those fixtures' \
	  'fixtures-down  Remove disposable test services and volumes'

install:
	composer --working-dir=backend install --prefer-dist --no-interaction --no-scripts --no-plugins
	npm --prefix frontend ci --ignore-scripts
	npm --prefix tests/e2e ci --ignore-scripts

check:
	npm --prefix frontend run typecheck
	npm --prefix tests/e2e run typecheck

test: test-backend test-frontend test-tools

test-backend:
	composer --working-dir=backend test

test-frontend:
	npm --prefix frontend test

test-tools:
	node --test tools/build/*.test.mjs tools/release/*.test.mjs

build:
	npm --prefix frontend run build

assemble:
	node tools/build/assemble-runtime.mjs

package: build assemble
	composer --working-dir=dist/runtime install --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-scripts --no-plugins
	node tools/release/build-release.mjs

fixtures-up: build
	tools/test/test-stack-v3 up

test-e2e:
	npm --prefix tests/e2e test

fixtures-down:
	tools/test/test-stack-v3 down
