<?php
/*
Plugin Name:  Fivem shop integration
Plugin URI:   https://github.com/Oliwoo/wp-fivem-store
Description:  A plugin to interface Woocommerce with Fivem servers 
Version:      1.0
Author:       Oliwoo
Author URI:   https://www.damianocaccamo.altervista.com
*/

defined( 'ABSPATH' ) or die();
include "discord_api.php";

// AutoUpdate
include_once 'autoUpdater.php';

$updater = new PDUpdater(__FILE__);
$updater->set_username('Oliwoo');
$updater->set_repository('wp-fivem-store');
$updater->authorize(get_option('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'));
$updater->initialize();

/* -- STYLE MANAGER -- */
function fivem_service_init_style(){
    wp_register_style('fivem-login-components-default', plugins_url('/widgets/default.css', __FILE__));
    wp_enqueue_style( 'fivem-login-components-default' );
}
add_action('wp_enqueue_scripts',"fivem_service_init_style");
add_action('admin_enqueue_scripts',"fivem_service_init_style");

/* -- SESSION MANAGER -- */
if(!session_id()){session_start();}
if(!isset($_SESSION["fivem_nonce"])){
    $_SESSION["fivem_nonce"] = bin2hex(random_bytes(16));
}

/* -- FIVEM LOGGING MANAGER -- */
$tmp = explode("\\",plugin_dir_path(__FILE__));
$file_path = end($tmp);
define("PLUGIN_NAME", str_replace("_","-",str_replace("/","",$file_path)));
define("BASE_URL", "https://forum.cfx.re");
define("REDIRECT_URL", "http://".$_SERVER['SERVER_NAME'].$_SERVER['REQUEST_URI']);
define("APP_NAME", get_bloginfo("name"));
define("APP_CLIENT_ID", get_bloginfo("name"));
$keypair = openssl_pkey_get_private(file_get_contents(plugin_dir_url( __FILE__ )."keypair.pem")) or die("Failed to open keypair");
define("KEYPAIR", $keypair);

/* -- JS SCRIPT MANAGER -- */
function fivem_service_init_script(){
    wp_enqueue_script('jquery');
    wp_enqueue_script("fivem-login-components-service-script", plugins_url('/widgets/fivem-login-service.js', __FILE__), '1.0');

    wp_localize_script("fivem-login-components-service-script", "fivem_service_option", array(
        'url' => admin_url("admin-ajax.php"),
        'redirect' => REDIRECT_URL,
        'nonce' => wp_create_nonce($_SESSION["fivem_nonce"])
    ));
}
add_action('wp_enqueue_scripts',"fivem_service_init_script");
add_action('admin_enqueue_scripts', 'fivem_service_init_script');

/* -- LINK / UNLINK ACCOUNT -- */
function unlinkFivem(){ // Disconect fivem user account
    if(!wp_verify_nonce($_REQUEST["_nonce"], $_SESSION["fivem_nonce"])){
        die("Non autorizzato");
    }

    unset($_POST["fivem_user_id"]);
    unset($_POST["fivem_user_name"]);
    unset($_POST["fivem_user_img"]);

    if(function_exists("wp_get_current_user")){
        $user = wp_get_current_user();

        $metas = array( 
            'fivem_id'   => null,
            'fivem_name' => null, 
            'fivem_img'  => null
        );
        
        foreach($metas as $key => $value) {
            update_user_meta( $user->ID, $key, $value );
        }
    }
}
add_action("wp_ajax_unlinkFivem", "unlinkFivem");
add_action("wp_ajax_nopriv_unlinkFivem", "unlinkFivem");

