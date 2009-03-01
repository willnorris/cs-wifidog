<?php

add_action('admin_menu', 'wifidog_admin_menu');


/**
 * Hook into admin menu.
 */
function wifidog_admin_menu() {
	$hookname = add_options_page('Wifidog', 'Wifidog', 8, 'wifidog', 'wifidog_options_page');

	add_action("load-$hookname", 'wifidog_options_load');
	register_setting('wifidog', 'wifidog_password');
}


/**
 * Setup Wifidog page on load.
 */
function wifidog_options_load() {
	add_thickbox();
}


/**
 * Manage Wifidog options.
 */
function wifidog_options_page() {
	global $action;

	//Wifidog_Store::new_connection('citizenspace', '192.168.147.1', $user->ID, '00:00:00:00:00', '10.0.0.1');
	//Wifidog_Store::update_connection_usage('4bca87adc24d3c4f1b50cabc58bbd148', 999, 888);
	//$connection = Wifidog_Store::get_connection('4bca87adc24d3c4f1b50cabc58bbd148');

	switch ($action) {
		case 'add_node':
			if (empty($_REQUEST['node_id'])) {
				echo 'no node_id';
				break;
			}

			check_admin_referer('add_node');

			$nodes = get_option('wifidog_nodes');
			if (array_key_exists($_REQUEST['node_id'], $nodes)) {
				echo 'node_id already exists';
			} else {
				$node = array(
					'id' => $_REQUEST['node_id'],
					'name' => $_REQUEST['node_name'],
					'location' => $_REQUEST['node_location'],
				);
				$node = apply_filters('wifidog_update_node', $node);
				$nodes[$node['id']] = $node;

				update_option('wifidog_nodes', $nodes);
			}

			break;

		case 'delete_nodes':
			check_admin_referer('delete_nodes');
			$nodes = get_option('wifidog_nodes');

			$new_nodes = array();
			foreach (array_keys($nodes) as $id) {
				if ( in_array(md5($id), $_REQUEST['delete']) ) {
					unset($nodes[$id]);
				}
			}
			$nodes = array_filter($nodes);
			update_option('wifidog_nodes', $nodes);
			break;

		case 'delete_tokens':
			check_admin_referer('delete_tokens');
			$tokens = get_option('wifidog_tokens');

			$new_tokens = array();
			foreach (array_keys($tokens) as $id) {
				if ( in_array(md5($id), $_REQUEST['delete']) ) {
					unset($tokens[$id]);
				}
			}
			$tokens = array_filter($tokens);
			update_option('wifidog_tokens', $tokens);
			break;
	}

	screen_icon('wifidog');
?>
	<style type="text/css">
		#icon-wifidog { background-image: url("<?php echo plugins_url('wifidog/icon.png'); ?>"); }
	</style>

	<div class="wrap">
		<h2><?php _e('Wifidog', 'wifidog') ?></h2>

		<h3><?php _e('Nodes', 'wifidog') ?></h3>
		<form method="get">
			<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
			<div class="tablenav">
				<div class="alignleft actions">
					<select name="action">
						<option value="" selected="selected">Bulk Actions</option>
						<option value="delete_nodes">Delete</option>
					</select>
					<?php wp_nonce_field('delete_nodes'); ?>
					<input type="submit" value="Apply" id="doaction" class="button-secondary action" />
				</div>
				<br class="clear" />
			</div>

			<div class="clear"></div>

			<table class="widefat">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-cb check-column"><input type="checkbox" /></th>
						<th scope="col"><?php _e('Gateway ID', 'wifidog'); ?></th>
						<th scope="col"><?php _e('Name', 'wifidog'); ?></th>
						<th scope="col"><?php _e('Location', 'wifidog'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
					$nodes = get_option('wifidog_nodes');

					if (!is_array($nodes) || empty($nodes)) {
						echo '<tr><td colspan="2">'.__('No Nodes.', 'wifidog').'</td></tr>';
					} else {
						foreach ($nodes as $id => $data) {
							echo '
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="'.md5($id).'" /></th>
								<td>' . $id . '</td>
								<td>' . $data['name'] . '</td>
								<td>' . apply_filters('wifidog_display_node_location', $data['location'], $data) . '</td>
							</tr>';
						}
					}
				?>
				</tbody>
			</table>
		</form>
		<p><a id="node_thickbox" href="#TB_inline?height=600&width=800&inlineId=add_node" class="thickbox">Add New Node</a></p>

		<div id="add_node" style="display: none;">
			<form method="post" id="add_node_form">
				<h1>Add New Node</h1>
				<label for="node_id">Gateway ID: <input type="text" id="node_id" name="node_id" /></label>
				<label for="node_name">Name: <input type="text" id="node_name" name="node_name" size="50" /></label>
				<label for="node_location">Location: <input type="text" id="node_location" name="node_location" size="50" /></label>
				<?php do_action('wifidog_update_node_form'); ?>
				<input type="hidden" name="action" value="add_node" />
				<?php wp_nonce_field('add_node'); ?>
				<input type="submit" value="submit" />
			</form>
		</div>

		<style type="text/css">
			#add_node_form label { display: block; }
			#add_node_form label input { display: block; margin-bottom: 1.5em; }
		</style>

		<h3><?php _e('Wifi Password', 'wifidog') ?></h3>
		<form method="post" action="options.php">
			<table class="form-table">
					<tr>
						<th scope="row"><label for="wifidog_password"><?php _e('Wifi Password', 'wifidog'); ?></label></th>
						<td>Wifidog can be setup to require users to enter a common password (similar to using WPA2 on your network).  To enable this, enter a password below.
						<p><input type="text" name="wifidog_password" id="wifidog_password" value="<?php echo get_option('wifidog_password'); ?>" /></p>
					</tr>
			</table>
			<?php settings_fields('wifidog'); ?>
			<p class="submit"><input type="submit" name="info_update" value="<?php _e('Update Options') ?> &raquo;" /></p>
		</form>

		<h3><?php _e('Active Tokens', 'wifidog') ?></h3>
		<form>
			<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
			<div class="tablenav">
				<div class="alignleft actions">
					<select name="action">
						<option value="" selected="selected">Bulk Actions</option>
						<option value="delete_tokens">Delete</option>
					</select>
					<?php wp_nonce_field('delete_tokens'); ?>
					<input type="submit" value="Apply" id="doaction" class="button-secondary action" />
				</div>
				<br class="clear" />
			</div>

			<div class="clear"></div>
			<table class="widefat">
				<thead>
					<tr>
						<th scope="col" class="manage-column column-cb check-column"><input type="checkbox" /></th>
						<th scope="col"><?php _e('Token', 'wifidog'); ?></th>
						<th scope="col"><?php _e('Created', 'wifidog'); ?></th>
						<th scope="col"><?php _e('User', 'wifidog'); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
					$tokens = get_option('wifidog_tokens');

					if (!is_array($tokens) || empty($tokens)) {
						echo '<tr><td colspan="2">'.__('No wifi Tokens.', 'wifidog').'</td></tr>';
					} else {
						foreach ($tokens as $token => $data) {
							$user = get_userdata($data['user']);

							echo '
							<tr>
								<th scope="row" class="check-column"><input type="checkbox" name="delete[]" value="'.md5($token).'" /></th>
								<td>' . $token . '</td>
								<td>' . date('r', (int) $data['created']) . '</td>
								<td>' . ($user ? $user->display_name : ' - ') . '</td>
							</tr>';
						}   
					}
				?>
				</tbody>
			</table>
		</form>

	</div>
<?php
	do_settings_sections('wifidog');
}

?>
