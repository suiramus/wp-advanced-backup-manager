<?php
/**
 * Plugin Name: WP Advanced Backup Manager
 * Description: Advanced backup plugin cu control granular asupra componentelor. Alege exact ce dorești să incluzi în backup.
 * Version: 1.0.0
 * Author: Generic WP Developer
 * License: GPL-2.0-or-later
 * Text Domain: wpabm
 */

if (!defined('ABSPATH')) exit;

// Ensure WordPress functions are loaded
if (!function_exists('get_plugins')) {
	require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
if (!function_exists('get_mu_plugins')) {
	require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

// Define backup directory
$root_parent = untrailingslashit(dirname(ABSPATH));
define('WPABM_BACKUP_DIR', $root_parent . '/wp-backups');
define('WPABM_PLUGIN_DIR', plugin_dir_path(__FILE__));

/**
 * ENSURE BACKUP DIRECTORY
 */
function wpabm_ensure_backup_dir() {
	if (!file_exists(WPABM_BACKUP_DIR)) {
		@mkdir(WPABM_BACKUP_DIR, 0755, true);
	}
	if (file_exists(WPABM_BACKUP_DIR) && !is_writable(WPABM_BACKUP_DIR)) {
		@chmod(WPABM_BACKUP_DIR, 0755);
	}
	if (is_writable(WPABM_BACKUP_DIR)) {
		if (!file_exists(WPABM_BACKUP_DIR . '/.htaccess')) {
			@file_put_contents(WPABM_BACKUP_DIR . '/.htaccess', "deny from all");
		}
		if (!file_exists(WPABM_BACKUP_DIR . '/index.php')) {
			@file_put_contents(WPABM_BACKUP_DIR . '/index.php', '<?php // WP Advanced Backup');
		}
		return true;
	}
	return false;
}

/**
 * GET DIRECTORY SIZE
 */
function wpabm_get_dir_size($path) {
	$size = 0;
	if (!file_exists($path)) return 0;
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
		$size += $file->getSize();
	}
	return $size;
}

/**
 * GET BACKUP COMPONENTS - CORE COMPONENTS
 * Filter: wpabm_backup_components
 */

function wpabm_get_backup_components() {
	$components = array(
		'uploads' => array(
			'label' => 'Media (Uploads)',
			'path' => wp_upload_dir()['basedir'],
			'description' => 'Toate imaginile și media-urile încărcate',
			'abbrev' => 'UP'
		),
		'theme' => array(
			'label' => 'Tema Activă',
			'path' => get_stylesheet_directory(),
			'description' => 'Fișierele temei curente',
			'abbrev' => 'TM'
		),
		'database' => array(
			'label' => 'Baza de Date (SQL)',
			'path' => null,
			'description' => 'Export complet al bazei de date',
			'abbrev' => 'DB'
		),
		'wp_config' => array(
			'label' => 'wp-config.php',
			'path' => ABSPATH . 'wp-config.php',
			'description' => 'Fișierul de configurare WordPress',
			'abbrev' => 'CF'
		),
		'htaccess' => array(
			'label' => '.htaccess',
			'path' => ABSPATH . '.htaccess',
			'description' => 'Regulile de rewrite și securitate',
			'abbrev' => 'HT'
		),
		'mu_plugins' => array(
			'label' => 'Must-Use Plugins',
			'path' => WPMU_PLUGIN_DIR,
			'description' => 'Folderul mu-plugins',
			'abbrev' => 'MU'
		),
		'plugins' => array(
			'label' => 'Toate Plugin-urile',
			'path' => WP_PLUGIN_DIR,
			'description' => 'Folderul complet cu toate plugin-urile instalate',
			'abbrev' => 'ALLPLUG',
		),
		'wp_content' => array(
			'label' => 'Intreg wp-content',
			'path' => WP_CONTENT_DIR,
			'description' => 'Folderul complet wp-content',
			'abbrev' => 'ALLWPCONT',
		),
		'wxr_export' => array(
			'label' => 'Export WordPress (WXR)',
			'path' => null, // Special handling
			'description' => 'Export complet în format WordPress (WXR) - posturi, pagini, comentarii, metadate',
			'abbrev' => 'WXR',
		),
	);

	// Allow custom components via filter
	return apply_filters('wpabm_backup_components', $components);
}

/**
 * EXPORT DATABASE - SECURED
 */
function wpabm_export_db() {
	global $wpdb;
	$tables = $wpdb->get_results("SHOW TABLES", ARRAY_N);
	$sql = "-- WP Advanced Backup SQL\n-- Generated: " . date('Y-m-d H:i:s') . "\nSET FOREIGN_KEY_CHECKS=0;\n\n";
	
	foreach ($tables as $table) {
		$table_name = $table[0];
		
		$create = $wpdb->get_results($wpdb->prepare("SHOW CREATE TABLE %i", $table_name), ARRAY_N);
		if (empty($create)) continue;
		
		$sql .= "DROP TABLE IF EXISTS `" . esc_sql($table_name) . "`;\n" . $create[0][1] . ";\n\n";
		
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM %i", $table_name), ARRAY_A);
		if ($rows) {
			foreach ($rows as $row) {
				$vals = array_map(function($v) { 
					return is_null($v) ? "NULL" : "'" . esc_sql($v) . "'"; 
				}, $row);
				$sql .= "INSERT INTO `" . esc_sql($table_name) . "` VALUES (" . implode(", ", $vals) . ");\n";
			}
		}
		$sql .= "\n";
	}
	return $sql . "SET FOREIGN_KEY_CHECKS=1;";
}

/**
 * GENERATE WORDPRESS WXR EXPORT
 */
function wpabm_export_wxr() {
	// Require WordPress export functionality
	require_once(ABSPATH . 'wp-admin/includes/export.php');
	
	// Capture output
	ob_start();
	export_wp(array(
		'content' => 'all',
		'author' => 'all',
		'category' => 'all',
		'start_date' => '',
		'end_date' => '',
		'status' => 'all'
	));
	$wxr_content = ob_get_clean();
	
	return $wxr_content;
}

/**
 * CREATE ZIP ARCHIVE
 */
function wpabm_create_zip($zip_path, $sources, $options = array()) {
	if (!class_exists('ZipArchive')) {
		return "Eroare: ZipArchive inactiv.";
	}
	if (!wpabm_ensure_backup_dir()) {
		return "Eroare: Directorul destinație nu poate fi scris.";
	}

	@set_time_limit(0);
	$zip = new ZipArchive();
	$res = $zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	if ($res !== true) {
		return "Eroare Zip (Cod $res).";
	}

	$only_originals = !empty($options['only_originals']);
	$exclude = array('cache', 'node_modules', '.git', '.zip');

	foreach ($sources as $path => $local_name) {
		if (!file_exists($path)) continue;
		
		if (is_file($path)) { 
			$zip->addFile($path, $local_name); 
			continue; 
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ($files as $file) {
			$file_path = $file->getRealPath();
			if (!$file_path || !is_readable($file_path)) continue;
			$rel_path = $local_name . str_replace($path, '', $file_path);
			
			foreach ($exclude as $ex) { 
				if (strpos($file_path, $ex) !== false) continue 2; 
			}

			if ($file->isDir()) { 
				$zip->addEmptyDir($rel_path); 
			} else {
				if ($only_originals && strpos($file_path, 'uploads') !== false) {
					if (preg_match('/-\d+x\d+\.(jpg|jpeg|png|gif|webp)$/i', $file_path) && !strpos($file_path, '-scaled')) continue;
				}
				$zip->addFile($file_path, $rel_path);
			}
		}
	}

	// Add virtual files (metadata, etc)
	if (!empty($options['virtual_files'])) {
		foreach ($options['virtual_files'] as $name => $content) {
			// Don't sanitize special files like .htaccess, wp-config.php
			$safe_name = $name;
			$zip->addFromString($safe_name, $content);
		}
	}

	$closed = $zip->close();
	return ($closed && file_exists($zip_path)) ? true : "Eroare la scrierea ZIP.";
}

/**
 * HANDLE ADMIN REQUESTS
 */
add_action('admin_init', 'wpabm_handle_requests');
function wpabm_handle_requests() {
	if (!current_user_can('manage_options')) {
		return;
	}

	if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
		return;
	}

	// CREATE BACKUP
	if (!empty($_POST['wpabm_action'])) {
		if (!isset($_POST['wpabm_nonce']) || !wp_verify_nonce($_POST['wpabm_nonce'], 'wpabm_backup_action')) {
			wp_die('Eroare de securitate: Nonce invalid.');
		}

		// Get selected components
		$selected_components = array();
		$components = wpabm_get_backup_components();
		
		foreach ($components as $key => $component) {
			if (!empty($_POST['wpabm_component_' . $key])) {
				$selected_components[$key] = $component;
			}
		}

		// Validate at least one component selected
		if (empty($selected_components)) {
			set_transient('wpabm_error', 'Trebuie să selectezi cel puțin un component pentru backup!', 30);
			wp_redirect(admin_url('admin.php?page=wpabm&status=error'));
			exit;
		}

		// Build sources array
		$sources = array();
		$components_included = array();

		foreach ($selected_components as $key => $component) {
			if ($key === 'database') {
				continue; // Handle separately
			}
			if (file_exists($component['path'])) {
				// Use actual filename for special files, key for directories
				$local_name = is_file($component['path']) ? basename($component['path']) : $key;
				$sources[$component['path']] = $local_name;  // ✅ Acum corect!
				$components_included[] = $component['label'];
			}
		}

		// Prepare virtual files
		$notes = sanitize_textarea_field($_POST['wpabm_notes'] ?? '');
		$only_originals = !empty($_POST['wpabm_only_originals']);

		$virtual_files = array(
			'backup_info.txt' => wpabm_generate_backup_info($selected_components, $notes)
		);

		// Add database if selected
		if (!empty($selected_components['database'])) {
			$virtual_files['database.sql'] = wpabm_export_db();
			$components_included[] = 'Baza de Date';
		}
		
		// Add WXR export if selected - NEW
		if (!empty($selected_components['wxr_export'])) {
			$virtual_files['wordpress-export.xml'] = wpabm_export_wxr();
			$components_included[] = 'Export WXR';
		}

		// Create archive
		$component_suffix = wpabm_get_component_suffix($selected_components);
		
		/* $site_name = sanitize_file_name(get_bloginfo('name'));
		$backup_time = current_time('Y-m-d-Hi');
		$zip_file = WPABM_BACKUP_DIR . '/' . $site_name . '_' . $backup_time . $component_suffix . '.zip'; */
		$site_name = sanitize_file_name(get_bloginfo('name'));
		$backup_time = wpabm_format_local_time('Y-m-d_Hi');
		$zip_file = WPABM_BACKUP_DIR . '/' . $site_name . '_' . $backup_time . $component_suffix . '.zip';

		$result = wpabm_create_zip($zip_file, $sources, array(
			'only_originals' => $only_originals,
			'virtual_files' => $virtual_files
		));

		if ($result === true) {
			wp_redirect(admin_url('admin.php?page=wpabm&status=success'));
		} else {
			set_transient('wpabm_error', $result, 30);
			wp_redirect(admin_url('admin.php?page=wpabm&status=error'));
		}
		exit;
	}

	// BULK DELETE
	if (!empty($_POST['wpabm_bulk_action'])) {
		if (!isset($_POST['wpabm_bulk_nonce']) || !wp_verify_nonce($_POST['wpabm_bulk_nonce'], 'wpabm_bulk_action')) {
			wp_die('Eroare de securitate: Nonce invalid.');
		}

		$bulk_action = sanitize_text_field($_POST['wpabm_bulk_action']);
		$selected_files = isset($_POST['selected_files']) ? (array)$_POST['selected_files'] : array();

		if ($bulk_action === 'delete' && !empty($selected_files)) {
			foreach ($selected_files as $file_name) {
				$file_name = basename($file_name);
				$file = WPABM_BACKUP_DIR . '/' . $file_name;
				
				$real_path = realpath($file);
				$real_backup_dir = realpath(WPABM_BACKUP_DIR);
				
				if ($real_path && $real_backup_dir && file_exists($file) && strpos($real_path, $real_backup_dir) === 0) {
					@unlink($file);
				}
			}
			wp_redirect(admin_url('admin.php?page=wpabm&status=bulk_deleted'));
			exit;
		}
	}

	// CSV EXPORT
	if (!empty($_GET['export_csv'])) {
		if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wpabm_export_csv')) {
			wp_die('Eroare de securitate: Nonce invalid.');
		}

		$files = glob(WPABM_BACKUP_DIR . '/*.zip');
		if ($files) {
			$files_with_time = array();
			foreach ($files as $file) {
				$files_with_time[$file] = filemtime($file);
			}
			arsort($files_with_time);

			header('Content-Type: text/csv; charset=utf-8');
			header('Content-Disposition: attachment; filename="backups-' . date('Y-m-d_Hi') . '.csv"');
			
			$output = fopen('php://output', 'w');
			fputcsv($output, array('Nume', 'Data', 'Dimensiune', 'Componente'));

			foreach ($files_with_time as $f => $mtime) {
				$n = basename($f);
				$d = date("d.m.Y H:i", $mtime);
				$s = size_format(filesize($f));
				$components = wpabm_extract_components_from_filename($n);
				
				fputcsv($output, array($n, $d, $s, $components));
			}
			fclose($output);
			exit;
		}
	}

	// DOWNLOAD FILE
	if (!empty($_GET['wpabm_file'])) {
		$file_name = basename($_GET['wpabm_file']);
		$file = WPABM_BACKUP_DIR . '/' . $file_name;
		
		$real_path = realpath($file);
		$real_backup_dir = realpath(WPABM_BACKUP_DIR);
		
		if ($real_path && $real_backup_dir && file_exists($file) && strpos($real_path, $real_backup_dir) === 0) {
			if (isset($_GET['wpabm_do']) && $_GET['wpabm_do'] === 'download') {
				header('Content-Type: application/zip');
				header('Content-Disposition: attachment; filename="' . sanitize_file_name($file_name) . '"');
				header('Content-Length: ' . filesize($file));
				header('X-Content-Type-Options: nosniff');
				header('X-Frame-Options: DENY');
				header('Pragma: public');
				header('Cache-Control: public, must-revalidate');
				
				readfile($file);
				exit;
			} elseif (isset($_GET['wpabm_do']) && $_GET['wpabm_do'] === 'delete') {
				@unlink($file);
				wp_redirect(admin_url('admin.php?page=wpabm&status=deleted'));
				exit;
			}
		} else {
			wp_die('Fișierul nu a fost găsit sau calea este invalidă.');
		}
	}
}

