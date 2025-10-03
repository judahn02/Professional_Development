#!/usr/bin/env python3
from __future__ import annotations
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
F = ROOT / 'includes' / 'functions.php'

text = F.read_text(encoding='utf-8', errors='ignore')

errors = []

# 1) derive_key exists and uses wp_salt/site_url
if 'function ProfessionalDevelopment_derive_key' not in text:
    errors.append('derive_key function not found')

if 'wp_salt' not in text or 'site_url' not in text:
    errors.append('derive_key does not reference wp_salt/site_url')

# 2) encrypt uses derive_key
if re.search(r'function\s+ProfessionalDevelopment_encrypt\s*\(.*?\)\s*{[\s\S]*?ProfessionalDevelopment_derive_key\s*\(', text) is None:
    errors.append('encrypt() does not use ProfessionalDevelopment_derive_key')

# 3) decrypt uses derive_key first
if re.search(r'function\s+ProfessionalDevelopment_decrypt[\s\S]*?\$key\s*=\s*ProfessionalDevelopment_derive_key\s*\(', text) is None:
    errors.append('decrypt() does not try derive_key first')

# 4) No hardcoded fallback assignment in encrypt
if re.search(r"encrypt\([\s\S]*?\$key\s*=\s*defined\(\'PS_ENCRYPTION_KEY\'\).*?\'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm\'", text):
    errors.append('encrypt() still assigns hardcoded fallback key')

# 5) Legacy fallback allowed only in decrypt
if 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm' in text:
    # ensure it appears only in decrypt block
    decrypt_section = re.search(r'function\s+ProfessionalDevelopment_decrypt[\s\S]*?}', text)
    if not decrypt_section or 'hT4vaqdf3FLZePEyMfNbNn1M4SJf7Smm' not in decrypt_section.group(0):
        errors.append('legacy fallback key appears outside decrypt()')

if errors:
    print('Encryption key derivation test FAILED:')
    for e in errors:
        print(' -', e)
    sys.exit(1)
else:
    print('Encryption key derivation test OK')

