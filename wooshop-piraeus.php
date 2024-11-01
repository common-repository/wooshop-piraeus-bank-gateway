<?php
/*
  Plugin Name: Wooshop Piraeus Bank Gateway
  Description: Wooshop Piraeus Bank Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards On your Woocommerce Powered Site. This plugin is also compatible with the Polylang plugin, thus it is fully functional on multilingual sites. It currently only allows redirection to Piraeus Bank website
  Version: 1.0.1
  Author: Lefteris Saroukos
  Author URI: https://www.lsaroukos.gr
  License: GPL-3.0+
  License URI: http://www.gnu.org/licenses/gpl-3.0.txt
  Domain Path: /languages
 */
 /*
 Based on original plugin "Piraeus Bank Greece Payment Gateway for WooCommerce" by emspace.gr [https://wordpress.org/plugins/woo-payment-gateway-piraeus-bank-greece/]
 */

if (!defined('ABSPATH'))
    exit;
    
if(!class_exists('WOOSHOP_PB_Gateway') && class_exists('WC_Payment_Gateway')){   
    
  class WOOSHOP_PB_Gateway extends WC_Payment_Gateway {
  /**
  * Gateway class
  */
      
      /**
      * Loads the translations
      */
      function load_textdomain(){
        load_plugin_textdomain('wooshop-piraeus', false, dirname(plugin_basename(__FILE__)) . '/languages/');
      }
      
      /**
      * if Polylang plugin is enabled it stores the current language to the wooshop_language COOKIE
      */
      function set_wooshop_language(){
        if(function_exists('pll_current_language')){
          setcookie('wooshop_language',pll_current_language(),time() + (86400), "/");
        }
      }
        
      /**
      * Creates a custom post type that will store the results of the transactionsas provided from the bank
      */
      function create_response_post_type(){
        $labels = array('name' => __('Piraeus Bank Response','wooshop-piraeus'), 'add_new_item' => __('Add New Response','wooshop-piraeus'));
        register_post_type('wc_piraeus_response',array(
            'labels'        => $labels,
            'description'   => __('stores the information from piraeus bank response', 'wooshop-piraeus'),
            'public'        =>  true,
            'menu_icon'     => 'dashicons-building',
            'supports'      => array('title','editor')
        ));
      }
      
      /**
      * Constructor
      */
      public function __construct() {
        add_action('init',array($this, 'create_response_post_type'));   //creates custom post type that stores repsonse information
        $this->id = 'wooshop_piraeus';  //ID of the plugin. Will be used to create specific hooks to functions of this plugin    
        $this->icon = apply_filters('piraeusbank_icon', plugins_url('img/PB_blue_GR.png', __FILE__)); //the icon to be displayed at the front-end for this payment method by woocommerce
        $this->has_fields = true; //True if the gateway shows fields on the checkout
        $this->notify_url = WC()->api_request_url('WOOSHOP_PB_Gateway');  //Return {WC_API_URL}/WOOSHOP_PB_Gateway
        $this->method_description = __('Wooshop Piraeus Bank Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards On your Woocommerce Powered Site.', 'wooshop-piraeus'); //description to be displayed at the backend
        $this->redirect_page_id = absint($this->get_option('redirect_page_id')); //custom page to redirect on transaction completetion 
        $this->method_title = 'Wooshop Piraeus Bank Gateway'; //define the gateway title
        
        //Load the form fields.
        $this->init_form_fields();    //prepares the html code of the back-end options
        $this->init_settings();       //initializes the settings of the gateway

        //Define user set variables from back-end
        $this->title = ($this->get_option('title')!='') ? sanitize_text_field($this->get_option('title')): __('Piraeus Bank Gateway','wooshop-piraeus');
        $this->description = sanitize_text_field($this->get_option('description'));
        $this->pb_PayMerchantId = absint($this->get_option('pb_PayMerchantId'));
        $this->pb_AcquirerId = absint($this->get_option('pb_AcquirerId'));
        $this->pb_PosId = absint($this->get_option('pb_PosId'));
        $this->pb_Username = sanitize_user($this->get_option('pb_Username'));
        $this->pb_Password = sanitize_text_field($this->get_option('pb_Password'));
        $this->pb_ProxyHost = esc_url($this->get_option('pb_ProxyHost'));
        $this->pb_ProxyPort = absint($this->get_option('pb_ProxyPort'));
        $this->pb_ProxyUsername = sanitize_text_field($this->get_option('pb_ProxyUsername'));
        $this->pb_ProxyPassword = sanitize_text_field($this->get_option('pb_ProxyPassword'));
        $this->pb_authorize = absint($this->get_option('pb_authorize'));
        $this->pb_installments = (absint($this->get_option('pb_installments'))<=24) ? absint($this->get_option('pb_installments')) : 0;
        
        //Add actions and filters
        add_action('init',array($this,'load_textdomain'));  //adds transaltions
        add_filter('woocommerce_payment_gateways', array($this,'woocommerce_add_wooshop_piraeus'));  //apends this to the wc gateway methods
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));  //hook to save changes in admin options
        add_filter('plugin_action_links', array($this,'piraeusbank_plugin_action_links'), 10, 2);   //adds settings links to the plugin activation page
        add_action('the_post', array($this,'piraeusbank_message')); //adds a wc notice, i.e. a message to be displayed on the frontend
        add_action('the_post', array($this,'load_common_checkout_scripts')); //adds javascript to the common-checkout page that will be used to redirect to piraeus bank website
        add_action('woocommerce_receipt_wooshop_piraeus', array($this, 'receipt_page'));  //displays message after visitor has sent the payment form
        add_action('woocommerce_after_checkout_billing_form',array($this, 'set_wooshop_language'));   //initializes a special cookie that holds the language code of checkout page
        //Payment listener/API hook
        add_action('woocommerce_api_wooshop_piraeus', array($this, 'check_piraeusbank_response')); //handles payment response
        
        //creates wc-piraeusbank-common-checkout page if it does not exist
        if( get_page_by_title('wc-piraeusbank-common-checkout') === null ){
          wp_insert_post(array(
              'ID'          => 0,
              'post_type'   => 'page',
              'post_title'  => 'wc-piraeusbank-common-checkout',
              'post_status' => 'publish'
          ));          
        }
      }

     /**
     * appends settings link to the plugin activation page
     **/
      function piraeusbank_plugin_action_links($links, $file) {
          $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=wooshop_piraeus') .'">Settings</a>';
          
          static $this_plugin;
          if (!$this_plugin) {
              $this_plugin = plugin_basename(__FILE__);
          }

          if ($file == $this_plugin) {
              array_unshift($links, $settings_link);
          }
          return $links;
      }
      
      /**
      * Add Piraeus Bank Gateway to WC
      **/
      function woocommerce_add_wooshop_piraeus($methods) {
        $methods[] = 'WOOSHOP_PB_Gateway';
        return $methods;
      }

      /**
      * Outputs The Gateway Settings Screen
      **/
      public function admin_options() {
        $checkout_page_url = post_exists('wc-piraeusbank-common-checkout') ? get_permalink(post_exists('wc-piraeusbank-common-checkout')) : '';
        /**
         * fix in case user has not set up correclty Settings»General»Site Address, Wordpress Address, to implement https
         * 
         * @since v1.0.1
         */
        $site_url = get_site_url();
        if( strstr($site_url,'https'))
          $checkout_page_url = str_replace("http://","https://",$checkout_page_url);
        
        echo '<h3>'. __('Wooshop Piraeus Bank Gateway', 'wooshop-piraeus') . '</h3>';
        echo '<p>' . __('Wooshop Piraeus Bank Gateway allows you to accept payment through various channels such as Maestro, Mastercard, AMex cards, Diners  and Visa cards.', 'wooshop-piraeus') . '</p>';
        //output current urls
        echo '<h3>'.__('Current URLs','wooshop-piraeus').'</h3>';
        echo '<table style="text-align:left;background: #0085ba;padding: 10px;color: whitesmoke;box-shadow: inset 0 0 6px 2px #535353;border-radius: 4px;">
                <tr><td colspan="2">'.__('This is a list with all the URLs that the banks requires from you in order to set up your profile.<br>Note, that if you change the permalink structure from your Dashboard Settings, new links will be formed and you will have to let the bank know of your updated urls!','wooshop-piraeus').'</td></tr>
                <tr><th>Website URL:</th><td>'.get_site_url().'</td></tr>
                <tr><th>Referrer URL:</th><td>'.$checkout_page_url.'</td></tr>
                <tr><th>Success URL:</th><td>'.add_query_arg('piraeus', 'success', get_site_url()."/wc-api/Wooshop_Piraeus/") .'</td></tr>
                <tr><th>Failure URL:</th><td>'.add_query_arg('piraeus', 'fail', get_site_url()."/wc-api/Wooshop_Piraeus/") .'</td></tr>
                <tr><th>Backlink URL:</th><td>'.add_query_arg('piraeus', 'cancel', get_site_url()."/wc-api/Wooshop_Piraeus/") .'</td></tr>
              </table>';
        
        
        echo '<table class="form-table">';
        $this->generate_settings_html();  //gets the html code of the settings as assigned to $this->form_fields
        echo '</table>';
      }
      
      /**
      * Adds the messages to be printed from woocommere
      **/
      function piraeusbank_message() {
        if( isset($_COOKIE['wooshop_message_type']) ){ //if this is the order received page
            if( $_COOKIE['wooshop_message_type'] == 'error' ){
              $message = __('A technical problem occured. <br/>The transaction wasn\'t successful, payment wasn\'t received.', 'wooshop-piraeus');
              wc_add_notice(__('Payment error:', 'wooshop-piraeus') . $message, 'error');
            }elseif( $_COOKIE['wooshop_message_type'] == 'process'){
              $message = __('Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is currently being processed.', 'wooshop-piraeus');
              wc_add_notice( $message, 'success' ); 
            }elseif( $_COOKIE['wooshop_message_type'] == 'success'){
              $message = __('Thank you for shopping with us.<br />Your transaction was successful, payment was received.<br />Your order is now complete.', 'wooshop-piraeus');
              wc_add_notice( $message, 'success' );
            }else{ //$_COOKIE['wooshop_message_type'] == 'fail'
              $message =__('Thank you for shopping with us. <br />However, the transaction wasn\'t successful, payment wasn\'t received.', 'wooshop-piraeus');
              wc_add_notice(__('Payment error:', 'wooshop-piraeus') . $message, 'error');   //sets a message in a cookie to be displayed anywhere on the front-end by calling 
            }
        }
        if( is_order_received_page() || (get_the_ID() == $this->redirect_page_id) ) //if this is the order-received page
          print_r(is_order_received_page());
          if(function_exists('wc_print_notices'))
            wc_print_notices(); //display any message was set by add_wc_notice and displays it ini a stylish box
      }

      /**
        * Initialize Gateway Settings Form Fields
        * stores in $this->form_fields the html code of the options to be added at the backend 
        * */
      function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'wooshop-piraeus'),
                'type' => 'checkbox',
                'label' => __('Enable Piraeus Bank Gateway', 'wooshop-piraeus'),
                'description' => __('Enable or disable the gateway.', 'wooshop-piraeus'),
                'desc_tip' => true,
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'wooshop-piraeus'),
                'desc_tip' => false,
                'default' => __('Piraeus Bank Gateway', 'wooshop-piraeus')
            ),
            'description' => array(
                'title' => __('Description', 'wooshop-piraeus'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'wooshop-piraeus'),
                'default' => __('Pay Via Piraeus Bank: Accepts  Mastercard, Visa cards and etc.', 'wooshop-piraeus')
            ),
            'pb_PayMerchantId' => array(
                'title' => __('Piraeus Bank Merchant ID', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Enter Your Piraeus Bank Merchant ID', 'wooshop-piraeus'),
                'default' => '',
                'desc_tip' => true
            ),
            'pb_AcquirerId' => array(
                'title' => __('Piraeus Bank Acquirer ID', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Enter Your Piraeus Bank Acquirer ID', 'wooshop-piraeus'),
                'default' => '',
                'desc_tip' => true
            ),
            'pb_PosId' => array(
                'title' => __('Piraeus Bank POS ID', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Enter your Piraeus Bank POS ID', 'wooshop-piraeus'),
                'default' => '',
                'desc_tip' => true
            ), 'pb_Username' => array(
                'title' => __('Piraeus Bank Username', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Enter your Piraeus Bank Username', 'wooshop-piraeus'),
                'default' => '',
                'desc_tip' => true
            ), 'pb_Password' => array(
                'title' => __('Piraeus Bank Password', 'wooshop-piraeus'),
                'type' => 'password',
                'description' => __('Enter your Piraeus Bank Password', 'wooshop-piraeus'),
                'default' => '',
                'desc_tip' => true
            ), 
            'pb_ProxyHost' => array(
                'title' => __('HTTP Proxy Hostname', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Used when your server is not behind a static IP. Leave blank for normal HTTP connection.', 'wooshop-piraeus'),
                'desc_tip' => false,
                'default' => ''
            ),
            'pb_ProxyPort' => array(
                'title' => __('HTTP Proxy Port', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Used with Proxy Host.', 'wooshop-piraeus'),
                'desc_tip' => false,
                'default' => __('8888', 'wooshop-piraeus')
            ),
            'pb_ProxyUsername' => array(
                'title' => __('HTTP Proxy Login Username', 'wooshop-piraeus'),
                'type' => 'text',
                'description' => __('Used with Proxy Host. Leave blank for anonymous connection.', 'wooshop-piraeus'),
                'desc_tip' => false,
                'default' => ''
            ),
            'pb_ProxyPassword' => array(
                'title' => __('HTTP Proxy Login Password', 'wooshop-piraeus'),
                'type' => 'password',
                'description' => __(' Used with Proxy Host. Leave blank for anonymous connection.', 'wooshop-piraeus'),
                'desc_tip' => false,
                'default' => ''
            ),
            'pb_authorize' => array(
                'title' => __('Pre-Authorize', 'wooshop-piraeus'),
                'type' => 'checkbox',
                'label' => __('Enable to capture preauthorized payments', 'wooshop-piraeus'),
                'default' => true,
                'description' => __('Default payment method is Purchase, enable for Pre-Authorized payments. You will then need to accept them from Piraeus Bank AdminTool', 'wooshop-piraeus')
            ),
            'redirect_page_id' => array(
                'title' => __('Return Page', 'wooshop-piraeus'),
                'type' => 'select',
                'options' => $this->pb_get_pages('Select Page'),
                'description' => __('URL of success page', 'wooshop-piraeus')
            ),
            'pb_installments' => array(
                'title' => __('Max Installments', 'wooshop-piraeus'),
                'type' => 'select',
                'options' => $this->pb_get_installments('Select Installments'),
                'description' => __('1 to 24 Installments,1 for one time payment. You must contact Piraeus Bank first', 'wooshop-piraeus')
            )
        );
      }
      
      /**
      * returns an array of all available pages
      * Return: array(page_id=>page_title)
      **/
      function pb_get_pages($title = false, $indent = true) {
          $wp_pages = get_pages('sort_column=menu_order');
          $page_list = array();
          if ($title)
              $page_list[] = $title;
          foreach ($wp_pages as $page) {
              $prefix = '';
              // show indented childarray($this,'deactivate') pages?
              if ($indent) {
                  $has_parent = $page->post_parent;
                  while ($has_parent) {
                      $prefix .= ' - ';
                      $next_page = get_page($has_parent);
                      $has_parent = $next_page->post_parent;
                  }
              }
              // add to page list array array
              $page_list[$page->ID] = $prefix . $page->post_title;
          }
          $page_list[-1] = __('Thank you page', 'wooshop-piraeus');
          return $page_list;
      }
      
      /**
      * Returns an array from 0-24 to be used as options of the installments select box
      */
      function pb_get_installments($title = false, $indent = true) {
          for ($i = 0; $i <= 24; $i++) {
              $installment_list[$i] = $i;
          }
          return $installment_list;
      }

      /**
      * Displays the description at the front-end at the payment method choose box
      * If the installments option is enabled, it presents a select box to let the user choose the number of installments
      */
      function payment_fields() {
        //echo description
        if ($description = $this->get_description()) {
          echo wpautop(wptexturize($description));  //wpautop: Replaces double line-breaks with paragraph elements 
        }
        $installments = $this->pb_installments;
        if ($installments > 1) {
          $installments_field = '<p class="form-row ">
                                  <label for="' . esc_attr($this->id) . '-card-doseis">' . __('Choose Installments', 'wooshop-piraeus') . ' <span class="required">*</span></label>
                                  <select id="' . esc_attr($this->id) . '-card-doseis" name="' . esc_attr($this->id) . '-card-doseis" class="input-select wc-credit-card-form-card-doseis">';
          for ($i = 1; $i <= $installments; $i++) {
            $installments_field .= '<option value="' . $i . '">' . $i . '</option>';
          }
          $installments_field .= '</select></p>';
          echo $installments_field;
        }
      }

      /**
      * Generate the code that sends the POST request to Piraeus Bank website
      **/
      public function generate_piraeusbank_form($order_id){      
        $order = new WC_Order($order_id);
        if ($this->pb_authorize == true) {
          $requestType = '00';
          $ExpirePreauth = '30';
        }else {
          $requestType = '02';
          $ExpirePreauth = '0';
        }
        
        $installments = 0;
        //get installments from $order meta
        if (method_exists($order, 'get_meta')) {
          $installments = $order->get_meta('_doseis');
          if ($installments == '') {  //if there are no installments defined
              $installments = 0;      //assume 0
          }
        } else {
          $installments = get_post_meta($order_id, '_doseis', true);
        }
        try {
          /* ---initialize SoapClient--- */
          if( $this->pb_ProxyHost!=''){      //if proxy settings are defined
            if($this->pb_ProxyUsername != '' && $this->pb_ProxyPassword != ''){
              $soap = new SoapClient("https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL",
              array(
                'proxy_host'     => $this->pb_ProxyHost,
                'proxy_port'     => intval($this->pb_ProxyPort),
                'proxy_login'    => $this->pb_ProxyUsername,
                'proxy_password' => $this->pb_ProxyPassword
                )
              );
            }
            else{
              $soap = new SoapClient("https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL",
              array(
                  'proxy_host'     => $this->pb_ProxyHost,
                  'proxy_port'     => intval($this->pb_ProxyPort)
                  )
              );                  
            }
          }else{  //if there are no proxy settings defined at the back-end settings page
            $soap = new SoapClient("https://paycenter.piraeusbank.gr/services/tickets/issuer.asmx?WSDL");
          }
          
          //initialize the parameters to send via POST Request, according to values provided by the bank
//           //the values are retrieved from the back-end settings
          $ticketRequest = array(
              'Username' => $this->pb_Username,               
              'Password' => hash('md5', $this->pb_Password),  
              'MerchantId' => $this->pb_PayMerchantId,        
              'PosId' => $this->pb_PosId,                     
              'AcquirerId' => $this->pb_AcquirerId,
              'MerchantReference' => $order_id,
              'RequestType' =>  $requestType,
              'ExpirePreauth' => $ExpirePreauth,
              'Amount' => $order->get_total(),
              'CurrencyCode' => '978',  //default for euro
              'Installments' => (($installments==1 || $installments=='1') ? 0 : $installments),
              'Bnpl' => '0',
              'Parameters' => ''
          );
          $xml = array('Request' => $ticketRequest);

          $oResult = $soap->IssueNewTicket($xml); //create the SOAP elemnt to send
          if ($oResult->IssueNewTicketResult->ResultCode == 0) {
            //store Transaction Ticket in a Session	
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            $tt = isset($_SESSION['trans_ticket']) ? json_decode($_SESSION['trans_ticket']) : array();  //get existing value from session
            $tt[] =  $oResult->IssueNewTicketResult->TranTicket;                                        //append the new transaction ticket
            $_SESSION['trans_ticket'] = json_encode($tt);                                               //update the session value

            //redirect to payment
          
            //shows the box message
            wc_enqueue_js('
              $.blockUI({
                  message: "' . esc_js(__('Thank you for your order. We are now redirecting you to Piraeus Bank to make payment.', 'wooshop-piraeus')) . '",
                  baseZ: 99999,
                  overlayCSS:
                  {
                    background: "#fff",
                    opacity: 0.6
                  },
                  css: {
                    padding:        "20px",
                    zindex:         "9999999",
                    textAlign:      "center",
                    color:          "#555",
                    border:         "3px solid #aaa",
                    backgroundColor:"#fff",
                    cursor:         "wait",
                    lineHeight:		"24px",
                  }
                });
            ');
  
            /* --decide display language according to pll language code-- */ 
            /*
              Other available Language codes
              el-GR: Greek
              en-US: English
              ru-RU: Russian
              de-DE: German
              */
            $LanCode = "en-US"; 
            if(isset($_COOKIE['wooshop_language']) ){
              $pll_code = $_COOKIE['wooshop_language'];
              if(strstr($pll_code,'el')){
                $LanCode = "el-GR";
              }
            }
            
            //creates a form that will be used to send the parameters to the BANK via POST Request
            echo '<form action="' . esc_url("https://paycenter.piraeusbank.gr/redirection/pay.aspx") . '" method="post" id="pb_payment_form" target="_top">				
                <input type="hidden" id="AcquirerId" name="AcquirerId" value="' . esc_attr($this->pb_AcquirerId) . '"/>
                <input type="hidden" id="MerchantId" name="MerchantId" value="' . esc_attr($this->pb_PayMerchantId) . '"/>
                <input type="hidden" id="PosID" name="PosID" value="' . esc_attr($this->pb_PosId) . '"/>
                <input type="hidden" id="User" name="User" value="' . esc_attr($this->pb_Username) . '"/>
                <input type="hidden" id="LanguageCode"  name="LanguageCode" value="' . esc_attr($LanCode) . '"/>
                <input type="hidden" id="MerchantReference" name="MerchantReference"  value="' . esc_attr($order_id) . '"/>
                <!-- Button Fallback -->
                <div class="payment_buttons">
                  <input type="submit" class="button alt" id="submit_pb_payment_form" value="' . __('Pay via Pireaus Bank', 'wooshop-piraeus') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'wooshop-piraeus') . '</a>
                  
                </div>
              </form>
              <script type="text/javascript">
                //jQuery(".payment_buttons").hide();
              </script>';
            wc_enqueue_js('jQuery("#submit_pb_payment_form").click();');  //submit the form
          }else{
            _e('An error occured, please contact the Administrator', 'wooshop-piraeus');
            _e('Result code is ' . absint($oResult->IssueNewTicketResult->ResultCode), 'wooshop-piraeus');
            $order->add_order_note(__('Error' . absint($oResult->IssueNewTicketResult->ResultCode).':'.sanitize_text_field($oResult->IssueNewTicketResult->ResultDescription), 'wooshop-piraeus'));                   
          }
        }catch (SoapFault $fault) {
          $order->add_order_note(__('Error' . sanitize_text_field($fault), 'wooshop-piraeus'));
          _e('Error' . esc_attr($fault), 'wooshop-piraeus');
        }
      }

      /**
      * Process the payment and return the result
      * This function is called when proceed to payment is pressed
      * get_permalink was used instead of $order->get_checkout_payment_url in redirect in order to have a fixed checkout page to provide to Piraeus Bank
      **/
      function process_payment($order_id) {
        $order = new WC_Order($order_id);
        $doseis = intval($_POST[esc_attr($this->id) . '-card-doseis']);
        if ($doseis > 1) {
            $this->generic_add_meta($order_id, '_doseis', $doseis);
        }
        return array(
            'result' => 'success',
            'redirect' => add_query_arg('order-pay', $order->get_id(), add_query_arg('key', $order->get_order_key(), wc_get_page_permalink('checkout')))  //forms the url http(s)://example.com/checkout_page/order_received_page/{order_id}/?key={key_name}
        );
      }

      /**
      * This function redirects to common-checkout-page
      **/
      function receipt_page($order) {
        echo '<p>' . __('Thank you - your order is now pending payment. You should be automatically redirected to Piraeus Paycenter to make payment.', 'wooshop-piraeus') . '</p>';
        //get common checkout page url
        $common_page = post_exists('wc-piraeusbank-common-checkout');
        $url = get_permalink($common_page);
        //redirect by sending a post request with all the necessary information
        echo "
          <form hidden id='common-checkout-redirect' action='".esc_url($url)."' method='POST'>
            <input name='order' value='".esc_attr($order)."'>
          </form>
          <script>
            document.getElementById('common-checkout-redirect').submit();
          </script>
        ";
      }
      
      /**
      * Checks if currently the user is on the common checkout page, and if it is true, sends the POST request to the banks website
      **/
      function load_common_checkout_scripts(){
        if(get_the_title() == 'wc-piraeusbank-common-checkout'){  //run only in common-checkout page
          if( isset($_POST['order'])  ){
            $this->generate_piraeusbank_form(absint($_POST['order']));
          }
        }
      }
      
      /**
       * This function retrieves inforamtion from a special URL that the user is redirected to after completing a transaction to piraeus bank website
       * It then, stores the information to the database and the returned message to a cookie, redirects to a custom page from where this message should be displayed
       **/
      function check_piraeusbank_response() {
        global $woocommerce;
        
        //get info from banks response
        $ResultCode = filter_var($_GET['ResultCode'],FILTER_SANITIZE_NUMBER_INT);    
        $ResponseCode = filter_var($_GET['ResponseCode'],FILTER_SANITIZE_NUMBER_INT);
        $AuthStatus = isset($_GET['AuthStatus']) ? filter_var($_GET['AuthStatus'],FILTER_SANITIZE_NUMBER_INT) : '03';
        if(isset($_GET['StatusFlag']))
          $StatusFlag = ( strtolower(sanitize_text_field($_GET['StatusFlag']))=='success' || strtolower(sanitize_text_field($_GET['StatusFlag']))=='failure' ) ? sanitize_text_field($_GET['StatusFlag']) : ''; //possible values Success, Failure
        else
          $StatusFlag = 'failure';
        $PackageNo = isset($_GET['PackageNo']) ? absint($_GET['PackageNo']) : -1;
        $ApprovalCode = isset($_GET['ApprovalCode']) ? filter_var($_GET['ApprovalCode'], FILTER_SANITIZE_STRING) : '';

        if (isset($_GET['piraeus']) && (strtolower($_GET['piraeus']) == 'success')) {   //case 1: success url
          $order_id = sanitize_text_field($_GET['MerchantReference']); //get order id
          $order = new WC_Order($order_id);       //get Woocommerce order object

          if ($ResultCode != 0) {
          //if there is an error return failure message
            setcookie('wooshop_message_type','error',time() + (86400), "/");
            $order->update_status('failed', '');  //Update the order status
            $checkout_url = $woocommerce->cart->get_checkout_url();
            wp_redirect($checkout_url);
            exit;
          }
          
          //get additional information
          $HashKey = filter_var($_GET['HashKey'],FILTER_SANITIZE_STRING);
          $SupportReferenceID = absint($_GET['SupportReferenceID']);
          $Parameters = sanitize_text_field($_GET['Parameters']);
          
          //store the response in a custom post type registry
          $response = array();
          if( isset($_GET['SupportReferenceID']) )
            array_push($response, 'SupportReferenceID', $SupportReferenceID );
          if( isset($_GET['MerchantReference']) )
            array_push($response, 'MerchantReference', $order_id );
          if( isset($_GET['ResultCode']) )
            array_push($response, 'ResultCode', $ResultCode );
          if( isset($_GET['ResponseCode']) )
            array_push($response, 'ResponseCode', $ResponseCode );
          if( isset($_GET['ResponseDescription']) )
            array_push($response, 'ResponseDescription', sanitize_text_field($_GET['ResponseDescription']) );
          if( isset($_GET['AuthStatus']) )
            array_push($response, 'AuthStatus', $AuthStatus );
          if( isset($_GET['StatusFlag']) )
            array_push($response, 'StatusFlag', $StatusFlag );
          if( isset($_GET['PackageNo']) )
            array_push($response, 'PackageNo', $PackageNo );
          if( isset($_GET['ApprovalCode']) )
            array_push($response, 'ApprovalCode', $ApprovalCode );
          if( isset($_GET['TransactionId']) )
            array_push($response, 'TransactionId', absint($_GET['TransactionId']) );
          $r = wp_insert_post(array('post_type'=>'wc_piraeus_response','post_content'=>json_encode($response), 'post_title'=>$SupportReferenceID) );
          
          //checks if hashes match
          if (session_status() == PHP_SESSION_NONE) {
            session_start();
          }
          $tt = isset($_SESSION['trans_ticket'])? json_decode($_SESSION['trans_ticket']) : array(); //gets the tranaction ticket value
          unset($_SESSION['trans_ticket']);
              
          $hasHashKeyNotMatched = true;
          foreach($tt as $transaction) {              
            if(!$hasHashKeyNotMatched)
                break;
            $transticket = $transaction;
            $stcon = $transticket . $this->pb_PosId . $this->pb_AcquirerId . $order_id . $ApprovalCode . $Parameters . $ResponseCode . $SupportReferenceID . $AuthStatus . $PackageNo . $StatusFlag;
            $conhash = strtoupper(hash('sha256', $stcon));

            //generate hash from information returned from the bank
            $stconHmac = $transticket . ';' . $this->pb_PosId . ';' .  $this->pb_AcquirerId . ';' .  $order_id . ';' .  $ApprovalCode . ';' .  $Parameters . ';' .  $ResponseCode . ';' .  $SupportReferenceID . ';' .  $AuthStatus . ';' .  $PackageNo . ';' .  $StatusFlag;
            $consHashHmac = strtoupper(hash_hmac('sha256', $stconHmac, $transticket, false));
            //compare hashes
            if($consHashHmac != $HashKey && $conhash != $HashKey) {
                continue;
            } else {
                $hasHashKeyNotMatched= false;
            }
          }

          if($hasHashKeyNotMatched) { //if there is a hash matching error
          //return error message and redirect to  checkout paqge
            setcookie('wooshop_message_type','error',time() + (86400), "/");
            //Update the order status
            $order->update_status('failed', '');
            $checkout_url = $woocommerce->cart->get_checkout_url();
            wp_redirect($checkout_url);
            exit;
          }else{ // if hashes match 
            if ($ResponseCode == 0 || $ResponseCode == 8 || $ResponseCode == 10 || $ResponseCode == 16) { //if a success response code was sent
              if ($order->get_status() == 'processing') { //if order status is 'processing'
                //add order not to be displayed at the back-end
                $order->add_order_note(__('Payment Via Piraeus Bank<br />Transaction ID: ', 'wooshop-piraeus') . $SupportReferenceID);
                //Add customer order note
                $order->add_order_note(__('Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Piraeus Bank ID: ', 'wooshop-piraeus') . $SupportReferenceID, 1);
                //Reduce stock levels
                $order->reduce_order_stock();
                //Empty cart
                WC()->cart->empty_cart();
              setcookie('wooshop_message_type','process',time() + (86400), "/");
              }else{  //if status is success
                if($order->has_downloadable_item()){  //if this is a downloadable item
                  //Update order status
                  $order->update_status('completed', __('Payment received, your order is now complete.', 'wooshop-piraeus'));
                  //Add admin order note
                  $order->add_order_note(__('Payment Via Piraeus Bank<br />Transaction ID: ', 'wooshop-piraeus') . $SupportReferenceID);
                  //Add customer order note
                  $order->add_order_note(__('Payment Received.<br />Your order is now complete.<br />Piraeus Transaction ID: ', 'wooshop-piraeus') . $SupportReferenceID, 1);
                }else{    //if it is a simple item
                  //Update order status
                  $order->update_status('processing', __('Payment received, your order is currently being processed.', 'wooshop-piraeus'));
                  //Add admin order note
                  $order->add_order_note(__('Payment Via Piraeus Bank<br />Transaction ID: ', 'wooshop-piraeus') . $SupportReferenceID);
                  //Add customer order note
                  $order->add_order_note(__('Payment Received.<br />Your order is currently being processed.<br />We will be shipping your order to you soon.<br />Piraeus Bank ID: ', 'wooshop-piraeus') . $SupportReferenceID, 1);
                }
                setcookie('wooshop_message_type','success',time() + (86400), "/");
                // Reduce stock levels
                $order->reduce_order_stock();
              }
              // Empty cart if a successfull transaction was completed
              if( isset($_GET['piraeus']) && (strtolower($_GET['piraeus']) == 'success') )
                WC()->cart->empty_cart();   
            }else { //if a failure response code is returned
              setcookie('wooshop_message_type','fail',time() + (86400), "/");
              //Update the order status
              $order->update_status('failed', '');
            }
          }
        } //end of case 1
        
        if (isset($_GET['piraeus']) && (strtolower($_GET['piraeus']) == 'fail')) {  //case 2: failure url
          setcookie('wooshop_message_type','fail',time() + (86400), "/");
          $SupportReferenceID = absint($_GET['SupportReferenceID']);  
          $order_id = sanitize_text_field($_GET['MerchantReference']); //get order id
          //save transaction response in custom post type
          $response = array();    
          if( isset($_GET['SupportReferenceID']) )
            array_push($response, 'SupportReferenceID', $SupportReferenceID );
          if( isset($_GET['MerchantReference']) )
            array_push($response, 'MerchantReference', $order_id );
          if( isset($_GET['ResultCode']) )
            array_push($response, 'ResultCode', $ResultCode );
          if( isset($_GET['ResponseCode']) )
            array_push($response, 'ResponseCode', $ResponseCode );
          if( isset($_GET['ResponseDescription']) )
            array_push($response, 'ResponseDescription', sanitize_text_field($_GET['ResponseDescription']) );
          if( isset($_GET['AuthStatus']) )
            array_push($response, 'AuthStatus', $AuthStatus );
          if( isset($_GET['StatusFlag']) )
            array_push($response, 'StatusFlag', $StatusFlag );
          if( isset($_GET['PackageNo']) )
            array_push($response, 'PackageNo', $PackageNo );
          if( isset($_GET['ApprovalCode']) )
            array_push($response, 'ApprovalCode', $ApprovalCode );
          if( isset($_GET['TransactionId']) )
            array_push($response, 'TransactionId', absint($_GET['TransactionId']) );
          $r = wp_insert_post(array('post_type'=>'wc_piraeus_response','post_content'=>json_encode($response), 'post_title'=>$SupportReferenceID) );

          if (isset($_GET['MerchantReference'])) {
            $order = new WC_Order($order_id);
            $transaction_id = $SupportReferenceID;
            //Add Customer Order Note
            $order->add_order_note($message . '<br />Piraeus Bank Transaction ID: ' . $transaction_id, 1);
            //Add Admin Order Note
            $order->add_order_note($message . '<br />Piraeus Bank Transaction ID: ' . $transaction_id);
            //Update the order status
            $order->update_status('failed', '');
          }
        } //end of case 2: failure url
          
        if (isset($_GET['piraeus']) && (strtolower($_GET['piraeus']) == 'cancel')) {  //case 3: cancelation
          /*  stores nothing cutom post type
          $SupportReferenceID = absint($_GET['SupportReferenceID']);  
          $order_id = sanitize_text_field($_GET['MerchantReference']); //get order id
          $response = array();    
          if( isset($_GET['SupportReferenceID']) )
            array_push($response, 'SupportReferenceID', $SupportReferenceID );
          if( isset($_GET['MerchantReference']) )
            array_push($response, 'MerchantReference', $order_id );
          if( isset($_GET['ResultCode']) )
            array_push($response, 'ResultCode', $ResultCode );
          if( isset($_GET['ResponseCode']) )
            array_push($response, 'ResponseCode', $ResponseCode );
          if( isset($_GET['ResponseDescription']) )
            array_push($response, 'ResponseDescription', sanitize_text_field($_GET['ResponseDescription']) );
          if( isset($_GET['AuthStatus']) )
            array_push($response, 'AuthStatus', $AuthStatus );
          if( isset($_GET['StatusFlag']) )
            array_push($response, 'StatusFlag', $StatusFlag );
          if( isset($_GET['PackageNo']) )
            array_push($response, 'PackageNo', $PackageNo );
          if( isset($_GET['ApprovalCode']) )
            array_push($response, 'ApprovalCode', $ApprovalCode );
          if( isset($_GET['TransactionId']) )
            array_push($response, 'TransactionId', absint($_GET['TransactionId']) );
          $r = wp_insert_post(array('post_type'=>'wc_piraeus_response','post_content'=>json_encode($response), 'post_title'=>$SupportReferenceID) );
          */
          $checkout_url = $woocommerce->cart->get_checkout_url();
          wp_redirect($checkout_url);
          exit;
        }

        //get the return page url as defined at the back-end settings
        if ($this->redirect_page_id == "-1" || $this->redirect_page_id == 0) {  //if no return page is defined at the backend
        //redirect to woocommerce order-receievd page
          $redirect_url = $this->get_return_url($order); //woocommerce function that returns the order-received page
          if(function_exists('pll_get_post_language')){ //if pollylang is activated
            $redirect_id = url_to_postid( $redirect_url );

            if( isset($_COOKIE['wooshop_language']) && pll_get_post_language($redirect_id)!=$_COOKIE['wooshop_language'] ){     //if the order-received page is not displayed in the selected language
              $translation_id = pll_get_post($redirect_id, $_COOKIE['wooshop_language']);
              $translation_permalink = get_permalink($translation_id);
              $redirect_permalink = get_permalink($redirect_id);
              $redirect_url = str_replace($redirect_permalink,$translation_permalink,$redirect_url);
            }
          }
        }else { //else if return page is defined
        //redirect to the defined page
          $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url() . "/" : get_permalink($this->redirect_page_id);
          //For wooCoomerce 2.0
          $redirect_url = add_query_arg(array('msg' => urlencode($this->msg['message']), 'type' => $this->msg['class']), $redirect_url);
        }
        
        wp_redirect($redirect_url);   //redirects to "Return Page", defined at the payment options
        exit;
    }

    /**
    * Adds meta information to woocommerce order post type
    **/
    function generic_add_meta($orderid, $key, $value) {
      $order = new WC_Order(absint($orderid));
      if (method_exists($order, 'add_meta_data') && method_exists($order, 'save_meta_data')) {
          $order->add_meta_data(sanitize_key($key), santize_text_field($value), true);
          $order->save_meta_data();
      } else {
          update_post_meta(absint($orderid), sanitize_key($key), santize_text_field($value));
      }
    }    
  }
  
}//--end of class definition
    
if(class_exists('WOOSHOP_PB_Gateway')){  
  $pb_gateway = new WOOSHOP_PB_Gateway(); //initialize class
}

?>