function linkFivem(){ // Generate the fivem account linking url
    if(!wp_verify_nonce($_REQUEST["_nonce"], $_SESSION["fivem_nonce"])){
        die("Non autorizzato");
    }

    $pub = openssl_pkey_get_details(KEYPAIR)["key"];

    $query = http_build_query([
        "auth_redirect"     => $_REQUEST["redirect"],
        "application_name"  => APP_NAME,
        "client_id"         => APP_CLIENT_ID,
        "scopes"            => "session_info,read,write",
        "nonce"             => $_SESSION["fivem_nonce"],
        "public_key"        => $pub
    ]);
    // redirect user to endpoint
    $url = BASE_URL . "/user-api-key/new?" . $query;
    echo $url;
}
add_action("wp_ajax_doFivemLogin", "linkFivem");
add_action("wp_ajax_nopriv_doFivemLogin", "linkFivem");
$fivem_user = null;
if(isset($_GET["payload"])){ // Get the fivem linking url response and link fivem account 
    $payload = base64_decode($_GET["payload"]);

    if(openssl_private_decrypt($payload, $data, KEYPAIR) === false){
        die("Failed to decrypt payload");
    }

    if (($response = json_decode($data, false)) === false){
        die("Failed to decode payload");
    }

    // check nonce
    if ($response->nonce != $_SESSION["fivem_nonce"]){
        die("Invalid nonce");
    }

    $key = $response->key;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BASE_URL . "/session/current.json");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["User-Api-Key: " . $key, "User-Api-Client-Id: " . APP_CLIENT_ID]);

    if(($body = curl_exec($ch)) == false){
        curl_close($ch);
        die("Failed to get session information");
    }

    curl_close($ch);

    if(($session = json_decode($body, false)) === false){
        die("Failed to decode session information");
    }

    $fivem_user = $session;
    $fivem_user_id = $fivem_user->current_user->id;
    $fivem_user_name = $fivem_user->current_user->name;
    $fivem_user_img = $fivem_user->current_user->avatar_template;
    $fivem_user_img = "https://forum.cfx.re/".str_replace("{size}", 256,$fivem_user_img);

    $_POST["fivem_user_id"] = $fivem_user_id;
    $_POST["fivem_user_name"] = $fivem_user_name;
    $_POST["fivem_user_img"] = $fivem_user_img;
    $_POST["username"] = $fivem_user_name;
    $_POST["email"] = $fivem_user->current_user->email;
}

/* -- BUTTON GENERATOR -- */
function generateFivemAccountBtn(){ // Preset Fivem account linking button
    $user = null;
    if(function_exists("wp_get_current_user")){
        $user = wp_get_current_user();
    }

    $fivem_user_id = isset($_POST["fivem_user_id"])?$_POST["fivem_user_id"]:(esc_attr($user->fivem_id)?esc_attr($user->fivem_id):null);
    $fivem_user_name = isset($_POST["fivem_user_name"])? $_POST["fivem_user_name"]:esc_attr($user->fivem_name);
    $fivem_user_img = isset($_POST["fivem_user_img"])?$_POST["fivem_user_img"]:esc_attr($user->fivem_img);
    
    ?>
        <div class="fivem-login-btn" id="fivem">
            <?php if($fivem_user_id):?>
                <a onClick="unlinkFivemAccount()">
                    <span>Unlink account <?=$fivem_user_name?></span>
                    <img src="<?=$fivem_user_img?>">
                </a>
            <?php else: ?>
            <a onClick="loginToFivem()"><span>Link fivem account</span></a>
            <?php endif; ?>
        </div>
    <?php
}
add_action('woocommerce_edit_account_form', "generateFivemAccountBtn"); // Generate fivem linking btn in My-account/User-detail page
add_action('woocommerce_register_form', 'generateFivemAccountBtn' ); // Generate fivem linking btn in registration form

function validate_fivem_register_fields(){ // Validator of fivem linked account
    if(isset($_POST["fivem_user_id"]) && empty($_POST["fivem_user_id"])){
           $validation_errors->add( 'fivem_user_missing', __( '<strong>Error</strong>: Fivem account not linked!', 'woocommerce' ) );
    }
    return $validation_errors;
}
add_action( 'woocommerce_register_post', 'validate_fivem_register_fields', 10, 0);

function fivem_save_extra_register_fields($customer_id){ // Save Fivem linked account 
    if(isset($_POST["fivem_user_id"])){
        update_user_meta($customer_id, 'fivem_id', sanitize_text_field($_SESSION["fivem_user_id"]));
    }
    if(isset($_POST["fivem_user_name"])){
        update_user_meta($customer_id, 'fivem_name', sanitize_text_field($_SESSION["fivem_user_name"]));
    }
    if(isset($_POST["fivem_user_img"])){
        update_user_meta($customer_id, 'fivem_img', sanitize_text_field($_SESSION["fivem_user_img"]));
    }

    unset($_POST["fivem_user_id"]);
    unset($_POST["fivem_user_name"]);
    unset($_POST["fivem_user_img"]);
}
add_action( 'woocommerce_created_customer', 'fivem_save_extra_register_fields' ); // Save fivem account on new account registrated
add_action( 'woocommerce_save_account_details', 'fivem_save_extra_register_fields' ); // Save fivem account when user acoutn edited  

