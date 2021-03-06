<?php
/**
Plugin Name: WP S3
Plugin URI: https://github.com/parleer/wp-s3
Description: Plugin for running your Wordpress site without Write Access to the web directory. Amazon S3 is used for uploads/binary storage. This plugin was made with cluster/load balancing server setups in mind - where you do not want your WordPress to write anything to the local web directory.
Version: 1.2
Author: parleer
Author URI: https://github.com/parleer
License: GPLv2
 */

// define('WPRO_DEBUG', true);

// PHP < 5.2.1 compatibility
if ( !function_exists('sys_get_temp_dir')) {
	function sys_get_temp_dir() {
		if( $temp = getenv('TMP') ) return $temp;
		if( $temp = getenv('TEMP') ) return $temp;
		if( $temp = getenv('TMPDIR') ) return $temp;
		$temp = tempnam(__FILE__, '');
		if (file_exists($temp)) {
			unlink($temp);
			return dirname($temp);
		}
		return null;
	}
}

// WP < 3.5 compatibility
if ( !function_exists('is_main_network')) {
	function is_main_network() {
		return true;
	}
}

// WP < 3.2 compatibility
if ( !function_exists('ms_is_switched')) {
	define('USEBLOGSDIR', true);
	function ms_is_switched() {
		return true;
	}
}

// open_basedir / safe_mode disallows CURLOPT_FOLLOWLOCATION
function curl_exec_follow($ch, &$maxredirect = null) { 
    $mr = $maxredirect === null ? 5 : intval($maxredirect); 
    if (ini_get('open_basedir') == '' && ini_get('safe_mode' == 'Off')) { 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0); 
        curl_setopt($ch, CURLOPT_MAXREDIRS, $mr); 
    } else { 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); 
        if ($mr > 0) { 
            $newurl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL); 

            $rch = curl_copy_handle($ch); 
            curl_setopt($rch, CURLOPT_HEADER, true); 
            curl_setopt($rch, CURLOPT_NOBODY, true); 
            curl_setopt($rch, CURLOPT_FORBID_REUSE, false); 
            curl_setopt($rch, CURLOPT_RETURNTRANSFER, true); 
            do { 
                curl_setopt($rch, CURLOPT_URL, $newurl); 
                $header = curl_exec($rch); 
                if (curl_errno($rch)) { 
                    $code = 0; 
                } else { 
                    $code = curl_getinfo($rch, CURLINFO_HTTP_CODE); 
                    if ($code == 301 || $code == 302) { 
                        preg_match('/Location:(.*?)\n/', $header, $matches); 
                        $newurl = trim(array_pop($matches)); 
                    } else { 
                        $code = 0; 
                    } 
                } 
            } while ($code && --$mr); 
            curl_close($rch); 
            if (!$mr) { 
                if ($maxredirect === null) { 
                    trigger_error('Too many redirects. When following redirects, libcurl hit the maximum amount.', E_USER_WARNING); 
                } else { 
                    $maxredirect = 0; 
                } 
                return false; 
            } 
            curl_setopt($ch, CURLOPT_URL, $newurl); 
        } 
    } 
    return curl_exec($ch); 
}

function wpro_get_option($option, $default = false) {
	if (!defined('WPRO_ON') || !WPRO_ON) {
		return get_site_option($option, $default);
	}
	$constantName = strtoupper(str_replace('-', '_', $option));
	if (defined($constantName)) {
		return constant($constantName);
	} else {
		return $default;
	}
}

new WordpressReadOnly;

