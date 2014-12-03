<?php

class BVDynamicBackup {
	function BVDynamicBackup() {
		$this->add_actions_and_listeners();
		$this->reset_events();
	}

	static public function &init() {
		static $instance = false;
		if (!$instance) {
			$instance = new BVDynamicBackup();
		}
		return $instance;
	}

	function reset_events() {
		global $bvDynamicEvents;
		$bvDynamicEvents = array();
	}

	function send_updates() {
		global $bvDynamicEvents, $blogvault;
		if (count($bvDynamicEvents) == 0) {
			return true;
		}
		$clt = new BVHttpClient();
		if (strlen($clt->errormsg) > 0) {
			return false;
		}
		if ($blogvault->isMultisite()) {
			$site_id = get_current_blog_id();
		} else {
			$site_id = 1;
		}
		$timestamp = gmmktime();
		// Should we do a GET to bypass hosts which might block POSTS
		$resp = $clt->post($blogvault->getUrl("dynamic_updates"), array(), array('events' => serialize($bvDynamicEvents),
			'site_id' => $site_id, 'timestamp' => $timestamp, 'wpurl' => urlencode($blogvault->wpurl())));
		if ($resp['status'] != '200') {
			return false;
		}
		$this->reset_events();
		return true;
	}

	function add_event($event_type, $message) {
		global $bvDynamicEvents, $wp_current_filter;
		$message['event_type'] = $event_type;
		$message['event_tag'] = end($wp_current_filter);
		if (!in_array($message, $bvDynamicEvents))
			$bvDynamicEvents[] = $message;
	}

	function add_db_event($table, $message) {
		global $bvDynamicEvents;
		$_msg = array();
		$_msg['table'] = $table;
		$_msg['data'] = $message;
		$this->add_event('db', $_msg);
	}

	function post_action_handler($post_id) {
		if (current_filter() == 'delete_post')
			$msg_type = 'delete';
		else 
			$msg_type = 'edit';
		$this->add_db_event('posts', array('ID' => $post_id, 'msg_type' => $msg_type));
	}

	function get_ignored_postmeta() {
		global $blogvault;
		$ignored_postmeta = $blogvault->getOption('bvIgnoredPostmeta');
		if (empty($ignored_postmeta)) {
			$ignored_postmeta = array();
		}
		return $ignored_postmeta;
	}

	function postmeta_insert_handler($meta_id, $post_id, $meta_key, $meta_value='') {
		if (in_array($meta_key, $this->get_ignored_postmeta()))
			return;
		$this->add_db_event('postmeta', array('meta_id' => $meta_id));
	}

	function postmeta_modification_handler($meta_id, $object_id, $meta_key, $meta_value) {
		if (in_array($meta_key, $this->get_ignored_postmeta()))
			return;
		if (!is_array($meta_id))
			return $this->add_db_event('postmeta', array('meta_id' => $meta_id));
		foreach ($meta_id as $id) {
			$this->add_db_event('postmeta', array('meta_id' => $id));
		}
	}

	function postmeta_action_handler($meta_id, $post_id = null, $meta_key = null) {
		if (in_array($meta_key, $this->get_ignored_postmeta()))
			return;
		if ( !is_array($meta_id) )
			return $this->add_db_event('postmeta', array('meta_id' => $meta_id));
		foreach ( $meta_id as $id )
			$this->add_db_event('postmeta', array('meta_id' => $id));
	}

	function comment_action_handler($comment_id) {
		if (!is_array($comment_id)) {
			if (wp_get_comment_status($comment_id) != 'spam')
				$this->add_db_event('comments', array('comment_ID' => $comment_id));
		} else {
			foreach ($comment_id as $id) {
				if (wp_get_comment_status($comment_id) != 'spam')
					$this->add_db_event('comments', array('comment_ID' => $id));
			}
		}
	}

	function commentmeta_insert_handler($meta_id, $comment_id = null) {
		if (empty($comment_id) || wp_get_comment_status($comment_id) != 'spam')
			$this->add_db_event('commentmeta', array('meta_id' => $meta_id));
	}