function missingFivemAccountError(){ // Show in the cart checkout an error if user not have an Fivem linked account
    $user = null;
    $fivem_user_id = null;
    if(function_exists("wp_get_current_user")){
        $user = wp_get_current_user();
        $fivem_user_id = $user->fivem_id;
    }
    if(!$fivem_user_id): ?>
        <div class="woocommerce-error">
		    Fivem account is not linked! <a href="<?=get_permalink(get_option('woocommerce_myaccount_page_id'))."edit-account#fivem"?>">Link Fivem account</a>
    	</div>
    <?php endif;
}
add_action('woocommerce_before_checkout_form', 'missingFivemAccountError', 0);

function checkout_fivem_account_validation($fields,$errors){ // Check in cart checkout if the user have an Fivem linked account
    $user = null;
    $fivem_user_id = null;
    if(function_exists("wp_get_current_user")){
        $user = wp_get_current_user();
        $fivem_user_id = $user->fivem_id;
    }
    
    if(!isset($fivem_user_id)){
        $errors->add( 'validation', '<b>Your fivem account is not linked</b> <a href="'.get_permalink(get_option('woocommerce_myaccount_page_id'))."edit-account#fivem".'">Link your Fivem Account</a>' );
    }
    
}
add_filter( 'woocommerce_after_checkout_validation', 'checkout_fivem_account_validation', 10, 2);

/* -- WOOCOMMERCE PRODUCT METABOX --  */
function fivem_shop_meta_box_markup($post){
    $post = $post->ID;
    $fivem_action = get_post_meta($post, 'fivem_action', true);
    $fivem_shop_add_money_value = get_post_meta($post, 'fivem_shop_add_money_value', true);
    $fivem_shop_add_vehicle_value = get_post_meta($post, 'fivem_shop_add_vehicle_value', true);
    $fivem_shop_custom_action_value = get_post_meta($post, 'fivem_shop_custom_action_value', true);

    ?>
    <div class="fivem-shop-metabox">
        <div class="fivem-shop-metabox-field" data-control-name="fivem_action">
            <div class="fivem-shop-metabox-field-info">
                <div class="fivem-shop-metabox-field-info-title" role="banner">Fivem Action</div>
                <div class="fivem-shop-metabox-field-info-description" role="none">Set the product type action</div>
            </div>
            <div class="fivem-shop-metabox-field-content" role="group">
                <div class="fivem-shop-metabox-field-container">
                    <div class="fivem-shop-metabox-field-wrapper">
                        <select id="fivem_action" class="fivem-shop-metabox-field-type-select" name="fivem_action" size="1" data-filter="false" data-placeholder="" style="width: 100%" required="required">
                            <option value="" <?php if(!$fivem_action):?>selected="selected"<?php endif;?>>Select the action to execute after purchasing</option>
                            <option value="fivem_shop_add_money" <?php if($fivem_action == "fivem_shop_add_money"):?>selected="selected"<?php endif;?>>Add money</option>
                            <option value="fivem_shop_add_vehicle" <?php if($fivem_action == "fivem_shop_add_vehicle"):?>selected="selected"<?php endif;?>>Add vehicle</option>
                            <option value="fivem_shop_custom_action" <?php if($fivem_action == "fivem_shop_custom_action"):?>selected="selected"<?php endif;?>>Custom action</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <div class="fivem-shop-metabox-field <?php if($fivem_action != "fivem_shop_add_money"):?>fivem-shop-metabox-field-hidden<?php endif;?>" verify="fivem_action" data-control-name="fivem_shop_add_money_value">
            <div class="fivem-shop-metabox-field-info">
                <div class="fivem-shop-metabox-field-info-title" role="banner">Money Ammount</div>
                <div class="fivem-shop-metabox-field-info-description" role="note">Add the ammount of money to add to user.</div>
            </div>
            <div class="fivem-shop-metabox-field-content" role="group">
                <div class="fivem-shop-metabox-field-container">
                    <div class="fivem-shop-metabox-field-wrapper">
                        <input type="number" id="fivem_shop_add_money_value" pattern="[0-5]+([\.,][0-5]+)?" name="fivem_shop_add_money_value" value="<?=$fivem_shop_add_money_value ?>" min="0" max="" step="5" placeholder="">
                    </div>
                </div>
            </div>
        </div>
        <div class="fivem-shop-metabox-field <?php if($fivem_action != "fivem_shop_add_vehicle"):?>fivem-shop-metabox-field-hidden<?php endif;?>" verify="fivem_action" data-control-name="fivem_shop_add_vehicle_value">
            <div class="fivem-shop-metabox-field-info">
                <div class="fivem-shop-metabox-field-info-title" role="banner">Vehicle name</div>
                <div class="fivem-shop-metabox-field-info-description" role="note">Insert vehicle name to add to user. <br></div>
            </div>
            <div class="fivem-shop-metabox-field-content" role="group">
                <div class="fivem-shop-metabox-field-container ">
                    <input type="text" id="fivem_shop_add_vehicle_value" name="fivem_shop_add_vehicle_value" value="<?=$fivem_shop_add_vehicle_value ?>" placeholder="" autocomplete="on" data-required="1">
                </div>
            </div>
        </div>
        <div class="fivem-shop-metabox-field <?php if($fivem_action != "fivem_shop_custom_action"):?>fivem-shop-metabox-field-hidden<?php endif;?>" verify="fivem_action" data-control-name="fivem_shop_custom_action_value">
            <div class="fivem-shop-metabox-field-info">
                <div class="fivem-shop-metabox-field-info-title" role="banner">Action event name</div>
                <div class="fivem-shop-metabox-field-info-description" role="note">Add the custom script action name to execute when user order the product. <br></div>
            </div>
            <div class="fivem-shop-metabox-field-content" role="group">
                <div class="fivem-shop-metabox-field-container ">
                    <input type="text" id="fivem_shop_custom_action_value" name="fivem_shop_custom_action_value" value="<?=$fivem_shop_custom_action_value ?>" placeholder="" autocomplete="on" data-required="1">
                </div>
            </div>
        </div>
    </div>
    <?php
}

