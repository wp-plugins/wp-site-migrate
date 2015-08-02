<?php
global $blogvault;
global $bvNotice;
global $bvWPEAdminPage;
$bvNotice = '';
$bvWPEAdminPage = 'wpe-automated-migration';

if (!function_exists('bvWPEAdminUrl')) :
	function bvWPEAdminUrl($_params = '') {
		global $bvWPEAdminPage;
		if (function_exists('network_admin_url')) {
			return network_admin_url('admin.php?page='.$bvWPEAdminPage.$_params);
		} else {
			return admin_url('admin.php?page='.$bvWPEAdminPage.$_params);
		}
	}
endif;

if (!function_exists('bvAddStyleSheet')) :
	function bvAddStyleSheet() {
		wp_register_style('form-styles', plugins_url('form-styles.css',__FILE__ ));
		wp_enqueue_style('form-styles');
	}
add_action( 'admin_init','bvAddStyleSheet');
endif;

if (!function_exists('bvWPEAdminInitHandler')) :
	function bvWPEAdminInitHandler() {
		global $bvNotice, $blogvault, $bvWPEAdminPage;
		global $sidebars_widgets;
		global $wp_registered_widget_updates;

		if (!current_user_can('activate_plugins'))
			return;

		if (isset($_REQUEST['bvnonce']) && wp_verify_nonce($_REQUEST['bvnonce'], "bvnonce")) {
			if (isset($_REQUEST['blogvaultkey']) && isset($_REQUEST['page']) && $_REQUEST['page'] == $bvWPEAdminPage) {
				if ((strlen($_REQUEST['blogvaultkey']) == 64)) {
					$keys = str_split($_REQUEST['blogvaultkey'], 32);
					$blogvault->updatekeys($keys[0], $keys[1]);
					bvActivateHandler();
					$bvNotice = "<b>Activated!</b> blogVault is now backing up your site.<br/><br/>";
					if (isset($_REQUEST['redirect'])) {
						$location = $_REQUEST['redirect'];
						wp_redirect("https://webapp.blogvault.net/migration/".$location);
						exit();
					}
				} else {
					$bvNotice = "<b style='color:red;'>Invalid request!</b> Please try again with a valid key.<br/><br/>";
				}
			}
		}

		if ($blogvault->getOption('bvActivateRedirect') === 'yes') {
			$blogvault->updateOption('bvActivateRedirect', 'no');
			wp_redirect(bvWPEAdminUrl());
		}
	}
	add_action('admin_init', 'bvWPEAdminInitHandler');
endif;

if (!function_exists('bvWpeAdminMenu')) :
	function bvWpeAdminMenu() {
		global $bvWPEAdminPage;
		add_menu_page('WP Engine Migrate', 'WP Engine Migrate', 'manage_options', $bvWPEAdminPage, 'bvWpEMigrate', plugins_url( 'favicon.ico', __FILE__ ));
	}
	if (function_exists('is_multisite') && is_multisite()) {
		add_action('network_admin_menu', 'bvWpeAdminMenu');
	} else {
		add_action('admin_menu', 'bvWpeAdminMenu');
	}
endif;

if ( !function_exists('bvSettingsLink') ) :
	function bvSettingsLink($links, $file) {
		if ( $file == plugin_basename( dirname(__FILE__).'/blogvault.php' ) ) {
			$links[] = '<a href="'.bvWPEAdminUrl().'">'.__( 'Settings' ).'</a>';
		}
		return $links;
	}
	add_filter('plugin_action_links', 'bvSettingsLink', 10, 2);
endif;

