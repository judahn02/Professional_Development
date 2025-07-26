<?php

if (!defined('ABSPATH')) {
    exit ;
}

function Professional_Development_show_user_id ($atts = [], $content = null, $tag = ' ') {

    $usr_ID = get_current_user_id() ;

     ob_start(); // Start output buffering
    ?>
    <div class="pd-metaData-box">
        <p>current user ID: <?php echo esc_html($usr_ID); ?></p>
    </div>
    <?php
    return ob_get_clean(); // Return the buffered content
}