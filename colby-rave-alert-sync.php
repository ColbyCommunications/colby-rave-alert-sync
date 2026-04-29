<?php
/**
 * Plugin Name:       Colby Rave Alert Sync
 * Description:       Monitors a Rave RSS feed and updates an ACF-based site alert banner and a specific alert page.
 * Version:           1.0.0
 * Author:            Brandon Waltz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       colby-rave-alert
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// --- Configuration ---

/**
 * Define the Page ID of the "Colby College Updates" page.
 * This is the page that will be updated with alert content.
 */
define( 'COLBY_ALERT_PAGE_ID', 42334 );

/**
 * Define the URL of the Rave RSS feed to monitor.
 */
define( 'COLBY_RAVE_FEED_URL', 'https://content.getrave.com/rss/colby/channel1' );

/**
 * Define the hook name for our cron job.
 */
define( 'COLBY_RAVE_CRON_HOOK', 'colby_check_rave_feed' );

/**
 * Define the option key to store the pubDate of the last processed feed item.
 */
define( 'COLBY_RAVE_LAST_DATE_OPTION', 'colby_last_rave_alert_date' );

/**
 * Define the option key to store the last processed description.
 * This helps us track the state (e.g., was it 'CLEAR FEED' or an alert?).
 */
define( 'COLBY_RAVE_LAST_DESC_OPTION', 'colby_last_rave_alert_description' );


// --- ACF Dependency Check ---

/**
 * Displays an admin notice if ACF Pro is not active.
 */
function colby_rave_admin_notice_missing_acf() {
	if ( ! class_exists( 'ACF' ) ) {
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post( '<strong>Colby Rave Alert Sync:</strong> This plugin requires Advanced Custom Fields Pro to be installed and activated. The feed will not be checked.' );
		echo '</p></div>';
	}
}
add_action( 'admin_notices', 'colby_rave_admin_notice_missing_acf' );

// --- Cron Job Setup ---

/**
 * Adds a custom 5-minute cron schedule.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Modified cron schedules.
 */
function colby_rave_add_cron_interval( $schedules ) {
	$schedules['five_minutes'] = array(
		'interval' => 300, // 5 minutes in seconds
		'display'  => esc_html__( 'Every Five Minutes' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'colby_rave_add_cron_interval' );

/**
 * Schedules the cron event upon plugin activation.
 */
function colby_rave_activate_plugin() {
	if ( ! wp_next_scheduled( COLBY_RAVE_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'five_minutes', COLBY_RAVE_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'colby_rave_activate_plugin' );

/**
 * Clears the scheduled cron event upon plugin deactivation.
 */
function colby_rave_deactivate_plugin() {
	wp_clear_scheduled_hook( COLBY_RAVE_CRON_HOOK );
}
register_deactivation_hook( __FILE__, 'colby_rave_deactivate_plugin' );

// --- Main Plugin Logic ---

/**
 * The main function that checks the RSS feed and updates the site.
 * This is hooked into our custom cron schedule.
 */
function colby_do_rave_feed_check() {

	// Stop if ACF isn't active.
	if ( ! function_exists( 'update_field' ) ) {
		error_log( 'Colby Rave Alert: ACF function update_field() not found. Halting feed check.' );
		return;
	}

	// WordPress Core function to fetch and parse RSS feeds.
	include_once ABSPATH . WPINC . '/feed.php';

	$feed = fetch_feed( COLBY_RAVE_FEED_URL );

	if ( is_wp_error( $feed ) ) {
		error_log( 'Colby Rave Alert: Failed to fetch feed. ' . $feed->get_error_message() );
		return;
	}

	// Get the first (and only) item.
	$item = $feed->get_item( 0 );

	if ( ! $item ) {
		error_log( 'Colby Rave Alert: Feed is empty or could not be parsed.' );
		return;
	}

	// Get data from the new feed item.
	$new_description = trim( $item->get_description() );
	$new_pub_date    = $item->get_date( 'U' ); // Get as Unix timestamp.

	// Get the previously stored state from the database.
	$last_pub_date    = get_option( COLBY_RAVE_LAST_DATE_OPTION );
	$last_description = get_option( COLBY_RAVE_LAST_DESC_OPTION, 'CLEAR FEED' ); // Default to 'CLEAR FEED'.

	// --- Check if anything has changed ---
	// Use pubDate (as timestamp) to check for new items instead of GUID.
	if ( $new_pub_date == $last_pub_date ) {
		// The pubDate is the same as the last one we processed.
		// This means the feed hasn't updated. Do nothing.
		return;
	}


	// A new item is present. Let's process it.
	$is_new_alert = ( 'CLEAR FEED' !== $new_description );
	$was_clear    = ( 'CLEAR FEED' === $last_description );

	if ( $is_new_alert ) {

        update_field('alert_active', true, 'options');
        update_field('alert_heading', '', 'options');

        $sync_from_rave = get_field('alert_from_rave', 'options');

        if ($sync_from_rave) {
		    update_field( 'alert_paragraph', $new_description, 'option' ); // alert_active
        }

        update_field('alert_buttons', 1 ,'options');
        update_field('alert_buttons_0_button', array( "title" => "Updates", "url" => "https://www.colby.edu/college-updates/", "target" => "_blank" ) ,'options');
		update_field( 'alert_type', 'emergency', 'option' ); // alert_type

		$formatted_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $new_pub_date );
		$new_entry_html = "<!-- wp:paragraph -->\n<p><strong>" . esc_html( $formatted_date ) . "</strong><br>" . wp_kses_post( $new_description ) . "</p>\n<!-- /wp:paragraph -->\n\n";
        
		$page_content = '';

		if ( $was_clear ) {
			// This is the FIRST alert after a "CLEAR FEED".
			// As requested, we remove all old content and add the new one.
			$page_content = $new_entry_html;
		} else {
			// This is a NEW alert replacing an OLD alert.
			// As requested, we prepend the new alert to the existing content.
			$current_post = get_post( COLBY_ALERT_PAGE_ID );
			$current_content = $current_post ? $current_post->post_content : '';
			$page_content = $new_entry_html . $current_content;
		}

		// 3. Update and Publish the Alert Page.
		$post_data = array(
			'ID'           => COLBY_ALERT_PAGE_ID,
			'post_content' => $page_content,
			'post_status'  => 'publish',
		);
		wp_update_post( $post_data );

	} else {
		// --- STATE: "CLEAR FEED" is Active ---

		// 1. De-activate the ACF Global Settings Alert Banner.
		update_field('alert_active', false, 'options');
		update_field('alert_from_rave', true, 'options');

		// 2. Unpublish the Alert Page (set to 'draft').
		// The content will be preserved for the next alert cycle.
		$post_data = array(
			'ID'          => COLBY_ALERT_PAGE_ID,
			'post_status' => 'draft',
		);
		wp_update_post( $post_data );
	}

	// --- Save the new state ---
	// Store the GUID and description of the item we just processed.
	update_option( COLBY_RAVE_LAST_DATE_OPTION, $new_pub_date );
	update_option( COLBY_RAVE_LAST_DESC_OPTION, $new_description );
}
// Hook our main function into the cron action.
add_action( COLBY_RAVE_CRON_HOOK, 'colby_do_rave_feed_check' );
