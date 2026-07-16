<?php

defined( 'ABSPATH' ) || exit;

/**
 * Builds the bundled Mabotech catalog without crawling the supplier and adds
 * conservative exact FuelEconomy.gov vehicle-ID fitment in saved chunks.
 */
final class Echo_Motorworks_Mabotech_Builder {
    private const CATALOG_STATE_OPTION = 'echo_mabotech_catalog_state_v1';
    private const IMAGE_STATE_OPTION = 'echo_mabotech_image_state_v1';
    private const FITMENT_STATE_OPTION = 'echo_mabotech_fitment_state_v1';
    private const STATE_VERSION = '1';
    private const SOURCE = 'mabotech_exact_builder_v1';
    private const OPTIONS_PER_REQUEST = 2;
    private const BRAND_REPAIR_OPTION = 'echo_mabotech_brand_repair_state_v1';

    private Echo_Motorworks_API $api;
    private Echo_Motorworks_Garage $garage;

    public function __construct( Echo_Motorworks_API $api, Echo_Motorworks_Garage $garage ) {
        $this->api = $api;
        $this->garage = $garage;
        add_action( 'admin_menu', array( $this, 'admin_menu' ), 65 );
        add_action( 'wp_ajax_echo_sync_mabotech_catalog', array( $this, 'ajax_sync_catalog' ) );
        add_action( 'wp_ajax_echo_sync_mabotech_images', array( $this, 'ajax_sync_images' ) );
        add_action( 'wp_ajax_echo_build_mabotech_fitment', array( $this, 'ajax_build_fitment' ) );
        add_action( 'wp_ajax_echo_repair_mabotech_branding', array( $this, 'ajax_repair_branding' ) );
        add_action( 'admin_post_echo_export_mabotech_products', array( $this, 'export_products' ) );
        add_action( 'admin_post_echo_export_mabotech_fitment', array( $this, 'export_fitment' ) );
    }

    public function admin_menu(): void {
        $parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'tools.php';
        add_submenu_page( $parent, 'Mabotech', 'Mabotech', 'manage_woocommerce', 'echo-mabotech', array( $this, 'page' ) );
    }

