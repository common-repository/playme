<?php
/*
Plugin Name: PlayMe
Description: Embeddable Song Request Form for Radio Stations
Version: 0.2.7
Author: era404
Author URI: http://www.era404.com
License: GPLv2 or later.
Copyright 2014 ERA404 Creative Group, Inc.
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as 
published by the Free Software Foundation.
This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
*/

/***********************************************************************************
*     Setup Plugin > Create Table & Build/Destroy Table(s)
***********************************************************************************/
// this hook will cause our creation function to run when the plugin is activated
register_activation_hook(   __FILE__, 'PlayMe_install' );
register_deactivation_hook( __FILE__, 'PlayMe_uninstall' );
register_uninstall_hook(    __FILE__, 'PlayMe_uninstall' );

/***********************************************************************************
 *     Add Required Styles & Scripts (Front End)
***********************************************************************************/
add_action( 'wp_enqueue_scripts', 'PlayMe_required' ); 
function PlayMe_required() {
	wp_register_style( 	'PlayMe_styles', plugins_url('styles.css', __FILE__) );
	wp_enqueue_style( 	'PlayMe_styles' );

	wp_enqueue_script(	'PlayMe_script', plugins_url('scripts.js', __FILE__), array('jquery'), '1.1', true );
	wp_enqueue_script(	'PlayMe_script');
	wp_localize_script(	'PlayMe_script', 'ajax_object', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ) ) ); 	// setting ajaxurl
}

/***********************************************************************************
*	AJAX Function(s)
***********************************************************************************/
//ajax functions
add_action( 'wp_ajax_playme_submitrequest', 		'playme_ajax_submitrequest' ); //authd
add_action( 'wp_ajax_nopriv_playme_submitrequest', 	'playme_ajax_submitrequest' ); //nonauthd

add_action( 'wp_ajax_playme_fetchrequests', 		'playme_ajax_fetchrequests' ); //authd
add_action( 'wp_ajax_playme_deleterequest', 		'playme_ajax_deleterequest' ); //authd

/***********************************************************************************
*	INIT Function(s)
***********************************************************************************/
add_action( 'admin_init', 'PlayMe_admin_init' );
function PlayMe_admin_init() {
	add_action( 'wp_enqueue_scripts', 'PlayMe_required' ); 
}

/***********************************************************************************
*	Song Requests are shown from the newest to the oldest
*	Only the newest (n) will be displayed
*	Set this value, below:
***********************************************************************************/
define("PLAYME_MAX_SONG_REQUESTS", 25);
define("PLAYME_IP_LOCATION_SERVICE", 'https://whatismyipaddress.com/ip/{ip}');

/***********************************************************************************
*     Setup Plugin Menu(s)
***********************************************************************************/
add_action( 'admin_menu', 'PlayMe_admin_menu' );
function PlayMe_admin_menu() {
	if(!current_user_can('view_playme_requests')) return;
	
	//setting the badge
	global $wpdb;
    $results = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}requests WHERE hide<1 LIMIT ".PLAYME_MAX_SONG_REQUESTS, OBJECT );
    if(!empty($results)){
    	set_transient( 'playme_requests', $results );
	    $requests_count = count( $results );
	    $requests_title = esc_attr( sprintf( '%d NEW Requests', $requests_count ) );
	    $menu_label = sprintf( __( 'PlayMe %s' ), "<span class='update-plugins count-$requests_count' title='$requests_title'><span class='update-count'>" . number_format_i18n($requests_count) . "</span></span>" );
    } else {
    	$menu_label = "PlayMe";
    }
	
    $playme_admin = add_menu_page(	"PlayMe", 
				    				 $menu_label, 
				    				"view_playme_requests",
				    				"PlayMe",
				    				"PlayMe_plugin_options",
				    				"dashicons-format-audio",//https://developer.wordpress.org/reference/functions/add_menu_page/
				    				85);
    add_action( 'load-' . $playme_admin, "PlayMe_required_admin"); 
}
function PlayMe_required_admin(){
	wp_register_style( 	'PlayMe_styles', plugins_url('styles.css', __FILE__) );
	wp_enqueue_style( 	'PlayMe_styles' );

	wp_enqueue_script(	'PlayMe_scripts_admin', plugins_url('scripts_admin.js', __FILE__), array('jquery'), '1.1', true );
	wp_enqueue_script(	'PlayMe_scripts_admin');
	wp_localize_script(	'PlayMe_scripts_admin', 'ajax_object', array( 'ajaxurl' => admin_url( 'admin-ajax.php' ), 
																	  'ip_location_service' => PLAYME_IP_LOCATION_SERVICE ) );
}

