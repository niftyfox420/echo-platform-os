<?php

defined( 'ABSPATH' ) || exit;

/**
 * Echo Platform OS supplier sync pipeline.
 * Fetches configured public/API feeds in background jobs, normalizes records,
 * creates a safe preview, and routes catalog changes into Review Center.
 */
final class Echo_Platform_Sync_Engine {
    private const MAP_OPTION = 'echo_platform_sync_mappings_v1';

    public function __construct() {
        add_action( 'admin_post_echo_sync_build_preview', array( $this, 'request_preview' ) );
        add_action( 'admin_post_echo_sync_apply_preview', array( $this, 'apply_preview' ) );
        add_action( 'admin_post_echo_sync_save_mapping', array( $this, 'save_mapping' ) );
        add_action( 'admin_post_echo_sync_upload_feed', array( $this, 'upload_feed' ) );
        add_action( 'echo_os_run_job_supplier_preview', array( $this, 'run_preview_job' ), 10, 2 );
        add_action( 'echo_os_run_job_supplier_apply', array( $this, 'run_apply_job' ), 10, 2 );
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_sync_runs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            supplier varchar(190) NOT NULL,
            source_type varchar(40) NOT NULL,
            source_url text NULL,
            status varchar(30) NOT NULL DEFAULT 'queued',
            total_items int unsigned NOT NULL DEFAULT 0,
            new_items int unsigned NOT NULL DEFAULT 0,
            changed_items int unsigned NOT NULL DEFAULT 0,
            unchanged_items int unsigned NOT NULL DEFAULT 0,
            error_items int unsigned NOT NULL DEFAULT 0,
            summary longtext NULL,
            created_at datetime NOT NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id), KEY supplier (supplier), KEY status (status)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_sync_preview (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            run_id bigint unsigned NOT NULL,
            supplier varchar(190) NOT NULL,
            item_key varchar(190) NOT NULL,
            product_id bigint unsigned NULL,
            change_type varchar(40) NOT NULL,
            confidence decimal(5,2) NOT NULL DEFAULT 100,
            status varchar(30) NOT NULL DEFAULT 'pending',
            source_record longtext NULL,
            before_value longtext NULL,
            after_value longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY run_id (run_id), KEY supplier (supplier), KEY status (status), KEY item_key (item_key)
        ) $charset;" );
    }

    public static function mapping( string $supplier ): array {
        $all = get_option( self::MAP_OPTION, array() );
        $all = is_array( $all ) ? $all : array();
        return wp_parse_args( $all[ $supplier ] ?? array(), array(
            'name' => 'name', 'sku' => 'sku', 'part_number' => 'part_number',
            'price' => 'price', 'sale_price' => 'sale_price', 'stock' => 'stock_status',
            'description' => 'description', 'short_description' => 'short_description',
            'image' => 'image_url', 'brand' => 'brand', 'category' => 'category',
            'fitment' => 'fitment_raw', 'source_url' => 'source_url',
        ) );
    }

    public function save_mapping(): void {
        $this->guard( 'echo_sync_save_mapping' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        if ( ! $supplier ) $this->redirect( 'mapping', 'Choose a supplier.', 'error' );
        $allowed = array( 'name','sku','part_number','price','sale_price','stock','description','short_description','image','brand','category','fitment','source_url' );
        $mapping = array();
        foreach ( $allowed as $target ) $mapping[ $target ] = sanitize_key( wp_unslash( $_POST['mapping'][ $target ] ?? '' ) );
        $all = get_option( self::MAP_OPTION, array() ); $all = is_array($all)?$all:array(); $all[$supplier]=$mapping;
        update_option( self::MAP_OPTION, $all, false );
        Echo_Platform_OS::emit( 'mapping_saved', 'Saved field mapping for ' . $supplier, array( 'supplier' => $supplier ) );
        $this->redirect( 'mapping', 'Field mapping saved.', 'success', array( 'supplier'=>$supplier ) );
    }

    public function request_preview(): void {
        $this->guard( 'echo_sync_build_preview' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        if ( ! $supplier ) $this->redirect( 'sync', 'Choose a supplier.', 'error' );
        $run_id = $this->create_run( $supplier, 'connection', '' );
        Echo_Platform_OS::queue_job( 'supplier_preview', array( 'supplier'=>$supplier, 'run_id'=>$run_id ), 'Building supplier preview' );
        $this->redirect( 'previews', 'Preview queued. Process Background Jobs if it does not start automatically.', 'success', array('supplier'=>$supplier) );
    }

    public function upload_feed(): void {
        $this->guard( 'echo_sync_upload_feed' );
        $supplier = sanitize_key( $_POST['supplier'] ?? '' );
        if ( ! $supplier || empty($_FILES['feed_file']['tmp_name']) || ! is_uploaded_file($_FILES['feed_file']['tmp_name']) ) $this->redirect('sync','Choose a supplier and feed file.','error');
        $ext = strtolower( pathinfo( sanitize_file_name($_FILES['feed_file']['name']), PATHINFO_EXTENSION ) );
        if ( ! in_array($ext,array('csv','json','xml'),true) ) $this->redirect('sync','Use CSV, JSON, or XML.','error');
        $uploads=wp_upload_dir(); $dir=trailingslashit($uploads['basedir']).'echo-sync'; wp_mkdir_p($dir);
        $path=$dir.'/'.time().'-'.wp_generate_password(6,false,false).'-'.sanitize_file_name($_FILES['feed_file']['name']);
        if(!move_uploaded_file($_FILES['feed_file']['tmp_name'],$path)) $this->redirect('sync','Could not store the uploaded feed.','error');
        $run_id=$this->create_run($supplier,$ext,$path);
        Echo_Platform_OS::queue_job('supplier_preview',array('supplier'=>$supplier,'run_id'=>$run_id,'file'=>$path,'type'=>$ext),'Reading manual supplier feed');
        $this->redirect('previews','Manual feed uploaded and queued for preview.','success',array('supplier'=>$supplier));
    }

    public function run_preview_job( int $job_id, array $payload ): void {
        global $wpdb;
        $run_id=absint($payload['run_id']??0); $supplier=sanitize_key($payload['supplier']??'');
        if(!$run_id||!$supplier) throw new RuntimeException('Invalid supplier preview job.');
        $wpdb->update($wpdb->prefix.'echo_sync_runs',array('status'=>'running'),array('id'=>$run_id));
        $records = !empty($payload['file']) ? $this->read_file($payload['file'],sanitize_key($payload['type']??'')) : $this->fetch_connection($supplier);
        if(!$records) throw new RuntimeException('No product records were returned by the supplier source.');
        $mapping=self::mapping($supplier); $counts=array('new'=>0,'changed'=>0,'unchanged'=>0,'error'=>0); $limit=5000;
        foreach(array_slice($records,0,$limit) as $record){
            if(!is_array($record)){ $counts['error']++; continue; }
            $item=$this->normalize($record,$mapping); $sku=trim((string)$item['sku']); $part=trim((string)$item['part_number']); $name=trim((string)$item['name']);
            if(!$sku&&!$part&&!$name){$counts['error']++;continue;}
            $product_id=$sku&&function_exists('wc_get_product_id_by_sku')?(int)wc_get_product_id_by_sku($sku):0;
            if(!$product_id&&$part)$product_id=$this->find_by_part($part);
            $before=$product_id?$this->product_snapshot($product_id):array(); $change=$product_id?$this->diff($before,$item):$item;
            $type=$product_id?(empty($change)?'unchanged':'update'):'new'; $counts['new'] += 'new'===$type; $counts['changed'] += 'update'===$type; $counts['unchanged'] += 'unchanged'===$type;
            $wpdb->insert($wpdb->prefix.'echo_sync_preview',array('run_id'=>$run_id,'supplier'=>$supplier,'item_key'=>sanitize_text_field($sku?:($part?:substr(md5($name),0,16))),'product_id'=>$product_id,'change_type'=>$type,'confidence'=>$sku||$part?100:82,'status'=>'unchanged'===$type?'ignored':'pending','source_record'=>wp_json_encode($record),'before_value'=>wp_json_encode($before),'after_value'=>wp_json_encode($item),'created_at'=>current_time('mysql')));
        }
        $total=array_sum($counts); $wpdb->update($wpdb->prefix.'echo_sync_runs',array('status'=>'preview_ready','total_items'=>$total,'new_items'=>$counts['new'],'changed_items'=>$counts['changed'],'unchanged_items'=>$counts['unchanged'],'error_items'=>$counts['error'],'summary'=>wp_json_encode($counts),'completed_at'=>current_time('mysql')),array('id'=>$run_id));
        $wpdb->update($wpdb->prefix.'echo_os_jobs',array('progress'=>100,'message'=>sprintf('Preview ready: %d new, %d changed',$counts['new'],$counts['changed'])),array('id'=>$job_id));
        Echo_Platform_OS::notify('success','Supplier preview ready',sprintf('%s: %d new and %d changed products are ready for review.',$supplier,$counts['new'],$counts['changed']),admin_url('admin.php?page=echo-catalog-manager&tab=previews&run_id='.$run_id));
        Echo_Platform_OS::emit('supplier_preview_ready','Supplier preview ready for '.$supplier,array('supplier'=>$supplier,'run_id'=>$run_id));
    }

    public function apply_preview(): void {
        $this->guard('echo_sync_apply_preview'); $run_id=absint($_POST['run_id']??0); $ids=array_map('absint',(array)($_POST['preview_ids']??array()));
        if(!$run_id||!$ids)$this->redirect('previews','Select at least one change.','error',array('run_id'=>$run_id));
        Echo_Platform_OS::queue_job('supplier_apply',array('run_id'=>$run_id,'preview_ids'=>$ids),'Routing supplier changes to Review Center');
        $this->redirect('jobs','Selected changes queued for safe review.','success');
    }

    public function run_apply_job( int $job_id, array $payload ): void {
        global $wpdb; $run_id=absint($payload['run_id']??0);$ids=array_values(array_filter(array_map('absint',(array)($payload['preview_ids']??array()))));if(!$ids)throw new RuntimeException('No preview changes selected.');
        $placeholders=implode(',',array_fill(0,count($ids),'%d'));$sql=$wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_sync_preview WHERE run_id=%d AND id IN ($placeholders)",array_merge(array($run_id),$ids));$rows=$wpdb->get_results($sql,ARRAY_A);$queued=0;
        foreach($rows as $row){if(!in_array($row['change_type'],array('new','update'),true))continue;$after=json_decode((string)$row['after_value'],true)?:array();$before=json_decode((string)$row['before_value'],true)?:array();$title=('new'===$row['change_type']?'New product: ':'Update product: ').($after['name']??$row['item_key']);$confidence=(float)$row['confidence'];Echo_Platform_OS::add_review('supplier_'.$row['change_type'],$title,$confidence,array('Supplier '.$row['supplier'],'Exact SKU/part number preferred','Preview generated before apply'),$before,$after,$row['supplier'],(int)$row['product_id']);$wpdb->update($wpdb->prefix.'echo_sync_preview',array('status'=>'review'),array('id'=>(int)$row['id']));$queued++;}
        $wpdb->update($wpdb->prefix.'echo_os_jobs',array('progress'=>100,'message'=>sprintf('%d changes sent to Review Center',$queued)),array('id'=>$job_id));Echo_Platform_OS::notify('info','Supplier changes need approval',sprintf('%d changes were sent to Review Center.',$queued),admin_url('admin.php?page=echo-catalog-manager&tab=review'));
    }

    public static function runs( int $limit=50 ): array { global $wpdb; return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_sync_runs ORDER BY id DESC LIMIT %d",$limit),ARRAY_A)?:array(); }
    public static function previews( int $run_id=0, int $limit=250 ): array { global $wpdb; return $run_id?$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_sync_preview WHERE run_id=%d ORDER BY id ASC LIMIT %d",$run_id,$limit),ARRAY_A)?:array():array(); }

    private function create_run(string $supplier,string $type,string $url):int{global $wpdb;$wpdb->insert($wpdb->prefix.'echo_sync_runs',array('supplier'=>$supplier,'source_type'=>$type,'source_url'=>$url,'status'=>'queued','created_at'=>current_time('mysql')));return(int)$wpdb->insert_id;}
    private function fetch_connection(string $supplier):array{$c=Echo_Motorworks_Operations::connection($supplier);$url=esc_url_raw($c['base_url']??'');if(!$url)throw new RuntimeException('No source URL is configured.');$args=array('timeout'=>30,'redirection'=>3,'headers'=>array('Accept'=>'application/json, application/xml, text/csv;q=0.9, */*;q=0.5'));$resp=wp_safe_remote_get($url,$args);if(is_wp_error($resp))throw new RuntimeException($resp->get_error_message());if((int)wp_remote_retrieve_response_code($resp)>=400)throw new RuntimeException('Supplier source returned HTTP '.wp_remote_retrieve_response_code($resp));$body=(string)wp_remote_retrieve_body($resp);$type=sanitize_key($c['connection_type']??'json');return $this->decode_body($body,$type);}
    private function read_file(string $path,string $type):array{if(!is_readable($path))throw new RuntimeException('Uploaded feed file is unavailable.');return $this->decode_body((string)file_get_contents($path),$type);}
    private function decode_body(string $body,string $type):array{if(in_array($type,array('json','rest','graphql'),true)){ $d=json_decode($body,true);if(!is_array($d))return array();foreach(array('products','data','items','results') as $k)if(isset($d[$k])&&is_array($d[$k]))return array_values($d[$k]);return array_is_list($d)?$d:array($d);}if('csv'===$type){$rows=array();$f=fopen('php://temp','r+');fwrite($f,$body);rewind($f);$h=fgetcsv($f);if(!$h)return array();$h=array_map(fn($v)=>sanitize_key((string)$v),$h);while(($r=fgetcsv($f))!==false)$rows[]=array_combine($h,array_pad($r,count($h),''));fclose($f);return $rows;}if('xml'===$type){libxml_use_internal_errors(true);$xml=simplexml_load_string($body,'SimpleXMLElement',LIBXML_NOCDATA);if(!$xml)return array();$json=json_decode(wp_json_encode($xml),true);foreach(array('product','item','entry') as $k)if(isset($json[$k]))return array_is_list($json[$k])?$json[$k]:array($json[$k]);return array($json);}return array();}
    private function normalize(array $r,array $m):array{$out=array();foreach($m as $target=>$source)$out[$target]=$this->value($r,$source);if(is_array($out['image']))$out['image']=reset($out['image']);return $out;}
    private function value(array $r,string $path){if(!$path)return'';$v=$r;foreach(explode('.',$path) as $p){if(!is_array($v)||!array_key_exists($p,$v))return'';$v=$v[$p];}return $v;}
    private function find_by_part(string $part):int{global $wpdb;foreach(array('_echo_part_number','_mpn','mpn','part_number','manufacturer_part_number') as $key){$id=(int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s LIMIT 1",$key,$part));if($id)return$id;}return 0;}
    private function product_snapshot(int $id):array{$p=function_exists('wc_get_product')?wc_get_product($id):null;if(!$p)return array();return array('name'=>$p->get_name(),'sku'=>$p->get_sku(),'part_number'=>get_post_meta($id,'_echo_part_number',true),'price'=>$p->get_regular_price(),'sale_price'=>$p->get_sale_price(),'stock'=>$p->get_stock_status(),'description'=>$p->get_description(),'short_description'=>$p->get_short_description(),'image'=>get_the_post_thumbnail_url($id,'full')?:'','brand'=>get_post_meta($id,'_echo_brand',true),'fitment'=>get_post_meta($id,'_echo_fitment_raw',true),'source_url'=>get_post_meta($id,'_echo_source_url',true));}
    private function diff(array $before,array $after):array{$d=array();foreach($after as $k=>$v){if(''===$v||null===$v)continue;if((string)($before[$k]??'')!==(string)$v)$d[$k]=$v;}return$d;}
    private function guard(string $action):void{if(!current_user_can('manage_woocommerce'))wp_die('Insufficient permissions.');check_admin_referer($action);}
    private function redirect(string $tab,string $message,string $type='success',array $extra=array()):void{$args=array_merge(array('page'=>'echo-catalog-manager','tab'=>$tab,'echo_notice'=>rawurlencode($message),'echo_notice_type'=>$type),$extra);wp_safe_redirect(add_query_arg($args,admin_url('admin.php')));exit;}
}
