<?php

class BlogVault {

	private static $instance = NULL; 
	public $status;

	static public function getInstance() {
		if (self::$instance === NULL)
			self::$instance = new BlogVault();
		return self::$instance;
	}

	function BlogVault() {
		$this->status = array("blogvault" => "response");
	}

	function addStatus($key, $value) {
		$this->status[$key] = $value;
	}

	function addArrayToStatus($key, $value) {
		if (!isset($this->status[$key])) {
			$this->status[$key] = array();
		}
		$this->status[$key][] = $value;
	}

	function terminate() {
		die("bvbvbvbvbv".serialize($this->status)."bvbvbvbvbv");
		exit;
	}

	function getUrl($method) {
		global $bvVersion;
		$baseurl = "/bvapi/";
		$time = time();
		if ($time < $this->getOption('bvLastSendTime')) {
			$time = $this->getOption('bvLastSendTime') + 1;
		}
		$this->updateOption('bvLastSendTime', $time);
		$public = urlencode($this->getOption('bvPublic'));
		$secret = urlencode($this->getOption('bvSecretKey'));
		$serverip = urlencode($_SERVER['SERVER_ADDR']);
		$time = urlencode($time);
		$version = urlencode($bvVersion);
		$sig = sha1($public.$secret.$time.$version);
		return $baseurl.$method."?sha1=1&sig=".$sig."&bvTime=".$time."&bvPublic=".$public."&bvVersion=".$version."&serverip=".$serverip;
	}

	function randString($length) {
		$chars = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";

		$size = strlen($chars);
		for( $i = 0; $i < $length; $i++ ) {
			$str .= $chars[rand(0, $size - 1)];
		}
		return $str;
	}

	function scanFiles($initdir = "./", $offset = 0, $limit = 0, $bsize = 512) {
		$i = 0;
		$j = 0;
		$dirs = array();
		$dirs[] = $initdir;
		$j++;
		$bfc = 0;
		$bfa = array();
		$current = 0;
		$recurse = true;
		if (array_key_exists('recurse', $_REQUEST) && $_REQUEST["recurse"] == "false") {
			$recurse = false;
		}
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("listfiles")."&recurse=".$_REQUEST["recurse"]."&offset=".$offset."&initdir=".urlencode($initdir), "fileslist", "allfiles");
		while ($i < $j) {
			$dir = $dirs[$i];
			$d = @opendir(ABSPATH.$dir);
			if ($d) {
				while (($file = readdir($d)) !== false) {
					if ($file == '.' || $file == '..') { continue; }
						$relfile = $dir.$file;
					$absfile = ABSPATH.$relfile;
					if (is_dir($absfile)) {
						if (is_link($absfile)) { continue; }
							$dirs[] = $relfile."/";
						$j++;
					}
					$stats = @stat($absfile);
					$fdata = array();
					if (!$stats)
						continue;
					$current++;
					if ($offset >= $current)
						continue;
					if (($limit != 0) && (($current - $offset) > $limit)) {
						$i = $j;
						break;
					}
					foreach(preg_grep('#size|uid|gid|mode|mtime#i', array_keys($stats)) as $key ) {
						$fdata[$key] = $stats[$key];
					}

					$fdata["filename"] = $relfile;
					if (($fdata["mode"] & 0xF000) == 0xA000) {
						$fdata["link"] = @readlink($absfile);
					}
					$bfa[] = $fdata;
					$bfc++;
					if ($bfc == $bsize) {
						$str = serialize($bfa);
						$clt->newChunkedPart(strlen($str).":".$str);
						$bfc = 0;
						$bfa = array();
					}
				}
				closedir($d);
			}
			$i++;
			if ($recurse == false)
				break;
		}
		if ($bfc != 0) {
			$str = serialize($bfa);
			$clt->newChunkedPart(strlen($str).":".$str);
		}
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function getValidFiles($files)
	{
		$outfiles = array();
		foreach($files as $file) {
			if (!file_exists($file) || !is_readable($file) ||
				(!is_file($file) && !is_link($file))) {
					$this->addArrayToStatus("missingfiles", $file);
					continue;
				}
			$outfiles[] = $file;
		}
		return $outfiles;
	}

