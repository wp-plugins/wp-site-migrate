<?php
global $blogvault;
global $bvNotice;
$bvNotice = "";

define('BVMIGRATEPLUGIN', true);

if (!function_exists('bvAddStyleSheet')) :
	function bvAddStyleSheet() {
		wp_register_style('form-styles', plugins_url('form-styles.css',__FILE__ ));
		wp_enqueue_style('form-styles');
	}
add_action( 'admin_init','bvAddStyleSheet');
endif;

if (!function_exists('bvWPEAdminInitHandler')) :
	function bvWPEAdminInitHandler() {
		global $bvNotice, $blogvault;
		global $sidebars_widgets;
		global $wp_registered_widget_updates;

		if (!current_user_can('activate_plugins'))
			return;

		if (isset($_REQUEST['bvnonce']) && wp_verify_nonce($_REQUEST['bvnonce'], "bvnonce")) {
			if (isset($_REQUEST['blogvaultkey'])) {
				if ((strlen($_REQUEST['blogvaultkey']) == 64)) {
					$keys = str_split($_REQUEST['blogvaultkey'], 32);
					$blogvault->updatekeys($keys[0], $keys[1]);
					bvActivateHandler();
					$bvNotice = "<b>Activated!</b> blogVault is now backing up your site.<br/><br/>";
					if (isset($_REQUEST['redirect'])) {
						$location = $_REQUEST['redirect'];
						wp_redirect("https://webapp.blogvault.net/dash/redir?q=".urlencode($location));
						exit();
					}
				} else {
					$bvNotice = "<b style='color:red;'>Invalid request!</b> Please try again with a valid key.<br/><br/>";
				}
			}
		}

		if ($blogvault->getOption('bvActivateRedirect')) {
			$blogvault->updateOption('bvActivateRedirect', false);
			wp_redirect('admin.php?page=bv-wpe-migrate');
		}
	}
	add_action('admin_init', 'bvWPEAdminInitHandler');
endif;

if (!function_exists('bvWpeAdminMenu')) :
	function bvWpeAdminMenu() {
		add_menu_page('BlogVault WPEngine', 'BlogVault WPEngine', 'manage_options', 'bv-wpe-migrate', 'bvWpEMigrate');
	}
	add_action('admin_menu', 'bvWpeAdminMenu');
endif;

if ( !function_exists('bvSettingsLink') ) :
	function bvSettingsLink($links, $file) {
		if ( $file == plugin_basename( dirname(__FILE__).'/blogvault.php' ) ) {
			$links[] = '<a href="' . admin_url( 'admin.php?page=bv-wpe-migrate' ) . '">'.__( 'Settings' ).'</a>';
		}
		return $links;
	}
	add_filter('plugin_action_links', 'bvSettingsLink', 10, 2);
endif;

