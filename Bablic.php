<?php
require_once("sdk.php");

class wp_store {
    public function get($key){
		$value = get_option('bablic.'.$key);
		if(empty($value)){
		    $value = get_option($key);
		    if(!empty($value)){
		        update_option('bablic.'.$key, $value);
		        delete_option($key);
            }
        }
        return $value;
    }
    public function set($key, $value){
        update_option('bablic.'.$key, $value);
    }
}


/*
Plugin Name: Bablic
Plugin URI: https://www.bablic.com/docs#wordpress'
Description: Integrates your site with Bablic localization cloud service.
Version: 2.4
Author: Ishai Jaffe
Author URI: https://www.bablic.com
License: GPLv3
	Copyright 2012 Bablic
*/
class bablic {
	// declare globals
	var $options_name = 'bablic_item';
	var $options_group = 'bablic_option_option';
	var $options_page = 'bablic';
	var $plugin_homepage = 'https://www.bablic.com/integrations/wordpress';
	var $bablic_docs = 'https://www.bablic.com/documentation';
	var $plugin_name = 'Bablic';
	var $plugin_textdomain = 'Bablic';
	var $bablic_version = '3.3';
    var $query_var = 'bablic_locale';
    var $bablic_plugin_version = '2.4.1';
	
	

	var $log = array();
	var $locale;
	var $saved;
	var $keys;
	var $tcs;