/* * * * * * * * * * * * * * * * * * * * * * *
  GENERIC FUNCTION FOR NORMALIZING URLS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnlyGeneric {

	public $temporaryLocalData = array();

	function debug($msg) {
		if (defined('WPRO_DEBUG') && WPRO_DEBUG) {
			$fh = fopen('/tmp/wpro-debug', 'a');
			fwrite($fh, trim($msg) . "\n");
			fclose($fh);
		}
	}

	function removeTemporaryLocalData($file) {
		$this->debug('WordpressReadOnlyGeneric::removeTemporaryLocalData("' . $file . '");');
		$this->temporaryLocalData[] = $file;
	}

	function url_normalizer($url) {
		if (strpos($url, '%') !== false) return $url;
		$url = explode('/', $url);
		foreach ($url as $key => $val) $url[$key] = urlencode($val);
		return str_replace('%3A', ':', join('/', $url));
	}

}

/* * * * * * * * * * * * * * * * * * * * * * *
  BACKENDS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnlyBackend extends WordpressReadOnlyGeneric {

	function upload($localpath, $url, $mimetype) {
		return false;
	}

	function file_exists($path) {

		$this->debug('WordpressReadOnlyBackend::file_exists("' . $path . '");');

		$path = $this->url_normalizer($path);

		$this->debug('-> testing url: ' . $path);

		// If at this point, the testing url is not a full http url,
		// then there is something wrong in the wp_upload_dir functionality,
		// because of write permission errors to the system tmp or any thing else.

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_NOBODY, 1);
		curl_setopt($ch, CURLOPT_URL, $path);
		$result = trim(curl_exec_follow($ch));

		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$this->debug('-> http return code: ' . $httpCode);

		if ($httpCode != 200) return false;

		return true;
	}

}

class WordpressReadOnlyFTP extends WordpressReadOnlyBackend {

	function __construct() {
	}

	function upload($file, $fullurl, $mime) {
		return false;
	}

}

class WordpressReadOnlyS3 extends WordpressReadOnlyBackend {

	public $key;
	public $secret;
	public $bucket;
	public $endpoint;

	function __construct() {
		$this->key = wpro_get_option('wpro-aws-key');
		$this->secret = wpro_get_option('wpro-aws-secret');
		$this->bucket = wpro_get_option('wpro-aws-bucket');
		$this->endpoint = wpro_get_option('wpro-aws-endpoint');
	}

	function upload($file, $fullurl, $mime) {
		$this->debug('WordpressReadOnlyS3::upload("' . $file . '", "' . $fullurl . '", "' . $mime . '");');
		$fullurl = $this->url_normalizer($fullurl);
		if (!preg_match('/^\/\/([^\/]+)\/(.*)$/', $fullurl, $regs)) return false;
		$url = $regs[2];

		if (preg_match('/^' . str_replace('.', '\\.', str_replace('/', '\/', trim(str_replace('//', '/', wpro_get_option('wpro-aws-bucket')), '/'))) . '\/(.*)$/', $url, $regs)) {
				$url = $regs[1];
		}
		
		if (!file_exists($file)) return false;
		$this->removeTemporaryLocalData($file);

		$fin = fopen($file, 'r');
		if (!$fin) return false;

		$fout = fsockopen($this->endpoint, 80, $errno, $errstr, 30);
		if (!$fout) return false;
		$datetime = gmdate('r');
		$string2sign = "PUT\n\n" . $mime . "\n" . $datetime . "\nx-amz-acl:public-read\n/" . $this->bucket . "/" . $url;

		$this->debug('STRING TO SIGN:');
		$this->debug($string2sign);
		$debug = '';
		for ($i = 0; $i < strlen($string2sign); $i++) $debug .= dechex(ord(substr($string2sign, $i, 1))) . ' ';
		$this->debug($debug);

		// Todo: Make this work with php cURL instead of fsockopen/etc..

		$query = "PUT /" . $this->bucket . "/" . $url . " HTTP/1.1\n";
		$query .= "Host: " . $this->endpoint . "\n";
		$query .= "x-amz-acl: public-read\n";
		$query .= "Cache-Control: max-age=86400\n";
		$query .= "Connection: keep-alive\n";
		$query .= "Content-Type: " . $mime . "\n";
		$query .= "Content-Length: " . filesize($file) . "\n";
		$query .= "Date: " . $datetime . "\n";
		$query .= "Authorization: AWS " . $this->key . ":" . $this->amazon_hmac($string2sign) . "\n\n";

		$this->debug('SEND:');
		$this->debug($query);

		fwrite($fout, $query);
		while (feof($fin) === false) fwrite($fout, fread($fin, 8192));
		fclose($fin);

		// Get the amazon response:
		$this->debug('RECEIVE:');
		$response = '';
		while (!feof($fout)) {
			$data = fgets($fout, 256);
			$this->debug($data);
			$response .= $data;
			if (strpos($response, "\r\n\r\n") !== false) { // Header fully returned.
				$this->debug('ALL RESPONSE HEADERS RECEIVED.');
				if (strpos($response, 'Content-Length: 0') !== false) break; // Return if Content-Length: 0 (and header is fully returned)
				if (substr($response, -7) == "\r\n0\r\n\r\n") break; // Keep-alive responses does not return EOF, they end with this string.
			}
		}
	
		fclose($fout);


		if (strpos($response, '<Error>') !== false) return false;

		return true;
	}

	function amazon_hmac($string) {
		return base64_encode(extension_loaded('hash') ?
		hash_hmac('sha1', $string, $this->secret, true) : pack('H*', sha1(
		(str_pad($this->secret, 64, chr(0x00)) ^ (str_repeat(chr(0x5c), 64))) .
		pack('H*', sha1((str_pad($this->secret, 64, chr(0x00)) ^
		(str_repeat(chr(0x36), 64))) . $string)))));
	}

}

/* * * * * * * * * * * * * * * * * * * * * * *
  THE MAIN PLUGIN CLASS:
* * * * * * * * * * * * * * * * * * * * * * */