	function commentmeta_modification_handler($meta_id, $object_id, $meta_key, $meta_value) {
		if (!is_array($meta_id))
			return $this->add_db_event('commentmeta', array('meta_id' => $meta_id));
		foreach ($meta_id as $id) {
			$this->add_db_event('commentmeta', array('meta_id' => $id));
		}
	}

	function userid_action_handler($user_or_id) {
		if (is_object($user_or_id))
			$userid = intval( $user_or_id->ID );
		else
			$userid = intval( $user_or_id );
		if ( !$userid )
			return;
		$this->add_db_event('users', array('ID' => $userid));
	}

	function usermeta_insert_handler($umeta_id, $user_id = null) {
		$this->add_db_event('usermeta', array('umeta_id' => $umeta_id));
	}

	function usermeta_modification_handler($umeta_id, $object_id, $meta_key, $meta_value = '') {
		if (!is_array($umeta_id))
			return $this->add_db_event('usermeta', array('umeta_id' => $umeta_id));
		foreach ($umeta_id as $id) {
			$this->add_db_event('usermeta', array('umeta_id' => $id));
		}
	}

	function link_action_handler($link_id) {
		$this->add_db_event('links', array('link_id' => $link_id));
	}

	function edited_terms_handler($term_id, $taxonomy = null) {
		$this->add_db_event('terms', array('term_id' => $term_id));
	}

	function term_handler($term_id, $tt_id, $taxonomy) {
		$this->add_db_event('terms', array('term_id' => $term_id));
		$this->term_taxonomy_handler($tt_id, $taxonomy);
	}

	function delete_term_handler($term, $tt_id, $taxonomy, $deleted_term ) {
		$this->add_db_event('terms', array('term_id' => $term, 'msg_type' => 'delete'));
	}

	function term_taxonomy_handler($tt_id, $taxonomy = null) {
		$this->add_db_event('term_taxonomy', array('term_taxonomy_id' => $tt_id));
	}

	function term_taxonomies_handler($tt_ids) {
		foreach((array)$tt_ids as $tt_id) {
			$this->term_taxonomy_handler($tt_id);
		}
	}

	function term_relationship_handler($object_id, $term_id) {
		$this->add_db_event('term_relationships', array('term_taxonomy_id' => $term_id, 'object_id' => $object_id));
	}

	function term_relationships_handler($object_id, $term_ids) {
		foreach ((array)$term_ids as $term_id) {
			$this->term_relationship_handler($object_id, $term_id);
		}
	}

	function set_object_terms_handler( $object_id, $terms, $tt_ids ) {
		$this->term_relationships_handler( $object_id, $tt_ids );
	}

	function get_ignored_options() {
		global $blogvault;
		$defaults = array(
			'cron',
			'wpsupercache_gc_time',
			'rewrite_rules',
			'akismet_spam_count',
			'/_transient_/',
			'bvLastRecvTime',
			'bvLastSendTime',
			'iwp_client_user_hit_count',
			'_disqus_sync_lock',
			'stats_cache'
		);
		$ignored_options = $blogvault->getOption('bvIgnoredOptions');
		if (empty($ignored_options)) {
			$ignored_options = array();
		}
		return array_unique(array_merge($defaults, $ignored_options));
	}

	function option_handler($option_name) {
		$should_ping = true;
		$ignored_options = $this->get_ignored_options();
		foreach($ignored_options as $val) {
			if ($val{0} == '/') {
				if (preg_match($val, $option_name))
					$should_ping = false;
			} else {
				if ($val == $option_name)
					$should_ping = false;
			}
			if (!$should_ping)
				break;
		}
		if ($should_ping)
			$this->add_db_event('options', array('option_name' => $option_name));
		if ($option_name == '_transient_doing_cron')
			$this->send_updates();
		return $option_name;	
	}

	function theme_action_handler($theme) {
		global $blogvault;
		$this->add_event('themes', array('theme' => $blogvault->getOption('stylesheet')));
	}

	function plugin_action_handler($plugin='') {
		$this->add_event('plugins', array('name' => $plugin));
	}

	function upload_handler($file) {
		$this->add_event('uploads', array('file' => $file['file']));
		return $file;	
	}

	function wpmu_new_blog_create_handler($site_id) {
		$this->add_db_event('blogs', array('site_id' => $site_id));
	}

