<?php
/*
Plugin Name: WP Engine Automated Migration
Plugin URI: http://wpengine.com
Description: The easiest way to migrate your site to WP Engine
Author: WP Engine
Author URI: http://blogvault.net/
Version: 1.17
Network: True
 */

/*  Copyright YEAR  PLUGIN_AUTHOR_NAME  (email : PLUGIN AUTHOR EMAIL)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/* Global response array */
global $bvVersion;
global $blogvault;
global $bvDynamicEvents;
$bvVersion = '1.17';

if (is_admin())
	require_once dirname( __FILE__ ) . '/admin.php';

if (!class_exists('BVHttpClient')) {
	require_once dirname( __FILE__ ) . '/bv_http_client.php';
}

if (!class_exists('BlogVault')) {
	require_once dirname( __FILE__ ) . '/bv_class.php';
	$blogvault = BlogVault::getInstance();
}

if (!class_exists('BVDynamicBackup')) {
	require_once dirname( __FILE__ ) . '/bv_dynamic_backup.php';

	$isdynsyncactive = $blogvault->getOption('bvDynSyncActive');
		if ($isdynsyncactive == 'yes') {
			BVDynamicBackup::init();
		}
}

if (!class_exists('BVSecurity')) {
	require_once dirname( __FILE__ ) . '/bv_security.php';
	$bvsecurity = BVSecurity::init();
}

add_action('bvdailyping_daily_event', array($blogvault, 'dailyping'));
if ( !function_exists('bvActivateHandler') ) :
	function bvActivateHandler() {
		global $blogvault;
		if (!wp_next_scheduled('bvdailyping_daily_event')) {
			wp_schedule_event(time(), 'daily', 'bvdailyping_daily_event');
		}
		##BVKEYSLOCATE##
		if ($blogvault->getOption('bvPublic') !== false) {
			$blogvault->updateOption('bvLastSendTime', time());
			$blogvault->updateOption('bvLastRecvTime', 0);
			$blogvault->activate();
		} else {
			$rand_secret = $blogvault->randString(32);
			$blogvault->updateOption('bvSecretKey', $rand_secret);
			$blogvault->updateOption('bvActivateRedirect', 'yes');
		}
	}
	register_activation_hook(__FILE__, 'bvActivateHandler');
endif;

if ( !function_exists('bvDeactivateHandler') ) :
	function bvDeactivateHandler() {
		global $blogvault;
		wp_clear_scheduled_hook('bvdailyping_daily_event');
		$body = array();
		$body['wpurl'] = urlencode($blogvault->wpurl());
		$body['url2'] = urlencode(get_bloginfo('wpurl'));
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$resp = $clt->post($blogvault->getUrl("deactivate"), array(), $body);
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}
	register_deactivation_hook(__FILE__, 'bvDeactivateHandler');
endif;