class WordpressReadOnly extends WordpressReadOnlyGeneric {

	public $backend = null;
	public $tempdir = '/tmp';

	function __construct() {
		if (!defined('WPRO_ON') || !WPRO_ON) {
			add_action('admin_init', array($this, 'admin_init')); // Register the settings.
			// if ($this->is_trusted()) { // To early to find out, however in admin_init hook, it seems(?) to be too late to add network_admin_menu (which is weird, but i don't get it working there.)
				if (is_multisite()) {
					add_action('network_admin_menu', array($this, 'network_admin_menu'));
				} else {
					add_action('admin_menu', array($this, 'admin_menu')); // Will add the settings menu.
				}
				add_action('admin_post_wpro_settings_POST', array($this, 'admin_post')); // Gets called from plugin admin page POST request.
			// }
		}
		add_filter('wp_handle_upload', array($this, 'handle_upload')); // The very filter that takes care of uploads.
		add_filter('upload_dir', array($this, 'upload_dir')); // Sets the paths and urls for uploads.
		add_filter('wp_generate_attachment_metadata', array($this, 'generate_attachment_metadata')); // We use this filter to store resized versions of the images.
		add_filter('wp_update_attachment_metadata', array($this, 'update_attachment_metadata')); // We use this filter to store resized versions of the images.
		add_filter('load_image_to_edit_path', array($this, 'load_image_to_edit_path')); // This filter downloads the image to our local temporary directory, prior to editing the image.
		add_filter('wp_save_image_file', array($this, 'save_image_file')); // Store image file.
		add_filter('wp_save_image_editor_file', array($this, 'save_image_file'), 10, 5);
		add_filter('wp_upload_bits', array($this, 'upload_bits')); // On XMLRPC uploads, files arrives as strings, which we are handling in this filter.
		add_filter('wp_handle_upload_prefilter', array($this, 'handle_upload_prefilter')); // This is where we check for filename dupes (and change them to avoid overwrites).
		add_filter('shutdown', array($this, 'shutdown'));

		// Support for Gravity Forms if Gravity Forms is enabled
		//RYAN:commented out class_exists check because when running as mu-plugin the GFCommon class does not yet exist.
		//if(class_exists("GFCommon")) {
			add_action("gform_after_submission", array($this, 'gravityforms_after_submission'), 10, 2);
		//}

		switch (wpro_get_option('wpro-service')) {
		case 'ftp':
			$this->backend = new WordpressReadOnlyFTP();
			break;
		default:
			$this->backend = new WordpressReadOnlyS3();
		}

		$this->tempdir = sys_get_temp_dir();
		if (substr($this->tempdir, -1) != '/') $this->tempdir = $this->tempdir . '/';

	}

	function is_trusted() {
		if (is_multisite()) {
			if (is_super_admin()) {
				return true;
			}
		} else {
			if (current_user_can('manage_options')) {
				return true;
			}
		}
		return false;
	}