	function fileStat($file) {
		$stats = @stat(ABSPATH.$file);
		$fdata = array();
		foreach(preg_grep('#size|uid|gid|mode|mtime#i', array_keys($stats)) as $key ) {
			$fdata[$key] = $stats[$key];
		}

		$fdata["filename"] = $file;
		return $fdata;
	}

	function fileMd5($files, $offset = 0, $limit = 0, $bsize = 102400) {
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("filesmd5")."&offset=".$offset, "filemd5", "list");
		$files = $this->getValidFiles($files);
		foreach($files as $file) {
			$fdata = array();
			$fdata = $this->fileStat($file);
			$_limit = $limit;
			$_bsize = $bsize;
			if (!file_exists(ABSPATH.$file)) {
				$this->addArrayToStatus("missingfiles", $file);
				continue;
			}
			if ($offset == 0 && $_limit == 0) {
				$md5 = md5_file(ABSPATH.$file);
			} else {
				if ($_limit == 0)
					$_limit = $fdata["size"];
				if ($offset + $_limit < $fdata["size"])
					$_limit = $fdata["size"] - $offset;
				$handle = fopen(ABSPATH.$file, "rb");
				$ctx = hash_init('md5');
				fseek($handle, $offset, SEEK_SET);
				$dlen = 1;
				while (($_limit > 0) && ($dlen > 0)) {
					if ($_bsize > $_limit)
						$_bsize = $_limit;
					$d = fread($handle, $_bsize);
					$dlen = strlen($d);
					hash_update($ctx, $d);
					$_limit -= $dlen;
				}
				fclose($handle);
				$md5 = hash_final($ctx);
			}
			$fdata["md5"] = $md5;
			$sfdata = serialize($fdata);
			$clt->newChunkedPart(strlen($sfdata).":".$sfdata);
		}
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}

		return true;
	}

	function uploadFiles($files, $offset = 0, $limit = 0, $bsize = 102400) {
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("filedump")."&offset=".$offset, "filedump", "data");

		foreach($files as $file) {
			if (!file_exists(ABSPATH.$file)) {
				$this->addArrayToStatus("missingfiles", $file);
				continue;
			}
			$handle = fopen(ABSPATH.$file, "rb");
			if (($handle != null) && is_resource($handle)) {
				$fdata = $this->fileStat($file);
				$sfdata = serialize($fdata);
				$_limit = $limit;
				$_bsize = $bsize;
				if ($_limit == 0)
					$_limit = $fdata["size"];
				if ($offset + $_limit > $fdata["size"])
					$_limit = $fdata["size"] - $offset;
				$clt->newChunkedPart(strlen($sfdata).":".$sfdata.$_limit.":");
				fseek($handle, $offset, SEEK_SET);
				$dlen = 1;
				while (($_limit > 0) && ($dlen > 0)) {
					if ($_bsize > $_limit)
						$_bsize = $_limit;
					$d = fread($handle, $_bsize);
					$dlen = strlen($d);
					$clt->newChunkedPart($d);
					$_limit -= $dlen;
				}
				fclose($handle);
			} else {
				$this->addArrayToStatus("unreadablefiles", $file);
			}
		}
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function wpurl() {
		if (function_exists('network_site_url'))
			return network_site_url();
		else
			return get_bloginfo('wpurl');
	}

	/* This informs the server about the activation */
	function activate() {
		global $wpdb;
		global $blogvault;
		$body = $blogvault->basicInfo();
		if (defined('DB_CHARSET'))
			$body['dbcharset'] = urlencode(DB_CHARSET);
		if ($wpdb->base_prefix) {
			$body['dbprefix'] = urlencode($wpdb->base_prefix);
		} else {
			$body['dbprefix'] = urlencode($wpdb->prefix);
		}
		if (extension_loaded('openssl')) {
			$body['openssl'] = "1";
		}
		if (function_exists('is_ssl') && is_ssl()) {
			$body['https'] = "1";
		}
		$body['sha1'] = "1";
		$all_tables = $this->getAllTables();
		$i = 0;
		foreach ($all_tables as $table) {
			$body["all_tables[$i]"] = urlencode($table);
			$i++;
		}

		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$resp = $clt->post($this->getUrl("activate"), array(), $body);
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	/* This informs the presence of the plugin in site everyday */
	function dailyping() {
		global $blogvault;
		if (!$blogvault->getOption('bvPublic') || $blogvault->getOption('bvDailyPing') == "no") {
			return false;
		}
		$body = $blogvault->basicInfo();
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$resp = $clt->post($blogvault->getUrl("dailyping"), array(), $body);
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function updateDailyPing($value) {
		if(update_option("bvDailyPing", $value)) {
			return $value;
		}
		return "failed";
	}

	function basicInfo() {
		global $bvVersion;
		global $blogvault;
		$body = array();
		$body['wpurl'] = urlencode($blogvault->wpurl());
		$body['url2'] = urlencode(get_bloginfo('wpurl'));
		$body['bvversion'] = urlencode($bvVersion);
		$body['serverip'] = urlencode($_SERVER['SERVER_ADDR']);
		$body['abspath'] = urlencode(ABSPATH);
		$body['dynsync'] = urlencode($blogvault->getOption('bvDynSyncActive'));
		$body['woodyn'] = urlencode($blogvault->getOption('bvWooDynSync'));
		return $body;
	}

	function listTables() {
		global $wpdb;

		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("listtables"), "tableslist", "status");
		$data["listtables"] = $wpdb->get_results( "SHOW TABLE STATUS", ARRAY_A);
		$data["tables"] = $wpdb->get_results( "SHOW TABLES", ARRAY_N);
		$str = serialize($data);
		$clt->newChunkedPart(strlen($str).":".$str);
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function tableKeys($table) {
		global $wpdb, $blogvault;
		$info = $wpdb->get_results("SHOW KEYS FROM $table;", ARRAY_A);
		$blogvault->addStatus("table_keys", $info);
		return true;
	}

	function describeTable($table) {
		global $wpdb, $blogvault;
		$info = $wpdb->get_results("DESCRIBE $table;", ARRAY_A);
		$blogvault->addStatus("table_description", $info);
		return true;
	}

	function checkTable($table, $type) {
		global $wpdb, $blogvault;
		$info = $wpdb->get_results("CHECK TABLE $table $type;", ARRAY_A);
		$blogvault->addStatus("status", $info);
		return true;
	}

	function repairTable($table) {
		global $wpdb, $blogvault;
		$info = $wpdb->get_results("REPAIR TABLE $table;", ARRAY_A);
		$blogvault->addStatus("status", $info);
		return true;
	}

	function tableCreate($tbl) {
		global $wpdb;
		$str = "SHOW CREATE TABLE " . $tbl . ";";
		return $wpdb->get_var($str, 1);
	}

	function rowsCount($tbl) {
		global $wpdb;
		$count = $wpdb->get_var("SELECT COUNT(*) FROM ".$tbl);
		return intval($count);
	}

	function tableInfo($tbl, $offset = 0, $limit = 0, $bsize = 512, $filter = "") {
		global $wpdb;

		$data = array();
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("tableinfo")."&offset=".$offset, "tablename", $tbl);
		if (array_key_exists('create', $_REQUEST)) {
			$data["create"] = $this->tableCreate($tbl);
		}
		if (array_key_exists('count', $_REQUEST)) {
			$data["count"] = $this->rowsCount($tbl);
		}
		$str = serialize($data);
		$clt->newChunkedPart(strlen($str).":".$str);

		if ($limit == 0) {
			$limit = $rows_count;
		}
		$srows = 1;
		while (($limit > 0) && ($srows > 0)) {
			if ($bsize > $limit)
				$bsize = $limit;
			$rows = $wpdb->get_results("SELECT * FROM $tbl $filter LIMIT $bsize OFFSET $offset", ARRAY_A);
			$srows = sizeof($rows);
			$data = array();
			$data["table"] = $tbl;
			$data["offset"] = $offset;
			$data["size"] = $srows;
			$data["md5"] = md5(serialize($rows));
			$str = serialize($data);
			$clt->newChunkedPart(strlen($str).":".$str);
			$offset += $srows;
			$limit -= $srows;
		}
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function uploadRows($tbl, $offset = 0, $limit = 0, $bsize = 512, $filter = "") {
		global $wpdb;
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("uploadrows")."&offset=".$offset, "tablename", $tbl);

		if ($limit == 0) {
			$limit = $wpdb->get_var("SELECT COUNT(*) FROM ".$tbl);
		}
		$srows = 1;
		while (($limit > 0) && ($srows > 0)) {
			if ($bsize > $limit)
				$bsize = $limit;
			$rows = $wpdb->get_results("SELECT * FROM $tbl $filter LIMIT $bsize OFFSET $offset", ARRAY_A);
			$srows = sizeof($rows);
			$data = array();
			$data["offset"] = $offset;
			$data["size"] = $srows;
			$data["rows"] = $rows;
			$data["md5"] = md5(serialize($rows));
			$str = serialize($data);
			$clt->newChunkedPart(strlen($str).":".$str);
			$offset += $srows;
			$limit -= $srows;
		}
		$clt->closeChunkedPart();
		$resp = $clt->getResponse();
		if (array_key_exists('status', $resp) && ($resp['status'] != '200')) {
			return false;
		}
		return true;
	}

	function updateKeys($publickey, $secretkey) {
		$this->updateOption('bvPublic', $publickey);
		$this->updateOption('bvSecretKey', $secretkey);
	}

	function updateOption($key, $value) {
		if (function_exists('update_site_option')) {
			update_site_option($key, $value);
		} else {
			if ($this->isMultisite()) {
				update_blog_option(1, $key, $value);
			} else {
				update_option($key, $value);
			}
		}
	}

	function getOption($key) {
		$res = false;
		if (function_exists('get_site_option')) {
			$res = get_site_option($key, false);
		}
		if ($res === false) {
			if ($this->isMultisite()) {
				$res = get_blog_option(1, $key, false);
			} else {
				$res = get_option($key, false);
			}
		}
		return $res;
	}

	function getAllTables() {
		global $wpdb;
		$all_tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
		$all_tables = array_map(create_function('$a', 'return $a[0];'), $all_tables);
		return $all_tables;
	}


	/* Control Channel */
	function authenticateControlRequest() {
		$secret = urlencode($this->getOption('bvSecretKey'));
		$method = $_REQUEST['bvMethod'];
		$sig = $_REQUEST['sig'];
		$time = intval($_REQUEST['bvTime']);
		$version = $_REQUEST['bvVersion'];
		$this->addStatus("requestedsig", $sig);
		$this->addStatus("requestedtime", $_REQUEST['bvTime']);
		$this->addStatus("requestedversion", $version);
		$bvlastrecvtime = $this->getOption('bvLastRecvTime');
		if ($time < intval($bvlastrecvtime) - 300) {
			$this->addStatus("bvlastrecvtime", $bvlastrecvtime);
			return false;
		}
		if (array_key_exists('sha1', $_REQUEST)) {
			$sig_match = sha1($method.$secret.$time.$version);
			$this->addStatus('sha1', $_REQUEST['sha1']);
		} else {
			$sig_match = md5($method.$secret.$time.$version); 
		}
		if ($sig_match != $sig) {
			$this->addStatus("sigmatch", $sig_match);
			return false;
		}
		$this->updateOption('bvLastRecvTime', $time);
		return true;
	}

	function isMultisite() {
		if (function_exists('is_multisite'))
			return is_multisite();
		return false;
	}

	function isMainSite() {
		if (!function_exists('is_main_site' ) || !$this->isMultisite())
			return true;
		return is_main_site();
	}

	function uploadPath() {
		$dir = wp_upload_dir();

		return $dir['basedir'];
	}

	function processApiRequest() {
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
		$this->addStatus("signature", "Blogvault API");
		$this->addStatus("callback", $method);
		$this->addStatus("public", substr($this->getOption('bvPublic'), 0, 6));
		if (!$this->authenticateControlRequest()) {
			$this->addStatus("statusmsg", 'failed authentication');
			$this->terminate();
		}
		$this->addStatus("bvVersion", $bvVersion);
		$this->addStatus("abspath", urldecode(ABSPATH));
		$this->addStatus("serverip", urlencode($_SERVER['SERVER_ADDR']));
		$this->addStatus("siteurl", urlencode($this->wpurl()));
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
			$this->addStatus("status", $this->uploadFiles($files, $offset, $limit, $bsize));
			break;
		case "sendfilesmd5":
			$files = $_REQUEST['files'];
			$offset = intval(urldecode($_REQUEST['offset']));
			$limit = intval(urldecode($_REQUEST['limit']));
			$bsize = intval(urldecode($_REQUEST['bsize']));
			$this->addStatus("status", $this->fileMd5($files, $offset, $limit, $bsize));
			break;
		case "listtables":
			$this->addStatus("status", $this->listTables());
			break;
		case "tableinfo":
			$table = urldecode($_REQUEST['table']);
			$offset = intval(urldecode($_REQUEST['offset']));
			$limit = intval(urldecode($_REQUEST['limit']));
			$bsize = intval(urldecode($_REQUEST['bsize']));
			$filter = urldecode($_REQUEST['filter']);
			$this->addStatus("status", $this->tableInfo($table, $offset, $limit, $bsize, $filter));
			break;
		case "uploadrows":
			$table = urldecode($_REQUEST['table']);
			$offset = intval(urldecode($_REQUEST['offset']));
			$limit = intval(urldecode($_REQUEST['limit']));
			$bsize = intval(urldecode($_REQUEST['bsize']));
			$filter = urldecode($_REQUEST['filter']);
			$this->addStatus("status", $this->uploadRows($table, $offset, $limit, $bsize, $filter));
			break;
		case "sendactivate":
			$this->addStatus("status", $this->activate());
			break;
		case "scanfilesdefault":
			$this->addStatus("status", $this->scanFiles());
			break;
		case "scanfiles":
			$initdir = urldecode($_REQUEST['initdir']);
			$offset = intval(urldecode($_REQUEST['offset']));
			$limit = intval(urldecode($_REQUEST['limit']));
			$bsize = intval(urldecode($_REQUEST['bsize']));
			$this->addStatus("status", $this->scanFiles($initdir, $offset, $limit, $bsize));
			break;
		case "setdynsync":
			$this->updateOption('bvDynSyncActive', $_REQUEST['dynsync']);
			break;
		case "setwoodyn":
			$this->updateOption('bvWooDynSync', $_REQUEST['woodyn']);
			break;
		case "setserverid":
			$this->updateOption('bvServerId', $_REQUEST['serverid']);
			break;
		case "updatekeys":
			$this->addStatus("status", $this->updateKeys($_REQUEST['public'], $_REQUEST['secret']));
			break;
		case "setignorednames":
			switch ($_REQUEST['table']) {
			case "options":
				$this->updateOption('bvIgnoredOptions', $_REQUEST['names']);
				break;
			case "postmeta":
				$this->updateOption('bvIgnoredPostmeta', $_REQUEST['names']);
				break;
			}
			break;
			case "getignorednames":
				switch ($_REQUEST['table']) {
				case "options":
					$names = $this->getOption('bvIgnoredOptions');
					break;
				case "postmeta":
					$names = $this->getOption('bvIgnoredPostmeta');
					break;
				}
				$this->addStatus("names", $names);
				break;
				case "phpinfo":
					phpinfo();
					die();
					break;
				case "getposts":
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
						$this->addArrayToStatus("posts", $pdata);
					}
					break;
				case "getstats":
					$this->addStatus("posts", get_object_vars(wp_count_posts()));
					$this->addStatus("pages", get_object_vars(wp_count_posts("page")));
					$this->addStatus("comments", get_object_vars(wp_count_comments()));
					break;
				case "getinfo":
					if (array_key_exists('wp', $_REQUEST)) {
						$wp_info = array(
							'current_theme' => (string)(function_exists('wp_get_theme') ? wp_get_theme() : get_current_theme()),
							'dbprefix' => $wpdb->base_prefix ? $wpdb->base_prefix : $wpdb->prefix,
							'wpmu' => $this->isMultisite(),
							'mainsite' => $this->isMainSite(),
							'name' => get_bloginfo('name'),
							'site_url' => get_bloginfo('wpurl'),
							'home_url' => get_bloginfo('url'),
							'charset' => get_bloginfo('charset'),
							'wpversion' => $wp_version,
							'dbversion' => $wp_db_version,
							'abspath' => ABSPATH,
							'uploadpath' => $this->uploadPath(),
							'uploaddir' => wp_upload_dir(),
							'contentdir' => defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : null,
							'plugindir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : null,
							'dbcharset' => defined('DB_CHARSET') ? DB_CHARSET : null,
							'disallow_file_edit' => defined('DISALLOW_FILE_EDIT'),
							'disallow_file_mods' => defined('DISALLOW_FILE_MODS'),
							'bvversion' => $bvVersion
						);
						$this->addStatus("wp", $wp_info);
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
							$this->addArrayToStatus("plugins", $pdata);
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
							$this->addArrayToStatus("themes", $pdata);
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
								$this->addArrayToStatus("users", $pdata);
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
						$this->addStatus("sys", $sys_info);
					}
					break;
				case "setsecurityconf":
					$new_conf = $_REQUEST['secconf'];
					if (!is_array($new_conf)) {
						$new_conf = array();
					}
					$this->updateOption('bvsecurityconfig', $new_conf);
					break;
				case "getsecurityconf":
					$new_conf = $this->getOption('bvsecurityconfig');
					$this->addStatus("secconf", $new_conf);
					break;
				case "describetable":
					$table = urldecode($_REQUEST['table']);
					$this->describeTable($table);
					break;
				case "checktable":
					$table = urldecode($_REQUEST['table']);
					$type = urldecode($_REQUEST['type']);
					$this->checkTable($table, $type);
					break;
				case "repairtable":
					$table = urldecode($_REQUEST['table']);
					$this->repairTable($table);
					break;
				case "tablekeys":
					$table = urldecode($_REQUEST['table']);
					$this->tableKeys($table);
					break;
				case "gettablecreate":
					$tname = $_REQUEST['table'];
					$this->addStatus("create", $this->tableCreate($tname));
					break;
				case "getrowscount":
					$tname = $_REQUEST['table'];
					$this->addStatus("count", $this->rowsCount($tname));
					break;
				case "updatedailyping":
					$value = $_REQUEST['value'];
					$this->addStatus("bvDailyPing", $this->updateDailyPing($value));
					break;
				default:
					$this->addStatus("statusmsg", "Bad Command");
					$this->addStatus("status", false);
					break;
		}

		$this->terminate();
	}
}