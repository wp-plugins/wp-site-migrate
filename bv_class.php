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
		$time = urlencode($time);
		$version = urlencode($bvVersion);
		$sig = md5($public.$secret.$time.$version);
		return $baseurl.$method."?sig=".$sig."&bvTime=".$time."&bvPublic=".$public."&bvVersion=".$version;
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
					if ($bfc == 512) {
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
		global $bvVersion;
		global $blogvault;
		$body = array();
		$body['wpurl'] = urlencode($blogvault->wpurl());
		$body['url2'] = urlencode(get_bloginfo('wpurl'));
		$body['abspath'] = urlencode(ABSPATH);
		if (defined('DB_CHARSET'))
			$body['dbcharset'] = urlencode(DB_CHARSET);
		if ($wpdb->base_prefix) {
			$body['dbprefix'] = urlencode($wpdb->base_prefix);
		} else {
			$body['dbprefix'] = urlencode($wpdb->prefix);
		}
		$body['bvversion'] = urlencode($bvVersion);
		$body['serverip'] = urlencode($_SERVER['SERVER_ADDR']);
		$body['dynsync'] = urlencode($blogvault->getOption('bvDynSyncActive'));
		$body['woodyn'] = urlencode($blogvault->getOption('bvWooDynSync'));
		if (extension_loaded('openssl')) {
			$body['openssl'] = "1";
		}
		if (function_exists('is_ssl') && is_ssl()) {
			$body['https'] = "1";
		}
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

	function tableInfo($tbl, $offset = 0, $limit = 0, $bsize = 512, $filter = "") {
		global $wpdb;

		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		$clt->uploadChunkedFile($this->getUrl("tableinfo")."&offset=".$offset, "tablename", $tbl);
		$str = "SHOW CREATE TABLE " . $tbl . ";";
		$create = $wpdb->get_var($str, 1);
		$rows_count = $wpdb->get_var("SELECT COUNT(*) FROM ".$tbl);
		$data = array();
		$data["create"] = $create;
		$data["count"] = intval($rows_count);
		$data["encoding"] = mysql_client_encoding();
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
		if ($this->isMultisite()) {
			update_blog_option(1, $key, $value);
		} else {
			update_option($key, $value);
		}
	}

	function getOption($key) {
		if ($this->isMultisite()) {
			return get_blog_option(1, $key);
		} else {
			return get_option($key);
		}
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
		if ($time < intval($this->getOption('bvLastRecvTime')) - 300) {
			return false;
		}
		if (md5($method.$secret.$time.$version) != $sig) {
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

}