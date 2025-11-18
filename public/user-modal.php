<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- PD Public Test Modal -->
<div class="pd-user-test-modal-wrap">
    <button type="button" class="pd-btn pd-btn-primary pd-btn-wide" id="pdOpenUserTestModal">
        Open Test Modal
    </button>

    <div
        class="pd-modal-overlay"
        id="pdUserTestModal"
        aria-hidden="true"
    >
        <div
            class="pd-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="pdUserTestModalTitle"
        >
            <div class="pd-modal-header">
                <h3 class="pd-modal-title" id="pdUserTestModalTitle">Test Modal</h3>
                <button type="button" class="pd-modal-close" aria-label="Close">&times;</button>
            </div>

            <div class="pd-modal-body">
                <p>This is a simple test modal on the public side.</p>
                <p>You can close it by clicking outside, the ×, or pressing ESC.</p>
            </div>

            <div class="pd-modal-actions">
                <button type="button" class="pd-btn pd-btn-primary" id="pdConfirmBtn">Confirm</button>
                <button type="button" class="pd-btn" id="pdCancelBtn">Cancel</button>
            </div>
        </div>
    </div>
</div>

<style>
/* Scoped styles for the public test modal */
.pd-user-test-modal-wrap { margin: 1rem 0; }

.pd-btn {
  display: inline-flex;
  align-items: center;
  gap: .5rem;
  padding: .5rem 1rem;
  border: 1px solid #d1d5db;
  border-radius: .375rem;
  background: #fff;
  color: #374151;
  font-size: .875rem;
  font-weight: 500;
  cursor: pointer;
}
.pd-btn:hover { background: #f9fafb; }
.pd-btn-primary { background: #e2144a; border-color: #e2144a; color: #fff; }
.pd-btn-primary:hover { filter: brightness(.98); }
.pd-btn-wide { padding-left: 4rem; padding-right: 4rem; }

.pd-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 10000;
}
.pd-modal-overlay.active { display: flex; }

.pd-modal {
  width: min(600px, 100%);
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: .5rem;
  box-shadow: 0 10px 30px rgba(0,0,0,.2);
  overflow: hidden;
}
.pd-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
}
.pd-modal-title { margin: 0; font-size: 1.125rem; font-weight: 700; color: #e2144a; }
.pd-modal-close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  padding: .25rem .5rem;
  cursor: pointer;
  color: #64748b;
}
.pd-modal-close:hover { color: #0f172a; }
.pd-modal-body { padding: 1rem 1.25rem; color: #0f172a; }
.pd-modal-actions { display: flex; gap: .5rem; justify-content: flex-end; padding: 1rem 1.25rem; border-top: 1px solid #f1f5f9; }
</style>

<script>
(function() {
  var overlay = document.getElementById('pdUserTestModal');
  var modal   = overlay ? overlay.querySelector('.pd-modal') : null;
  var openBtn = document.getElementById('pdOpenUserTestModal');
  var closeBtn = overlay ? overlay.querySelector('.pd-modal-close') : null;
  var cancelBtn = document.getElementById('pdCancelBtn');
  var confirmBtn = document.getElementById('pdConfirmBtn');
  var lastFocused = null;

  if (!overlay || !modal || !openBtn) return;

  function open() {
    lastFocused = document.activeElement;
    overlay.classList.add('active');
    overlay.removeAttribute('aria-hidden');
    document.addEventListener('keydown', onKeyDown);
    // focus first focusable element
    var focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
    setTimeout(function(){ (focusables[0] || closeBtn || modal).focus(); }, 0);
  }

  function close() {
    overlay.classList.remove('active');
    overlay.setAttribute('aria-hidden', 'true');
    document.removeEventListener('keydown', onKeyDown);
    if (lastFocused && typeof lastFocused.focus === 'function') {
      lastFocused.focus();
    }
  }

  function onKeyDown(e) {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key === 'Tab') {
      var focusables = modal.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
      if (!focusables.length) return;
      var first = focusables[0];
      var last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  }

  openBtn.addEventListener('click', open);
  if (closeBtn) closeBtn.addEventListener('click', close);
  if (cancelBtn) cancelBtn.addEventListener('click', close);
  overlay.addEventListener('click', function(e) { if (e.target === overlay) close(); });
  if (confirmBtn) confirmBtn.addEventListener('click', function(){ try { alert('Confirmed!'); } catch(_) {} close(); });
})();
</script>