function add_fivem_shop_meta_box(){
    add_meta_box("fivem_shop-meta-box", "Fivem product proprieties", "fivem_shop_meta_box_markup", "product", "normal");
}
add_action("add_meta_boxes", "add_fivem_shop_meta_box");

function save_fivem_shop_meta_box($post_id, $post){
    if(!current_user_can("edit_post", $post_id))
        return $post_id;
 
    if(defined("DOING_AUTOSAVE") && DOING_AUTOSAVE)
        return $post_id;
 
    $slug = "product";
    if($slug != $post->post_type)
        return $post_id;
 
        $fivem_action = "";
        $fivem_shop_add_money_value = "";
        $fivem_shop_add_vehicle_value = "";
        $fivem_shop_custom_action_value = "";
 
    if(isset($_POST["fivem_action"])) {
        $fivem_action = $_POST["fivem_action"];
    }
    update_post_meta($post_id, "fivem_action", $fivem_action);
 
    if(isset($_POST["fivem_shop_add_money_value"])) {
        $fivem_shop_add_money_value = $_POST["fivem_shop_add_money_value"];
    }
    update_post_meta($post_id, "fivem_shop_add_money_value", $fivem_shop_add_money_value);
 
    if(isset($_POST["fivem_shop_add_vehicle_value"])) {
        $fivem_shop_add_vehicle_value = $_POST["fivem_shop_add_vehicle_value"];
    }
    update_post_meta($post_id, "fivem_shop_add_vehicle_value", $fivem_shop_add_vehicle_value);

    if(isset($_POST["fivem_shop_custom_action_value"])) {
        $fivem_shop_custom_action_value = $_POST["fivem_shop_custom_action_value"];
    }
    update_post_meta($post_id, "fivem_shop_custom_action_value", $fivem_shop_custom_action_value);
}
add_action("save_post", "save_fivem_shop_meta_box", 10, 2);

function hide_fivem_order_meta($arr){
    $arr = ['_fivem_shop_error','_fivem_shop_complete_order'];
    return $arr;
}

add_filter('woocommerce_hidden_order_itemmeta', 'hide_fivem_order_meta', 10, 1);