/**
 * GENERATE BACKUP INFO
 */
/**
 * GENERATE BACKUP INFO - MODULAR SECTIONS
 */
function wpabm_generate_backup_info($selected_components, $notes) {
	$info = "WP ADVANCED BACKUP INFORMATION\n";
	$info .= "===============================\n\n";
	
	// System Info Section
	$info .= wpabm_backup_info_system();
	
	// Components Section
	$info .= wpabm_backup_info_components($selected_components);
	
	// Plugins Section
	$info .= wpabm_backup_info_plugins();
	
	// Notes Section
	if (!empty($notes)) {
		$info .= "\nNOTE:\n";
		$info .= "------\n";
		$info .= $notes . "\n";
	}
	
	return $info;
}

/**
 * SYSTEM INFO SECTION
 */
function wpabm_backup_info_system() {
	global $wpdb;
	
	// Get theme data
	$theme = wp_get_theme();
	
	// Get server software
	$server_software = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : 'Unknown';
	
	// Get memory and upload limits
	$memory_limit = WP_MEMORY_LIMIT;
	$max_upload = size_format(wp_max_upload_size());
	$post_max_size = ini_get('post_max_size');
	
	// Database info
	$db_name = DB_NAME;
	$db_host = DB_HOST;
	$db_charset = $wpdb->charset;
	$db_collate = $wpdb->collate;
	
	// MySQL version
	$mysql_version = $wpdb->db_version();
	
	// Multisite
	$is_multisite = is_multisite() ? 'Yes' : 'No';
	
	// Disk free
	$disk_free = size_format(disk_free_space(ABSPATH));
	
	// Count posts & users
	$total_posts = wp_count_posts();
	$total_users = count_users();
	$total_posts_count = (int)$total_posts->publish + (int)$total_posts->draft + (int)$total_posts->private;
	$total_users_count = $total_users['total_users'] ?? 0;
	
	// Build info
	$info = "INFORMAȚII SISTEM\n";
	$info .= "-------------------\n\n";
	
	// Backup metadata
	$info .= "Backup Metadata:\n";
	// $info .= "  Data: " . current_time('d.m.Y H:i:s') . "\n\n";
	$info .= "  Data: " . wpabm_format_local_time('d.m.Y H:i:s') . "\n";
	$timezone = get_option('timezone_string') ?: ('UTC' . get_option('gmt_offset'));
	$info .= "  Timezone: " . $timezone . "\n\n";
	
	// WordPress Info
	$info .= "WordPress:\n";
	$info .= "  Version: " . get_bloginfo('version') . "\n";
	$info .= "  Language: " . get_bloginfo('language') . "\n";
	$info .= "  Site Name: " . get_bloginfo('name') . "\n";
	$info .= "  Site URL (WordPress Address): " . get_option('siteurl') . "\n";
	$info .= "  Home URL (Site Address): " . get_option('home') . "\n";
	$info .= "  Multisite: " . $is_multisite . "\n";
	$info .= "  Active Theme: " . $theme->get('Name') . " (" . $theme->get('Version') . ")\n";
	$info .= "  Theme Author: " . $theme->get('Author') . "\n";
	$info .= "  WP Install Path: " . ABSPATH . "\n";
	$info .= "  WP Content Path: " . WP_CONTENT_DIR . "\n\n";
	
	// Server Info
	$info .= "Server:\n";
	$info .= "  PHP Version: " . PHP_VERSION . "\n";
	$info .= "  MySQL Version: " . $mysql_version . "\n";
	$info .= "  Server Software: " . $server_software . "\n";
	$info .= "  Memory Limit: " . $memory_limit . "\n";
	$info .= "  Post Max Size: " . $post_max_size . "\n";
	$info .= "  Max Upload Size: " . $max_upload . "\n";
	$info .= "  Disk Free Space: " . $disk_free . "\n\n";
	
	// Database Info
	$info .= "Database:\n";
	$info .= "  Name: " . $db_name . "\n";
	$info .= "  Host: " . $db_host . "\n";
	$info .= "  Charset: " . $db_charset . "\n";
	$info .= "  Collate: " . $db_collate . "\n\n";
	
	// Content Stats
	$info .= "Content Statistics:\n";
	$info .= "  Total Posts: " . $total_posts_count . "\n";
	$info .= "  Total Users: " . $total_users_count . "\n\n";
	
	return $info;
}