if ( !function_exists('bvWpEMigrate') ) :
	function bvWpEMigrate() {
		global $blogvault, $bvNotice;
		$_error = NULL;
		if (isset($_GET['error'])) {
			$_error = $_GET['error'];
		}
?>
		<div class="logo-container" style="padding: 50px 0px 10px 20px">
			<a href="http://blogvault.net/" style="padding-right: 20px;"><img src="<?php echo plugins_url('logo.png', __FILE__); ?>" /></a>
			<a href="http://wpengine.com/"><img src="<?php echo plugins_url('wpengine-logo.png', __FILE__); ?>" /></a>
		</div>

		<div id="wrapper">
			<form rel="canonical" action="https://webapp.blogvault.net/home/api_signup" style="padding:0 2% 2em 1%;" method="post" name="signup">
				<h1>Migrate Site to WP Engine</h1>
				<p><font size="3">This plugin makes it very easy to migrate your site to WP Engine</font></p>
<?php if ($_error == "email") { 
	echo '<div class="error" style="padding-bottom:0.5%;"><p>There is already an account with this email.</p></div>';
} else if ($_error == "blog") {
	echo '<div class="error" style="padding-bottom:0.5%;"><p>Could not create an account. Please contact <a href="http://blogvault.net/contact/">blogVault Support</a></p></div>';
} else if (($_error == "custom") && isset($_REQUEST['bvnonce']) && wp_verify_nonce($_REQUEST['bvnonce'], "bvnonce")) {
	echo '<div class="error" style="padding-bottom:0.5%;"><p>'.base64_decode($_GET['message']).'</p></div>';
}
?>
				<input type="hidden" name="bvsrc" value="wpplugin" />
				<input type="hidden" name="migrate" value="wpengine" />
				<input type="hidden" name="loc" value="MIGRATE3FREE" />
				<input type="hidden" name="type" value="sftp" />
				<input type="hidden" name="url" value="<?php echo $blogvault->wpurl(); ?>" />
				<input type="hidden" name="secret" value="<?php echo $blogvault->getOption('bvSecretKey'); ?>">
				<input type='hidden' name='bvnonce' value='<?php echo wp_create_nonce("bvnonce") ?>'>
				<div class="row-fluid">
					<div class="span5" style="border-right: 1px solid #EEE; padding-top:1%;">
						<label id='label_email'>Email</label>
			 			<div class="control-group">
							<div class="controls">
								<input type="text" id="email" name="email" value="<?php echo get_option('admin_email');?>">
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
								<input type="text" class="input-large" placeholder="eg. 1.2.3.4" name="address">
								<p class="help-block"></p>
							</div>
						</div>
						<label class="control-label" for="input01">SFTP Username</label>
						<div class="control-group">
							<div class="controls">
								<input type="text" class="input-large" placeholder="eg. akshatc" name="username">
								<p class="help-block"></p>
							</div>
						</div>
						<label class="control-label" for="input02">SFTP Password</label>
						<div class="control-group">
							<div class="controls">
								<input type="password" class="input-large" name="passwd">
							</div>
						</div>
					</div>
				</div>
				<input type='submit' value='Migrate'>
			</form>
			<div style="max-width: 650px; padding-left: 20px;">
				<h1>How to Use This Plugin</h1>
				<iframe src="//fast.wistia.net/embed/iframe/0rrkl3w1vu?videoFoam=true" allowtransparency="true" frameborder="0" scrolling="no" class="wistia_embed" name="wistia_embed" allowfullscreen mozallowfullscreen webkitallowfullscreen oallowfullscreen msallowfullscreen width="500" height="313"></iframe><script src="//fast.wistia.net/assets/external/E-v1.js"></script>
				<p>In order to successfully move over to WP Engine using this plugin, you will need to find your <strong>SFTP Host</strong>, <strong>SFTP Username</strong>, and <strong>SFTP Password</strong>. We make this very easy to find!</p>
				<p>In addition to that information, you will need to supply a valid email address and the WP Engine Site URL.</p>
				<p>Please note, this plugin is best used along side our migration checklist. Our checklist will be able to provide invaluable information about what data to supply each field.</p>
				<h2>Email</h2>
				<p>This email is used for all communications during the migration process. Please make sure the email you submit is valid and that you have access to the account.</p>
				<h2>WP Engine Site URL</h2>
				<p>This represents the URL you want your site to be migrated too. </p>
				<h3>Using WP Engine’s Temporary URL</h3>
				<p>If you want to view the site on the WP Engine platform before pointing your domain to us, you will want to use WP Engine’s temporary URL for this field. To find your WP Engine Site URL, follow these steps:</p>
				<ol><li>Log in to the WP Engine User Portal at my.wpengine.com.</li>
				<li>Select the install that you want to move this site too.</li>
				<li>Make note of the WP Engine URL labeled “CNAME”</li></ol>
				<p>Here is a visual reference to find the WP Engine Site URL:<p/>
				<img src="<?php echo plugins_url("quick-tut-1.jpg", __FILE__); ?>" />
				<h3>Using Custom URL</h3>
				<p>You may find yourself migrating your site to a different URL to WP Engine. For example, you may have been developing a site on your current host and want to launch it on WP Engine.</p>
				<p>If this sounds like your project, the “WP Engine Site URL” field above should be populated with the domain you want your WP Engine site to live under.</p>
				<p>For example, if you are currently hosting your site under <strong>mydomain-dev.com</strong> and you want your site to be migrated to work with the domain <strong>mydomain.com</strong>, “WP Engine Site URL” will be <strong>http://mydomain.com.</strong></p>
				<h2>SFTP Host</h2>
				<p>To find your SFTP Host, follow these steps:</p>
				<ol><li>Log in to the WP Engine User Portal at my.wpengine.com.</li>
				<li>Select the install that you want to move this site too.</li>
				<li>Find the SFTP Login panel at the bottom.</li>
				<li>The Server Address is your SFTP Host.</li></ol>
				<p>Here is a visual reference to find the SFTP Host:</p>
				<img src="<?php echo plugins_url("quick-tut-2.jpg", __FILE__); ?>" />
				<h2>SFTP Username</h2>
				<p>To find your SFTP Host, follow these steps:</p>
				<ol><li>Log in to the WP Engine User Portal at my.wpengine.com.</li>
				<li>Select the install that you want to move this site too.</li>
				<li>Find the SFTP Login panel at the bottom.</li>
				<li>You will find the Username under the Server Address.</li></ol>
				
				<strong>Note: Make sure the username you select has the environment “Live” selected</strong>
				<p>Here is a visual reference to find the SFTP Username:</p>
				<img src="<?php echo plugins_url("quick-tut-3.jpg", __FILE__); ?>" />
				<h2>SFTP Password</h2>
				<p>To find your SFTP Password, follow these steps:</p>
				<ol><li>Log in to the WP Engine User Portal at my.wpengine.com.</li>
				<li>Select the install that you want to move this site too.</li>
				<li>Find the SFTP Login panel at the bottom.</li>
				<li>Click the Username you are using for the migration.</li>
				<img src="<?php echo plugins_url("quick-tut-4.jpg", __FILE__); ?>" />
				<li>A new screen should pop up for you to change your password. Change the password, confirm the password and save it to our server. Note that the Environment should be set to “Production”, which is your live environment.</li>
				<img src="<?php echo plugins_url("quick-tut-5.jpg", __FILE__); ?>" />
				</ol>
			</div>
		</div> <!-- wrapper ends here -->
<?php
	}
endif;