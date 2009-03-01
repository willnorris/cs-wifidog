<?php
/*
 Plugin Name: WifiDog
 Plugin URI: http://wordpress.org/extend/plugins/wifidog
 Description: Allow WordPress to serve as an Authentication server for you WifiDog captive portal.
 Author: Will Norris
 Author URI: http://willnorris.com/
 Version: trunk
 */

include_once(dirname(__FILE__) . '/admin_panels.php');

register_activation_hook('wifidog/wifidog.php', 'wifidog_activate_plugin');
register_deactivation_hook('wifidog/wifidog.php', 'wifidog_deactivate_plugin');

add_action('query_vars', 'wifidog_query_vars');
add_action('generate_rewrite_rules', 'wifidog_rewrite_rules');
add_filter('parse_request', 'wifidog_parse_request');


/**
 * Activate plugin.
 */
function wifidog_activate_plugin() {
	global $wp_rewrite;

	$wp_rewrite->flush_rules();

	add_option('wifidog_tokens', array());
	add_option('wifidog_nodes', array());

	$user = wp_get_current_user();
}


/**
 * Deactivate plugin.
 */
function wifidog_deactivate_plugin() {
}


/**
 * Add 'wifidog' as a valid query var.
 *
 * @param array $vars valid query vars
 * @return array update array of query vars
 */
function wifidog_query_vars($vars) {
	$vars[] = 'wifidog';
	return $vars;
}


/**
 * Add wifidog rewrite rules.
 *
 * @param WP_Rewrite $wp_rewrite
 * @return WP_Rewrite 
 */
function wifidog_rewrite_rules($wp_rewrite) {
	$site_url = get_option('siteurl');
	$home_url = get_option('home');

	if ($site_url != $home_url) {
		$url = substr(trailingslashit($site_url), strlen($home_url)+1);
	} else {
		$url = '';
	}

	$wifidog_rules = array(
		$url . 'wifidog/(.*)'  => 'index.php?wifidog=$matches[1]',
		$url . 'index.php/wifidog/(.*)'  => 'index.php?wifidog=$matches[1]',
	);

	$wp_rewrite->rules = $wifidog_rules + $wp_rewrite->rules;
}


/**
 * Parse wifidog requests.
 *
 * @param WP $wp
 */
function wifidog_parse_request($wp) {
	if (!array_key_exists('wifidog', $wp->query_vars)) return;
	extract($_GET);

	switch ($wp->query_vars['wifidog']) {
		case 'ping':
			Wifidog::ping($gw_id, $sys_uptime, $sys_memfree, $sys_load, $wifidog_uptime);
			break;

		case 'login':
			Wifidog::login($gw_id, $gw_address, $gw_port, $url);
			break;

		case 'auth':
			Wifidog::auth($stage, $token, $ip, $mac, $incoming, $outgoing);
			break;

		case 'portal':
			Wifidog::portal($gw_id);
			break;

		case 'gw_message.php':
			Wifidog::message($message);
			break;

		default:
			do_action('wifidog_request', $wp->query_vars['wifidog']);
			wp_die('Unknown wifidog URL: ' . $wp->query_vars['wifidog']);
	}
}


/**
 * Implementation of the Wifidog protocol (v1).  
 *
 * There is still a lot of WordPress specific code in here, so it's not 
 * directly reusable in other platforms without a little work.
 *
 * @see http://dev.wifidog.org/wiki/doc/developer/WiFiDogProtocol_V1
 */
class Wifidog {

	/** User firewall users are deleted and the user removed. */
	const AUTH_DENIED = 0;

	/** User email validation timeout has occured and user/firewall is deleted. */
	const AUTH_VALIDATION_FAILED = 6;

	/** User was valid, add firewall rules if not present. */
	const AUTH_ALLOWED = 1;

	/** Permit user access to email to get validation email under default rules. */
	const AUTH_VALIDATION = 5;

	/** An error occurred during the validation process. */
	const AUTH_ERROR = -1;

	/** Number of seconds of inactivity, after which a user must re-authenticate. (57600 = 16 hours)*/
	const SESSION_LIFETIME = 57600;