/**
 * COMPONENTS SECTION - WITH INCLUDED & EXCLUDED
 */
function wpabm_backup_info_components($selected_components) {
	$all_components = wpabm_get_backup_components();
	
	$info = "COMPONENTE INCLUSE:\n";
	$info .= "-------------------\n";
	foreach ($selected_components as $key => $component) {
		$info .= "✓ " . $component['label'] . "\n";
	}
	
	// Excluded components
	$excluded = array_diff_key($all_components, $selected_components);
	if (!empty($excluded)) {
		$info .= "\nCOMPONENTE NEINCLUSE:\n";
		$info .= "---------------------\n";
		foreach ($excluded as $key => $component) {
			$info .= "✗ " . $component['label'] . "\n";
		}
	}
	
	$info .= "\n";
	return $info;
}

/**
 * PLUGINS SECTION - ACTIVE, INACTIVE, MU
 */
function wpabm_backup_info_plugins() {
	$info = "PLUGINURI:\n";
	$info .= "----------\n";
	
	// Active Plugins
	$active_plugins = get_option('active_plugins', array());
	if (!empty($active_plugins)) {
		$info .= "\nActive Plugins:\n";
		foreach ($active_plugins as $plugin_file) {
			$plugin_data = wpabm_get_plugin_data($plugin_file);
			$info .= "  - " . $plugin_data['name'];
			if (!empty($plugin_data['version'])) {
				$info .= " (v" . $plugin_data['version'] . ")";
			}
			if (!empty($plugin_data['author'])) {
				$info .= " by " . $plugin_data['author'];
			}
			if (!empty($plugin_data['url'])) {
				$info .= " - " . $plugin_data['url'];
			}
			$info .= "\n";
		}
	} else {
		$info .= "\nActive Plugins:\n";
		$info .= "  (None)\n";
	}
	
	// Inactive Plugins
	if (function_exists('get_plugins')) {
		$all_plugins = get_plugins();
		$inactive_plugins = array_diff_key($all_plugins, array_flip($active_plugins));
		if (!empty($inactive_plugins)) {
			$info .= "\nInactive Plugins:\n";
			foreach ($inactive_plugins as $plugin_file => $plugin_data) {
				$author = wp_strip_all_tags($plugin_data['Author'] ?? '');
				$info .= "  - " . $plugin_data['Name'];
				if (!empty($plugin_data['Version'])) {
					$info .= " (v" . $plugin_data['Version'] . ")";
				}
				if (!empty($author)) {
					$info .= " by " . $author;
				}
				if (!empty($plugin_data['PluginURI'])) {
					$info .= " - " . $plugin_data['PluginURI'];
				}
				$info .= "\n";
			}
		} else {
			$info .= "\nInactive Plugins:\n";
			$info .= "  (None)\n";
		}
	}
	
	// Must-Use Plugins
	if (function_exists('get_mu_plugins')) {
		$mu_plugins = get_mu_plugins();
		$info .= "\nMust-Use Plugins:\n";
		if (!empty($mu_plugins)) {
			foreach ($mu_plugins as $plugin_file => $plugin_data) {
				$author = wp_strip_all_tags($plugin_data['Author'] ?? '');
				$info .= "  - " . $plugin_data['Name'];
				if (!empty($plugin_data['Version'])) {
					$info .= " (v" . $plugin_data['Version'] . ")";
				}
				if (!empty($author)) {
					$info .= " by " . $author;
				}
				if (!empty($plugin_data['PluginURI'])) {
					$info .= " - " . $plugin_data['PluginURI'];
				}
				$info .= "\n";
			}
		} else {
			$info .= "  (None)\n";
		}
	}
	
	$info .= "\n";
	return $info;
}

