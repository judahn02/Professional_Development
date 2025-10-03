<?php

if (!defined('ABSPATH')) {
    exit ;
}

function Pofessional_Development_show_member_progress ($atts = [], $content= null, $tag = '') {


    // get variables 

    $usr_ID = get_current_user_id() ;
    $host = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_host', ''));
    $name = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_name', ''));
    $user = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_user', ''));
    $pass = ProfessionalDevelopment_decrypt(get_option('ProfessionalDevelopment_db_pass', ''));


    ob_start() ; ?>
    <div class="container">
        <div class="max-width">
            <h1 class="main-title">Your ASLTA Profile</h1>

            <div class="grid grid-2">
                <div class="card card-primary">
                    <div class="card-header">
                        <h2 class="card-title">Profile Information</h2>
                    </div>
                    <div class="card-content space-y-3">
                        <p class="font-semibold">Name: Darcy Red</p>
                        <p class="font-semibold">ID: dar_red@gmail.com</p>
                        <p class="font-semibold">Certification Level: Certified</p>
                        <p class="font-semibold">Membership Status: Active</p>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            <svg class="icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                            </svg>
                            Progress Summary
                        </h2>
                    </div>
                    <div class="card-content space-y-3">
                        <p>Total Hours all-time: <span class="font-bold text-primary" id="totalHours">23</span></p>
                        <div class="range-control space-y-3">
                            <label for="yearRange">
                                <span class="font-bold">Total Hours in the last</span>
                                <span class="font-bold" id="yearLabel">1</span>
                                <span class="font-bold">year(s):</span>
                            </label>
                            <span class="font-bold text-primary" id="filteredHours">0</span>
                            <input type="range" min="1" max="10" value="1" id="yearRange" style="width: 100%;">
                        </div>
                        <p>Most recent session: <span class="font-bold">3/14/2025 – ASL Conference 1</span></p>
                        <button class="btn btn-sm mt-2" onclick="exportReport()">
                            <svg class="icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                            Export Report
                        </button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Training & Conference History</h2>
                    <div class="search-container">
                        <div class="search-input-wrapper">
                            <svg class="search-icon icon-sm" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input 
                                type="text" 
                                class="search-input" 
                                placeholder="Search sessions..."
                                id="searchInput"
                                oninput="filterSessions()"
                            >
                        </div>
                        <div class="filter-buttons">
                            <button class="btn btn-primary btn-sm" data-filter="all" onclick="setFilter('all')">All</button>
                            <button class="btn btn-sm" data-filter="workshops" onclick="setFilter('workshops')">Workshops</button>
                            <button class="btn btn-sm" data-filter="training" onclick="setFilter('training')">Training</button>
                            <button class="btn btn-sm" data-filter="conference" onclick="setFilter('conference')">Conference</button>
                        </div>
                    </div>
                </div>
                <div class="card-content">
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Session Title</th>
                                    <th>Type</th>
                                    <th>Hours</th>
                                </tr>
                            </thead>
                            <tbody id="sessionsTable">
                                <!-- Sessions will be populated by JavaScript -->
                            </tbody>
                        </table>
                        <div id="emptyState" class="empty-state" style="display: none;">
                            No sessions found matching your criteria.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php
    return ob_get_clean() ;
}