	/**
	 * Process node check-ins.
	 *
	 * @param string $gateway
	 * @param string $sys_uptime
	 * @param string $sys_memfree
	 * @param string $sys_load
	 * @param string $wifidog_uptime
	 */
	public function ping($gateway, $sys_uptime, $sys_memfree, $sys_load, $wifidog_uptime) {
		// do somethign with ping information

		echo 'Pong';
		die;
	}


	/**
	 * Authenticate user and return to WifiDog gateway.
	 *
	 * @param string $gateway
	 * @param string $address
	 * @param string $port
	 * @param string $url
	 */
	public function login($gateway, $address, $port, $url) {
		session_start();
		$_SESSION['wifidog_url'] = $url;

		if (!is_user_logged_in()) {
			$_SESSION['wifidog_login'] = true;
			$_SESSION['wifidog_address'] = $address;
			$_SESSION['wifidog_port'] = $port;

			auth_redirect();
		}

		$token = self::new_token();
		$redirect_url = 'http://' . $address . ':' . $port . '/wifidog/auth?token=' . $token;

		do_action('wifidog_login', $gateway);
		wp_redirect($redirect_url);
		exit;
	}


	/**
	 * Display portal page for the specified gateway.
	 *
	 * @param string $gateway
	 */
	public function portal($gateway) {
		session_start();

		if (!empty($_SESSION['openid_request'])) {
			$request = $_SESSION['openid_request']; unset($_SESSION['openid_request']);
			$trust_root = $_SESSION['openid_trust_root']; unset($_SESSION['openid_trust_root']);
			$return_to = $_SESSION['openid_return_to']; unset($_SESSION['openid_return_to']);

			openid_redirect($request, $trust_root, $return_to);

		} else if (!empty($_SESSION['wifidog_url'])) {
			$url = $_SESSION['wifidog_url'];
			unset($_SESSION['wifidog_url']);

			wp_redirect($url);
			exit;
		} else {
			wp_die('Welcome to Citizen Space.');
		}
	}


	/**
	 * Authenticate a user token.
	 *
	 * @param string $stage
	 * @param string $token
	 * @param string $ip
	 * @param string $mac
	 * @param string $incoming
	 * @param string $outgoing
	 */
	public function auth($stage, $token, $ip, $mac, $incoming = 0, $outgoing = 0) {
		$status = self::AUTH_DENIED;
		$message = '';

		switch($stage) {
			case 'login':
			case 'counters':
				$status = self::validate_token($token);
				break;
		}

		echo "Auth: $status\n";
		echo "Message: $message\n";
		exit;
	}


	/**
	 * Display Wifidog message.
	 *
	 * @param string $message message to be displayed
	 */
	public function message($message) {
		wp_die('wifidog message:' . $message);
	}


	/**
	 * Create a new token
	 *
	 * @return string new token
	 */
	public function new_token( $temp = false) {
		$t = md5(uniqid(rand(), 1));
		$user = wp_get_current_user();

		$tokens = get_option('wifidog_tokens');
		$tokens[$t] = array(
			'user' => $user->ID,
			'created' => time(),
			'temp' => $temp,
		);
		update_option('wifidog_tokens', $tokens);

		return $t;
	}


	/**
	 * Check if the specified token is valid.
	 *
	 * @param string $token token to validate
	 * @return boolean
	 */
	private function validate_token($token) {
		$tokens = get_option('wifidog_tokens');
		$status = self::AUTH_DENIED;

		if (array_key_exists($token, $tokens)) {
			if (time() - $tokens[$token]['created'] < self::SESSION_LIFETIME) {
				if ($tokens[$token]['temp']) {
					$status = self::AUTH_VALIDATION;
				} else {
					$status = self::AUTH_ALLOWED;
				}
			} else {
				if ($tokens[$token]['temp']) {
					$status = self::AUTH_VALIDATION_FAILED;
				}

				unset($tokens[$token]);
				update_option('wifidog_tokens', $tokens);
			}
		}

		return $status;
	}

}


?>