/**
 * GET PLUGIN DATA HELPER
 */
function wpabm_get_plugin_data($plugin_file) {
	$plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
	
	// Clean author from HTML tags
	$author = $plugin_data['Author'] ?? '';
	$author = wp_strip_all_tags($author);
	
	return array(
		'name' => $plugin_data['Name'] ?? 'Unknown',
		'version' => $plugin_data['Version'] ?? '',
		'author' => $author,
		'url' => $plugin_data['PluginURI'] ?? ''
	);
}

/**
 * GET COMPONENT SUFFIX FOR FILENAME
 */
function wpabm_get_component_suffix($selected_components) {
	$abbrev = array();
	
	foreach ($selected_components as $key => $component) {
		if (!empty($component['abbrev'])) {
			$abbrev[] = $component['abbrev'];
		}
	}

	return !empty($abbrev) ? '_[' . implode('-', $abbrev) . ']' : '';
}

/**
 * EXTRACT COMPONENTS FROM FILENAME
 */
function wpabm_extract_components_from_filename($filename) {
	if (preg_match('/\[(.*?)\]/', $filename, $matches)) {
		return $matches[1];
	}
	return 'Mixed';
}

/**
 * REGISTER ADMIN MENU
 */
add_action('admin_menu', 'wpabm_register_menu');
function wpabm_register_menu() {
	add_menu_page(
		'WP Advanced Backup Manager',
		'WP Backups',
		'manage_options',
		'wpabm',
		'wpabm_render_page',
		'dashicons-archive',
		80
	);
}