function show_fivem_order_error_allert_column_header($order){
    ?>
        <th class="item_fivem_shop_error sortable" width="100" data-sort="string-ins">Reedem status</th>
    <?php 
}
add_action('woocommerce_admin_order_item_headers', "show_fivem_order_error_allert_column_header", 1);

function show_fivem_order_error_allert_column($_product, $item, $product_id = null){
    $has_complete = false;
    $has_error = false;
    $item_meta = $item->get_meta_data();
    foreach($item_meta as $meta){
        if($meta->key == "_fivem_shop_error" && $meta->value){
            $has_error = true;
        }
        if($meta->key == "_fivem_shop_complete_order" && $meta->value){
            $has_complete = true;
        }
    }
    ?>
        <td class="fivem_shop_col"> 
            <?php if($has_complete): ?>
                <span class="fivem_shop_success dashicons dashicons-yes"></span><div class="icon-help">Assigned</div>
            <?php elseif($has_error): ?>
                <div class="fivem_row"><div class="fivem_box"><span class="fivem_shop_error dashicons dashicons-warning"></span><div class="icon-help">Not Assigned</div></div><div class="fivem_fix_error" order="<?=$_GET["post"]?>" item="<?=$_product->get_id()?>">Mark as complete</div></div>
            <?php else: ?>
                <div class="fivem_row"><div class="fivem_box"><span class="dashicons dashicons-minus"></span><div class="icon-help">On Assignment</div></div><div class="fivem_fix_error" order="<?=$_GET["post"]?>" item="<?=$_product->get_id()?>">Mark as complete</div></div>
            <?php endif; ?>
        </td>
    <?php
}
add_action('woocommerce_admin_order_item_values', "show_fivem_order_error_allert_column", 10, 3);

/* -- Mark item as assigned from admin order review page -- */
function completeOrderItem(){
    if(!wp_verify_nonce($_REQUEST["_nonce"], $_SESSION["fivem_nonce"])){
        die("Non autorizzato");
    }

    $order = wc_get_order($_REQUEST["order"]);
    $completed = $_REQUEST["item"];
    $item_completed = 0;
    foreach($order->get_items() as $item_id => $item){
        $product_id = $item->get_product_id(); 
        if($item->get_meta('_fivem_shop_complete_order')){
            $item_completed++;
        }

        if($product_id == $completed){
            $item->update_meta_data('_fivem_shop_complete_order', true);
            $item->save();
            $order->add_order_note('The product: <b>'.$item->get_name().'</b> has been successfully assigned',true);
            do_action("fivem_shop_order_product_assigned",$_REQUEST["order"],$item);
            
            $item_completed++;

            if(count($order->get_items()) == $item_completed){
                $order->update_status('completed');
                $order->add_order_note('Your order has been completed',true);
                do_action("fivem_shop_order_complete",$_REQUEST["order"]);
            }
            return array("status" => true);
        }
    }
    return array("status" => false);
    
}
add_action("wp_ajax_completeOrderItem", "completeOrderItem");
add_action("wp_ajax_nopriv_completeOrderItem", "completeOrderItem");

/* -- API MANAGER -- */
function fivem_getOrders_API($data){ // Get orders
    $query = new WC_Order_Query( array(
        'status' => 'wc-processing',
        'return' => 'ids',
    ) );
    $ordersList = $query->get_orders();

    $orders = [];

    foreach($ordersList as $order){
        $order = wc_get_order($order);

        $customer = $order->get_user_id();
        $fivem_account = get_user_meta($customer,"fivem_id");

        $order_items = [];
        $completed = 0;
        foreach($order->get_items() as $item_id => $item){
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $product = $item->get_product(); // see link above to get $product info
            $product_name = $item->get_name();
            $quantity = $item->get_quantity();
            $product_meta = $product->get_meta_data();
            $order_meta = $item->get_meta_data();
            $add = true;
            foreach($order_meta as $meta){
                $meta_name = $meta->key;
                switch($meta_name){
                    case "_fivem_shop_error":
                        $add = !$meta->value;
                        break;
                    case "_fivem_shop_complete_order":
                        $add = !$meta->value;
                        if($meta->value){
                            $completed++;
                        }
                        break;
                }
            }
            if($add){
                array_push($order_items,array(
                    "id" => $product_id,
                    "variation" => $variation_id,
                    "product_name" => $product_name,
                    "product_quantity" => $quantity,
                    "product_meta"=> $product_meta,
                    "order_meta"=> $order_meta
                ));
            }
        }
        $orderInfo = array(
            "order_id" =>$order->get_id(),
            "items" => $order_items,
            "customer" => $customer,
            "fivem_account" => $fivem_account[0],
        );

        if(count($order->get_items()) == $completed){
            $order = wc_get_order($order->ID);
            $order->update_status('completed');
            $order->add_order_note('Your order has been completed',true);
            do_action("fivem_shop_order_complete",$order->ID);
        }
        
        if(count($order_items)){
            array_push($orders,$orderInfo);
        }
    }

    return $orders;
}
add_action('rest_api_init', function(){ // Fivem order API registration
    register_rest_route(PLUGIN_NAME.'/v1', '/getOrderList', array(
      'methods' => 'GET',
      'callback' => 'fivem_getOrders_API',
      'permission_callback' => '__return_true'
    ));
});