	/* * * * * * * * * * * * * * * * * * * * * * *
	  REGISTER THE SETTINGS:
	* * * * * * * * * * * * * * * * * * * * * * */

	function admin_init() {
		add_site_option('wpro-service', '');
		add_site_option('wpro-folder', '');
		add_site_option('wpro-aws-key', '');
		add_site_option('wpro-aws-secret', '');
		add_site_option('wpro-aws-bucket', '');
		add_site_option('wpro-aws-virthost', '');
		add_site_option('wpro-aws-endpoint', '');
		add_site_option('wpro-ftp-server', '');
		add_site_option('wpro-ftp-user', '');
		add_site_option('wpro-ftp-password', '');
		add_site_option('wpro-ftp-pasvmode', '');
		add_site_option('wpro-ftp-webroot', '');
	}


	/* * * * * * * * * * * * * * * * * * * * * * *
	  ADMIN MENU:
	* * * * * * * * * * * * * * * * * * * * * * */

	function admin_menu() {
		add_options_page('WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', array($this, 'admin_form'));
	}
	function network_admin_menu() {
		add_submenu_page('settings.php', 'WPRO Plugin Settings', 'WPRO Settings', 'manage_options', 'wpro', array($this, 'admin_form'));
	}
	function admin_post() {
		// We are handling the POST settings stuff ourselves, instead of using the Settings API.
		// This is because the Settings API has no way of storing network wide options in multisite installs.
		if (!$this->is_trusted()) return false;
		if ($_POST['action'] != 'wpro_settings_POST') return false;
		foreach (array('wpro-service', 'wpro-folder', 'wpro-aws-key', 'wpro-aws-secret', 'wpro-aws-bucket', 'wpro-aws-virthost', 'wpro-aws-endpoint', 'wpro-ftp-server', 'wpro-ftp-user', 'wpro-ftp-password', 'wpro-ftp-pasvmode') as $allowedPostData) {
			$data = false;
			if (isset($_POST[$allowedPostData])) $data = stripslashes($_POST[$allowedPostData]);
			update_site_option($allowedPostData, $data);
		}
		header('Location: ' . admin_url('network/settings.php?page=wpro&updated=true'));
		exit();
	}