	function sitemeta_handler($option) {
		global $wpdb;
		$this->add_db_event('sitemeta', array('site_id' => $wpdb->siteid, 'meta_key' => $option));
	}

	/* WOOCOMMERCE SUPPORT FUNCTIONS BEGINS FROM HERE*/

	function woocommerce_settings_start_handler() {
		if (!empty($_POST)) {
			if ($_GET['tab'] == 'tax') {
				$this->add_event('sync_table', array('name' => 'woocommerce_tax_rate_locations'));
				$this->add_event('sync_table', array('name' => 'woocommerce_tax_rates'));
			}
		}
	}

	function woocommerce_resume_order_handler($order_id) {
		$this->add_db_event('woocommerce_order_items', array('order_id' => $order_id, 'msg_type' => 'delete'));
		$this->add_event('sync_table', array('name' => 'woocommerce_order_itemmeta'));
	}

	function woocommerce_new_order_item_handler($item_id, $item, $order_id) {
		$this->add_db_event('woocommerce_order_items', array('order_item_id' => $item_id));
	}

	function woocommerce_delete_order_item_handler($item_id) {
		$this->add_db_event('woocommerce_order_itemmeta', array('order_item_id' => $item_id, 'msg_type' => 'delete'));
		$this->add_db_event('woocommerce_order_items', array('order_item_id' => $item_id, 'msg_type' => 'delete'));
	}

	function woocommerce_downloadable_product_permissions_handler($order_id = null) {
		$this->add_db_event('woocommerce_downloadable_product_permissions', array('order_id' => $order_id));
	}

	function woocommerce_download_product_handler($email, $order_key, $product_id, $user_id, $download_id, $order_id) {
		$this->add_db_event('woocommerce_downloadable_product_permissions', array(
				'user_email' => $email,
				'download_id' => $download_id,
				'product_id' => $product_id,
				'order_key' => $order_key));
	}