    public function page(): void {
        ?>
        <div class="wrap">
            <h1>Mabotech Catalog & Exact Fitment</h1>
            <p>This page rebuilds the bundled 32-product Mabotech catalog without crawling the supplier site. Product creation uses local plugin data. Supplier requests occur only in the separate optional image step, one missing image at a time.</p>
            <div class="notice notice-info inline"><p><strong>Low-request design:</strong> catalog creation makes zero Mabotech requests. Image sync makes at most one supplier image request per saved step and skips products that already have an image. Vehicle fitment uses FuelEconomy.gov, not Mabotech.</p></div>

            <h2>1. Create / refresh the 32 Mabotech products</h2>
            <p>
                <button type="button" class="button button-primary" id="echo-mab-catalog">Build / Resume Mabotech Catalog</button>
                <button type="button" class="button" id="echo-mab-catalog-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-mab-catalog-restart">Restart Catalog from Beginning</button>
            </p>
            <p><em>This step uses bundled catalog data and does not contact Mabotech.</em></p>
            <div id="echo-mab-catalog-progress" style="max-width:950px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-mab-catalog-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-mab-catalog-text" style="font-weight:600"></p>
                <textarea id="echo-mab-catalog-log" readonly style="width:100%;min-height:160px;font-family:monospace"></textarea>
            </div>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 22px">
                <input type="hidden" name="action" value="echo_export_mabotech_products">
                <?php wp_nonce_field( 'echo_export_mabotech_products' ); ?>
                <?php submit_button( 'Download Built Mabotech Catalog CSV', 'secondary', 'submit', false ); ?>
            </form>

            <h2>2. Download missing supplier images</h2>
            <p>
                <button type="button" class="button button-primary" id="echo-mab-images">Sync / Resume Missing Images</button>
                <button type="button" class="button" id="echo-mab-images-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-mab-images-restart">Retry All Missing Images</button>
            </p>
            <p><em>One image request per step, with a delay between successful requests. Existing featured images are never re-downloaded.</em></p>
            <div id="echo-mab-images-progress" style="max-width:950px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-mab-images-bar" style="height:100%;width:0;background:#8c8f94;transition:width .2s"></div></div>
                <p id="echo-mab-images-text" style="font-weight:600"></p>
                <textarea id="echo-mab-images-log" readonly style="width:100%;min-height:140px;font-family:monospace"></textarea>
            </div>

            <h2>3. Repair the Mabotech brand field</h2>
            <div class="notice notice-warning inline"><p>The original Mabotech catalog builder used <strong>Mabotech</strong> as a product tag and did not populate the WooCommerce Brands field. This repair assigns <strong>Mabotech</strong> as the proper brand and removes only the redundant supplier-name tag. Category and <strong>Performance Parts</strong> tags stay intact.</p></div>
            <p>
                <button type="button" class="button button-primary" id="echo-mab-brand">Repair Mabotech Brand Assignment</button>
                <button type="button" class="button" id="echo-mab-brand-stop" disabled>Stop</button>
            </p>
            <div id="echo-mab-brand-progress" style="max-width:950px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-mab-brand-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-mab-brand-text" style="font-weight:600"></p>
                <textarea id="echo-mab-brand-log" readonly style="width:100%;min-height:140px;font-family:monospace"></textarea>
            </div>

            <h2>4. Build exact vehicle fitment</h2>
            <p>
                <button type="button" class="button button-primary" id="echo-mab-fitment">Build / Resume Mabotech Exact Fitment</button>
                <button type="button" class="button" id="echo-mab-fitment-stop" disabled>Stop</button>
                <button type="button" class="button" id="echo-mab-fitment-restart">Restart Fitment from Beginning</button>
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:8px 0 18px">
                <input type="hidden" name="action" value="echo_export_mabotech_fitment">
                <?php wp_nonce_field( 'echo_export_mabotech_fitment' ); ?>
                <?php submit_button( 'Download Built Mabotech Exact CSV', 'secondary', 'submit', false ); ?>
            </form>
            <p><em>Exact rows are conservative. Products that depend on an engine code, chassis revision, connector, injector dimensions or an unspecified MLB application stay conditional or needs-review.</em></p>
            <div id="echo-mab-fitment-progress" style="max-width:950px;display:none">
                <div style="height:18px;background:#dcdcde;border-radius:4px;overflow:hidden"><div id="echo-mab-fitment-bar" style="height:100%;width:0;background:#2271b1;transition:width .2s"></div></div>
                <p id="echo-mab-fitment-text" style="font-weight:600"></p>
                <textarea id="echo-mab-fitment-log" readonly style="width:100%;min-height:180px;font-family:monospace"></textarea>
            </div>

            <script>
            jQuery(function($){
                function runner(config){
                    let running=false, stopped=false, retries=0;
                    const $start=$(config.start), $stop=$(config.stop), $restart=$(config.restart), $wrap=$(config.wrap), $bar=$(config.bar), $text=$(config.text), $log=$(config.log);
                    function append(m){ if(!m)return; $log.val($log.val()+m+'\n'); $log.scrollTop($log[0].scrollHeight); }
                    function finish(m){ running=false; $start.prop('disabled',false); $restart.prop('disabled',false); $stop.prop('disabled',true); $text.text(m); append(m); }
                    function request(reset){
                        if(stopped){ finish('Stopped. Saved progress is retained.'); return; }
                        $.ajax({url:ajaxurl,method:'POST',timeout:50000,data:{action:config.action,nonce:config.nonce,reset:reset?1:0}})
                        .done(function(response){
                            if(!response||!response.success){ retry(false,response&&response.data&&response.data.message?response.data.message:'WordPress returned an error.'); return; }
                            retries=0; const d=response.data; const pct=typeof d.progress_pct==='number'?d.progress_pct:0;
                            $bar.css('width',Math.max(0,Math.min(100,pct))+'%'); $text.text(d.progress_text||'Progress saved'); append(d.message);
                            if(d.errors&&d.errors.length)d.errors.forEach(function(e){append('  Warning: '+e);});
                            if(d.done)finish(config.complete); else window.setTimeout(function(){request(false);},config.delay||200);
                        }).fail(function(xhr){ retry(false,xhr&&xhr.status?'HTTP '+xhr.status:'Request failed'); });
                    }
                    function retry(reset,message){
                        if(stopped){finish('Stopped. Saved progress is retained.');return;}
                        if(retries>=3){finish('Paused after repeated request failures. Click Build / Resume to continue from the last saved step.');return;}
                        retries++; const delay=Math.min(10000,1000*Math.pow(2,retries-1)); append(message+' Retrying in '+Math.round(delay/1000)+'s…'); window.setTimeout(function(){request(reset);},delay);
                    }
                    function begin(reset){
                        if(running)return; running=true; stopped=false; retries=0; $start.prop('disabled',true); $restart.prop('disabled',true); $stop.prop('disabled',false); $wrap.show();
                        if(reset){$bar.css('width','0');$log.val('');append('Restarting from the beginning…');}else append('Starting or resuming…'); request(reset);
                    }
                    $start.on('click',function(){begin(false);});
                    $restart.on('click',function(){if(window.confirm(config.confirm||'Restart from the beginning?'))begin(true);});
                    $stop.on('click',function(){stopped=true;$stop.prop('disabled',true);});
                }
                runner({start:'#echo-mab-catalog',stop:'#echo-mab-catalog-stop',restart:'#echo-mab-catalog-restart',wrap:'#echo-mab-catalog-progress',bar:'#echo-mab-catalog-bar',text:'#echo-mab-catalog-text',log:'#echo-mab-catalog-log',action:'echo_sync_mabotech_catalog',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_sync_mabotech_catalog' ) ); ?>,complete:'Mabotech catalog complete: all 32 products are ready.',delay:100,confirm:'Restart catalog progress? Existing SKUs will be refreshed, not duplicated.'});
                runner({start:'#echo-mab-images',stop:'#echo-mab-images-stop',restart:'#echo-mab-images-restart',wrap:'#echo-mab-images-progress',bar:'#echo-mab-images-bar',text:'#echo-mab-images-text',log:'#echo-mab-images-log',action:'echo_sync_mabotech_images',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_sync_mabotech_images' ) ); ?>,complete:'Mabotech image pass complete.',delay:1400,confirm:'Retry all products that still do not have a featured image?'});
                runner({start:'#echo-mab-brand',stop:'#echo-mab-brand-stop',restart:'#echo-mab-brand-restart',wrap:'#echo-mab-brand-progress',bar:'#echo-mab-brand-bar',text:'#echo-mab-brand-text',log:'#echo-mab-brand-log',action:'echo_repair_mabotech_branding',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_repair_mabotech_branding' ) ); ?>,complete:'Mabotech brand repair complete.',delay:200});
                runner({start:'#echo-mab-fitment',stop:'#echo-mab-fitment-stop',restart:'#echo-mab-fitment-restart',wrap:'#echo-mab-fitment-progress',bar:'#echo-mab-fitment-bar',text:'#echo-mab-fitment-text',log:'#echo-mab-fitment-log',action:'echo_build_mabotech_fitment',nonce:<?php echo wp_json_encode( wp_create_nonce( 'echo_build_mabotech_fitment' ) ); ?>,complete:'Mabotech exact-fitment build complete.',delay:180,confirm:'Restart Mabotech fitment from the beginning? Existing rows will be refreshed safely.'});
            });
            </script>
        </div>
        <?php
    }

    public function ajax_sync_catalog(): void {
        $this->authorize_ajax( 'echo_sync_mabotech_catalog' );
        if ( ! class_exists( 'WooCommerce' ) ) wp_send_json_error( array( 'message'=>'WooCommerce is not active.' ), 400 );
        $catalog=$this->catalog(); $total=count($catalog); $reset=!empty($_POST['reset']);
        if($reset)delete_option(self::CATALOG_STATE_OPTION);
        $state=get_option(self::CATALOG_STATE_OPTION,array('version'=>self::STATE_VERSION,'index'=>0,'completed'=>false));
        if(!is_array($state)||(string)($state['version']??'')!==self::STATE_VERSION)$state=array('version'=>self::STATE_VERSION,'index'=>0,'completed'=>false);
        $index=absint($state['index']??0);
        if($index>=$total){$state['completed']=true;update_option(self::CATALOG_STATE_OPTION,$state,false);wp_send_json_success($this->simple_response(true,$total,$total,'Mabotech catalog sync is complete.'));}
        $definition=$catalog[$index]; $result=$this->sync_product($definition); $errors=array();
        if(is_wp_error($result))$errors[]=$definition['sku'].': '.$result->get_error_message();
        $state['index']=$index+1;$state['completed']=$state['index']>=$total;$state['updated_at']=time();update_option(self::CATALOG_STATE_OPTION,$state,false);
        $message=is_wp_error($result)?$definition['sku'].' skipped after a local save error.':sprintf('%s — %s.', $definition['name'], !empty($result['created'])?'created':'refreshed');
        wp_send_json_success($this->simple_response($state['completed'],$state['index'],$total,$message,$errors));
    }

    public function ajax_sync_images(): void {
        $this->authorize_ajax( 'echo_sync_mabotech_images' );
        wp_raise_memory_limit('admin'); if(function_exists('set_time_limit'))@set_time_limit(35);
        $catalog=$this->catalog();$total=count($catalog);$reset=!empty($_POST['reset']);if($reset)delete_option(self::IMAGE_STATE_OPTION);
        $state=get_option(self::IMAGE_STATE_OPTION,array('version'=>self::STATE_VERSION,'index'=>0,'completed'=>false));
        if(!is_array($state)||(string)($state['version']??'')!==self::STATE_VERSION)$state=array('version'=>self::STATE_VERSION,'index'=>0,'completed'=>false);
        $index=absint($state['index']??0);$errors=array();$message='';
        while($index<$total){
            $definition=$catalog[$index];$index++;$product_id=absint(wc_get_product_id_by_sku($definition['sku']));
            if(!$product_id||get_post_thumbnail_id($product_id)||empty($definition['image']))continue;
            $image=$this->sideload_image($definition['image'],$product_id,$definition['name']);
            if(is_wp_error($image)){$errors[]=$definition['sku'].': '.$image->get_error_message();$message=$definition['name'].': image request failed; progress moved forward safely.';}
            else $message=$definition['name'].': featured image downloaded.';
            break;
        }
        $state['index']=$index;$state['completed']=$index>=$total;$state['updated_at']=time();update_option(self::IMAGE_STATE_OPTION,$state,false);
        if(''===$message)$message=$state['completed']?'No additional Mabotech images are pending.':'Skipped products that already had images.';
        wp_send_json_success($this->simple_response($state['completed'],$index,$total,$message,$errors));
    }

    public function ajax_build_fitment(): void {
        $this->authorize_ajax( 'echo_build_mabotech_fitment' );
        wp_raise_memory_limit('admin'); if(function_exists('set_time_limit'))@set_time_limit(45);
        $this->sync_product_scopes();$tasks=$this->tasks();$total=count($tasks);$reset=!empty($_POST['reset']);$state=$this->fitment_state($reset);
        if(!$total||!empty($state['completed'])||(int)$state['task_index']>=$total){$state['completed']=true;$state['task_index']=$total;$this->save_fitment_state($state);wp_send_json_success(array('done'=>true,'completed_tasks'=>$total,'total_tasks'=>$total,'progress_pct'=>100,'progress_text'=>'Complete','message'=>'Mabotech exact-fitment build is complete.','errors'=>array()));}
        $task_index=(int)$state['task_index'];$task=$tasks[$task_index];$year=(int)$task['year'];$make=(string)$task['make'];$profiles=$task['profiles'];
        $models=$this->api->get_menu('model',$year,$make);if(is_wp_error($models))wp_send_json_error(array('message'=>$task['label'].': '.$models->get_error_message()),503);
        $work_models=array();foreach($models as $model_item){$model=sanitize_text_field($model_item['text']??$model_item['value']??'');if(''===$model)continue;$candidate=array_values(array_filter($profiles,fn(array $p):bool=>$this->pattern_matches($p['model_pattern']??'',$model)&&!$this->pattern_matches($p['exclude_model_pattern']??'',$model,false)));if($candidate)$work_models[]=array('model'=>$model,'profiles'=>$candidate);}
        if((int)$state['model_index']>=count($work_models)){$message=$this->complete_task($state,$task,$total);$this->save_fitment_state($state);wp_send_json_success($this->fitment_response($state,$total,$message,array()));}
        $model_index=(int)$state['model_index'];$work=$work_models[$model_index];$model=$work['model'];$candidate_profiles=$work['profiles'];$options=$this->api->get_menu('options',$year,$make,$model);if(is_wp_error($options))wp_send_json_error(array('message'=>$task['label'].' '.$model.': '.$options->get_error_message()),503);
        $option_index=(int)$state['option_index'];if($option_index>=count($options)){$state['model_index']=$model_index+1;$state['option_index']=0;$state['chunk_failures']=0;$message=(int)$state['model_index']>=count($work_models)?$this->complete_task($state,$task,$total):$task['label'].' '.$model.': model complete; moving to the next model.';$this->save_fitment_state($state);wp_send_json_success($this->fitment_response($state,$total,$message,array()));}
        $slice=array_slice($options,$option_index,self::OPTIONS_PER_REQUEST);$matched=array();$fitment_rows=0;$errors=array();
        foreach($slice as $option){$epa_id=sanitize_text_field($option['value']??'');if(''===$epa_id||!ctype_digit($epa_id))continue;$vehicle=$this->api->get_vehicle($epa_id);if(is_wp_error($vehicle)){$state['chunk_failures']=(int)($state['chunk_failures']??0)+1;$this->save_fitment_state($state);if((int)$state['chunk_failures']<3)wp_send_json_error(array('message'=>$task['label'].' '.$model.' #'.$epa_id.': '.$vehicle->get_error_message()),503);$errors[]=$model.' #'.$epa_id.': skipped after three failed detail requests.';$state['chunk_failures']=0;continue;}
            $vehicle_groups=array();$vehicle_notes=array();foreach($candidate_profiles as $profile){if(!$this->vehicle_matches_profile($vehicle,$profile))continue;foreach((array)($profile['groups']??array()) as $group)$vehicle_groups[sanitize_key($group)]=sanitize_key($profile['status']??'conditional');$vehicle_notes[]=sanitize_text_field($profile['label']??'Mabotech application profile');}
            if(empty($vehicle_groups))continue;$internal=$this->garage->upsert_vehicle($vehicle);if(!$internal)continue;$vehicle['id']=$internal;$matched[$epa_id]=true;$state['task_matched_vehicle_ids'][$epa_id]=true;
            foreach($vehicle_groups as $group=>$status){foreach($this->group_products($group) as $sku){$product_id=absint(wc_get_product_id_by_sku($sku));if(!$product_id){$state['task_missing_products'][$sku]=true;continue;}if($this->upsert_fitment($product_id,$vehicle,$status,implode('; ',array_unique($vehicle_notes)))){$fitment_rows++;$state['task_fitment_rows']=(int)($state['task_fitment_rows']??0)+1;}}}
        }
        $processed=count($slice);$state['option_index']=$option_index+$processed;$state['chunk_failures']=0;if($errors)$state['task_errors']=array_values(array_unique(array_merge((array)($state['task_errors']??array()),$errors)));
        $message=sprintf('%s / %s: processed option%s %d–%d of %d; %d matching vehicle%s, %d product link%s.',$task['label'],$model,1===$processed?'':'s',$option_index+1,min(count($options),$option_index+$processed),count($options),count($matched),1===count($matched)?'':'s',$fitment_rows,1===$fitment_rows?'':'s');
        if((int)$state['option_index']>=count($options)){$state['model_index']=$model_index+1;$state['option_index']=0;if((int)$state['model_index']>=count($work_models))$message.=' '.$this->complete_task($state,$task,$total);}
        $this->save_fitment_state($state);wp_send_json_success($this->fitment_response($state,$total,$message,$errors));
    }

    public function export_products(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('You are not allowed to export products.');check_admin_referer('echo_export_mabotech_products');
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=mabotech-products.csv');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
        fputcsv($out,array('SKU','Name','Published','Regular price','Sale price','Stock status','Category','Supplier','Source URL','Image source URL','Fitment type','Fitment raw','Confidence','Reason'));
        foreach($this->catalog() as $d){$id=absint(wc_get_product_id_by_sku($d['sku']));$p=$id?wc_get_product($id):false;fputcsv($out,array($d['sku'],$d['name'],$p&&'publish'===$p->get_status()?1:0,$p?$p->get_regular_price():$d['price'],$p?$p->get_sale_price():$d['sale_price'],$p?$p->get_stock_status():'',$d['category'],'Mabotech',$d['source'],$d['image'],$d['scope_type'],$d['scope_raw'],$d['confidence'],$d['reason']));}
        fclose($out);exit;
    }

    public function export_fitment(): void {
        if(!current_user_can('manage_woocommerce'))wp_die('You are not allowed to export fitment.');check_admin_referer('echo_export_mabotech_fitment');global $wpdb;$fitment=Echo_Motorworks_DB::fitment_table();$vehicles=Echo_Motorworks_DB::vehicles_table();$rows=$wpdb->get_results($wpdb->prepare("SELECT f.*,v.source AS vehicle_source,v.source_vehicle_id FROM {$fitment} f LEFT JOIN {$vehicles} v ON v.id=f.vehicle_id WHERE f.source=%s ORDER BY f.product_id,f.year_start,f.make,f.model,v.source_vehicle_id",self::SOURCE),ARRAY_A);
        nocache_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename=mabotech-fitment.csv');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");$headers=array('product_id','product_sku','vehicle_source','source_vehicle_id','year_start','year_end','make','model','submodel','generation','chassis','engine','engine_code','transmission','drivetrain','body_style','status','notes','supplier','source');fputcsv($out,$headers);
        foreach($rows as $r)fputcsv($out,array($r['product_id'],get_post_meta((int)$r['product_id'],'_sku',true),$r['vehicle_source']?:'epa',$r['source_vehicle_id'],$r['year_start'],$r['year_end'],$r['make'],$r['model'],$r['submodel'],$r['generation'],$r['chassis'],$r['engine'],$r['engine_code'],$r['transmission'],$r['drivetrain'],$r['body_style'],$r['fitment_status'],$r['fitment_notes'],$r['supplier'],'https://mabotech.net/'));
        fclose($out);exit;
    }

    private function authorize_ajax(string $nonce):void{if(!current_user_can('manage_woocommerce'))wp_send_json_error(array('message'=>'You are not allowed to run this builder.'),403);check_ajax_referer($nonce,'nonce');}
    private function catalog():array{$path=ECHO_MOTORWORKS_CORE_DIR.'data/mabotech-catalog.json';if(!is_readable($path))return array();$data=json_decode((string)file_get_contents($path),true);return is_array($data)?$data:array();}
    private function simple_response(bool $done,int $completed,int $total,string $message,array $errors=array()):array{$pct=$total?round((min($completed,$total)/$total)*100,2):100;return array('done'=>$done,'completed_tasks'=>min($completed,$total),'total_tasks'=>$total,'progress_pct'=>$pct,'progress_text'=>$done?'Complete':sprintf('Completed %d of %d — progress saved',min($completed,$total),$total),'message'=>$message,'errors'=>array_slice($errors,0,5));}

    private function sync_product(array $d){
        $existing=absint(wc_get_product_id_by_sku($d['sku']));$created=!$existing;$product=$existing?wc_get_product($existing):new WC_Product_Simple();if(!$product||!is_a($product,'WC_Product'))$product=new WC_Product_Simple($existing);
        $product->set_name($d['name']);if(!$existing)$product->set_sku($d['sku']);$product->set_status('publish');$product->set_catalog_visibility('visible');$product->set_regular_price((string)$d['price']);$product->set_sale_price((string)($d['sale_price']??''));$product->set_price(''!==($d['sale_price']??'')?(string)$d['sale_price']:(string)$d['price']);
        $short=sprintf('Mabotech %s performance product. Verify the exact engine, chassis and installation configuration before purchase.',strtolower((string)$d['category']));$desc='<p><strong>'.esc_html($d['name']).'</strong> from Mabotech Labs.</p><p>Catalog data and public retail pricing were archived from the supplier catalog. Professional installation and application-specific tuning may be required.</p><p><strong>Compatibility:</strong> '.esc_html($d['scope_raw']).'</p><p><strong>Important:</strong> Confirm current inventory, warranty terms, dealer/MAP requirements and the exact vehicle or engine configuration before fulfillment.</p><p>Manufacturer reference: <a href="'.esc_url($d['source']).'" rel="nofollow">'.esc_html($d['source']).'</a></p>';
        $product->set_short_description($short);$product->set_description($desc);$product->set_manage_stock(false);$product->set_stock_status('instock');$product->set_reviews_allowed(true);
        $parent=$this->term_id('Mabotech Labs','product_cat');$category=$this->term_id($d['category'],'product_cat',$parent);if($category)$product->set_category_ids(array($category));$tags=array_filter(array($this->term_id('Performance Parts','product_tag'),$this->term_id($d['category'],'product_tag')));if($tags)$product->set_tag_ids(array_values($tags));
        $id=$product->save();if(!$id)return new WP_Error('mabotech_save','WooCommerce could not save the product.');
        $this->assign_brand($id);update_post_meta($id,'_echo_supplier','Mabotech');update_post_meta($id,'_echo_manufacturer','Mabotech Labs');update_post_meta($id,'_echo_brand','Mabotech');update_post_meta($id,'_echo_source_url',esc_url_raw($d['source']));update_post_meta($id,'_echo_source_checked','2026-07-10');update_post_meta($id,'_echo_mabotech_image_url',esc_url_raw($d['image']));
        update_post_meta($id,'_echo_fitment_type',sanitize_key($d['scope_type']));update_post_meta($id,'_echo_fitment_raw',sanitize_textarea_field($d['scope_raw']));update_post_meta($id,'_echo_fitment_confidence',sanitize_key($d['confidence']));update_post_meta($id,'_echo_fitment_reason',sanitize_textarea_field($d['reason']));wc_delete_product_transients($id);return array('created'=>$created,'product_id'=>$id);
    }
    private function term_id(string $name,string $taxonomy,int $parent=0):int{if(!taxonomy_exists($taxonomy))return 0;$term=term_exists($name,$taxonomy,$parent);if(!$term)$term=wp_insert_term($name,$taxonomy,array('parent'=>$parent));if(is_wp_error($term))return 0;return absint(is_array($term)?$term['term_id']:$term);}
    private function brand_taxonomy():string{foreach(array('product_brand','pwb-brand','yith_product_brand','berocket_brand') as $taxonomy)if(taxonomy_exists($taxonomy))return $taxonomy;return '';}
    private function assign_brand(int $product_id):bool{$taxonomy=$this->brand_taxonomy();if(!$taxonomy)return false;$brand_id=$this->term_id('Mabotech',$taxonomy,0);if(!$brand_id)return false;$result=wp_set_object_terms($product_id,array($brand_id),$taxonomy,false);if(is_wp_error($result))return false;if(taxonomy_exists('product_tag')){foreach(array('Mabotech','Mabotech Labs') as $old_tag){$term=term_exists($old_tag,'product_tag');if($term)wp_remove_object_terms($product_id,absint(is_array($term)?$term['term_id']:$term),'product_tag');}}return true;}

    public function ajax_repair_branding():void{
        $this->authorize_ajax('echo_repair_mabotech_branding');
        global $wpdb;
        $taxonomy=$this->brand_taxonomy();
        if(!$taxonomy)wp_send_json_error(array('message'=>'No WooCommerce product-brand taxonomy is active.'),400);
        $state=get_option(self::BRAND_REPAIR_OPTION,array());
        if(!is_array($state)||empty($state['total'])||!empty($state['completed'])){
            $total=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type='product' AND pm.meta_key=%s AND pm.meta_value=%s",'_echo_supplier','Mabotech'));
            $state=array('last_id'=>0,'processed'=>0,'total'=>$total,'completed'=>false);
        }
        $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type='product' AND pm.meta_key=%s AND pm.meta_value=%s AND p.ID>%d ORDER BY p.ID ASC LIMIT 50",'_echo_supplier','Mabotech',(int)$state['last_id']));
        if(empty($ids)){
            $state['completed']=true;update_option(self::BRAND_REPAIR_OPTION,$state,false);
            wp_send_json_success(array('done'=>true,'progress_pct'=>100,'progress_text'=>sprintf('%d / %d products repaired',(int)$state['processed'],(int)$state['total']),'message'=>'Mabotech brand repair finished.'));
        }
        $repaired=0;
        foreach(array_map('absint',$ids) as $product_id){
            if($this->assign_brand($product_id)){update_post_meta($product_id,'_echo_manufacturer','Mabotech Labs');update_post_meta($product_id,'_echo_brand','Mabotech');wc_delete_product_transients($product_id);clean_post_cache($product_id);$repaired++;}
            $state['last_id']=max((int)$state['last_id'],$product_id);$state['processed']++;
        }
        update_option(self::BRAND_REPAIR_OPTION,$state,false);
        $total=max(1,(int)$state['total']);$pct=min(100,round(100*(int)$state['processed']/$total,1));
        wp_send_json_success(array('done'=>false,'progress_pct'=>$pct,'progress_text'=>sprintf('%d / %d products repaired',(int)$state['processed'],(int)$state['total']),'message'=>sprintf('Assigned Mabotech as the brand on %d products in this batch.',$repaired)));
    }
    private function sideload_image(string $url,int $product_id,string $description){require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/image.php';$id=media_sideload_image(esc_url_raw($url),$product_id,sanitize_text_field($description),'id');if(is_wp_error($id))return $id;set_post_thumbnail($product_id,absint($id));return absint($id);}

    private function fitment_state(bool $reset):array{if($reset)delete_option(self::FITMENT_STATE_OPTION);$s=get_option(self::FITMENT_STATE_OPTION,array());if(!is_array($s)||(string)($s['version']??'')!==self::STATE_VERSION){$s=array('version'=>self::STATE_VERSION,'task_index'=>0,'model_index'=>0,'option_index'=>0,'task_matched_vehicle_ids'=>array(),'task_fitment_rows'=>0,'task_missing_products'=>array(),'task_errors'=>array(),'chunk_failures'=>0,'completed'=>false,'updated_at'=>time());$this->save_fitment_state($s);}return $s;}
    private function save_fitment_state(array $s):void{$s['updated_at']=time();update_option(self::FITMENT_STATE_OPTION,$s,false);}
    private function complete_task(array &$s,array $task,int $total):string{$matched=count((array)($s['task_matched_vehicle_ids']??array()));$rows=(int)($s['task_fitment_rows']??0);Echo_Motorworks_DB::log('info','mabotech_fitment_builder','Mabotech exact-fitment task completed.',array('task'=>$task['label'],'matched_vehicles'=>$matched,'fitment_rows'=>$rows,'missing_products'=>array_keys((array)($s['task_missing_products']??array())),'errors'=>array_slice((array)($s['task_errors']??array()),0,10)));$s['task_index']=(int)$s['task_index']+1;$s['model_index']=0;$s['option_index']=0;$s['task_matched_vehicle_ids']=array();$s['task_fitment_rows']=0;$s['task_missing_products']=array();$s['task_errors']=array();$s['chunk_failures']=0;$s['completed']=(int)$s['task_index']>=$total;return sprintf('Completed %s: %d exact vehicle%s and %d product link%s.',$task['label'],$matched,1===$matched?'':'s',$rows,1===$rows?'':'s');}
    private function fitment_response(array $s,int $total,string $message,array $errors):array{$completed=min($total,(int)$s['task_index']);$pct=$total?round(($completed/$total)*100,2):100;return array('done'=>!empty($s['completed']),'completed_tasks'=>$completed,'total_tasks'=>$total,'progress_pct'=>$pct,'progress_text'=>!empty($s['completed'])?'Complete':sprintf('Completed %d of %d year/make tasks — progress saved',$completed,$total),'message'=>$message,'errors'=>array_slice($errors,0,5));}
    private function tasks():array{$grouped=array();foreach($this->profiles() as $p)for($year=absint($p['year_start']);$year<=absint($p['year_end']);$year++){$key=$year.'|'.$p['make'];if(!isset($grouped[$key]))$grouped[$key]=array('year'=>$year,'make'=>$p['make'],'profiles'=>array(),'label'=>$year.' '.$p['make']);$grouped[$key]['profiles'][]=$p;}ksort($grouped,SORT_NATURAL);return array_values($grouped);}

    private function profiles():array{
        $profiles=array();$add=static function(array $p)use(&$profiles):void{$p+=array('engine_pattern'=>'','transmission_pattern'=>'','fuel_pattern'=>'~Gasoline~i','drive_pattern'=>'','exclude_pattern'=>'','exclude_model_pattern'=>'','status'=>'conditional');$profiles[]=$p;};
        $v8_4='~8 cyl 4\\.0L~i';$i5_25='~5 cyl 2\\.5L~i';$i4_20='~4 cyl 2\\.0L~i';
        foreach(array(array('S6','~^S6(?:\\b|$)~i',2013,2018),array('S7','~^S7(?:\\b|$)~i',2013,2018),array('RS7','~^RS ?7(?:\\b|$)~i',2014,2018)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$v8_4,'groups'=>array('c7_watercore','audi_4t_injector'),'label'=>'Audi '.$m[0].' C7/C7.5 4.0T','status'=>'confirmed'));
        foreach(array(array('A8','~^A8(?: L)?(?:\\b|$)~i'),array('S8','~^S8(?:\\b|$)~i')) as $m)$add(array('year_start'=>2012,'year_end'=>2018,'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$v8_4,'groups'=>array('audi_4t_injector'),'label'=>'Audi '.$m[0].' D4 4.0T injector-harness application','status'=>'conditional'));
        foreach(array(array('RS3','~^RS ?3(?:\\b|$)~i',2017,2025),array('TT RS','~^TT RS(?:\\b|$)~i',2018,2023)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i5_25,'groups'=>array('daza_dnwa'),'label'=>'Audi '.$m[0].' EA855 EVO / DAZA-DNWA 2.5T','status'=>'confirmed'));
        $add(array('year_start'=>2017,'year_end'=>2025,'make'=>'Audi','model_pattern'=>'~^RS ?3(?:\\b|$)~i','engine_pattern'=>$i5_25,'groups'=>array('rs3_body'),'label'=>'Audi RS3 MQB body/intercooler application','status'=>'confirmed'));
        foreach(array(array('GTI','~^(?:Golf )?GTI(?:\\b|$)~i',2006,2008),array('Golf R','~^Golf R(?:\\b|$)~i',2012,2013),array('Jetta GLI','~^Jetta.*GLI|^GLI(?:\\b|$)~i',2006,2008)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Volkswagen','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('ea113'),'label'=>'Volkswagen '.$m[0].' 2.0T EA113 — verify engine code','status'=>'conditional'));
        foreach(array(array('A3','~^A3(?:\\b|$)~i',2006,2008),array('A4','~^A4(?:\\b|$)~i',2005,2008),array('TT','~^TT(?: Roadster)?(?:\\b|$)~i',2008,2009)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('ea113'),'label'=>'Audi '.$m[0].' 2.0T EA113 — verify engine code','status'=>'conditional'));
        foreach(array(array('GTI','~^(?:Golf )?GTI(?:\\b|$)~i',2006,2014),array('Golf R','~^Golf R(?:\\b|$)~i',2012,2013),array('Jetta GLI','~^Jetta.*GLI|^GLI(?:\\b|$)~i',2006,2014)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Volkswagen','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('vw_mk5_mk6'),'label'=>'Volkswagen Mk5/Mk6 '.$m[0].' intercooler application','status'=>'conditional'));
        foreach(array(array('GTI','~^(?:Golf )?GTI(?:\\b|$)~i'),array('Golf R','~^Golf R(?:\\b|$)~i')) as $m)$add(array('year_start'=>2015,'year_end'=>2021,'make'=>'Volkswagen','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('vw_mk7','mqb_ea888','ea888_conditional'),'label'=>'Volkswagen Mk7 '.$m[0].' MQB 2.0T','status'=>'conditional'));
        $add(array('year_start'=>2019,'year_end'=>2021,'make'=>'Volkswagen','model_pattern'=>'~^Jetta.*GLI|^GLI(?:\\b|$)~i','engine_pattern'=>$i4_20,'groups'=>array('mqb_ea888','ea888_conditional'),'label'=>'Volkswagen Jetta GLI MQB 2.0T','status'=>'conditional'));
        foreach(array(array('A3','~^A3(?:\\b|$)~i'),array('S3','~^S3(?:\\b|$)~i')) as $m)$add(array('year_start'=>2015,'year_end'=>2020,'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('mqb_ea888','ea888_conditional'),'label'=>'Audi 8V '.$m[0].' MQB 2.0T','status'=>'conditional'));
        foreach(array(array('TT','~^TT(?: Roadster)?(?:\\b|$)~i'),array('TTS','~^TTS(?:\\b|$)~i')) as $m)$add(array('year_start'=>2016,'year_end'=>2021,'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('mqb_ea888','ea888_conditional'),'label'=>'Audi 8S '.$m[0].' MQB 2.0T','status'=>'conditional'));
        foreach(array(array('GTI','~^(?:Golf )?GTI(?:\\b|$)~i'),array('Golf R','~^Golf R(?:\\b|$)~i')) as $m)$add(array('year_start'=>2022,'year_end'=>2025,'make'=>'Volkswagen','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('mqb_mk8'),'label'=>'Volkswagen Mk8 '.$m[0].' 2.0T','status'=>'confirmed'));
        foreach(array(array('A3','~^A3(?:\\b|$)~i'),array('S3','~^S3(?:\\b|$)~i')) as $m)$add(array('year_start'=>2022,'year_end'=>2025,'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('mqb_mk8'),'label'=>'Audi 8Y '.$m[0].' 2.0T','status'=>'confirmed'));
        foreach(array(array('GTI','~^(?:Golf )?GTI(?:\\b|$)~i',2009,2021),array('Golf R','~^Golf R(?:\\b|$)~i',2015,2021),array('Jetta GLI','~^Jetta.*GLI|^GLI(?:\\b|$)~i',2009,2021),array('Tiguan','~^Tiguan(?:\\b|$)~i',2009,2021)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Volkswagen','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('ea888_conditional'),'label'=>'Volkswagen '.$m[0].' EA888-family 2.0T — verify generation/engine code','status'=>'conditional'));
        foreach(array(array('A3','~^A3(?:\\b|$)~i',2009,2020),array('TT','~^TT(?: Roadster)?(?:\\b|$)~i',2009,2021)) as $m)$add(array('year_start'=>$m[2],'year_end'=>$m[3],'make'=>'Audi','model_pattern'=>$m[1],'engine_pattern'=>$i4_20,'groups'=>array('ea888_conditional'),'label'=>'Audi '.$m[0].' EA888-family 2.0T — verify generation/engine code','status'=>'conditional'));
        return $profiles;
    }
    private function group_products(string $group):array{$groups=array();foreach($this->catalog() as $d)foreach((array)($d['groups']??array()) as $g)$groups[sanitize_key($g)][]=$d['sku'];return array_values(array_unique($groups[$group]??array()));}
    private function sync_product_scopes():void{foreach($this->catalog() as $d){$id=absint(wc_get_product_id_by_sku($d['sku']));if(!$id)continue;update_post_meta($id,'_echo_fitment_type',sanitize_key($d['scope_type']));update_post_meta($id,'_echo_fitment_raw',sanitize_textarea_field($d['scope_raw']));update_post_meta($id,'_echo_fitment_confidence',sanitize_key($d['confidence']));update_post_meta($id,'_echo_fitment_reason',sanitize_textarea_field($d['reason']));}}
    private function pattern_matches(string $pattern,string $value,bool $blank_matches=true):bool{if(''===$pattern)return $blank_matches;return 1===@preg_match($pattern,$value);}
    private function vehicle_matches_profile(array $v,array $p):bool{if(!$this->pattern_matches($p['transmission_pattern']??'',(string)($v['transmission']??'')))return false;if(!$this->pattern_matches($p['engine_pattern']??'',(string)($v['engine']??'')))return false;if(!$this->pattern_matches($p['fuel_pattern']??'',(string)($v['fuel_type']??'')))return false;if(!$this->pattern_matches($p['drive_pattern']??'',(string)($v['drivetrain']??'')))return false;$combined=implode(' | ',array($v['model']??'',$v['engine']??'',$v['transmission']??'',$v['drivetrain']??'',$v['fuel_type']??'',$v['option_label']??''));if($this->pattern_matches($p['exclude_pattern']??'',$combined,false))return false;return true;}
    private function upsert_fitment(int $product_id,array $v,string $status,string $notes):bool{global $wpdb;$table=Echo_Motorworks_DB::fitment_table();$vehicle_id=absint($v['id']??0);$source_vehicle_id=sanitize_text_field($v['source_vehicle_id']??'');if(!$vehicle_id||''===$source_vehicle_id)return false;$status=in_array($status,array('confirmed','conditional'),true)?$status:'conditional';$source_key=hash('sha256',implode('|',array($product_id,$vehicle_id,'epa',$source_vehicle_id,self::SOURCE)));$now=current_time('mysql',true);$data=array('product_id'=>$product_id,'vehicle_id'=>$vehicle_id,'year_start'=>absint($v['year']??0),'year_end'=>absint($v['year']??0),'make'=>sanitize_text_field($v['make']??''),'model'=>sanitize_text_field($v['model']??''),'submodel'=>sanitize_text_field($v['submodel']??''),'generation'=>sanitize_text_field($v['generation']??''),'chassis'=>sanitize_text_field($v['chassis']??''),'engine'=>sanitize_text_field($v['engine']??''),'engine_code'=>sanitize_text_field($v['engine_code']??''),'transmission'=>sanitize_text_field($v['transmission']??''),'drivetrain'=>sanitize_text_field($v['drivetrain']??''),'body_style'=>sanitize_text_field($v['body_style']??''),'normalized_make'=>Echo_Motorworks_DB::normalize((string)($v['make']??'')),'normalized_model'=>Echo_Motorworks_DB::normalize((string)($v['model']??'')),'normalized_engine'=>Echo_Motorworks_DB::normalize((string)($v['engine']??'')),'normalized_submodel'=>Echo_Motorworks_DB::normalize((string)($v['submodel']??'')),'normalized_transmission'=>Echo_Motorworks_DB::normalize((string)($v['transmission']??'')),'normalized_drivetrain'=>Echo_Motorworks_DB::normalize((string)($v['drivetrain']??'')),'fitment_status'=>$status,'fitment_notes'=>sanitize_textarea_field('Mabotech exact EPA fitment. '.$notes.'. Confirm engine code, chassis generation, installation hardware and calibration before fulfillment.'),'supplier'=>'Mabotech','source'=>self::SOURCE,'source_key'=>$source_key,'updated_at'=>$now);$existing=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source_key=%s",$source_key));if($existing)return false!==$wpdb->update($table,$data,array('id'=>$existing));$data['created_at']=$now;return(bool)$wpdb->insert($table,$data);}
}
