SHELL := /bin/bash

SCAN_EXPECTED ?= 3
SCAN_OUTPUT ?= tofix.txt

.PHONY: check

# Run static checks for AJAX endpoints (nonce + form-encoded POST)
check:
	@echo "Running AJAX nonce checks..."
	@python3 tools/check_ajax_nonces.py
	@echo "Running encryption key derivation checks..."
	@python3 tools/test_encryption_key_derivation.py
	@echo "Running terminology scan..."
	@python3 tools/scan_bad_keywords.py --expected $(SCAN_EXPECTED) --output $(SCAN_OUTPUT) -e .php,.js,.css
