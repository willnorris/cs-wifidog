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


function wifidog_set_cookie() {
	$cookie = wifidog_get_cookie_value();
	$expire = time() + (60 * 60 * 24 * 365); // one year

	setcookie('wifidog_auth', $cookie, $expire, '/', COOKIE_DOMAIN);
}

function wifidog_get_cookie_value() {
	return sha1( wp_salt() . get_option('wifidog_password') );
}


function wifidog_validate_cookie() {
	return ($_COOKIE['wifidog_auth'] == wifidog_get_cookie_value());
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
		if ( wifidog_validate_cookie() ) { //|| ($_REQUEST['wifidog_password'] == get_option('wifidog_password')) ) {
			self::complete_login($gateway, $address, $port, $url);
		} else if ( $_REQUEST['wifidog_password'] == get_option('wifidog_password') && $_REQUEST['agree'] ) {
			self::complete_login($gateway, $address, $port, $url);
		} else {
			self::login_form();
		}
	}


	public function login_form() {
		global $wp_locale;
?>
		<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
		<html xmlns="http://www.w3.org/1999/xhtml" <?php if ( function_exists( 'language_attributes' ) ) language_attributes(); ?>>
		<head>
			<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
			<title>Welcome to Citizen Space</title>

			<meta name = "viewport" content = "user-scalable=no, width = device-width">
			<meta name = "viewport" content = "initial-scale = 1.0">

		<?php
			wp_admin_css('install', true);
			if ( ($wp_locale) && ('rtl' == $wp_locale->text_direction) ) {
				wp_admin_css('login-rtl', true);
			}

			do_action('admin_head');
		?>
			<style type="text/css">
				p { margin: 1.5em auto; }
				h1 {
					background: url(<?php echo plugins_url("wifidog/chandelier-sm.png"); ?>) top left no-repeat;
					line-height: 45px;
					padding-left: 45px;
				}
				@media screen and (max-device-width: 480px) {
					body {
						width: auto;
						margin: 0.5em;
						padding: 0.5em;
					}
					h1 {
						font-size: 20px;
					}
					#agree {
						width: 20px;
						height: 20px;
						float: left;
						margin-right: 1.5em;
					}
					#wifidog_password {
						font-size: 1.5em;
						width: 90%;
					}
					p {
						margin-top: 0.8em;
						margin-bottom: 0.8em;
					}
				}

			</style>
		</head>

		<body id="wifidog-page">
			<h1>Welcome to Citizen Space</h1>
			<form method="post">
				<p>
					<input type="checkbox" name="agree" id="agree" />
					<label for="agree">
						I agree to the <a href="http://citizenspace.us/policy/terms/" target="_blank">Citizen Space Terms of Service</a>.
					</label>
				</p>

				<p>
					Wifi Password: <input type="text" name="wifidog_password" id="wifidog_password" /><br />
					<em>It should be written on the large whiteboard near the drop-in desks.</em>
				</p>

				<div class="submit"><input type="submit" value="Submit" /></div>

				<p style="font-size: 0.6em">If you are prompted to login at this page more than once a week, please notify <a href="http://willnorris.com/">Will Norris</a> so it can be fixed.</p>
			</form>
		</body>
		</html>
<?php
		die();
	}

	protected function complete_login($gateway, $address, $port, $url) {
		wifidog_set_cookie();

		session_start();
		$_SESSION['wifidog_url'] = $url;

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