	function admin_form() {
		if (!$this->is_trusted()) {
			wp_die ( __ ('You do not have sufficient permissions to access this page.'));
		}

		$wproService = wpro_get_option('wpro-service');

		?>
			<script language="JavaScript">
				(function($) {
					$(document).ready(function() {
						$('#wpro-service-s3').change(function() {
							$('.wpro-service-div:visible').slideUp(function() {
								$('#wpro-service-s3-div').slideDown();
							});
						});
						$('#wpro-service-ftp').change(function() {
							$('.wpro-service-div:visible').slideUp(function() {
								$('#wpro-service-ftp-div').slideDown();
							});
						});
					});
				})(jQuery);
			</script>
			<div class="wrap">
				<div id="icon-plugins" class="icon32"><br /></div>
				<h2>WP Read-Only (WPRO)</h2>

				<?php if ($_GET['updated']) { ?>
					<div id="message" class="updated">
						<p>Options saved.</p>
					</div>
				<?php } ?>

				<form name="wpro-settings-form" action="<?php echo admin_url('admin-post.php');?>" method="post">
					<input type="hidden" name="action" value="wpro_settings_POST" />

					<h3><?php echo __('Common Settings'); ?></h3>
					<table class="form-table">
						<tr>
							<th><label>Storage Service</label></th>
							<td>
								<input name="wpro-service" id="wpro-service-s3" type="radio" value="s3" <?php if ($wproService != 'ftp') echo ('checked="checked"'); ?>/> <label for="wpro-service-s3">Amazon S3</label><br />
								<input name="wpro-service" id="wpro-service-ftp" type="radio" value="ftp" <?php if ($wproService == 'ftp') echo ('checked="checked"'); ?>/> <label for="wpro-service-ftp">FTP Server</label><br />
							</td>
						</tr>
						<tr>
							<th><label for="wpro-folder">Prepend all paths with folder</th>
							<td><input name="wpro-folder" id="wpro-folder" type="text" value="<?php echo(wpro_get_option('wpro-folder')); ?>" class="regular-text code" /></td>
						</tr>
					</table>
					<div class="wpro-service-div" id="wpro-service-s3-div" <?php if ($wproService == 'ftp') echo ('style="display:none"'); ?> >
						<h3><?php echo __('Amazon S3 Settings'); ?></h3>
						<table class="form-table">
							<tr>
								<th><label for="wpro-aws-key">AWS Key</label></th> 
								<td><input name="wpro-aws-key" id="wpro-aws-key" type="text" value="<?php echo wpro_get_option('wpro-aws-key'); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th><label for="wpro-aws-secret">AWS Secret</label></th> 
								<td><input name="wpro-aws-secret" id="wpro-aws-secret" type="text" value="<?php echo wpro_get_option('wpro-aws-secret'); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th><label for="wpro-aws-bucket">S3 Bucket</label></th> 
								<td>
									<input name="wpro-aws-bucket" id="wpro-aws-bucket" type="text" value="<?php echo wpro_get_option('wpro-aws-bucket'); ?>" class="regular-text code" /><br />
									<input name="wpro-aws-virthost" id="wpro-aws-virthost" type="checkbox" value="1"  <?php if (wpro_get_option('wpro-aws-virthost')) echo('checked="checked"'); ?> /> Virtual hosting is enabled for this bucket.
								</td>
							</tr>
							<tr>
								<th><label for="wpro-aws-endpoint">Bucket AWS Region</label></th> 
								<td>
									<select name="wpro-aws-endpoint" id="wpro-aws-endpoint">
										<?php
											$aws_regions = array(
												's3.amazonaws.com' => 'US East Region (Standard)',
												's3-us-west-2.amazonaws.com' => 'US West (Oregon) Region',
												's3-us-west-1.amazonaws.com' => 'US West (Northern California) Region',
												's3-eu-west-1.amazonaws.com' => 'EU (Ireland) Region',
												's3-ap-southeast-1.amazonaws.com' => 'Asia Pacific (Singapore) Region',
												's3-ap-southeast-2.amazonaws.com' => 'Asia Pacific (Sydney) Region',
												's3-ap-northeast-1.amazonaws.com' => 'Asia Pacific (Tokyo) Region',
												's3-sa-east-1.amazonaws.com' => 'South America (Sao Paulo) Region'
											);
											// Endpoints comes from http://docs.amazonwebservices.com/general/latest/gr/rande.html

											foreach ($aws_regions as $endpoint => $endpoint_name) {
												echo ('<option value="' . $endpoint . '"');
												if ($endpoint == wpro_get_option('wpro-aws-endpoint')) {
													echo(' selected="selected"');
												}
												echo ('>' . $endpoint_name . '</option>');
											}
										?>
									</select> 
								</td>
							</tr>
						</table>
					</div>
					<div class="wpro-service-div" id="wpro-service-ftp-div" <?php if ($wproService != 'ftp') echo ('style="display:none"'); ?> >
						<h3><?php echo __('FTP Settings'); ?></h3>
						<table class="form-table">
							<tr>
								<th><label for="wpro-ftp-server">FTP Server</label></th> 
								<td><input name="wpro-ftp-server" id="wpro-ftp-server" type="text" value="<?php echo wpro_get_option('wpro-ftp-server'); ?>" class="regular-text code" /></td>
							</tr>
							<tr>
								<th><label for="wpro-ftp-user">FTP Username</label></th> 
								<td><input name="wpro-ftp-user" id="wpro-ftp-user" type="text" value="<?php echo wpro_get_option('wpro-ftp-user'); ?>" class="regular-text code" /></td>
							</tr>
						</table>
					</div>
					<p class="submit"> 
						<input type="submit" name="submit" class="button-primary" value="<?php echo __('Save Changes'); ?>" /> 
					</p>
				</form>
			</div>
		<?php
	}

	/* * * * * * * * * * * * * * * * * * * * * * *
	  TAKING CARE OF UPLOADS:
	* * * * * * * * * * * * * * * * * * * * * * */