	// constructor
	function __construct() {
		$options = $this->optionsGetOptions();
		add_filter( 'plugin_row_meta', array( &$this, 'optionsSetPluginMeta' ), 10, 2 ); // add plugin page meta links
		// plugin startup
		add_action( 'admin_init', array( &$this, 'optionsInit' ) ); // whitelist options page
		// add setting page to admin
		add_action( 'admin_menu', array( &$this, 'optionsAddPage' ) ); // add link to plugin's settings page in 'settings' menu on admin menu initilization
		// add code in HTML head
		add_action( 'wp_head', array( &$this, 'writeHead' ));
		add_action( 'wp_footer', array( &$this, 'writeFooter' ));

        add_action('login_head', array( &$this, 'writeBoth' ));
		// before process buffer
		add_action( 'plugins_loaded', array( &$this, 'before_header' ),0);

        //add_action('shutdown', array(&$this, 'after_header'),9999999999);

		
		
		add_filter('rewrite_rules_array', array(&$this, 'bablic_insert_rewrite_rules'));

        // on plugin activate/de-activate
		register_activation_hook( __FILE__, array( &$this, 'optionsCompat' ) );
        register_activation_hook(__FILE__, array(&$this, 'flush_rules'));
        register_deactivation_hook(__FILE__, array(&$this, 'onDeactivate'));
		
		// replace all links
		add_filter( 'post_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'page_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'author_link', array(&$this, 'append_prefix'), 10, 3);
		add_filter( 'attachment_link', array(&$this, 'append_prefix'), 10, 3);
		add_filter( 'comment_reply_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'post_type_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'day_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'get_comment_author_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'get_comment_author_url_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'month_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'the_permalink', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'year_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'tag_link', array(&$this, 'append_prefix'), 10, 3 );
		add_filter( 'term_link', array(&$this, 'append_prefix'), 10, 3 );

		// get locale hook
		add_filter('locale', array(&$this, 'get_locale'));


        add_action( 'admin_notices', array(&$this, 'bablic_admin_messages') );

        // register ajax hook
        add_action('wp_ajax_bablicHideRating',array(&$this, 'bablic_hide_rating'));

        add_action('wp_ajax_bablicSettings',array(&$this, 'bablic_settings_save'));
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		$this->sdk = new BablicSDK(
            array(
                'channel_id' => 'wp',
                'subdir' => $options['dont_permalink'] == 'no',
                'subdir_base' => $this->getDirBase(),
                'store' => new wp_store()
            )
        );

        if($options['dont_permalink'] == 'no')
            remove_filter('template_redirect','redirect_canonical');

	}
	
	function register_routes(){
		register_rest_route('bablic', '/callback/', array(
		    array('methods' => 'POST','callback'=> array( $this, 'test_callback' ))
		));
	}
	
	function test_callback(){       
        $rslt = $this->sdk->get_site_from_bablic();
		echo "OK"; exit;
	}


    function site_create(){
        $url = get_site_url();
        $rslt = $this->sdk->create_site(
            array(
                'site_url' => $url,
                'callback' => "$url/wp-json/bablic/callback",
            )
        );
        if (!empty($rslt['error']))
            add_action( 'admin_notices', array(&$this, 'create_site_error') );

    }

    function getDirBase(){
        $url = get_site_url();
        $path = parse_url($url, PHP_URL_PATH);
        return preg_replace("/\/$/", "", $path);
    }

    function create_site_error() {
        echo '<div class="bablic_fivestar" style="box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);">Error in site creation, make sure Bablic snippet was added manually to your php files. Please contact support@bablic.com for further support</div>';
    }

    function before_header(){
        if(!is_admin())
            $this->sdk->handle_request();
	}

	function after_header(){
	    // for log
	}



	function get_locale($lang=''){
	    if(is_admin())
            return $lang;
		$header = (isset($_SERVER['HTTP_BABLIC_LOCALE']) ? $_SERVER['HTTP_BABLIC_LOCALE'] : null);
		$bablic_locale = '';
		if($header){
			$bablic_locale = $header;
		}
		else {
            $bablic_locale = $this->sdk->get_locale();
		}
		if($bablic_locale == $this->sdk->get_original())
		    return $lang;
        return $bablic_locale;
	}
	
	function append_prefix($url){
		global $wp_rewrite;
	    $is_sub_dir = ($wp_rewrite->permalink_structure) !== '';
		$options = $this->optionsGetOptions();	
		if($options['dont_permalink'] == 'yes')
			return $url;

	    $locale = $this->get_locale();
		if($locale == '')
			return $url;
		return $this->sdk->get_link($locale,$url);
	}
	 
	function flush_rules(){
		global $wp_rewrite;
    	$wp_rewrite->flush_rules();
	}

	function onDeactivate(){
	    global $wp_rewrite;
        $options = $this->optionsGetOptions();
        $options['dont_permalink'] = 'yes';
        $options['uninstalled'] = '1';
        $this->updateOptions($options);
        $wp_rewrite->flush_rules();
	}
	
	function bablic_insert_rewrite_rules($old_rules) {
		//print_r($old_rules);
        $new_rules = array();
		$options = $this->optionsGetOptions();
		if($options['dont_permalink'] == 'yes')
			return $old_rules;
		$locales = $this->sdk->get_locales();
        $locale_regex = "(" . implode("|",$locales) . ")/";
        $locale_replace = "&".$this->query_var."=\$matches[1]";

        $new_rules[$locale_regex . "?$"] = "index.php?". $this->query_var ."=\$matches[1]";
        foreach ($old_rules as $regex => $replace) {
            $save_regex = $regex;
            $save_replace = $replace;
			
            $regex = $locale_regex . $regex;
            for ($param=0; $param<=10; $param++) {
                $replace = str_replace('[' . (9-$param) . ']', '[' . (10-$param) . ']', $replace);
            }
            $replace .= $locale_replace;
            $new_rules[$regex] = $replace;
            $new_rules[$save_regex] = $save_replace;
        }
        return $new_rules;
    }
	

	// load i18n textdomain
	function loadTextDomain() {
		load_plugin_textdomain( $this->plugin_textdomain, false, trailingslashit( dirname( plugin_basename( __FILE__ ) ) ) . 'lang/' );
	}
	
	
	// compatability with upgrade from version <1.4
	function optionsCompat() {
		$old_options = get_option( 'ssga_item' );
		if ( !$old_options ) return false;
		
		$defaults = optionsGetDefaults();
		foreach( $defaults as $key => $value )
			if( !isset( $old_options[$key] ) )
				$old_options[$key] = $value;
		
		add_option( $this->options_name, $old_options, '', false );
		delete_option( 'ssga_item' );
		return true;
	}
	
	// get default plugin options
	function optionsGetDefaults() { 
		$defaults = array( 
			'dont_permalink' => 'yes',
			'date' => '',
			'rated' => 'no'
		);
		return $defaults;
	}
	
	function optionsGetOptions() {
		$options = get_option( $this->options_name, $this->optionsGetDefaults() );
		if(!$options['dont_permalink'])
			$options['dont_permalink'] = 'yes';
		if(!$options['date'] || $options['date'] == ''){
		    $options['date'] = new DateTime('NOW');
		    update_option($this->options_name, $options);
		}
		$defaults = $this->optionsGetDefaults();
		foreach($defaults as $key => $value){
			if(!isset($options[$key]))
				$options[$key] = $value;
		}
		return $options;
	}

	function updateOptions($options){
	    update_option($this->options_name, $options);
	}

	
	// set plugin links
	function optionsSetPluginMeta( $links, $file ) { 
		$plugin = plugin_basename( __FILE__ );
		if ( $file == $plugin ) { // if called for THIS plugin then:
			$newlinks = array( '<a href="options-general.php?page=' . $this->options_page . '">' . __( 'Settings', $this->plugin_textdomain ) . '</a>' ); // array of links to add
			return array_merge( $links, $newlinks ); // merge new links into existing $links
		}
        return $links; // return the $links (merged or otherwise)
	}
	
	// plugin startup
	function optionsInit() { 
		register_setting( $this->options_group, $this->options_name, array( &$this, 'optionsValidate' ) );
	}

	function addAdminScripts($hook_suffix){
		wp_enqueue_style('bablic-admin-css','//cdn2.bablic.com/addons/wp24.css');
		wp_enqueue_script('bablic-admin-sdk','//cdn2.bablic.com/addons/wp24.js');
    }  
	
	// create and link options page
	function optionsAddPage() {
        global $my_settings_page;
		$my_settings_page = add_options_page( $this->plugin_name . ' ' . __( 'Settings', $this->plugin_textdomain ), __( 'Bablic', $this->plugin_textdomain ), 'manage_options', $this->options_page, array( &$this, 'optionsDrawPage' ) );

		
        add_action( 'admin_enqueue_scripts',array( &$this, 'addAdminScripts' ));
 	}
	
	function log($stuff){
	      //array_push($this->log,$stuff);
	}



	// sanitize and validate options input
	function optionsValidate( $input ) { 
		return $input;
	}

	
	// draw the options page
	function optionsDrawPage() { 
		$options = $this->optionsGetOptions();
		$isFirstTime = $this->sdk->site_id == '';
		$site = $this->sdk->get_site();
	?>
        <div class="bablic-wp tw-bs" id="bablic_all">
             
			<label id="bablic-subdir">
				<input type="checkbox" id="bablic_dont_permalink" <?php checked( 'no', $options['dont_permalink'], true ) ?>  > Generate sub-directory urls (for example: /es/, /fr/about, ...)
			</label>

        <div style="display:none;">
            <?php if(isset($options['uninstalled']) && $options['uninstalled'] == '1') echo '<input type="hidden" id="bablic_was_uninstalled" />'  ?>
            <input type="hidden" value="<?php echo $site['site_id'] ?>" id="bablic_site_id" />
            <input type="hidden" value="<?php echo $site['access_token'] ?>" id="bablic_access_token" />
            <textarea  id="bablic_item_meta"><?php echo $site['meta'] ?></textarea>
            <input type="hidden" value="<?php echo ($site['trial_started'] ? '1' : '') ?>" id="bablic_trial" />
        </div>
        <div id="bablic-register-step">
        <div class='panel bablic_info'>
          <img class='row' src="https://s.bablic.com/images/4/assets/logo-onwhite.png" alt="Bablic Website Translation"/>
        	<div class='info_container row'>
        			<h2 class='header row'> Do it yourself website translation - no programming required! </h2>
        			<h4 class='subheader row'>That's how it works: </h4>
        		<div class='left_side col-xs-12 col-md-6'>
        			<ol>
        				<li> <span> Select a language & instantly preview your translated site using machine translation </span> </li>
        				<li> <span> Edit/improve the machine translation or order professional human translation with just a click </span> </li>
        				<li> <span> Easily replace images & fix style/CSS issues in a user-friendly, in-context, visual editor </span> </li>
        				<li> <span> Customize the language selector color, position and style to match your Prestashop site's look and feel </span> </li>
        				<li> <span> Click Publish when ready to go live </span>	</li>
        			</ol>
        		<div id='bablic_form'> </div>
        		</div>
        		<div class='right_side col-xs-12 col-md-6'>
        			<div><span></span> Reach new customers globally in a matter of minutes & increase sales</div>
        			<div><span></span> Start ranking on leading search engines for your new languages with Bablic's SEO-friendly solution</div>
        			<div><span></span> Immediately compete with the big guys using your fully localized Prestashop store</div>
        			<div><span></span> Free dedicated support on all plans</div>
        		</div>
        	</div>
                <div class="clear"></div>
        </div>
<div class="panel">											
	<div class="panel-heading bablic">
																For more information visit <a href="https://www.bablic.com">Bablic.com</a> or contact us at <a href="mailto: support@bablic.com">support@bablic.com</a>
	</div>
        <div class="clear"></div>
</div>
  </div>
        </div>
		<?php
		
		$this->sdk->refresh_site();
		global $wp_rewrite;
		$wp_rewrite->flush_rules();
	} 
	
	// 	the Bablic snippet to be inserted
	function writeHead() { 
		if(is_admin())
		    return;
        echo '<!-- start Bablic Head ' . $this->bablic_plugin_version . ' -->';
        try{
		    $this->sdk->alt_tags();
		}
		catch (Exception $e) { echo '<!-- Bablic No Alt Tags -->'; }
		$locale = $this->sdk->get_locale();
		try{
            if($locale != $this->sdk->get_original()){
                $snippet = $this->sdk->get_snippet();
                if($snippet != ''){
                    echo $snippet;
                    echo '<script>bablic.exclude("#wpadminbar,#wp-admin-bar-my-account");</script>';
                }
            }
		}
        catch (Exception $e) { echo '<!-- Bablic No Head -->'; }
        echo '<!-- end Bablic Head -->';

        try{
            $user_id = get_current_user_id();
            if($user_id){
                $metaLocale = get_user_meta($user_id,'bablic_locale',true);
                if($metaLocale != $locale){
                    update_user_meta($user_id,'bablic_locale',$locale);
                    echo '<!-- Set User Language '.$user_id . ' '.$locale.' -->';
                }
            }
        }
        catch (Exception $e) { echo '<!-- No user meta -->'; }
    }
	
	function writeFooter(){
		if(is_admin())
		    return;
            try{
                if($this->sdk->get_locale() == $this->sdk->get_original()){
                    echo '<!-- start Bablic Footer -->';
                    $snippet = $this->sdk->get_snippet();
                    if($snippet != ''){
                        echo $snippet;
                        echo '<script>bablic.exclude("#wpadminbar,#wp-admin-bar-my-account");</script>';
                    }
                    echo '<!-- end Bablic Footer -->';
                }
            }
            catch (Exception $e) { echo '<!-- Bablic No Footer -->'; }
	}

	function writeBoth(){
        echo '<!-- start Bablic Head '. $this->bablic_plugin_version .' -->';
		$this->sdk->alt_tags();
        $snippet = $this->sdk->get_snippet();
        if($snippet != ''){
            echo $snippet;
            echo '<script>bablic.markup("url","form","ignore");</script>';
            echo '<script>bablic.exclude("#wpadminbar,#wp-admin-bar-my-account");</script>';
        }
        echo '<!-- end Bablic Head -->';

	}

	function bablic_admin_messages() {
	    try{
			$options = $this->optionsGetOptions();
			//print_r $options;
			$install_date = $options['date'];
			$display_date = date('Y-m-d h:i:s');
			$datetime1 = $install_date;
			$datetime2 = new DateTime($display_date);
			$diff_intrval = round(($datetime2->format('U') - $datetime1->format('U')) / (60*60*24));
			if($diff_intrval >= 7 && $options['rated'] == 'no') {
			 echo '<div class="bablic_fivestar" style="box-shadow: 0 1px 1px 0 rgba(0,0,0,.1);">
				<p>Love Bablic? Help us by rating it 5? on <a href="https://wordpress.org/support/view/plugin-reviews/bablic" class="thankyou bablicRate" target="_new" title="Ok, you deserved it" style="font-weight:bold;">WordPress.org</a> 
				&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<a href="javascript:void(0);" class="bablicHideRating" style="font-weight:bold; font-size:9px;">Don\'t show again</a>
				</p>
			</div>
			<script>
			jQuery( document ).ready(function( $ ) {

			jQuery(\'.bablicHideRating,.bablicRate\').click(function(){
				var data={\'action\':\'bablicHideRating\'}
					 jQuery.ajax({

				url: "'.admin_url( 'admin-ajax.php' ).'",
				type: "post",
				data: data,
				dataType: "json",
				async: !0,
				success: function(e) {
				   jQuery(\'.bablic_fivestar\').slideUp(\'slow\');
				}
				 });
				})

			});
			</script>
			';
			}
		}
		catch (Exception $e) {}
    }

    function bablic_hide_rating(){
		header('Content-type: application/json');
        $options = $this->optionsGetOptions();
        $options['rated'] = 'yes';
        $this->updateOptions($options);
        echo json_encode(array("success")); exit;
    }

    function bablic_settings_save(){
        global $wp_rewrite;
        $data = $_POST['data'];
		header('Content-type: application/json');
        switch($data['action']){
            case 'create':
                $this->site_create();
                if(!$this->sdk->site_id){
                    echo json_encode(array('error' => 'Failed registering this website. Make sure this Bablic code snippet was not added manually into the website code')); exit;
                    return;
                }
                break;
            case 'set':
                $site = $data['site'];
                $url = get_site_url();
                $this->sdk->set_site($site,"$url/wp-json/bablic/callback");
                break;
            case 'subdir':
                $options = $this->optionsGetOptions();
                $options['dont_permalink'] = $data['on'] == 'true' ? 'no' : 'yes';
                $this->updateOptions($options);
                $wp_rewrite->flush_rules();
                break;
            case 'refresh':
                $this->sdk->refresh_site();
                $wp_rewrite->flush_rules();
                break;
            case 'delete':
                $this->sdk->clear_data();
                break;
            case 'keep':
                $options = $this->optionsGetOptions();
                $options['uninstalled'] = '';
                $this->updateOptions($options);
                break;
            case 'clear':
                $options = $this->optionsGetOptions();
                $options['uninstalled'] = '';
                $this->updateOptions($options);
                $this->sdk->remove_site();
                break;
        }
		$this->sdk->clear_cache();
        echo json_encode(array(
            'site' => $this->sdk->get_site()
        )); exit;
        return;
    }
	
} // end class

$bablic_instance = new bablic;
?>