function fivem_complete_order_API($data){ // Complete Order
    $order = wc_get_order($data["id"]);
    $order_id = $data["id"];
    $product_completed = $data["product_id"];
    foreach($order->get_items() as $item_id => $item){
        $product_id = $item->get_product_id(); 
        if($product_id == $product_completed){
            if($item->get_meta('_fivem_shop_complete_order'))return array("status" => true);
            $item->update_meta_data('_fivem_shop_complete_order', true);
            $item->save();
            $order->add_order_note('The product: <b>'.$item->get_name().'</b> has been successfully assigned',true);
            do_action("fivem_shop_order_product_assigned",$order_id,$item);
            return array("status" => true);
        }
    }
    return array("status" => false);
}
add_action('rest_api_init', function(){ // Fivem order API registration
    register_rest_route(PLUGIN_NAME.'/v1', '/completeOrder/(?P<id>\d+)/?(?P<product_id>\d+)', array(
      'methods' => 'GET',
      'callback' => 'fivem_complete_order_API',
      'permission_callback' => '__return_true'
    ));
});

function fivem_error_order_API($data){ // Notify error on reedem
    $order = wc_get_order($data["id"]);
    $order_id = $data["id"];
    $product_with_error = $data["product_id"];
    foreach($order->get_items() as $item_id => $item){
        $product_id = $item->get_product_id(); 
        if($product_id == $product_with_error ){
            if($item->get_meta('_fivem_shop_error'))break;
            $item->update_meta_data('_fivem_shop_error', true);
            $save = $item->save();
            $order->add_order_note('The delivery of the product: <b>'.$item->get_name().'</b> was not carried out due to an error, contact the assistance service by opening a <a href="#">ticket</a>',true);
            do_action("fivem_shop_order_product_not_assigned",$order_id,$item);
            return array("status" => $save?true:false);
        }
    }  

    return array("status" => false);
}
add_action('rest_api_init', function(){ // Fivem order API registration
    register_rest_route(PLUGIN_NAME.'/v1', '/orderError/(?P<id>\d+)/(?P<product_id>\d+)', array(
      'methods' => 'GET',
      'callback' => 'fivem_error_order_API',
      'permission_callback' => '__return_true'
    ));
});

/* -- DISCORD INTEGRATION --  */
$fivem_admin_notification_widgets = [
    ["fivem_ds_shop_order_error","Order Error"],
    ["fivem_ds_shop_new_order","New order"],
    ["fivem_ds_shop_order_complete","Order complete"]
];

$fivem_shop_options = get_option('fivem_plugin_options');
$admin_discord_bot = new DiscordMessageManager(); // Admin shop Messages
$community_discord_bot = new DiscordMessageManager(); // Community shop Messages
if($fivem_shop_options["ds_shop_webhook_url"]){
    $admin_discord_bot = new DiscordMessageManager($fivem_shop_options["ds_shop_webhook_url"]);
}
if($fivem_shop_options["ds_notify_webhook_url"]){
    $community_discord_bot = new DiscordMessageManager($fivem_shop_options["ds_notify_webhook_url"]);
}
$ds_footer_preset = new Footer(get_bloginfo('name'), get_site_icon_url());