	function handle_upload($data) {

		$this->debug('WordpressReadOnly::handle_upload($data);');
		$this->debug('-> $data = ');
		$this->debug(print_r($data, true));

		$data['url'] = $this->url_normalizer($data['url']);

		if (!file_exists($data['file'])) return false;

		$response = $this->backend->upload($data['file'], $data['url'], $data['type']);
		if (!$response) return false;

		return $data;
	}

	public $upload_basedir = ''; // Variable for caching in the upload_dir()-method
	function upload_dir($data) {
//		$this->debug('WordpressReadOnly::upload_dir($data);');
//		$this->debug('-> $data = ');
//		$this->debug(print_r($data, true));

		if ($this->upload_basedir == '') {
			$this->upload_basedir = $this->tempdir . 'wpro' . time() . rand(0, 999999);
			while (is_dir($this->upload_basedir)) $this->upload_basedir = $this->tempdir . 'wpro' . time() . rand(0, 999999);
		}
		$data['basedir'] = $this->upload_basedir;
		
		// Begin building baseurl
		switch (wpro_get_option('wpro-service')) {
		case 'ftp':
			$data['baseurl'] = '//' . trim(str_replace('//', '/', trim(wpro_get_option('wpro-ftp-webroot'))), '/');
			break;
		default:
			if (wpro_get_option('wpro-aws-virthost')) {
				// Use Virtual Hosted-Style with CNAME
				$data['baseurl'] = '//' . trim(str_replace('//', '/', wpro_get_option('wpro-aws-virthost')), '/');
			} else if (isset($_SERVER['HTTPS']) && !empty($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] !== 'off')) {
				// Force Path-Style when using SSL, otherwise user will get a certificate error if the 
				// bucket contains periods. (i.e. S3's certificate won't match www.mydomain.com.s3.amazonaws.com)
				$data['baseurl'] = '//s3.amazonaws.com/' . trim(str_replace('//', '/', wpro_get_option('wpro-aws-bucket')), '/');
			} else {
				// Use Virtual Hosted-Style without CNAME
		//RYAN: Use the same Path-Style to be compatible with the broken dynamic-featured-image plugin.
				//$data['baseurl'] = '//' . trim(str_replace('//', '/', wpro_get_option('wpro-aws-bucket') . '.s3.amazonaws.com'), '/');
				$data['baseurl'] = '//s3.amazonaws.com/' . trim(str_replace('//', '/', wpro_get_option('wpro-aws-bucket')), '/');
			}
		}
		// Append the appropriate wpro-folder to baseurl
		//see wp-includes/functions.php [
		if ( is_multisite() && ! ( is_main_network() && is_main_site() && defined( 'MULTISITE' ) ) ) {
			if ( ! defined('USEBLOGSDIR') && ! get_site_option( 'ms_files_rewriting' ) ) {
				$data['baseurl'] .= '/' . trim(str_replace('//', '/', trim(wpro_get_option('wpro-folder'))), '/');
				if ( defined( 'MULTISITE' ) )
					$ms_dir = '/sites/' . get_current_blog_id();
				else
					$ms_dir = '/' . get_current_blog_id();
			} elseif ( defined( 'UPLOADS' ) && ! ms_is_switched() ) {
				// Handle the old-form ms-files.php rewriting if the network still has that enabled.
				$data['baseurl'] .= '/' . trim(str_replace('//', '/', trim(wpro_get_option('wpro-blogsfolder', 'wp-content/blogs.dir'))), '/');
				$ms_dir = '/' . get_current_blog_id() . '/files';
			}
			$data['baseurl'] .= $ms_dir;
		} else {
			$data['baseurl'] .= '/' . trim(str_replace('//', '/', trim(wpro_get_option('wpro-folder'))), '/');
		}
		$data['path'] = $this->upload_basedir . $data['subdir'];
		$data['url'] = $data['baseurl'] . $data['subdir'];
		$this->removeTemporaryLocalData($data['path']);
//		$this->debug('-> RETURNS = ');
//		$this->debug(print_r($data, true));

