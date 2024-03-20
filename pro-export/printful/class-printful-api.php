<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

if( !class_exists('FPD_Export_Printful_Api') ) {

    class FPD_Export_Printful_Api {

        public $pf_client = null;
        private $api_url = 'https://api.printful.com/';

        public function call( $action, $request=null ){

            $region = get_option( 'fpd_printful_region', 'US' );

            if( $action == 'get_products') {
                
                return $this->jwt_request('products');

            }
            else if( $action == 'get_product' && isset($request['product_id']) ) {

                $product_id = $request['product_id'];
                $include_colors = isset($request['include_colors']) ? $request['include_colors'] : null;
                $include_sizes = isset($request['include_sizes']) ? $request['include_sizes'] : null;

                $product = $this->jwt_request("products/$product_id");
                $templates = $this->jwt_request("mockup-generator/templates/$product_id");
                $print_files = $this->jwt_request("mockup-generator/printfiles/$product_id");

                $template_variants = $templates['variant_mapping'];
                $template_designs = $templates['templates'];

                $available_variants = array();
                foreach($product['variants'] as $key => $variant) {

                    //only include variants with requested availability_regions
                    if( !empty($region) && $region != 'worldwide' ) {
                        if( !array_key_exists($region, $product['variants'][$key]['availability_regions']) )
                            continue;
                    }

                    //only include variants with requested color codes
                    if( is_array($include_colors) && !in_array( $variant['color_code'], $include_colors) )
                        continue;

                    //only include variants with requested sizes
                    if( is_array($include_sizes) && !in_array( $variant['size'], $include_sizes) )
                        continue;

                    //find templates by variant id
                    $variant_template_item = $this->findInArrayObject(
                        $template_variants,
                        'variant_id',
                        $variant['id']
                    );

                    //find printfiles by variant id
                    $variant_printfiles = $this->findInArrayObject(
                        $print_files['variant_printfiles'],
                        'variant_id',
                        $variant['id']
                    );

                    $variant['templates'] = array();
                    foreach($variant_template_item['templates'] as $template) {

                        //the template view key (default, front back....)
                        $template_view_key = $template['placement'];

                        //skip label_outside and preview
                        if( $template_view_key == 'label_inside' || $template_view_key == 'label_outside' || $template_view_key == 'preview' )
                            continue;

                        //get template data for every view
                        $template_data = $this->findInArrayObject($template_designs, 'template_id', $template['template_id']);

                        //store placement type
                        $template_data['type'] = $template_view_key;

                        //store color code for background
                        if( isset($variant['color_code']) )
                            $template_data['color_code'] = $variant['color_code'];

                        //getting correct name for view
                        $template_data['name'] = $print_files['available_placements'][$template_view_key];

                        $file_data = $this->findInArrayObject($product['product']['files'], 'type', $template_view_key);
                        $template_data['additional_price'] = $file_data['additional_price'];
                        
                        //getting print file id
                        $printfile_id = $variant_printfiles['placements'][$template_view_key];

                        $printfile_data = $this->findInArrayObject($print_files['printfiles'], 'printfile_id', $printfile_id);
                        $template_data['printfile'] = $printfile_data;

                        array_push($variant['templates'], $template_data);
                    }

                    array_push($available_variants, $variant);

                }

                $product['variants'] = $available_variants;

                return $product;

            }
            else if( $action == 'create_order'  && isset($request['order_data']) ) {

                return $this->jwt_request('orders', $request['order_data'] );

            }
            else if( $action == 'update_order' && isset($request['order_id'])  && isset($request['order_data']) ) {

                return $this->jwt_request(
                    'orders/'.$request['order_id'],
                     $request['order_data'],
                     true
                );

            }


        }

        private function findInArrayObject($arr, $key, $val) {

            $index = array_search($val, array_column($arr, $key));

            if( $index !== false)
                return $arr[$index];

            return null;
        }
        
        private function jwt_request( $endpoint, $post_data=null, $put=false) {
            
            $token = get_option( 'fpd_printful_api_key', '' );
            $url = $this->api_url . $endpoint;
            
            header('Content-Type: application/json');
            $ch = curl_init($url);
            $authorization = "Authorization: Bearer ".$token;
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json' , $authorization ));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            
            if( !empty($post_data) ) {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $put ? 'PUT' : 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post_data));
            }
            
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
            $result = curl_exec($ch);
            curl_close($ch);
            
            $json_arr = json_decode($result, true);
            
            if( isset($json_arr['code']) ) {
                return $json_arr['code'] == 200 ? $json_arr['result'] : array( 'error' => $json_arr['error'] );
            }
            else {
                return array('error' => 'API can not be reached');
            }
            
        }

    }

}