function ds_notify_new_order($order_id, $order){
    $order = wc_get_order($order_id);
    global $ds_footer_preset;
    global $admin_discord_bot;
    global $community_discord_bot;

    $order_info = new Field(
        "Order id:",
        '\#'.$order_id,
        true
    );

    $order_customer_info = new Field(
        "Customer:",
        $order->get_billing_last_name()." ".$order->get_billing_first_name(),
        true
    );

    $items = "";
    foreach($order->get_items() as $item){
        $items .= "
        **".$item->get_name()."** x".$item->get_quantity()."";
    }
    $order_items_info = new Field(
        "Items:",
        $items,
        false
    );

    $message = new EmbedMessage(
        'New Order: #'.$order_id,
        "A new order has been placed",
        get_bloginfo('wpurl')."/wp-admin/post.php?post=".$order_id."&action=edit",
        null,
        $ds_footer_preset->get(),
        null,
        null,
        null,
        [$order_info->get(),$order_customer_info->get(),$order_items_info->get()]
    );
    
    $admin_discord_bot->send($message->get());
}
add_action('woocommerce_new_order', 'ds_notify_new_order',  1, 2);

function ds_notify_order_complete($order_id){
    $order = wc_get_order($order_id);
    global $ds_footer_preset;
    global $admin_discord_bot;
    global $community_discord_bot;

    $message = new EmbedMessage(
        'Order: #'.$order_id.' completed',
        "The order has been completed, all items have been assigned",
        get_bloginfo('wpurl')."/wp-admin/post.php?post=".$order_id."&action=edit",
        "0BDC84",
        $ds_footer_preset->get(),
        null,
        null,
        null,
        null
    );
    
    $admin_discord_bot->send($message->get());
}
add_action("fivem_shop_order_complete","ds_notify_order_complete",10,1);
function ds_notify_order_item_assigned($order_id,$item){
    $order = wc_get_order($order_id);
    global $ds_footer_preset;
    global $admin_discord_bot;
    global $community_discord_bot;

    $message = new EmbedMessage(
        'Order: #'.$order_id.' - Item '.$item->get_name().' assigned',
        'The item: **'.$item->get_name().'** has been successfully assigned',
        get_bloginfo('wpurl')."/wp-admin/post.php?post=".$order_id."&action=edit",
        "0BDC84",
        $ds_footer_preset->get(),
        null,
        null,
        null,
        null
    );
    
    $admin_discord_bot->send($message->get());
}
add_action("fivem_shop_order_product_assigned","ds_notify_order_item_assigned",10,2);
function ds_notify_order_item_not_assigned($order_id,$item){
    $order = wc_get_order($order_id);
    global $ds_footer_preset;
    global $admin_discord_bot;
    global $community_discord_bot;

    $message = new EmbedMessage(
        'Order: #'.$order_id.' - Item '.$item->get_name().' not assigned',
        'The item: **'.$item->get_name().'** has not been assigned correctly',
        get_bloginfo('wpurl')."/wp-admin/post.php?post=".$order_id."&action=edit",
        "D21F3C",
        $ds_footer_preset->get(),
        null,
        null,
        null,
        null
    );
    
    $admin_discord_bot->send($message->get());
}
add_action("fivem_shop_order_product_not_assigned","ds_notify_order_item_not_assigned",10,2);

/* -- ADMIN PAGE -- */
function fivem_shop_admin_page(){
    add_menu_page('Fivem shop', 'Fivem shop', 'manage_options', 'fivem-shop', 'fivem_shop_admin_page_init', plugins_url(str_replace("-","_",PLUGIN_NAME).'/plugin-logo.svg'),10);
}
function fivem_shop_admin_page_init(){
    ?>
    <div class="fivem_admin_page">
        <div class="fivem_news">
            <img src="<?php echo plugin_dir_url( __FILE__ ) . 'img/banner.svg'; ?>">
            <div class="info">
                <h1>New Fivem shop integration 1.0 Alpha Relased</h1>
                <p>The new wordpress plugin for WooCommerce Shop with auto reedem item in game</p>
                <a href="#">Show more</a>
            </div>
        </div>
        <div class="fivem_plugin_guide">
            <div class="col">
                <img src="<?php echo plugin_dir_url( __FILE__ ) . 'img/settings_icon.png'; ?>">
                <div class="info">
                    <h1>Plugin Settings</h1>
                    <p>See ho configure the Fivem shop integration plugin</p>
                    <a href="#">Show more</a>
                </div>
            </div>
            <div class="col">
                <img src="<?php echo plugin_dir_url( __FILE__ ) . 'img/ds_icon.png'; ?>">
                <div class="info">
                    <h1>Discord Webhook configuration</h1>
                    <p>See how to enable the Discord webhook integration to get notification on your discord server</p>
                    <a href="#">Show more</a>
                </div>
            </div>
            <div class="col">
            <img src="<?php echo plugin_dir_url( __FILE__ ) . 'img/fivem_icon.png'; ?>">
                <div class="info">
                    <h1>Fivem server configuration</h1>
                    <p>How to configure the server script to auto reedem items</p>
                    <a href="#">Show more</a>
                </div>
            </div>
        </div>

        <form action="options.php" method="post">
            <?php settings_fields('fivem_plugin_options'); // Setting Form id ?>
            <div class="fivem_option">
                <?php 
                do_settings_sections('fivem_plugin_settings'); // Option List
                ?>
            </div>
            <input name="submit" class="fivem_settings_save button button-primary" type="submit" value="<?php esc_attr_e( 'Save' ); ?>" />
        </form>
    </div>
    <?php
}
add_action('admin_menu', 'fivem_shop_admin_page');