	function woocommerce_order_itemmeta_insert_handler($meta_id, $order_item_id = null) {
		$this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $meta_id));
	}

	function woocommerce_order_itemmeta_modification_handler($meta_id, $object_id, $meta_key, $meta_value) {
		if (!is_array($meta_id))
			return $this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $meta_id));
		foreach ($meta_id as $id) {
			$this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $id));
		}
	}

	function woocommerce_termmeta_insert_handler($meta_id, $order_item_id = null) {
		$this->add_db_event('woocommerce_termmeta', array('meta_id' => $meta_id));
	}

	function woocommerce_termmeta_modification_handler($meta_id, $object_id, $meta_key, $meta_value) {
		if (!is_array($meta_id))
			return $this->add_db_event('woocommerce_termmeta', array('meta_id' => $meta_id));
		foreach ($meta_id as $id) {
			$this->add_db_event('woocommerce_termmeta', array('meta_id' => $id));
		}
	}

	function woocommerce_attribute_added_handler($attribute_id, $attribute) {
		$this->add_db_event('woocommerce_attribute_taxonomies', array('attribute_id' => $attribute_id));
	}

	function woocommerce_attribute_updated_handler($attribute_id, $attribute, $old_attribute_name) {
		$this->add_db_event('woocommerce_attribute_taxonomies', array('attribute_id' => $attribute_id));
		# $woocommerce->attribute_taxonomy_name( $attribute_name )
		$this->add_db_event('term_taxonomy', array('taxonomy' => 'pa_' . $attribute['attribute_name']));
		# sanitize_title( $attribute_name )
		$this->add_db_event('woocommerce_termmeta', array('meta_key' => 'order_pa_' . $attribute['attribute_name']));
		$this->add_db_event('postmeta', array('meta_key' => '_product_attributes'));
		# sanitize_title( $attribute_name )
		$this->add_db_event('postmeta', array('meta_key' => 'attribute_pa_' . $attribute['attribute_name']));
	}

	function woocommerce_attribute_deleted_handler($attribute_id, $attribute_name, $taxonomy) {
		return $this->add_db_event('woocommerce_attribute_taxonomies', array('attribute_id' => $attribute_id, 'msg_type' => 'delete'));
	}

	function woocommerce_grant_access_to_download_handler() {
		$order_id   = intval($_POST['order_id']);
		$product_id = intval($_POST['product_id']);
		$this->add_db_event('woocommerce_downloadable_product_permissions', array('order_id' => $order_id, 'product_id' => $product_id));
	}

	function woocommerce_revoke_access_to_download_handler() {
		$order_id   = intval($_POST['order_id']);
		$product_id = intval($_POST['product_id']);
		$download_id = $_POST['download_id'];
		$this->add_db_event('woocommerce_downloadable_product_permissions', array('order_id' => $order_id, 'product_id' => $product_id,
				'download_id' => $download_id, 'msg_type' => 'delete'));
	}

	function woocommerce_remove_order_item_meta_handler() {
		$meta_id = absint($_POST['meta_id']);
		$this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $meta_id, 'msg_type' => 'delete'));
	}

	function woocommerce_calc_line_taxes_handler() {
		$order_id = absint($_POST['order_id']);
		$this->add_event('sync_table', array('name' => 'woocommerce_order_itemmeta'));
		$this->add_db_event('woocommerce_order_items', array('order_id' => $order_id, 'order_item_type' => 'tax', 'msg_type' => 'delete'));
	}

	function woocommerce_product_ordering_handler() {
		$this->add_event('sync_table', array('name' => 'posts'));
	}

	function woocommerce_process_shop_coupon_meta_handler($post_id, $post) {
		$this->add_db_event('posts', array('ID' => $post_id));
	}

	function woocommerce_process_shop_order_meta_handler($post_id, $post) {
		$this->add_db_event('posts', array('ID' => $post_id));
		if (isset($_POST['order_taxes_id'])) {
			foreach($_POST['order_taxes_id'] as $item_id) {
				$this->add_db_event('woocommerce_order_items', array('order_item_id' => $item_id));
			}
		}
		if (isset($_POST['order_item_id'])) {
			foreach($_POST['order_item_id'] as $item_id) {
				$this->add_db_event('woocommerce_order_items', array('order_item_id' => $item_id));
			}
		}
		if (isset($_POST['meta_key'])) {
			foreach($_POST['meta_key'] as $id => $meta_key) {
				$this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $id));
			}
		}
		if (isset($_POST['download_id']) && isset($_POST['product_id'])) {
			$download_ids = $_POST['download_id'];
			$product_ids = $_POST['product_id'];
			$product_ids_count = sizeof($product_ids);
			for ( $i = 0; $i < $product_ids_count; $i ++ ) {
				$this->add_db_event('woocommerce_downloadable_product_permissions', array(
					'order_id'    => $post_id,
					'product_id'  => absint($product_ids[$i]),
					'download_id' => $download_ids[$i]
				));
			}
		}
	}

	function woocommerce_update_product_variation_handler($variation_id) {
		$this->add_db_event('posts', array('ID' => $variation_id));
	}

	function woocommerce_save_product_variation_handler($variation_id, $i) {
		if ($variation_id) {
			$this->add_db_event('postmeta', array('post_id' => $variation_id, 'msg_type' => 'delete'));
		}
	}

	function woocommerce_duplicate_product_handler($id, $post) {
		#$this->add_db_event('posts', array('ID' => $id));
		#$this->add_db_event('postmeta', array('post_id' => $id));
		$this->add_event('sync_table', array('name' => 'posts'));
		$this->add_event('sync_table', array('name' => 'postmeta'));
	}

	function woocommerce_tax_rate_handler($tax_rate_id, $_tax_rate) {
		$this->add_db_event('woocommerce_tax_rates', array('tax_rate_id' => $tax_rate_id));
		$this->add_db_event('woocommerce_tax_rate_locations', array('tax_rate_id' => $tax_rate_id));
	}

	function woocommerce_tax_rate_deleted_handler($tax_rate_id) {
		$this->add_db_event('woocommerce_tax_rates', array('tax_rate_id' => $tax_rate_id, 'msg_type' => 'delete'));
		$this->add_db_event('woocommerce_tax_rate_locations', array('tax_rate_id' => $tax_rate_id, 'msg_type' => 'delete'));
	}

	function woocommerce_grant_product_download_access_handler($data) {
		$this->add_db_event('woocommerce_downloadable_product_permissions', array('download_id' => $data['download_id']));
	}

	function woocommerce_ajax_revoke_access_to_product_download_handler($download_id, $product_id, $order_id) {
		$this->add_db_event('woocommerce_downloadable_product_permissions', array('order_id' => $order_id, 'product_id' => $product_id, 'download_id' => $download_id, 'msg_type' => 'delete'));
	}

	function woocommerce_delete_order_items_handler($postid) {
		global $wpdb;
		$meta_ids = array();
		$order_item_ids = array();
		foreach( $wpdb->get_results("SELECT {$wpdb->prefix}woocommerce_order_itemmeta.meta_id, {$wpdb->prefix}woocommerce_order_items.order_item_id FROM {$wpdb->prefix}woocommerce_order_items JOIN {$wpdb->prefix}woocommerce_order_itemmeta ON {$wpdb->prefix}woocommerce_order_items.order_item_id = {$wpdb->prefix}woocommerce_order_itemmeta.order_item_id WHERE {$wpdb->prefix}woocommerce_order_items.order_id = '{$postid}'") as $key => $row) {
			if (!in_array($row->meta_id, $meta_ids)) {
				$meta_ids[] = $row->meta_id;
				$this->add_db_event('woocommerce_order_itemmeta', array('meta_id' => $row->meta_id, 'msg_type' => 'delete'));
			}
			if (!in_array($row->order_item_id, $order_item_ids)) {
				$order_item_ids[] = $row->order_item_id;
				$this->add_db_event('woocommerce_order_items', array('order_item_id' => $row->order_item_id, 'msg_type' => 'delete'));
			}
		}
	}

	function import_handler() {
		$this->add_event('sync_table', array('all' => 'true'));
	}

	function retrieve_password_key_handler($user_login, $key) {
		$this->add_db_event('users', array('user_login' => $user_login));
	}

	/* ADDING ACTION AND LISTENERS FOR CAPTURING EVENTS. */
	function add_actions_and_listeners() {
		global $blogvault;
		/* CAPTURING EVENTS FOR WP_COMMENTS TABLE */
		add_action('delete_comment', array($this, 'comment_action_handler'));
		add_action('wp_set_comment_status', array($this, 'comment_action_handler'));
		add_action('trashed_comment', array($this, 'comment_action_handler'));
		add_action('untrashed_comment', array($this, 'comment_action_handler'));
		add_action('wp_insert_comment', array($this, 'comment_action_handler'));
		add_action('comment_post', array($this, 'comment_action_handler'));
		add_action('edit_comment', array($this, 'comment_action_handler'));

		/* CAPTURING EVENTS FOR WP_COMMENTMETA TABLE */
		add_action('added_comment_meta', array($this, 'commentmeta_insert_handler' ), 10, 2);
		add_action('updated_comment_meta', array($this, 'commentmeta_modification_handler'), 10, 4);
		add_action('deleted_comment_meta', array($this, 'commentmeta_modification_handler'), 10, 4);

		/* CAPTURING EVENTS FOR WP_USERMETA TABLE */
		add_action('added_user_meta', array($this, 'usermeta_insert_handler' ), 10, 2);
		add_action('updated_user_meta', array($this, 'usermeta_modification_handler' ), 10, 4);
		add_action('deleted_user_meta', array($this, 'usermeta_modification_handler' ), 10, 4);
		add_action('added_usermeta',  array( $this, 'usermeta_modification_handler'), 10, 4);
		add_action('update_usermeta', array( $this, 'usermeta_modification_handler'), 10, 4);
		add_action('delete_usermeta', array( $this, 'usermeta_modification_handler'), 10, 4);

		add_action('user_register', array($this, 'userid_action_handler'));
		add_action('password_reset', array($this, 'userid_action_handler'));
		add_action('profile_update', array($this, 'userid_action_handler'));
		add_action('deleted_user', array($this, 'userid_action_handler'));

		/* CAPTURING EVENTS FOR WP_POSTS TABLE */
		add_action('delete_post', array($this, 'post_action_handler'));
		add_action('trash_post', array($this, 'post_action_handler'));
		add_action('untrash_post', array($this, 'post_action_handler'));
		add_action('edit_post', array($this, 'post_action_handler'));
		add_action('save_post', array($this, 'post_action_handler'));
		add_action('wp_insert_post', array($this, 'post_action_handler'));
		add_action('edit_attachment', array($this, 'post_action_handler'));
		add_action('add_attachment', array($this, 'post_action_handler'));
		add_action('delete_attachment', array($this, 'post_action_handler'));
		add_action('private_to_published', array($this, 'post_action_handler'));
		add_action('wp_restore_post_revision', array($this, 'post_action_handler'));

		/* CAPTURING EVENTS FOR WP_POSTMETA TABLE */
		// Why events for both delete and deleted
		add_action('added_post_meta', array($this, 'postmeta_insert_handler'), 10, 4);
		add_action('update_post_meta', array($this, 'postmeta_modification_handler'), 10, 4);
		add_action('updated_post_meta', array($this, 'postmeta_modification_handler'), 10, 4);
		add_action('delete_post_meta', array($this, 'postmeta_modification_handler'), 10, 4);
		add_action('deleted_post_meta', array($this, 'postmeta_modification_handler'), 10, 4);
		add_action('added_postmeta', array($this, 'postmeta_action_handler'), 10, 3);
		add_action('update_postmeta', array($this, 'postmeta_action_handler'), 10, 3);
		add_action('delete_postmeta', array($this, 'postmeta_action_handler'), 10, 3);

		/* CAPTURING EVENTS FOR WP_LINKS TABLE */
		add_action('edit_link', array($this, 'link_action_handler'));
		add_action('add_link', array($this, 'link_action_handler'));
		add_action('delete_link', array($this, 'link_action_handler'));

		/* CAPTURING EVENTS FOR WP_TERM AND WP_TERM_TAXONOMY TABLE */
		add_action('created_term', array($this, 'term_handler'), 10, 3);
		add_action('edited_term', array( $this, 'term_handler' ), 10, 3);
		add_action('edited_terms', array($this, 'edited_terms_handler'), 10, 2);
		add_action('delete_term', array($this, 'delete_term_handler'), 10, 4);
		add_action('edit_term_taxonomy', array($this, 'term_taxonomy_handler'), 10, 2);
		add_action('delete_term_taxonomy', array($this, 'term_taxonomy_handler'));
		add_action('edit_term_taxonomies', array($this, 'term_taxonomies_handler'));
		add_action('add_term_relationship', array($this, 'term_relationship_handler'), 10, 2);
		add_action('delete_term_relationships', array($this, 'term_relationships_handler'), 10, 2);
		add_action('set_object_terms', array($this, 'set_object_terms_handler'), 10, 3);

		add_action('switch_theme', array($this, 'theme_action_handler'));
		add_action('activate_plugin', array($this, 'plugin_action_handler'));
		add_action('deactivate_plugin', array($this, 'plugin_action_handler'));

		/* CAPTURING EVENTS FOR WP_OPTIONS */
		add_action('deleted_option', array($this, 'option_handler'));
		add_action('updated_option', array($this, 'option_handler'));
		add_action('added_option', array($this, 'option_handler'));

		/* CAPTURING EVENTS FOR FILES UPLOAD */
		add_action('wp_handle_upload', array($this, 'upload_handler'));

		if ($blogvault->isMultisite()) {
			add_action('wpmu_new_blog', array($this, 'wpmu_new_blog_create_handler'), 10, 1);
			add_action('refresh_blog_details', array($this, 'wpmu_new_blog_create_handler'), 10, 1);
			/* XNOTE: Handle registration_log_handler from within the server */
			/* These are applicable only in case of WPMU */
			add_action('delete_site_option',array($this, 'sitemeta_handler'), 10, 1);
			add_action('add_site_option', array($this, 'sitemeta_handler'), 10, 1);
			add_action('update_site_option', array($this, 'sitemeta_handler'), 10, 1);
		}

		$is_woo_dyn = $blogvault->getOption('bvWooDynSync');
		if ($is_woo_dyn == 'yes') {
			add_action('woocommerce_settings_start', array($this, 'woocommerce_settings_start_handler'));

			add_action('woocommerce_resume_order', array($this, 'woocommerce_resume_order_handler'), 10, 1);
			add_action('woocommerce_new_order_item', 	array($this, 'woocommerce_new_order_item_handler'), 10, 3);
			add_action('woocommerce_delete_order_item', array($this, 'woocommerce_delete_order_item_handler'), 10, 1);

			add_action('woocommerce_order_status_processing',array($this, 'woocommerce_downloadable_product_permissions_handler'), 10, 1);
			add_action('woocommerce_order_status_completed', array($this, 'woocommerce_downloadable_product_permissions_handler'), 10, 1);
			add_action('woocommerce_download_product', array($this, 'woocommerce_download_product_handler'), 10, 6);

			add_action('added_order_item_meta', array($this, 'woocommerce_order_itemmeta_insert_handler' ), 10, 2 );
			add_action('updated_order_item_meta', array($this, 'woocommerce_order_itemmeta_modification_handler'), 10, 4 );
			add_action('deleted_order_item_meta', array($this, 'woocommerce_order_itemmeta_modification_handler'), 10, 4 );

			add_action('added_woocommerce_term_meta', array($this, 'woocommerce_termmeta_insert_handler' ), 10, 2 );
			add_action('updated_woocommerce_term_meta', array($this, 'woocommerce_termmeta_modification_handler'), 10, 4 );
			add_action('deleted_woocommerce_term_meta', array($this, 'woocommerce_termmeta_modification_handler'), 10, 4 );

			add_action('woocommerce_attribute_added', array($this, 'woocommerce_attribute_added_handler' ), 10, 2 );
			add_action('woocommerce_attribute_updated', array($this, 'woocommerce_attribute_updated_handler'), 10, 3 );
			add_action('woocommerce_attribute_deleted', array($this, 'woocommerce_attribute_deleted_handler'), 10, 3 );

			add_action('wp_ajax_woocommerce_grant_access_to_download', array($this, 'woocommerce_grant_access_to_download_handler'));
			add_action('wp_ajax_woocommerce_revoke_access_to_download', array($this, 'woocommerce_revoke_access_to_download_handler'));
			add_action('wp_ajax_woocommerce_remove_order_item_meta', array($this, 'woocommerce_remove_order_item_meta_handler'));
			add_action('wp_ajax_woocommerce_calc_line_taxes', array($this, 'woocommerce_calc_line_taxes_handler'));
			add_action('wp_ajax_woocommerce_product_ordering', array($this, 'woocommerce_product_ordering_handler'));

			add_action('woocommerce_process_shop_coupon_meta', array($this, 'woocommerce_process_shop_coupon_meta_handler'), 10, 2);
			add_action('woocommerce_process_shop_order_meta', array($this, 'woocommerce_process_shop_order_meta_handler'), 10, 2);
			add_action('woocommerce_update_product_variation', array($this, 'woocommerce_update_product_variation_handler'), 10, 1);
			add_action('woocommerce_save_product_variation', array($this, 'woocommerce_save_product_variation_handler'), 10, 2);
			add_action('woocommerce_duplicate_product', array($this, 'woocommerce_duplicate_product_handler'), 10, 2);
			add_action('woocommerce_delete_order_items', array($this, 'woocommerce_delete_order_items_handler'), 10, 1);
			add_action('import_start', array($this, 'import_handler'));
			add_action('import_end', array($this, 'import_handler'));

			add_action('retrieve_password_key', array($this, 'retrieve_password_key_handler'), 10, 2);

			add_action('woocommerce_tax_rate_added', array($this, 'woocommerce_tax_rate_handler'), 10, 2);
			add_action('woocommerce_tax_rate_deleted', array($this, 'woocommerce_tax_rate_deleted_handler'), 10, 1);
			add_action('woocommerce_tax_rate_updated', array($this, 'woocommerce_tax_rate_handler'), 10, 2);
			
			add_action('woocommerce_grant_product_download_access', array($this, 'woocommerce_grant_product_download_access_handler'), 10, 1);
			add_action('woocommerce_ajax_revoke_access_to_product_download', array($this, 'woocommerce_ajax_revoke_access_to_product_download_handler'), 10, 3);
		}

		$this->add_bv_required_filters();
	}

	function add_bv_required_filters() {
		/* REPORT BACK TO BLOGVAULT FOR UPDATES */
		add_action('shutdown', array($this, 'send_updates'));
	}
}