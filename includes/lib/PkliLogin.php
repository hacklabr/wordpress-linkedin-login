<?php

/*  Copyright 2014  Samer Bechara  (email : sam@thoughtengineer.com)

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

Class PkliLogin {

    const _AUTHORIZE_URL = 'https://www.linkedin.com/uas/oauth2/authorization';
    const _TOKEN_URL = 'https://www.linkedin.com/uas/oauth2/accessToken';

    public $redirect_uri;
    public $li_api_key;
    public $li_secret_key;
    
    public $oauth;

    public function __construct() {

        // Setup redirect uri 
        $this->redirect_uri = wp_login_url() . '?action=pkli_login';

        // Add actions
        add_action('login_form', array($this, 'display_login_button'));
        add_action('init', array($this, 'process_login'));
        
        // Set LinkedIn keys
        $li_keys = get_option('pkli_basic_options');
        $this->li_api_key = $li_keys['li_api_key'];
        $this->li_secret_key = $li_keys['li_secret_key'];
        
        // Require OAuth2 client
        require_once(PKLI_PATH . '/includes/lib/Pkli_OAuth2Client.php');

        // Create new Oauth client
        $this->oauth = new Pkli_OAuth2Client($this->li_api_key, $this->li_secret_key);
        
        // Set Oauth URLs
        $this->oauth->redirect_uri = $this->redirect_uri;
        $this->oauth->authorize_url = self::_AUTHORIZE_URL;
        $this->oauth->token_url = self::_TOKEN_URL;
        
        // Add shortcode for getting LinkedIn Login URL
        add_shortcode( 'wpli_login_link', array($this, 'get_login_link') );        

    }

    // Returns LinkedIn authorization URL
    public function get_auth_url() {

        $state = wp_generate_password(12, false);
        $authorize_url = $this->oauth->authorizeUrl(array('scope' => 'r_basicprofile r_emailaddress',
            'state' => $state));

        // Store state in database in temporarily till checked back
        $_SESSION['li_api_state'] = $state;

        return $authorize_url;
    }

    // Returns the code for the login button
    public function get_login_button() {

        return "<a rel='nofollow' href='" . $this->get_auth_url() . "' title='".__('Connect with LinkedIn','pkli-login')."'>
                                            <img alt='LinkedIn' title='".__('Sign-in Using LinkedIn','pkli-login')."' src='" . plugins_url() . "/pkli-login/includes/assets/img/linkedin.png' />
        </a>";
    }

    // Logs in a user after he has authorized his LinkedIn account
    function process_login() {

        // Action exists on login form and code is sent back
        if (isset($_REQUEST['action']) && isset($_REQUEST['code'])) {
            
            
            // Check if the action belongs to our plugin
            if ($_REQUEST['action'] == "pkli_login") {

                // Check if state is correct to avoid request forgery
                if ($_REQUEST['state'] == $_SESSION['li_api_state']) {

                    // State should be deleted as it is no longer needed
                    unset($_SESSION['li_api_state']);

                    // Use GET method since POST isn't working
                    $this->oauth->curl_authenticate_method = 'GET';
                    
                    // Request access token
                    $response = $this->oauth->authenticate($_REQUEST['code']);
                    $access_token = $response->{'access_token'};
                    
                    // Get first name, last name and email address, and load 
                    // response into XML object
                    $xml = simplexml_load_string($this->oauth->get('https://api.linkedin.com/v1/people/~:(id,first-name,last-name,email-address)'));
                    
                    // Get the user's email address
                    $email = (string) $xml->{'email-address'};
                    
                    // Sign in the user if the email already exists
                    if(email_exists($email)){
                        
                        // Get the user ID by email
                        $user = get_user_by('email',$email);
                        
                        // Signon user by ID
                        wp_set_auth_cookie($user->ID);
                        
			// Redirect to URL if set
			$li_keys = get_option('pkli_basic_options');
			$redirect = $li_keys['li_redirect_url'];
			// Validate URL as absolute
			if(filter_var($redirect, FILTER_VALIDATE_URL, FILTER_FLAG_HOST_REQUIRED))
			    wp_safe_redirect($redirect);
                        // Invalid redirect URL, we'll redirect to admin URL
			else
			    wp_redirect( admin_url() );
                    }
                    
                    // User is signing in for the first time 
                    elseif(is_email($email)) {
                        
                        // Create user
                        $user_id = wp_create_user( $email, wp_generate_password(16), $email );
                        
                        // Signon user by ID
                        wp_set_auth_cookie($user_id);
                        
                        // Redirect to admin URL
                        wp_redirect( admin_url() );                        
                    }
                    
                    // Invalid user email
                    else {
                        echo $this->get_login_error($email);
                    }
                }
            }
        }
        // Getting an error, redirect to login page
        elseif (isset($_REQUEST['error'])) {
	    wp_redirect(wp_login_url());
        }
    }

    public function display_login_button() {
        echo $this->get_login_button();
    }
    
    // Display login error
    private function get_login_error($email){
        
        // Data has been marked as private inside LinkedIn user's account
        if($email== 'private')
            $error = __('We are unable to sign you in, since you have not allowed API'
                . ' access to your email address. Please register manually','pkli-login');
        else
            $error = __('It seems that your application does not have proper scope permissions.
                Please visit your application page on linkedin, and make sure r_emailaddress is checked under Scope','pkli-login');
        return '<div id="login_error">	<strong>ERROR</strong>: '.$error.'<br />
</div>';
    }
    
    public function get_login_link($attributes = false){
        $url = $this->get_auth_url();
	if($attributes != false){
	    // extract data from array
	    extract( shortcode_atts( array('text' => ''), $attributes ) );
	    return "<a href='".$url."'>".__($text,'pkli-login')."</a>";
	}
	
	// No text variable has been setup, pass default
        
        return "<a href='".$url."'>".__('Login with LinkedIn','pkli-login')."</a>";
    }

}