		return $data;
	}

	function generate_attachment_metadata($data) {
		$this->debug('WordpressReadOnly::generate_attachment_metadata();');
		if (!is_array($data) || !isset($data['sizes']) || !is_array($data['sizes'])) return $data;

		$upload_dir = wp_upload_dir();
		$filepath = $upload_dir['basedir'] . '/' . preg_replace('/^(.+\/)?.+$/', '\\1', $data['file']);
		foreach ($data['sizes'] as $size => $sizedata) {
			$file = $filepath . $sizedata['file'];
			$url = $upload_dir['baseurl'] . substr($file, strlen($upload_dir['basedir']));

			$mime = 'application/octet-stream';
			switch(strtolower(substr($file, -4))) {
				case '.gif':
					$mime = 'image/gif';
					break;
				case '.jpg':
					$mime = 'image/jpeg';
					break;
				case '.jpeg':
					$mime = 'image/jpeg';
					break;
				case '.png':
					$mime = 'image/png';
					break;
			}

			$this->backend->upload($file, $url, $mime);
		}

		return $data;
	}

	function update_attachment_metadata($data) {

		$this->debug('WordpressReadOnly::update_attachment_metadata();');

		if (!is_array($data) || !isset($data['sizes']) || !is_array($data['sizes'])) return $data;

		$upload_dir = wp_upload_dir();
		$filepath = $upload_dir['basedir'] . '/' . preg_replace('/^(.+\/)?.+$/', '\\1', $data['file']);

		foreach ($data['sizes'] as $size => $sizedata) {
			$file = $filepath . $sizedata['file'];
			$url = $upload_dir['baseurl'] . substr($file, strlen($upload_dir['basedir']));
			$mime = 'application/octet-stream';

			switch(strtolower(substr($file, -4))) {
				case '.gif':
					$mime = 'image/gif';
					break;
				case '.jpg':
					$mime = 'image/jpeg';
					break;
				case '.jpeg':
					$mime = 'image/jpeg';
					break;
				case '.png':
					$mime = 'image/png';
					break;
			}
			$this->backend->upload($file, $url, $mime);
		}

		return $data;
	}

	function load_image_to_edit_path($filepath) {

		$this->debug('WordpressReadOnly::load_image_to_edit_path("' . $filepath . '");');

		if (substr($filepath, 0, 2) == '//') {
			$ending = '';
			if (preg_match('/\.([^\.\/]+)$/', $filepath, $regs)) $ending = '.' . $regs[1];

			$tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;
			while (file_exists($tmpfile)) $tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;

			$filepath = $this->url_normalizer($filepath);

			$this->debug('-> Loading file from: ' . $filepath);
			$this->debug('-> Storing file at: ' . $tmpfile);

			$ch = curl_init();
                        $filepath_with_proto = 'http:' . $filepath;
			curl_setopt($ch, CURLOPT_URL, $filepath_with_proto);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_AUTOREFERER, true);

			$fh = fopen($tmpfile, 'w');
			fwrite($fh, curl_exec_follow($ch));
			fclose($fh);

			$this->removeTemporaryLocalData($tmpfile);

			return $tmpfile;

		}
		return $filepath;
	}

	function save_image_file($dummy, $filename, $image, $mime_type, $post_id) {

		$this->debug('WordpressReadOnly::save_image_file("' . $filename . '", "' . $mime_type . '", "' . $post_id . '");');

		if (substr($filename, 0, strlen($this->tempdir)) != $this->tempdir) return false;
		$tmpfile = substr($filename, strlen($this->tempdir));
		if (!preg_match('/^wpro[0-9]+(\/.+)$/', $tmpfile, $regs)) return false;

		$tmpfile = $regs[1];
		$this->debug('-> Storing image as temporary file: ' . $filename);
		$image->save($filename, $mime_type);

		$upload = wp_upload_dir();
		$url = $upload['baseurl'];
		if (substr($url, -1) != '/') $url .= '/';
		while (substr($tmpfile, 0, 1) == '/') $tmpfile = substr($tmpfile, 1);
		$url .= $tmpfile;

		return $this->backend->upload($filename, $this->url_normalizer($url), $mime_type);
	}

	function upload_bits($data) {

		$this->debug('WordpressReadOnly::upload_bits($data);');
		$this->debug('-> $data = ');
		$this->debug(print_r($data, true));

		$ending = '';
		if (preg_match('/\.([^\.\/]+)$/', $data['name'], $regs)) $ending = '.' . $regs[1];

		$tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;
		while (file_exists($tmpfile)) $tmpfile = $this->tempdir . 'wpro' . time() . rand(0, 999999) . $ending;

		$fh = fopen($tmpfile, 'wb');
		fwrite($fh, $data['bits']);
		fclose($fh);

		$upload = wp_upload_dir();

		return array(
			'file' => $tmpfile,
			'url' => $this->url_normalizer($upload['url'] . '/' . $data['name']),
			'error' => false
		);
	}

	// Handle duplicate filenames:
	// Wordpress never calls the wp_handle_upload_overrides filter properly, so we do not have any good way of setting a callback for wp_unique_filename_callback, which would be the most beautiful way of doing this. So, instead we are usting the wp_handle_upload_prefilter to check for duplicates and rename the files...
	function handle_upload_prefilter($file) {

		$this->debug('WordpressReadOnly::handle_upload_prefilter($file);');
		$this->debug('-> $file = ');
		$this->debug(print_r($file, true));

		$upload = wp_upload_dir();

		$name = $file['name'];
		
		// If a filename extension exists, lower it.
		if (preg_match('/\.([^\.\/]+)$/', $file['name'], $regs)) {
				$ending = '.' . $regs[1];
				$preending = substr($file['name'], 0, 0 - strlen($ending));
				$name = $preending . strtolower($ending);
		}
		
		$path = trim($upload['url'], '/') . '/' . $name;

		$counter = 0;
		while ($this->backend->file_exists($path)) {
			if (preg_match('/\.([^\.\/]+)$/', $file['name'], $regs)) {
				$ending = '.' . $regs[1];
				$preending = substr($file['name'], 0, 0 - strlen($ending));
				$name = $preending . '_' . $counter . strtolower($ending);
			} else {
				$name = $file['name'] . '_' . $counter;
			}
			$path = trim($upload['url'], '/') . '/' . $name;
			$counter++;
		}

		$file['name'] = $name;

		return $file;
	}

	function gravityforms_after_submission($entry, $form) {
	
		// Upload any file attachments
		$this->debug('WordpressReadOnly::gravityforms_after_submission($entry, $form);');

		$upload_dir = wp_upload_dir();
		foreach($form['fields'] as $field) {
			if ($field['type'] == 'fileupload' || $field['type'] == 'post_image') {
				$id = (int) $field['id'];
				$file_to_upload = $entry[$id];
				if($file_to_upload) {
					$url = $entry[$id];
					if ($field['type'] == 'post_image') {
						if (strpos($file_to_upload, '|')) { 
							$file_to_upload = strstr($file_to_upload, '|', true); 
						}
						if (strpos($url, '|')) { 
							$url = strstr($url, '|', true); 
						}
					}
					$file_to_upload = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $file_to_upload);
					$mime = wp_check_filetype($file_to_upload);
					$response = $this->backend->upload($file_to_upload, $url, $mime['type']);
					if (!$response) return false;
				}
			}
		}
	}

	function shutdown() {
		$this->debug('WordpressReadOnly::shutdown()');

		$this->temporaryLocalData = array_merge($this->temporaryLocalData, $this->backend->temporaryLocalData);

		$this->debug('-> $this->temporaryLocalData = ');
		$this->debug(print_r($this->temporaryLocalData, true));

		$tempdir = sys_get_temp_dir();
		if (substr($tempdir, -1) == '/') $tempdir = substr($tempdir, 0, -1);

		foreach ($this->temporaryLocalData as $file) {

			if (substr($file, 0, strlen($tempdir) + 1) == $tempdir . '/') {

				while ($file != $tempdir) {
					if (substr($file, -1) == '/') {
						$file = substr($file, 0, -1);
					} else {
						if (is_file($file)) {
							if (@unlink($file) == false) break;
							$this->debug('-> Removed file: ' . $file);
						} else if (is_dir($file)) {
							if (@rmdir($file) == false) break;
							$this->debug('-> Removed directory: ' . $file);
						} else {
							break;
						}
						if (preg_match('/^(.+)\/[^\/]+$/', $file, $regs)) {
							$file = $regs[1];
						} else {
							break;
						}
					}
				}
			}
		}
	}

}