/**
 * RENDER ADMIN PAGE
 */
function wpabm_render_page() {
	if (!current_user_can('manage_options')) {
		wp_die('Acces negat.');
	}

	$dir_size = wpabm_get_dir_size(WPABM_BACKUP_DIR);
	$limit = 500 * 1024 * 1024;
	$limit_formatted = size_format($limit);
	
	// Status messages
	if (isset($_GET['status'])) {
		$status = sanitize_text_field($_GET['status']);
		
		if ($status === 'success') {
			$msg = 'Backup creat cu succes!';
			$class = 'updated';
		} elseif ($status === 'deleted') {
			$msg = 'Backup șters cu succes!';
			$class = 'updated';
		} elseif ($status === 'bulk_deleted') {
			$msg = 'Backup-urile selectate au fost șterse cu succes!';
			$class = 'updated';
		} else {
			$msg = get_transient('wpabm_error') ?: 'A apărut o eroare.';
			$class = 'error';
			delete_transient('wpabm_error');
		}
		echo "<div class='" . esc_attr($class) . "'><p>" . esc_html($msg) . "</p></div>";
	}

	if ($dir_size > $limit) {
		echo '<div class="notice notice-warning"><p><strong>Atenție:</strong> Folderul de backup ocupă ' . esc_html(size_format($dir_size)) . '. Șterge backup-uri vechi.</p></div>';
	}
	?>
	<div class="wrap">
		<h1>WP Advanced Backup Manager</h1>
		<p>Calea: <code><?php echo esc_html(WPABM_BACKUP_DIR); ?></code></p>
		<p>Total utilizat: <strong><?php echo esc_html(size_format($dir_size)); ?></strong> / <?php echo esc_html($limit_formatted); ?></p>

		<div style="background:#fff; padding:20px; border:1px solid #ddd; border-radius:4px; margin-top:20px; margin-bottom:20px;">
			<h2>Crează Backup Personalizat</h2>
			<form method="post">
				<?php wp_nonce_field('wpabm_backup_action', 'wpabm_nonce'); ?>
				<input type="hidden" name="wpabm_action" value="create_backup">

				<h3>Selectează Componente</h3>
				<p><em>Bifează ce dorești să incluzi în backup</em></p>

				<div style="background:#f9f9f9; padding:15px; border-left:4px solid #0073aa; margin-bottom:20px;">
					<div style="margin-bottom:15px; padding-bottom:15px; border-bottom:1px solid #ddd;">
						<button type="button" class="button" id="toggle-all-components">✗ Debifează Tot</button>
					</div>

					<?php
					$components = wpabm_get_backup_components();
					foreach ($components as $key => $component) {
						echo '<label style="display:block; margin-bottom:10px; cursor:pointer;">';
						echo '<input type="checkbox" class="component-checkbox" name="wpabm_component_' . esc_attr($key) . '" value="1" checked>';
						echo ' <strong>' . esc_html($component['label']) . '</strong>';
						echo ' <span style="color:#666; font-size:11px;">- ' . esc_html($component['description']) . '</span>';
						echo '</label>';
					}
					?>
				</div>

				<script>
					(function() {
						const toggleBtn = document.getElementById('toggle-all-components');
						const checkboxes = document.querySelectorAll('.component-checkbox');
		
						if (!toggleBtn || !checkboxes.length) return;
		
						function updateButtonText() {
							const allChecked = Array.from(checkboxes).every(function(c) { 
								return c.checked; 
							});
							toggleBtn.textContent = allChecked ? '✗ Debifează Tot' : '✓ Bifează Tot';
						}
		
						toggleBtn.addEventListener('click', function(e) {
							e.preventDefault();
							const allChecked = Array.from(checkboxes).every(function(c) { 
								return c.checked; 
							});
							checkboxes.forEach(function(c) { 
								c.checked = !allChecked; 
							});
							updateButtonText();
						});
		
						checkboxes.forEach(function(c) {
							c.addEventListener('change', updateButtonText);
						});
		
						updateButtonText();
					})();
				</script>

				<h3>Opțiuni Suplimentare</h3>
				<label style="display:block; margin-bottom:15px;">
					<input type="checkbox" name="wpabm_only_originals" value="1" checked>
					<strong>Smart Media (exclude thumbnails)</strong>
					<span style="color:#666; font-size:11px;"> - reduce dimensiunea backup-ului</span>
				</label>

				<h3>Note (Opțional)</h3>
				<textarea name="wpabm_notes" style="width:100%; height:80px; box-sizing:border-box;" placeholder="Adaugă note despre acest backup..."></textarea>

				<p style="margin-top:20px;">
					<button type="submit" class="button button-primary button-large">🚀 Crează Backup</button>
				</p>
			</form>
		</div>

		<h2>Backup-uri Existente</h2>
		<?php wpabm_render_backups_table(); ?>
	</div>
	<?php
}