/***********************************************************************************
*     Build admin page
***********************************************************************************/
function PlayMe_plugin_options() {
	if(!current_user_can('view_playme_requests')) wp_die( __('You do not have sufficient permissions to access this page.'));
	echo '<div class="wrap" id="PlayMeAdmin">
			<h1 class="wp-heading-inline">
				<i class="dashicons-before dashicons-format-audio"></i> 
				<strong>PlayMe</strong>: Song Requests
			</h1>
			<br /><br />';

	/***********************************************************************************
	*     Song Requests List (later populated by AJAX on timer)
	***********************************************************************************/
	echo "<table id='PlayMe_submissions'>
			<thead><tr><th>Submitted by</th><th>Artist Name</th><th>Song Name</th><th>Date</th><th>Comments</th><th>&nbsp;</th></tr></thead>
			<tbody></tbody>
		  </table>
		  <div id='playme_timer'><span /></div>
		  <button id='PlayMe_refresh' type='button' data-seconds='60'>Refreshing in 60</button>";

	/***********************************************************************************
	*     ReCaptcha
	***********************************************************************************/
	//catch form submissions
	if(!empty($_POST) && current_user_can('manage_options')){
		//update
		if(isset($_POST['PlayMe_sitekey'])   && strlen(trim($_POST['PlayMe_sitekey']))>20) update_option( "PlayMe_recaptcha_sitekey", (string) trim($_POST['PlayMe_sitekey']) );
		if(isset($_POST['PlayMe_secretkey']) && strlen(trim($_POST['PlayMe_secretkey']))>20) update_option( "PlayMe_recaptcha_secretkey", (string) trim($_POST['PlayMe_secretkey']) );
		//clear
		if(isset($_POST['PlayMe_sitekey'])   && strlen(trim($_POST['PlayMe_sitekey']))<1) delete_option( "PlayMe_recaptcha_sitekey" );
		if(isset($_POST['PlayMe_secretkey']) && strlen(trim($_POST['PlayMe_secretkey']))<1) delete_option( "PlayMe_recaptcha_secretkey" );
	}
	
	//recall
	$playme_rsi = $playme_rse = false;
	if($recaptcha = playme_usingGoogleRecaptcha()) list($playme_rsi,$playme_rse) = $recaptcha;
	
	//form: recaptcha
	if(current_user_can('manage_options')){
		echo "<hr /><br /><h1 class='wp-heading-inline'>reCAPTCHA</h1>
			  <p>For site owners who wish to integrate <a href='https://support.google.com/recaptcha/answer/6080904?hl=en' title='What is Google&apos;s reCAPTCHA?' target='_blank'><strong>Google&apos;s reCAPTCHA</strong></a>, a service that will help protect your WordPress forms from possible abuse by internet bots, you can enter your Site Key and Secret Key below. Instructions for doing so are also below.</p>
	
			  <form method='post'>
			  <table id='PlayMe_recaptcha'><thead><tr>
				<th><label for='PlayMe_sitekey'>Site Key</label></th>
				<th><label for='PlayMe_secretkey'>Secret Key</label></th>
				<th>&nbsp;</th>
			  </tr></thead>
			  <tbody><tr>
				<td><input id='PlayMe_sitekey' name='PlayMe_sitekey' value='{$playme_rsi}' autocomplete='Off' /></td>
				<td><input id='PlayMe_secretkey' name='PlayMe_secretkey' value='{$playme_rse}' autocomplete='Off' /></td>
				<td><button type='submit'>Save</button></td>
			  </tr></tbody></table></form>
	
			  <p class='PlayMe_instructions'><strong>Instructions for using reCAPTCHA:</strong><br /><br />
				 &bull; Browse to the <a href='https://www.google.com/recaptcha/admin#list' title='Setting Up Google&apos;s reCAPTCHA for Protection from Abuse' target='_blank'>reCAPTCHA registration form</a>.<br /> 
				 &bull; Enter a <em><strong>label</strong></em> for the registration (typically the name of your website) and the <em><strong>domain</strong></em> (or web address).<br /> 
				 &nbsp; <em>For example: if your WordPress site is called <strong>&quot;FM-100 Radio!&quot;</strong> and the web address is <strong>&quot;www.fm-100.radio&quot;</strong>, you can enter these values.</em><br />
				 &bull; The type of reCAPTCHA is called: <em><strong>reCAPTCHA v2: Validate requests with the &quot;I'm not a robot&quot; checkbox.</strong></em> Select this option from among those available.<br />
				 &bull; Accept the reCAPTCHA Terms of Service, and you'll be given the <em><strong>Site Key</strong></em> and <em><strong>Secret Key</strong></em> which you can enter above.<br />
				 &bull; If you decide later that you no longer wish to use this service, simply clear out the values, below.
			  </p>"; 
	}
	
	/***********************************************************************************
	*     Footer
	***********************************************************************************/
?>
<hr />
<small>See more <a href='https://profiles.wordpress.org/era404/#content-plugins' title='WordPress plugins by ERA404' target='_blank'>WordPress plugins by ERA404</a> or visit us online: <a href='http://www.era404.com' title='ERA404 Creative Group, Inc.' target='_blank'>www.era404.com</a>. Thank you for using <i class="dashicons-before dashicons-format-audio"></i><a href='https://wordpress.org/plugins/playme/' title='PlayMe on WordPress.org' target='_blank'>PlayMe</a>.</small>
</div>

<?php
}