if ( !function_exists('bvWpEMigrate') ) :
	function bvWpEMigrate() {
		global $blogvault, $bvNotice;
		$_error = NULL;
		if (array_key_exists('error', $_REQUEST)) {
			$_error = $_REQUEST['error'];
		}
?>
		<div class="logo-container" style="padding: 50px 0px 10px 20px">
			<a href="http://blogvault.net/" style="padding-right: 20px;"><img src="<?php echo plugins_url('logo.png', __FILE__); ?>" /></a>
			<a href="http://wpengine.com/"><img src="<?php echo plugins_url('wpengine-logo.png', __FILE__); ?>" /></a>
		</div>

		<div id="wrapper toplevel_page_wpe-automated-migration">
			<form id="wpe_migrate_form" dummy=">" action="https://webapp.blogvault.net/home/migrate" style="padding:0 2% 2em 1%;" method="post" name="signup">
				<h1>Migrate Site to WP Engine</h1>
				<p><font size="3">This plugin makes it very easy to migrate your site to WP Engine</font></p>
<?php if ($_error == "email") { 
	echo '<div class="error" style="padding-bottom:0.5%;"><p>There is already an account with this email.</p></div>';
} else if ($_error == "blog") {
	echo '<div class="error" style="padding-bottom:0.5%;"><p>Could not create an account. Please contact <a href="http://blogvault.net/contact/">blogVault Support</a></p></div>';
} else if (($_error == "custom") && isset($_REQUEST['bvnonce']) && wp_verify_nonce($_REQUEST['bvnonce'], "bvnonce")) {
	echo '<div class="error" style="padding-bottom:0.5%;"><p>'.base64_decode($_REQUEST['message']).'</p></div>';
}
?>
				<input type="hidden" name="bvsrc" value="wpplugin" />
				<input type="hidden" name="migrate" value="wpengine" />
				<input type="hidden" name="type" value="sftp" />
				<input type="hidden" name="setkeysredirect" value="true" />
				<input type="hidden" name="url" value="<?php echo $blogvault->wpurl(); ?>" />
				<input type="hidden" name="secret" value="<?php echo $blogvault->getOption('bvSecretKey'); ?>">
				<input type='hidden' name='bvnonce' value='<?php echo wp_create_nonce("bvnonce") ?>'>
				<input type='hidden' name='serverip' value='<?php echo $_SERVER["SERVER_ADDR"] ?>'>
				<input type='hidden' name='adminurl' value='<?php echo bvWPEAdminUrl(); ?>'>
				<input type="hidden" name="multisite" value="<?php var_export($blogvault->isMultisite()); ?>" />
				<div class="row-fluid">
					<div class="span5" style="border-right: 1px solid #EEE; padding-top:1%;">
						<label id='label_email'>Email</label>
			 			<div class="control-group">
							<div class="controls">
								<input type="text" id="email" name="email" placeholder="ex. user@mydomain.com">
							</div>
						</div>
						<label class="control-label" for="input02">Destination Site URL</label>
						<div class="control-group">
							<div class="controls">
								<input type="text" class="input-large" name="newurl" placeholder="http://example.wpengine.com">
							</div>
						</div>
						<label class="control-label" for="inputip">
							SFTP Server Address
							<span style="color:#162A33">(of the destination server)</span>
						</label>
						<div class="control-group">
							<div class="controls">
								<input type="text" class="input-large" placeholder="ex. 123.456.789.101" name="address">
								<p class="help-block"></p>
							</div>
						</div>
						<label class="control-label" for="input01">SFTP Username</label>
						<div class="control-group">
							<div class="controls">
								<input type="text" class="input-large" placeholder="ex. installname" name="username">
								<p class="help-block"></p>
							</div>
						</div>
						<label class="control-label" for="input02">SFTP Password</label>
						<div class="control-group">
							<div class="controls">
								<input type="password" class="input-large" name="passwd">
							</div>
						</div>
<?php if (array_key_exists('auth_required_source', $_REQUEST)) { ?>
						<div id="source-auth">
							<label class="control-label" for="input02" style="color:red">User <small>(for this site)</small></label>
							<div class="control-group">
								<div class="controls">
									<input type="text" class="input-large" name="httpauth_src_user">
								</div>
							</div>
							<label class="control-label" for="input02" style="color:red">Password <small>(for this site)</small></label>
							<div class="control-group">
								<div class="controls">
									<input type="password" class="input-large" name="httpauth_src_password">
								</div>
							</div>
						</div>
<?php } ?>
						<a id="advanced-options-toggle" href="javascript:;">Advanced Options</a>
						<script type="text/javascript">
							jQuery(document).ready(function () {
<?php if (array_key_exists('auth_required_dest', $_REQUEST)) { ?>
								jQuery('#dest-auth').show();
<?php } ?>
								jQuery('#advanced-options-toggle').click(function() {
									jQuery('#dest-auth').toggle();
								});
							});
						</script>
						<div id="dest-auth" style="display:none;">
							<p>WP Engine Install is Password Protected</p>
							<label class="control-label" for="input02" style="color:red">Username <small>(for WP Engine Install)</small></label>
							<div class="control-group">
								<div class="controls">
									<input type="text" class="input-large" name="httpauth_dest_user">
								</div>
							</div>
							<label class="control-label" for="input02" style="color:red">Password <small>(for WP Engine Install)</small></label>
							<div class="control-group">
								<div class="controls">
									<input type="password" class="input-large" name="httpauth_dest_password">
								</div>
							</div>
						</div>
						<p style="font-size: 11px;">By pressing the "Migrate" button, you are agreeing to <a href="http://wpengine.com/terms-of-service/">WP Engine's Terms of Service</a></p>
					</div>
				</div>
				<input type='submit' value='Migrate'>
			</form>
			<div style="max-width: 650px; padding-left: 20px;">
				<h1>How to Use This Plugin</h1>
				<iframe src="//fast.wistia.net/embed/iframe/0rrkl3w1vu?videoFoam=true" allowtransparency="true" frameborder="0" scrolling="no" class="wistia_embed" name="wistia_embed" allowfullscreen mozallowfullscreen webkitallowfullscreen oallowfullscreen msallowfullscreen width="500" height="313"></iframe><script src="//fast.wistia.net/assets/external/E-v1.js"></script>
				<p><i>For full instructions and solutions to common errors, please visit our <a href="http://wpengine.com/support/wp-engine-automatic-migration/">WP Engine Automated Migration</a> support garage article.</i></p>
			</div>
		</div> <!-- wrapper ends here -->
<?php
	}
endif;