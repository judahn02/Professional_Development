<?php
// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="pd-member-profile-modal-wrap">
	<button type="button" class="pdmp-btn pdmp-btn-primary pdmp-btn-wide" id="pdMemberProfileOpenBtn">
		View Professional Development Record
	</button>

	<div
		class="pdmp-modal-overlay"
		id="pdMemberProfileModal"
		aria-hidden="true"
	>
		<div
			class="pdmp-modal"
			role="dialog"
			aria-modal="true"
			aria-labelledby="pdMemberProfileModalTitle"
		>
			<div class="pdmp-modal-header">
				<h3 class="pdmp-modal-title" id="pdMemberProfileModalTitle">Professional Development Record</h3>
				<button type="button" class="pdmp-modal-close" aria-label="Close">&times;</button>
			</div>

			<div class="pdmp-modal-body">
				<div id="pdMemberProfileError" class="pdmp-error" style="display:none;"></div>

				<section class="pdmp-section pdmp-section-header">
					<h4 class="pdmp-section-title">Member</h4>
					<div class="pdmp-member-meta">
						<div class="pdmp-member-name" id="pdMemberProfileName"></div>
						<div class="pdmp-member-email" id="pdMemberProfileEmail"></div>
						<div class="pdmp-member-phone" id="pdMemberProfilePhone"></div>
					</div>
				</section>

				<section class="pdmp-section">
					<h4 class="pdmp-section-title">Training &amp; Conference History</h4>
					<div class="pdmp-table-wrap">
						<table class="pdmp-table" aria-describedby="pdMemberProfileSessionsCaption">
							<caption id="pdMemberProfileSessionsCaption" class="pdmp-visually-hidden">
								Training and conference sessions attended by this member.
							</caption>
							<thead>
								<tr>
									<th scope="col">Date</th>
									<th scope="col">Session Title</th>
									<th scope="col">Type</th>
									<th scope="col">Hours</th>
									<th scope="col">CEU Capable</th>
									<th scope="col">CEU Weight</th>
									<th scope="col">Parent Event</th>
									<th scope="col">Event Type</th>
								</tr>
							</thead>
							<tbody id="pdMemberProfileSessionsBody">
								<tr><td colspan="8" class="pdmp-cell-muted">No sessions found.</td></tr>
							</tbody>
						</table>
					</div>
				</section>

				<section class="pdmp-section">
					<h4 class="pdmp-section-title">Administrative Service</h4>
					<div class="pdmp-table-wrap">
						<table class="pdmp-table" aria-describedby="pdMemberProfileAdminCaption">
							<caption id="pdMemberProfileAdminCaption" class="pdmp-visually-hidden">
								Administrative service entries for this member.
							</caption>
							<thead>
								<tr>
									<th scope="col">Start</th>
									<th scope="col">End</th>
									<th scope="col">Type</th>
									<th scope="col">CEU Weight</th>
								</tr>
							</thead>
							<tbody id="pdMemberProfileAdminBody">
								<tr><td colspan="4" class="pdmp-cell-muted">No administrative service entries found.</td></tr>
							</tbody>
						</table>
					</div>
				</section>
			</div>

			<div class="pdmp-modal-actions">
				<button type="button" class="pdmp-btn pdmp-btn-primary" id="pdMemberProfileExportBtn">Export CSV</button>
				<button type="button" class="pdmp-btn" id="pdMemberProfileCloseBtn">Close</button>
			</div>
		</div>
	</div>
</div>

<style>
.pd-member-profile-modal-wrap { margin: 1rem 0; }

.pdmp-btn {
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
.pdmp-btn:hover { background: #f9fafb; }
.pdmp-btn-primary { background: #e2144a; border-color: #e2144a; color: #fff; }
.pdmp-btn-primary:hover { filter: brightness(.98); }
.pdmp-btn-wide { padding-left: 3rem; padding-right: 3rem; }

.pdmp-modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  z-index: 10000;
}
.pdmp-modal-overlay.active { display: flex; }

.pdmp-modal {
  width: min(900px, 100%);
  max-height: 90vh;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: .5rem;
  box-shadow: 0 10px 30px rgba(0,0,0,.2);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.pdmp-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid #f1f5f9;
}
.pdmp-modal-title { margin: 0; font-size: 1.125rem; font-weight: 700; color: #e2144a; }
.pdmp-modal-close {
  border: none;
  background: transparent;
  font-size: 1.5rem;
  line-height: 1;
  padding: .25rem .5rem;
  cursor: pointer;
  color: #64748b;
}
.pdmp-modal-close:hover { color: #0f172a; }
.pdmp-modal-body {
  padding: 1rem 1.25rem;
  color: #0f172a;
  overflow-y: auto;
}
.pdmp-modal-actions {
  display: flex;
  gap: .5rem;
  justify-content: flex-end;
  padding: .75rem 1.25rem;
  border-top: 1px solid #f1f5f9;
}

.pdmp-section { margin-bottom: 1.25rem; }
.pdmp-section-title {
  margin: 0 0 .5rem;
  font-size: .95rem;
  font-weight: 600;
  color: #374151;
}

.pdmp-member-meta { display: flex; flex-direction: column; gap: .15rem; margin-bottom: .5rem; }
.pdmp-member-name { font-weight: 600; font-size: .95rem; }
.pdmp-member-email, .pdmp-member-phone { font-size: .85rem; color: #4b5563; }

.pdmp-table-wrap { overflow-x: auto; }
.pdmp-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .85rem;
}
.pdmp-table th,
.pdmp-table td {
  padding: .5rem .6rem;
  border-bottom: 1px solid #e5e7eb;
  text-align: left;
}
.pdmp-table th {
  background: #f9fafb;
  font-weight: 600;
  color: #4b5563;
  font-size: .8rem;
}
.pdmp-cell-muted {
  text-align: center;
  color: #6b7280;
}

.pdmp-error {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #b91c1c;
  padding: .5rem .75rem;
  border-radius: .375rem;
  font-size: .85rem;
  margin-bottom: .75rem;
}

.pdmp-visually-hidden {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
</style>
