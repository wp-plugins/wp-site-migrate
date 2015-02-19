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

if (!function_exists('bvAdminInitHandler')) :
	function bvAdminInitHandler() {
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
			if (defined('BVMIGRATEPLUGIN')) {
				wp_redirect('admin.php?page=bv-wpe-migrate');
			} else {
				wp_redirect('admin.php?page=bv-wpe-key-config');
			}
		}
	}
	add_action('admin_init', 'bvWPEAdminInitHandler');
endif;

if (!function_exists('bvWpeAdminMenu')) :
	function bvWpeAdminMenu() {
		add_menu_page('bV WPEngine', 'bV WPEngine', 'manage_options', 'bv-wpe-key-config', 'bvKeyConf');
		if (defined('BVMIGRATEPLUGIN')) {
			add_submenu_page('bv-wpe-key-config', 'blogVault', 'Migrate Site', 'manage_options', 'bv-wpe-migrate', 'bvWpEMigrate');
		}
	}
	add_action('admin_menu', 'bvWpeAdminMenu');
endif;

if ( !function_exists('bvSettingsLink') ) :
	function bvSettingsLink($links, $file) {
		if ( $file == plugin_basename( dirname(__FILE__).'/blogvault.php' ) ) {
			$links[] = '<a href="' . admin_url( 'admin.php?page=bv-wpe-key-config' ) . '">'.__( 'Settings' ).'</a>';
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
	<a href="http://blogvault.net/" style="float:right;padding: 1% 1% 0 0"><img src="<?php echo plugins_url('logo.png', __FILE__); ?>" /></a>
<?php
		echo '<h2 style="padding-top:1%;" class="nav-tab-wrapper" id="wpseo-tabs">';
		if ($_GET["tutorial"]) {
			echo '<a class="nav-tab" id="migrate-tab" href="'.admin_url("admin.php?page=bv-wpe-migrate").'">Migrate</a>';
			echo '<a class="nav-tab nav-tab-active" id="infobox-tab" href="'.admin_url("admin.php?page=bv-wpe-migrate&tutorial=true").'">Quick Tutorial</a>';
		} else {
			echo '<a class="nav-tab nav-tab-active" id="migrate-tab" href="'.admin_url("admin.php?page=bv-wpe-migrate").'">Migrate</a>';
			echo '<a class="nav-tab" id="infobox-tab" href="'.admin_url("admin.php?page=bv-wpe-migrate&tutorial=true").'">Quick Tutorial</a>';
		}
		echo '</h2>';
?>
<?php if ($_GET["tutorial"]) {
	// PUT TUTORIAL HERE
?>
<h1>How to get WPEngine SFTP Credentials</h1>
<p>blogVault requires SFTP credentials to copy files from your current site to the destination WPEngine site. This information can easily be retrieved from your WP Engine dashboard.<p>
<?php
} else {
	// PUT FORM HERE
?>
	<form rel="canonical" action="https://webapp.blogvault.net/home/api_signup" style="padding:0 2% 2em 1%;" method="post" name="signup">
	<h1>Migrate Site</h1>
	<p><font size="3">This Plugin makes it very easy to migrate your site to WPEngine</font></p>
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
				<span style="color:#82CC39">(of the destination server)</span>
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
<?php
}
	}
endif;

if ( !function_exists('bvKeyConf') ) :
	function bvKeyConf() {
		global $blogvault, $bvNotice;
		$_error = NULL;
		if (isset($_GET['error'])) {
			$_error = $_GET['error'];
		}
?>


<div class="bv_page_wide" style="display:block;background:#fff;padding-right:1%;overflow:hidden; margin-right:2.5%;margin-top:1%;"> <!-- SOWP MAIN -->

	<div class="bv_inside_heading" style="padding:0.25% 0 0 2%;overflow:hidden;border-bottom:1px solid #ebebeb;">
	<a href="http://blogvault.net/"><img src="<?php echo plugins_url('img/logo.png', __FILE__); ?>" /></a>
	</div>


	<div style="overflow:hidden;">	<!-- SOP 1 -->
			<div class="bv_inside_column1" style="width:100%;max-width:75%;float:left;padding:1% 2.5% 1% 2.5%;border-right:1px solid #ebebeb;overflow:hidden;"> <!-- MCA -->
<?php if (!isset($_REQUEST['free'])) { ?>
						<div align="center" style="margin-bottom: 25px;">
									<iframe style="border: 1px solid gray; padding: 3px;" src="https://player.vimeo.com/video/88638675?title=0&amp;byline=0&amp;portrait=0&amp;color=ffffff" width="450" height="275" frameborder="0" webkitAllowFullScreen mozallowfullscreen allowFullScreen></iframe>
					</div>
<?php } ?>


<?php
		echo $bvNotice;
		if ($blogvault->getOption('bvPublic')) {
?>
		<div style="display:table;table-layout:fixed;width:100%;float:left;padding:1% 2.5% 2em 2.5%;overflow:hidden;" id="form_wrapper">
				<font size='3'><a href='https://webapp.blogvault.net' target="_blank">Click here</a> to manage your backups from the <a href='https://webapp.blogvault.net' target="_blank">blogVault Dashboard.</a></font>
				<br/><br/>
<?php if (isset($_REQUEST['changekey'])) { ?>
				<form method='post'>
					<font size='3'>Change blogVault Key:</font> <input type='text' name='blogvaultkey' size='65'>
					<input type='hidden' name='change_parameter' value='true'>
					<input type='hidden' name='bvnonce' value='<?php echo wp_create_nonce("bvnonce") ?>'>
					<input type='submit' value='Change'>
				</form>
<?php } ?>
		</div>
<?php } else { ?> <!-- Change keys ELSE -->
			<div style="display:none">
				<a href='http://blogvault.net?bvsrc=bvplugin&wpurl=<?php echo urlencode($blogvault->wpurl()) ?>'> Click here </a> to get your blogVault Key.</font>
				<form method='post'> 
					<font size='3'>Enter blogVault Key:</font> <input type='text' name='blogvaultkey' size='65'>
					<input type='hidden' name='bvnonce' value='<?php echo wp_create_nonce("bvnonce") ?>'>
					<input type='submit' value='Activate'>	
				</form>
			</div>
<!-- form wrapper starts here-->
<div style="display:table;table-layout:fixed;width:100%;max-width:40%;float:left;padding:1% 2.5% 2em 2.5%;overflow:hidden;border: 1px solid #ebebeb;" id="form_wrapper">
<?php if (true) { ?>
			<!-- Signin form end here -->
			<div>
				<font size="3">Login to your blogVault Account!</font><br/>
				<font size="2">Learn more about blogVault and create your account <a href="https://blogvault.net">here</a></font>
			</div>
			<form rel="canonical" action="https://webapp.blogvault.net/home/api_signin" style="padding:0 2% 2em 1%;" method="post" name="signin">
				<input type="hidden" name="bvsrc" value="wpplugin" />
				<input type="hidden" name="url" value="<?php echo $blogvault->wpurl(); ?>">
				<input type="hidden" name="secret" value="<?php echo $blogvault->getOption('bvSecretKey'); ?>">
				<input type='hidden' name='bvnonce' value='<?php echo wp_create_nonce("bvnonce") ?>'>
<?php if ($_error == "user") { ?>
				<div style="color:red; font-weight: bold;">Incorrect Username or Password</div>
<?php } ?>
				<table style="border-spacing:50px 10px;">
					<tr>
						<td><label><strong>Email</strong></label></td>
						<td><input type="text" name="email" /></td>
					</tr>
					<tr>
						<td width="115"><label><strong>Password</strong></label></td>
						<td><input type="password" name="password" /></td>
					<tr/>
					<?php if ($_error == "pass") echo '<tr><td colspan=3><p style="color:red;">The Email or password provided is incorrect</p></td></tr>' ?>
					<tr>
						<td></td>
						<td align="right"><button type="submit">Sign In</button></td>
					</tr>
					<tr>
						<td></td>
						<td align="right"><a href="https://webapp.blogvault.net/password_resets/new?bvsrc=wpplugin&wpurl=<?php echo urlencode($blogvault->wpurl()) ?>" target="_blank">Forgot Password</a></td>
					</tr>
				</table>
			</form>

<?php } ?>
		</div>	<!-- Signin form ends here -->
		<div class="bv_3part_column1" style="width:100%;max-width:45%;float:left;padding:3% 2.5% 0 2.5%;overflow:hidden;">
					<div style="width:100%;overflow:hidden; margin-bottom: 10px;">
								<blockquote><span class="bqstart" style="float:left;font-size:400%;color:#cfcfcf;">&#8220;</span><h2>blogVault is my favorite way to backup, migrate, and restore WordPress websites.&nbsp;&nbsp;<font size='2'><a href="http://bit.ly/mightyreview" style="text-decoration:none;" align="right" target="_blank">Read the complete review.</a></font></h2> <span style="float:right;"> - Kristin &#38; Mickey &#64; <a href="http://www.mightyminnow.com" style="text-decoration:none;" target="_blank">MIGHTYminnow</a> <font size='1'>(A Top WordPress Agency)</font></span></blockquote>
					</div>
				<font size='2' color="gray">As seen on:</font>
				<div align="center" style="padding-top:3%;"><img src="<?php echo plugins_url('as_seen_in.png', __FILE__); ?>" /></div>
		</div>

	<?php
	}
?>
			</div> <!-- MCA -->
			<div class="bv_ selectedinside_column2" style="margin-top:0.5%;margin-right:0;border:0;max-width:19%;padding:0.5% 0 2em 1%;overflow:hidden;" align="center">
				<!-- SIDE COLUMN CONTENT GOES HERE -->
			</div>
	</div> <!-- EOP 1 -->

</div> <!-- EOWP MAIN -->
<?php
}
endif;

if ( !function_exists('bvActivateWarning') ) :
	function bvActivateWarning() {
		global $hook_suffix;
		global $blogvault;
		if (!$blogvault->getOption('bvPublic') && $hook_suffix == 'admin.php' ) {
?>
			<div id="message" class="updated" style="padding: 8px; font-size: 16px; background-color: #dff0d8">
						<a class="button-primary" href="<?php echo admin_url('admin.php?page=bv-wpe-key-config') ?>">Activate blogVault</a>	
						&nbsp;&nbsp;&nbsp;<b>Almost Done:</b> Activate your blogVault account to backup your site.
			</div>
<?php
		}
	}
	add_action('admin_notices', 'bvActivateWarning');
endif;