function fivem_register_settings() {
    register_setting( 'fivem_plugin_options', 'fivem_plugin_options', 'fivem_plugin_options_validate' );
    add_settings_section( 'fivem_ds_webhook_settings', 'Discord Webhook Settings', 'fivem_ds_webhook_title', 'fivem_plugin_settings' );

    add_settings_field( 'fivem_ds_webhook_shop_api_key', 'Order info & status (Admin)', 'fivem_ds_webhook_shop_field', 'fivem_plugin_settings', 'fivem_ds_webhook_settings' );
    add_settings_field( 'fivem_ds_webhook_shop_widgets', 'Enabled notifications (Admin)', 'fivem_ds_webhook_shop_widgets_field', 'fivem_plugin_settings', 'fivem_ds_webhook_settings' );
    add_settings_field( 'fivem_ds_webhook_notify_api_key', 'New order alert (Community)', 'fivem_ds_webhook_notify_field', 'fivem_plugin_settings', 'fivem_ds_webhook_settings' );
}
add_action( 'admin_init', 'fivem_register_settings' );

function fivem_plugin_options_validate($input) {
    global $fivem_admin_notification_widgets;

    // Admin notifications
    $data['ds_shop_webhook_url'] = trim($input['ds_shop_webhook_url']);
    if (!filter_var($data['ds_shop_webhook_url'], FILTER_VALIDATE_URL)){
        $data['ds_shop_webhook_url'] = '';
    }
    // Admin notification widgets
    foreach($fivem_admin_notification_widgets as $item){
        $data[$item[0]] = $input[$item[0]];
    }

    // Community notification
    $data['ds_notify_webhook_url'] = trim($input['ds_notify_webhook_url']);
    if (!filter_var($data['ds_notify_webhook_url'], FILTER_VALIDATE_URL)){
        $data['ds_notify_webhook_url'] = '';
    }

    print_r($input);

    return $data;
}

function fivem_ds_webhook_title() {
    echo '<p>Here you can set the discord webhook url to link your discord server with the notification system</p>';
}

function fivem_ds_webhook_shop_field(){
    $options = get_option('fivem_plugin_options');
    echo "<input id='fivem_ds_shop_webhook_field' name='fivem_plugin_options[ds_shop_webhook_url]' type='text' value='".esc_attr($options['ds_shop_webhook_url'])."'/>";
}
function fivem_ds_webhook_shop_widgets_field() {
    global $fivem_admin_notification_widgets;
    $options = get_option('fivem_plugin_options');
    ?><div class="checklist"><?php
    
    foreach($fivem_admin_notification_widgets as $item){
        ?>
        <div class="checkbox">
            <input type="checkbox" name="fivem_plugin_options[<?=$item[0]?>]" value="1"
            <?php if($options[$item[0]]):?> checked <?php endif;?>>
            <label for="<?=$item[0]?>"><?=$item[1]?></label>
        </div>
        <?php
    }
    ?></div><?php
}
function fivem_ds_webhook_notify_field() {
    $options = get_option('fivem_plugin_options');
    echo "<input id='fivem_ds_notify_webhook_field' name='fivem_plugin_options[ds_notify_webhook_url]' type='text' value='".esc_attr($options['ds_notify_webhook_url'])."'/>";
}
session_write_close();
