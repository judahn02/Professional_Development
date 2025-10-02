SHELL := /bin/bash

.PHONY: check

# Run static checks for AJAX endpoints (nonce + form-encoded POST)
check:
	@echo "Running AJAX nonce checks..."
	@python3 tools/check_ajax_nonces.py