/***********************************************************************************
*	Admin AJAX: Fetch Song Requests (after $id)
***********************************************************************************/
function playme_ajax_fetchrequests(){
	header('Content-type: application/json');
	global $wpdb;
	sleep(1);
	$maxid = (isset($_POST['maxid']) && is_numeric($_POST['maxid']) ? $_POST['maxid'] : -1);
	$results = $wpdb->get_results( "SELECT *, FROM_UNIXTIME(date,'%Y-%m-%d (%T)') AS datehr 
									FROM {$wpdb->prefix}requests 
									WHERE hide<1 AND id>{$maxid} 
									ORDER BY id DESC
									LIMIT ".PLAYME_MAX_SONG_REQUESTS, OBJECT );
	$submissions = array_reverse($results);
	
	//escape before delivery
	foreach($submissions as $k=>$v)	foreach($songs as $sk=>$sv) $submissions[$k][$sk] = esc_html( $sv );
	
	//badge
	if(!empty($submissions)) set_transient( 'playme_requests', $submissions );
	
	//return
	die(json_encode( array("count" => count($submissions),
						   "songs" => $submissions) ));
}
/***********************************************************************************
*	Admin AJAX: Delete Song Request
***********************************************************************************/
function playme_ajax_deleterequest(){
	global $wpdb;
	$requestId = (isset($_POST['requestId']) && is_numeric($_POST['requestId']) && 
				 $wpdb->get_var("SELECT count(id) FROM {$wpdb->prefix}requests WHERE id=".(int) trim($_POST['requestId']))==1 ? 
				 (int) $_POST['requestId'] : false);
	if(!$requestId)	PlayMe_appResponse(400,"Cannot delete this Song Request.");
	$wpdb->query("UPDATE {$wpdb->prefix}requests SET hide=1 WHERE id={$requestId}");
	PlayMe_appResponse(200,"OK");
}

/**************************************************************************************************
*	FRONT-END Functions
**************************************************************************************************/
$PlayMe_fields = array(
			array(	"Your Name",		//label
					"submittedby",		//id
					"submittedby",		//name
					"text",				//type
					"PlayMe_wide",//styles
					"",					//default value
					true),				//required
					
			array(	"Artist Name",		//label
					"artistname",		//id
					"artistname",		//name
					"text",				//type
					"PlayMe_wide",//styles
					"",					//default value
					true),				//required
					
			array(	"Song Name",		//label
					"songname",			//id
					"songname",			//name
					"text",				//type
					"PlayMe_wide",//styles
					"",					//default value
					true),				//required
					
			array(	"Comments",			//label
					"comments",			//id
					"comments",			//name
					"textarea",			//type
					"PlayMe_wide",//styles
					"",					//default value
					false),				//required
					
			array(	"Date",				//label
					"date",				//id
					"date",				//name
					"hidden",			//type
					"PlayMe_hidden",//styles
					time(),				//default value
					true),				//required
);
add_shortcode( 'playme', 'insert_PlayMe' );
function insert_PlayMe(){
	global $PlayMe_fields; //above
	
	$html = array();
	foreach($PlayMe_fields as $fk=>$fv){
		list($label,$id,$name,$type,$styles,$default,$required) = $fv;
		$html[$fk] = "<label for='{$id}' data-label='{$label}' class='{$styles}".($required?" required":"")."' title='Please Enter {$label}'>{$label} ".($required?"<strong>*</strong>":"")."<br />";
		switch($type){
			case "text":		$html[$fk] .= "<input type='text' name='{$name}' id='{$id}' value='{$default}' autocomplete='Off' />"; break;
			case "textarea":	$html[$fk] .= "<textarea name='{$name}' id='{$id}'>{$default}</textarea>"; break;
			case "hidden":		$html[$fk] .= "<input type='hidden' name='{$name}' id='{$id}' value='{$default}' autocomplete='Off' readonly />"; break;
			default:			$html[$fk] .= "";
		}
		$html[$fk] .= "</label>";
	}
	
	//check if reCAPTCHA is to be used
	$recaptcha = playme_usingGoogleRecaptcha();
	$playme_re = ( $recaptcha ? '<script src="https://www.google.com/recaptcha/api.js"></script><div class="g-recaptcha" data-sitekey="'.$recaptcha[0].'"></div>' : '' );

	//compile the form HTML
	return ( "<form id='PlayMe'>
			  <div id='PlayMe_status' class='notice'></div><br />" . 
			  implode("", $html) . 
			  $playme_re .
			  "<button type='button' name='Submit Request' id='PlayMe_submit'>Submit Request</button>" .
			  "</form>" );
}

/**************************************************************************************************
*	AJAX Functions
*	updatedb: update the meta properties for the image
**************************************************************************************************/
function playme_ajax_submitrequest() {
	global $wpdb, $PlayMe_fields;
	if(!wp_doing_ajax()) wp_die( __('You are not allowed to access this part of the site'));

	//recaptcha?
	$recaptcha = playme_usingGoogleRecaptcha();
	if($recaptcha && isset($_POST["g-recaptcha-response"])){
		playme_verifyGoogleRecaptcha( array("secret" => $recaptcha[1], "response" => $_POST["g-recaptcha-response"]) );
	}
	
	//the insert record
	$data = array(	'submittedby' 	=> (string)  stripslashes( sanitize_text_field( 	trim($_POST['submittedby']) 	)),
					'artistname' 	=> (string)  stripslashes( sanitize_text_field( 	trim($_POST['artistname']) 		)),
					'songname' 		=> (string)  stripslashes( sanitize_text_field( 	trim($_POST['songname']) 		)),
					'comments' 		=> (string)  stripslashes( sanitize_textarea_field( trim($_POST['comments']) 		)),
					'date' 			=> (int) 	 stripslashes( sanitize_text_field(		trim($_POST['date']) 			)),
					'ip'			=> (string)  PlayMe_getUserIP()
	);

	//validate & sanitize fields
	foreach($PlayMe_fields as $fk=>$fv){
		$key = $fv[2]; $label = $fv[0]; $required = $fv[6];
		//required cannot be blank (after sanitizing)
		if($required && (!isset($data[$key]) || ""==trim($data[$key]))) PlayMe_appResponse(400,"{$label} cannot be blank.");
	}

	//continue with the insert
	$playme_db = $wpdb->prefix . "requests";
	$format = array('%s','%s','%s','%s','%d','%s');
	$wpdb->insert($playme_db, $data, $format);
	$submission_id = $wpdb->insert_id;
	
	//success
	if(is_int($submission_id)) PlayMe_appResponse(200,"Thank you for your song request.");
	
	//some unknown error
	PlayMe_appResponse(400,"An unknown error occurred. Please try again later.");
}
function PlayMe_getUserIP(){
    if( array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER) && !empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
        if (strpos($_SERVER['HTTP_X_FORWARDED_FOR'], ',')>0) {
            $addr = explode(",",$_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($addr[0]);
        } else {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
    }
    else {
        return $_SERVER['REMOTE_ADDR'];
    }
}
function playme_usingGoogleRecaptcha(){
	$playme_rse = get_option("PlayMe_recaptcha_secretkey");
	$playme_rsi = get_option("PlayMe_recaptcha_sitekey");
	if(strlen($playme_rsi)>=20 && strlen($playme_rse)>=20) return( array($playme_rsi,$playme_rse) );
	return(false);
}
function playme_verifyGoogleRecaptcha($data){
	$args = array("body"=>$data,"timeout"=>5,"redirection"=>5,"httpversion"=>"1.0","blocking"=>true,"headers"=>array(),"cookies"=>array());
	$response = wp_remote_post("https://www.google.com/recaptcha/api/siteverify", $args);
	$results = json_decode($response['body']);
	if($results->success) return(true);
	PlayMe_appResponse(400,"Please verify you are human. reCAPTCHA says: &quot;".$results->{'error-codes'}[0].".&quot;");
}

/**************************************************************************************************
*	INSTALL / UNINSTALL
**************************************************************************************************/
function PlayMe_install() {
	if(!current_user_can( 'activate_plugins' )) return;
	
	global $wpdb;
	$playme_db = $wpdb->prefix . "requests";
	$charset_collate = $wpdb->get_charset_collate();
	
	$sql = "CREATE TABLE {$playme_db} (
	id int(10) unsigned auto_increment primary key NOT NULL, 
	submittedby varchar(128) NOT NULL,
	artistname varchar(128) NOT NULL,
	songname varchar(128) NOT NULL,
	comments text,
	date int(10) NOT NULL,
	ip varchar(15),
	hide tinyint(1) NOT NULL default '-1'
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
	
	checkPlayMeRoles();	
}
/**************************************************************************************************
*	Roles & Capabilities
*	Capability: view_playme_requests
*	Role: 		playme_requests_viewer
**************************************************************************************************/
function checkPlayMeRoles(){
	$role_admin = get_role('administrator');
	//add the capability for admins
	if(!array_key_exists("view_playme_requests", (array) $role_admin->capabilities)) $role_admin->add_cap('view_playme_requests', true);

	$role_editor = get_role('playme_requests_viewer');
	//add the role of PlayMe Requests Viewer for non-admin access
	if(!$role_editor){ 
		$result = add_role('playme_requests_viewer', __( 'PlayMe Requests Viewer' ), array('read' => true));
		if(null !== $result ) {
			$role_editor = get_role('playme_requests_viewer');
			$role_editor->add_cap('view_playme_requests', true);
			$role_editor->add_cap('view_admin_dashboard', true);
		}
	}
	//double-check the capabilities for the PlayMe Requests Viewer
	if(!array_key_exists("view_playme_requests", (array) $role_editor->capabilities)){
		$role_editor->add_cap('view_playme_requests', true);
		$role_editor->add_cap('view_admin_dashboard', true);
	}
}
//New Role /fix/ Woocommerce "My Account" Page
add_filter('woocommerce_prevent_admin_access', 'playme_role_gets_admin_access', 10, 1);
function playme_role_gets_admin_access($prevent_admin_access) {
    if(current_user_can('view_playme_requests')) return(false);
    return($prevent_admin_access);
}
function PlayMe_uninstall(){
	if(!current_user_can( 'activate_plugins' )) return;
	global $wpdb;
	$playme_db = $wpdb->prefix . "requests";
	$wpdb->query("DROP TABLE {$playme_db}");
}

/**************************************************************************************************
*	Responses
**************************************************************************************************/
function PlayMe_appResponse($code=400, $text="Bad Request"){
	$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
	header("{$protocol} {$code} ".( $code == 200 ? "OK" : "Bad Request" ));
	$GLOBALS['http_response_code'] = $code;
	die(json_encode(array(	"status" 	=> ($code==200?-1:1),
							"statuscode"=> $code,
							"statustext"=> $text)));
}
if(!function_exists("myprint_r")){
	function myprint_r($in) {
		echo "<pre>"; print_r($in); echo "</pre>"; return;
	}
}
?>