/**
 * RENDER BACKUPS TABLE
 */
function wpabm_render_backups_table() {
	$files = glob(WPABM_BACKUP_DIR . '/*.zip');
	
	if (!$files) {
		echo '<p><em>Nicio backup găsit.</em></p>';
		return;
	}

	// Pagination
	$per_page = 10;
	$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
	$sort = isset($_GET['sort']) ? sanitize_text_field($_GET['sort']) : 'date';
	$order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'desc';

	// Prepare files
	$files_with_time = array();
	foreach ($files as $file) {
		$files_with_time[$file] = filemtime($file);
	}

	// Sort
	if ($sort === 'size') {
		$files_with_size = array();
		foreach ($files_with_time as $file => $mtime) {
			$files_with_size[$file] = filesize($file);
		}
		if ($order === 'asc') {
			asort($files_with_size);
		} else {
			arsort($files_with_size);
		}
		$files_with_time = array_combine(array_keys($files_with_size), array_values(array_map(function($f) use ($files_with_time) { return $files_with_time[$f]; }, array_keys($files_with_size))));
	} else {
		if ($order === 'asc') {
			asort($files_with_time);
		} else {
			arsort($files_with_time);
		}
	}

	// Paginate
	$offset = ($page - 1) * $per_page;
	$files_paginated = array_slice($files_with_time, $offset, $per_page, true);
	$total_pages = ceil(count($files_with_time) / $per_page);

	// Toolbar
	echo '<div class="tablenav top">';
	echo '<div class="alignleft actions">';
	
	// Bulk form
	echo '<form method="post">';
	wp_nonce_field('wpabm_bulk_action', 'wpabm_bulk_nonce');
	
	echo '<select name="wpabm_bulk_action" style="margin-right:10px;">';
	echo '<option value="">- Alege acțiune -</option>';
	echo '<option value="delete">Șterge selectate</option>';
	echo '</select>';
	echo '<button type="submit" class="button">Aplică</button>';
	
	// CSV export
	$csv_nonce = wp_create_nonce('wpabm_export_csv');
	$csv_url = admin_url("admin.php?page=wpabm&export_csv=1&_wpnonce=$csv_nonce");
	echo ' <a href="' . esc_url($csv_url) . '" class="button" style="margin-left:10px;">📥 Export CSV</a>';
	
	echo '</div>';
	echo '</div>';

	// Table
	echo '<table class="wp-list-table widefat fixed striped">';
	echo '<thead><tr>';
	echo '<th style="width:50px;"><input type="checkbox" id="select-all" onchange="document.querySelectorAll(\'.file-checkbox\').forEach(c => c.checked = this.checked)"></th>';
	echo '<th>Nume Fișier</th>';
	
	// Date sort
	$date_order = ($sort === 'date' && $order === 'desc') ? 'asc' : 'desc';
	$date_link = admin_url("admin.php?page=wpabm&sort=date&order=$date_order");
	echo '<th><a href="' . esc_url($date_link) . '">Data</a>';
	if ($sort === 'date') echo ' ' . ($order === 'asc' ? '↑' : '↓');
	echo '</th>';
	
	// Size sort
	$size_order = ($sort === 'size' && $order === 'desc') ? 'asc' : 'desc';
	$size_link = admin_url("admin.php?page=wpabm&sort=size&order=$size_order");
	echo '<th><a href="' . esc_url($size_link) . '">Dimensiune</a>';
	if ($sort === 'size') echo ' ' . ($order === 'asc' ? '↑' : '↓');
	echo '</th>';
	
	echo '<th>Componente</th>';
	echo '<th style="text-align:right;">Acțiuni</th>';
	echo '</tr></thead>';

	echo '<tbody>';
	foreach ($files_paginated as $f => $mtime) {
		$n = basename($f);
		// $d = date("d.m.Y H:i", $mtime);
		$gmt_offset = get_option('gmt_offset') * 3600;
		$local_time = $mtime + $gmt_offset;
		$d = date("d.m.Y H:i", $local_time);
		
		$s = size_format(filesize($f));
		$components = wpabm_extract_components_from_filename($n);
		
		$dl = admin_url("admin.php?page=wpabm&wpabm_do=download&wpabm_file=" . urlencode($n));
		$del = admin_url("admin.php?page=wpabm&wpabm_do=delete&wpabm_file=" . urlencode($n));
		
		echo '<tr>';
		echo '<td><input type="checkbox" class="file-checkbox" name="selected_files[]" value="' . esc_attr($n) . '"></td>';
		echo '<td><strong>🔒 ' . esc_html($n) . '</strong></td>';
		echo '<td>' . esc_html($d) . '</td>';
		echo '<td>' . esc_html($s) . '</td>';
		echo '<td><code style="background:#f5f5f5; padding:3px 6px; border-radius:3px; font-size:11px;">' . esc_html($components) . '</code></td>';
		echo '<td style="text-align:right;">';
		echo '<a href="' . esc_url($dl) . '" class="button">⬇️ Download</a> ';
		echo '<a href="' . esc_url($del) . '" class="button" style="color:#d63638; border-color:#d63638;" onclick="return confirm(\'Ștergi definitiv?\')">🗑️ Șterge</a>';
		echo '</td>';
		echo '</tr>';
	}
	echo '</tbody>';
	echo '</table>';

	// Close form
	echo '</form>';

	// Pagination
	if ($total_pages > 1) {
		echo '<div class="tablenav bottom">';
		echo '<div class="tablenav-pages" style="margin:0;">';
		echo '<span class="displaying-num">' . count($files_with_time) . ' backup-uri total</span>';
		
		echo '<span class="pagination-links">';
		
		// Previous
		if ($page > 1) {
			$prev_link = admin_url("admin.php?page=wpabm&paged=" . ($page - 1) . "&sort=$sort&order=$order");
			echo '<a class="prev-page button" href="' . esc_url($prev_link) . '">← Anterior</a> ';
		} else {
			echo '<span class="prev-page button disabled">← Anterior</span> ';
		}
		
		// Pages
		for ($i = 1; $i <= $total_pages; $i++) {
			if ($i == $page) {
				echo '<span class="page-numbers current">' . esc_html($i) . '</span>';
			} else {
				$page_link = admin_url("admin.php?page=wpabm&paged=$i&sort=$sort&order=$order");
				echo '<a class="page-numbers" href="' . esc_url($page_link) . '">' . esc_html($i) . '</a>';
			}
		}
		
		// Next
		if ($page < $total_pages) {
			$next_link = admin_url("admin.php?page=wpabm&paged=" . ($page + 1) . "&sort=$sort&order=$order");
			echo ' <a class="next-page button" href="' . esc_url($next_link) . '">Următor →</a>';
		} else {
			echo ' <span class="next-page button disabled">Următor →</span>';
		}
		
		echo '</span>';
		echo '</div>';
		echo '</div>';
	}
}


/**
 * GET LOCAL TIME FROM WORDPRESS SETTINGS
 */
function wpabm_get_local_time() {
	$gmt_offset = get_option('gmt_offset') * 3600;
	$local_time = time() + $gmt_offset;
	return $local_time;
}

/**
 * FORMAT LOCAL TIME
 */
function wpabm_format_local_time($format = 'd.m.Y H:i:s') {
	return date($format, wpabm_get_local_time());
}