if ((array_key_exists('apipage', $_REQUEST)) && stristr($_REQUEST['apipage'], 'blogvault')) {
	global $blogvault;
	global $wp_version, $wp_db_version;
	global $wpdb, $bvVersion;
	if (array_key_exists('obend', $_REQUEST) && function_exists('ob_end_clean'))
		@ob_end_clean();
	if ((array_key_exists('mode', $_REQUEST)) && ($_REQUEST['mode'] === "resp")) {
		if (array_key_exists('op_reset', $_REQUEST)) {
			output_reset_rewrite_vars();
		}
		header("Content-type: application/binary");
		header('Content-Transfer-Encoding: binary');
	}
	$method = urldecode($_REQUEST['bvMethod']);
	$blogvault->addStatus("signature", "Blogvault API");
	$blogvault->addStatus("callback", $method);
	$blogvault->addStatus("public", substr($blogvault->getOption('bvPublic'), 0, 6));
	if (!$blogvault->authenticateControlRequest()) {
		$blogvault->addStatus("statusmsg", 'failed authentication');
		$blogvault->terminate();
	}
	$blogvault->addStatus("bvVersion", $bvVersion);
	$blogvault->addStatus("abspath", urldecode(ABSPATH));
	$blogvault->addStatus("serverip", urlencode($_SERVER['SERVER_ADDR']));
	$blogvault->addStatus("siteurl", urlencode($blogvault->wpurl()));
	if (!(array_key_exists('stripquotes', $_REQUEST)) && (get_magic_quotes_gpc() || function_exists('wp_magic_quotes'))) {
		$_REQUEST = array_map( 'stripslashes_deep', $_REQUEST );
	}
	if (array_key_exists('b64', $_REQUEST)) {
		foreach($_REQUEST['b64'] as $key) {
			if (is_array($_REQUEST[$key])) {
				$_REQUEST[$key] = array_map('base64_decode', $_REQUEST[$key]);
			} else {
				$_REQUEST[$key] = base64_decode($_REQUEST[$key]);
			}
		}
	}
	if (array_key_exists('memset', $_REQUEST)) {
		$val = intval(urldecode($_REQUEST['memset']));
		@ini_set('memory_limit', $val.'M');
	}
	switch ($method) {
	case "sendmanyfiles":
		$files = $_REQUEST['files'];
		$offset = intval(urldecode($_REQUEST['offset']));
		$limit = intval(urldecode($_REQUEST['limit']));
		$bsize = intval(urldecode($_REQUEST['bsize']));
		$blogvault->addStatus("status", $blogvault->uploadFiles($files, $offset, $limit, $bsize));
		break;
	case "sendfilesmd5":
		$files = $_REQUEST['files'];
		$offset = intval(urldecode($_REQUEST['offset']));
		$limit = intval(urldecode($_REQUEST['limit']));
		$bsize = intval(urldecode($_REQUEST['bsize']));
		$blogvault->addStatus("status", $blogvault->fileMd5($files, $offset, $limit, $bsize));
		break;
	case "listtables":
		$blogvault->addStatus("status", $blogvault->listTables());
		break;
	case "tableinfo":
		$table = urldecode($_REQUEST['table']);
		$offset = intval(urldecode($_REQUEST['offset']));
		$limit = intval(urldecode($_REQUEST['limit']));
		$bsize = intval(urldecode($_REQUEST['bsize']));
		$filter = urldecode($_REQUEST['filter']);
		$blogvault->addStatus("status", $blogvault->tableInfo($table, $offset, $limit, $bsize, $filter));
		break;
	case "uploadrows":
		$table = urldecode($_REQUEST['table']);
		$offset = intval(urldecode($_REQUEST['offset']));
		$limit = intval(urldecode($_REQUEST['limit']));
		$bsize = intval(urldecode($_REQUEST['bsize']));
		$filter = urldecode($_REQUEST['filter']);
		$blogvault->addStatus("status", $blogvault->uploadRows($table, $offset, $limit, $bsize, $filter));
		break;
	case "sendactivate":
		$blogvault->addStatus("status", $blogvault->activate());
		break;
	case "scanfilesdefault":
		$blogvault->addStatus("status", $blogvault->scanFiles());
		break;
	case "scanfiles":
		$initdir = urldecode($_REQUEST['initdir']);
		$offset = intval(urldecode($_REQUEST['offset']));
		$limit = intval(urldecode($_REQUEST['limit']));
		$bsize = intval(urldecode($_REQUEST['bsize']));
		$blogvault->addStatus("status", $blogvault->scanFiles($initdir, $offset, $limit, $bsize));
		break;
	case "setdynsync":
		$blogvault->updateOption('bvDynSyncActive', $_REQUEST['dynsync']);
		break;
	case "setwoodyn":
		$blogvault->updateOption('bvWooDynSync', $_REQUEST['woodyn']);
		break;
	case "setserverid":
		$blogvault->updateOption('bvServerId', $_REQUEST['serverid']);
		break;
	case "updatekeys":
		$blogvault->addStatus("status", $blogvault->updateKeys($_REQUEST['public'], $_REQUEST['secret']));
		break;
	case "setignorednames":
		switch ($_REQUEST['table']) {
		case "options":
			$blogvault->updateOption('bvIgnoredOptions', $_REQUEST['names']);
			break;
		case "postmeta":
			$blogvault->updateOption('bvIgnoredPostmeta', $_REQUEST['names']);
			break;
		}
		break;
	case "getignorednames":
		switch ($_REQUEST['table']) {
		case "options":
			$names = $blogvault->getOption('bvIgnoredOptions');
			break;
		case "postmeta":
			$names = $blogvault->getOption('bvIgnoredPostmeta');
			break;
		}
		$blogvault->addStatus("names", $names);
		break;
	case "phpinfo":
		phpinfo();
		die();
		break;
	case "getposts":
		require_once (ABSPATH."wp-includes/pluggable.php");
		$post_type = urldecode($_REQUEST['post_type']);
		$args = array('numberposts' => 5, 'post_type' => $post_type);
		$posts = get_posts($args);
		$keys = array('post_title', 'guid', 'ID', 'post_date');
		foreach($posts as $post) {
			$pdata = array();
			$post_array = get_object_vars($post);
			foreach($keys as $key) {
				$pdata[$key] = $post_array[$key];
			}
			$blogvault->addArrayToStatus("posts", $pdata);
		}
		break;
	case "getstats":
		if (!function_exists('wp_count_posts'))
			require_once (ABSPATH."wp-includes/post.php");
		require_once (ABSPATH."wp-includes/pluggable.php");
		$blogvault->addStatus("posts", get_object_vars(wp_count_posts()));
		$blogvault->addStatus("pages", get_object_vars(wp_count_posts("page")));
		$blogvault->addStatus("comments", get_object_vars(wp_count_comments()));
		break;
	case "getinfo":
		if (array_key_exists('wp', $_REQUEST)) {
			$wp_info = array(
				'current_theme' => (string)(function_exists('wp_get_theme') ? wp_get_theme() : get_current_theme()),
				'dbprefix' => $wpdb->base_prefix ? $wpdb->base_prefix : $wpdb->prefix,
				'wpmu' => $blogvault->isMultisite(),
				'mainsite' => $blogvault->isMainSite(),
				'name' => get_bloginfo('name'),
				'site_url' => get_bloginfo('wpurl'),
				'home_url' => get_bloginfo('url'),
				'charset' => get_bloginfo('charset'),
				'wpversion' => $wp_version,
				'dbversion' => $wp_db_version,
				'abspath' => ABSPATH,
				'uploadpath' => $blogvault->uploadPath(),
				'contentdir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
				'plugindir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
				'dbcharset' => defined('DB_CHARSET') ? DB_CHARSET : null,
				'disallow_file_edit' => defined('DISALLOW_FILE_EDIT'),
				'disallow_file_mods' => defined('DISALLOW_FILE_MODS'),
				'bvversion' => $bvVersion
			);
			$blogvault->addStatus("wp", $wp_info);
		}
		if (array_key_exists('plugins', $_REQUEST)) {
			if (!function_exists('get_plugins'))
				require_once (ABSPATH."wp-admin/includes/plugin.php");
			$plugins = get_plugins();
			foreach($plugins as $plugin_file => $plugin_data) {
				$pdata = array(
					'file' => $plugin_file,
					'title' => $plugin_data['Title'],
					'version' => $plugin_data['Version'],
					'active' => is_plugin_active($plugin_file)
				);
				$blogvault->addArrayToStatus("plugins", $pdata);
			}
		}
		if (array_key_exists('themes', $_REQUEST)) {
			$themes = function_exists('wp_get_themes') ? wp_get_themes() : get_themes();
			foreach($themes as $theme) {
				if (is_object($theme)) {
					$pdata = array(
						'name' => $theme->Name,
						'title' => $theme->Title,
						'stylesheet' => $theme->get_stylesheet(),
						'template' => $theme->Template,
						'version' => $theme->Version
					);
				} else {
					$pdata = array(
						'name' => $theme["Name"],
						'title' => $theme["Title"],
						'stylesheet' => $theme["Stylesheet"],
						'template' => $theme["Template"],
						'version' => $theme["Version"]
					);
				}
				$blogvault->addArrayToStatus("themes", $pdata);
			}
		}
		if (array_key_exists('users', $_REQUEST)) {
			$users = array();
			if (function_exists('get_users')) {
				$users = get_users('search=admin');
			} else if (function_exists('get_users_of_blog')) {
				$users = get_users_of_blog();
			}
			foreach($users as $user) {
				if (stristr($user->user_login, 'admin')) {
					$pdata = array(
						'login' => $user->user_login,
						'ID' => $user->ID
					);
					$blogvault->addArrayToStatus("users", $pdata);
				}
			}
		}
		if (array_key_exists('system', $_REQUEST)) {
			$sys_info = array(
				'serverip' => $_SERVER['SERVER_ADDR'],
				'host' => $_SERVER['HTTP_HOST'],
				'phpversion' => phpversion(),
				'uid' => getmyuid(),
				'gid' => getmygid(),
				'user' => get_current_user()
			);
			if (function_exists('posix_getuid')) {
				$sys_info['webuid'] = posix_getuid();
				$sys_info['webgid'] = posix_getgid();
			}
			$blogvault->addStatus("sys", $sys_info);
		}
		break;
	case "setsecurityconf":
		$new_conf = $_REQUEST['secconf'];
		if (!is_array($new_conf)) {
			$new_conf = array();
		}
		$blogvault->updateOption('bvsecurityconfig', $new_conf);
		break;
	case "getsecurityconf":
		$new_conf = $blogvault->getOption('bvsecurityconfig');
		$blogvault->addStatus("secconf", $new_conf);
		break;
	case "describetable":
		$table = urldecode($_REQUEST['table']);
		$blogvault->describeTable($table);
		break;
	case "checktable":
		$table = urldecode($_REQUEST['table']);
		$type = urldecode($_REQUEST['type']);
		$blogvault->checkTable($table, $type);
		break;
	case "repairtable":
		$table = urldecode($_REQUEST['table']);
		$blogvault->repairTable($table);
		break;
	case "tablekeys":
		$table = urldecode($_REQUEST['table']);
		$blogvault->tableKeys($table);
		break;
	case "gettablecreate":
		$tname = $_REQUEST['table'];
		$blogvault->addStatus("create", $blogvault->tableCreate($tname));
		break;
	case "getrowscount":
		$tname = $_REQUEST['table'];
		$blogvault->addStatus("count", $blogvault->rowsCount($tname));
		break;
	case "updatedailyping":
		$value = $_REQUEST['value'];
		$blogvault->addStatus("bvDailyPing", $blogvault->updateDailyPing($value));
		break;
	default:
		$blogvault->addStatus("statusmsg", "Bad Command");
		$blogvault->addStatus("status", false);
		break;
	}

	$blogvault->terminate();
}