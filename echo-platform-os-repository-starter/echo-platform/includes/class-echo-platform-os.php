<?php

defined( 'ABSPATH' ) || exit;

/** Echo Platform OS services: events, jobs, reviews, automation, notices and rollback history. */
final class Echo_Platform_OS {
    private const CRON = 'echo_platform_os_run_jobs';
    private const MODULES = 'echo_platform_os_modules';
    private const SCHEMA = 'echo_platform_os_schema';

    public function __construct() {
        add_action( self::CRON, array( $this, 'run_jobs' ) );
        add_action( 'admin_post_echo_os_run_jobs', array( $this, 'run_jobs_now' ) );
        add_action( 'admin_post_echo_os_review_action', array( $this, 'review_action' ) );
        add_action( 'admin_post_echo_os_bulk_review', array( $this, 'bulk_review_action' ) );
        add_action( 'admin_post_echo_os_notice_action', array( $this, 'notice_action' ) );
        add_action( 'admin_post_echo_os_save_modules', array( $this, 'save_modules' ) );
        add_action( 'admin_post_echo_os_save_rules', array( $this, 'save_rules' ) );
        add_action( 'admin_post_echo_os_rollback', array( $this, 'rollback_action' ) );
        add_action( 'echo_os_event', array( $this, 'record_event' ), 10, 3 );
        if ( ! wp_next_scheduled( self::CRON ) ) {
            wp_schedule_event( time() + 300, 'echo_five_minutes', self::CRON );
        }
    }

    public static function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            job_type varchar(80) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            payload longtext NULL,
            progress smallint unsigned NOT NULL DEFAULT 0,
            message text NULL,
            attempts smallint unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            PRIMARY KEY (id), KEY status (status), KEY job_type (job_type)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_activity (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(100) NOT NULL,
            object_type varchar(80) NULL,
            object_id bigint unsigned NULL,
            user_id bigint unsigned NULL,
            summary text NOT NULL,
            context longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY event_type (event_type), KEY created_at (created_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_reviews (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            review_type varchar(80) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            confidence decimal(5,2) NOT NULL DEFAULT 0,
            title text NOT NULL,
            evidence longtext NULL,
            before_value longtext NULL,
            after_value longtext NULL,
            source varchar(190) NULL,
            object_id bigint unsigned NULL,
            created_at datetime NOT NULL,
            decided_at datetime NULL,
            decided_by bigint unsigned NULL,
            PRIMARY KEY (id), KEY status (status), KEY review_type (review_type), KEY confidence (confidence)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_notifications (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            level varchar(20) NOT NULL DEFAULT 'info',
            status varchar(20) NOT NULL DEFAULT 'unread',
            title varchar(255) NOT NULL,
            message text NULL,
            action_url text NULL,
            created_at datetime NOT NULL,
            read_at datetime NULL,
            PRIMARY KEY (id), KEY status (status), KEY created_at (created_at)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            rule_key varchar(100) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 0,
            threshold decimal(6,2) NOT NULL DEFAULT 0,
            supplier varchar(190) NULL,
            config longtext NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY rule_key_supplier (rule_key,supplier)
        ) $charset;" );
        dbDelta( "CREATE TABLE {$wpdb->prefix}echo_os_changes (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            review_id bigint unsigned NULL,
            object_type varchar(80) NOT NULL,
            object_id bigint unsigned NOT NULL,
            field_name varchar(190) NULL,
            old_value longtext NULL,
            new_value longtext NULL,
            source varchar(190) NULL,
            status varchar(20) NOT NULL DEFAULT 'applied',
            applied_by bigint unsigned NULL,
            applied_at datetime NOT NULL,
            rolled_back_by bigint unsigned NULL,
            rolled_back_at datetime NULL,
            PRIMARY KEY (id), KEY object_lookup (object_type,object_id), KEY status (status)
        ) $charset;" );
        add_option( self::MODULES, self::default_modules(), '', false );
        update_option( self::SCHEMA, '1.2.0', false );
        if ( class_exists( 'Echo_Platform_Sync_Engine' ) ) { Echo_Platform_Sync_Engine::activate(); }
        self::seed_rules();
        self::notify( 'success', 'Echo Platform OS updated', 'Supplier Sync Engine, previews, mappings, and safe review routing are ready.' );
    }

    private static function seed_rules(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'echo_os_rules';
        $defaults = array(
            array( 'image_match', 0, 98, '', array( 'action' => 'approve' ) ),
            array( 'stock_update', 0, 100, '', array( 'action' => 'approve_trusted' ) ),
            array( 'price_change_review', 1, 10, '', array( 'action' => 'require_review' ) ),
            array( 'never_delete_products', 1, 100, '', array( 'action' => 'block_delete' ) ),
            array( 'draft_discontinued', 1, 100, '', array( 'action' => 'draft' ) ),
        );
        foreach ( $defaults as $d ) {
            $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE rule_key=%s AND supplier=%s", $d[0], $d[3] ) );
            if ( ! $exists ) {
                $wpdb->insert( $table, array( 'rule_key'=>$d[0], 'enabled'=>$d[1], 'threshold'=>$d[2], 'supplier'=>$d[3], 'config'=>wp_json_encode($d[4]), 'updated_at'=>current_time('mysql') ) );
            }
        }
    }

    public static function add_schedules( array $schedules ): array {
        $schedules['echo_five_minutes'] = array( 'interval' => 300, 'display' => 'Every five minutes' );
        return $schedules;
    }
    public static function emit( string $type, string $summary, array $context = array() ): void { do_action( 'echo_os_event', $type, $summary, $context ); }
    public function record_event( string $type, string $summary, array $context = array() ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'echo_os_activity', array( 'event_type'=>sanitize_key($type), 'object_type'=>sanitize_key($context['object_type']??''), 'object_id'=>absint($context['object_id']??0), 'user_id'=>get_current_user_id(), 'summary'=>sanitize_text_field($summary), 'context'=>wp_json_encode($context), 'created_at'=>current_time('mysql') ) );
    }
    public static function queue_job( string $type, array $payload = array(), string $message = '' ): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'echo_os_jobs', array( 'job_type'=>sanitize_key($type), 'status'=>'queued', 'payload'=>wp_json_encode($payload), 'progress'=>0, 'message'=>sanitize_text_field($message), 'attempts'=>0, 'created_at'=>current_time('mysql') ) );
        $id=(int)$wpdb->insert_id; self::emit('job_queued',sprintf('Queued %s job #%d',$type,$id),array('object_type'=>'job','object_id'=>$id)); return $id;
    }
    public function run_jobs(): void {
        global $wpdb; $table=$wpdb->prefix.'echo_os_jobs';
        $jobs=$wpdb->get_results("SELECT * FROM {$table} WHERE status IN ('queued','retry') ORDER BY id ASC LIMIT 5",ARRAY_A);
        foreach($jobs as $job){$id=(int)$job['id'];$wpdb->update($table,array('status'=>'running','started_at'=>current_time('mysql'),'attempts'=>(int)$job['attempts']+1),array('id'=>$id));try{do_action('echo_os_run_job_'.sanitize_key($job['job_type']),$id,json_decode((string)$job['payload'],true)?:array());$fresh=$wpdb->get_row($wpdb->prepare("SELECT status FROM {$table} WHERE id=%d",$id),ARRAY_A);if($fresh&&'running'===$fresh['status'])$wpdb->update($table,array('status'=>'complete','progress'=>100,'finished_at'=>current_time('mysql'),'message'=>'Completed'),array('id'=>$id));self::emit('job_complete',sprintf('Completed job #%d',$id),array('object_type'=>'job','object_id'=>$id));}catch(Throwable $e){$wpdb->update($table,array('status'=>'failed','message'=>substr($e->getMessage(),0,1000),'finished_at'=>current_time('mysql')),array('id'=>$id));self::notify('error','Background job failed',sprintf('Job #%d: %s',$id,$e->getMessage()));}}
    }
    public function run_jobs_now(): void { $this->guard('echo_os_run_jobs');$this->run_jobs();$this->redirect('jobs','Job queue processed.'); }
    public static function notify( string $level, string $title, string $message = '', string $action_url = '' ): void {
        global $wpdb;$wpdb->insert($wpdb->prefix.'echo_os_notifications',array('level'=>sanitize_key($level),'status'=>'unread','title'=>sanitize_text_field($title),'message'=>sanitize_textarea_field($message),'action_url'=>esc_url_raw($action_url),'created_at'=>current_time('mysql')));
    }
    public static function add_review( string $type, string $title, float $confidence, array $evidence = array(), $before = null, $after = null, string $source = '', int $object_id = 0 ): int {
        global $wpdb;$wpdb->insert($wpdb->prefix.'echo_os_reviews',array('review_type'=>sanitize_key($type),'status'=>'pending','confidence'=>max(0,min(100,$confidence)),'title'=>sanitize_text_field($title),'evidence'=>wp_json_encode($evidence),'before_value'=>wp_json_encode($before),'after_value'=>wp_json_encode($after),'source'=>sanitize_text_field($source),'object_id'=>absint($object_id),'created_at'=>current_time('mysql')));$id=(int)$wpdb->insert_id;
        self::maybe_auto_decide($id);return $id;
    }
    private static function maybe_auto_decide(int $id):void{
        global $wpdb;$review=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_os_reviews WHERE id=%d",$id),ARRAY_A);if(!$review)return;
        $rule=self::matching_rule($review);if(!$rule||empty($rule['enabled']))return;
        if((float)$review['confidence']<(float)$rule['threshold'])return;
        $config=json_decode((string)$rule['config'],true)?:array();if(($config['action']??'')!=='approve')return;
        $wpdb->update($wpdb->prefix.'echo_os_reviews',array('status'=>'approved','decided_at'=>current_time('mysql'),'decided_by'=>0),array('id'=>$id));
        do_action('echo_os_review_approved',$review);self::emit('review_auto_approved',sprintf('Auto-approved review #%d',$id),array('object_type'=>'review','object_id'=>$id));self::notify('success','Safe recommendation auto-approved',sprintf('%s (%s%% confidence)',$review['title'],$review['confidence']));
    }
    private static function matching_rule(array $review):?array{
        global $wpdb;$map=array('image'=>'image_match','image_match'=>'image_match','stock'=>'stock_update','inventory'=>'stock_update','price'=>'price_change_review');$key=$map[$review['review_type']]??'';if(!$key)return null;
        $supplier=(string)$review['source'];return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_os_rules WHERE rule_key=%s AND (supplier=%s OR supplier='') ORDER BY supplier DESC LIMIT 1",$key,$supplier),ARRAY_A)?:null;
    }
    public function review_action(): void {$this->guard('echo_os_review_action');$id=absint($_POST['review_id']??0);$decision=sanitize_key($_POST['decision']??'');$this->decide_review($id,$decision);$this->redirect('review','Review item updated.');}
    public function bulk_review_action():void{
        $this->guard('echo_os_bulk_review');$decision=sanitize_key($_POST['decision']??'');$ids=array_map('absint',(array)($_POST['review_ids']??array()));if(!$ids)$this->redirect('review','Select at least one review item.','error');$done=0;foreach($ids as $id){if($this->decide_review($id,$decision,false))$done++;}$this->redirect('review',sprintf('%d review items updated.',$done));
    }
    private function decide_review(int $id,string $decision,bool $redirect_on_error=true):bool{
        global $wpdb;if(!in_array($decision,array('approved','rejected','ignored'),true)){if($redirect_on_error)$this->redirect('review','Invalid review action.','error');return false;}$review=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_os_reviews WHERE id=%d",$id),ARRAY_A);if(!$review||'pending'!==$review['status'])return false;$wpdb->update($wpdb->prefix.'echo_os_reviews',array('status'=>$decision,'decided_at'=>current_time('mysql'),'decided_by'=>get_current_user_id()),array('id'=>$id));do_action('echo_os_review_'.$decision,$review);self::emit('review_'.$decision,sprintf('%s review #%d',ucfirst($decision),$id),array('object_type'=>'review','object_id'=>$id));return true;
    }
    public static function log_change(int $review_id,string $object_type,int $object_id,string $field,$old,$new,string $source=''):int{
        global $wpdb;$wpdb->insert($wpdb->prefix.'echo_os_changes',array('review_id'=>$review_id,'object_type'=>sanitize_key($object_type),'object_id'=>$object_id,'field_name'=>sanitize_key($field),'old_value'=>wp_json_encode($old),'new_value'=>wp_json_encode($new),'source'=>sanitize_text_field($source),'status'=>'applied','applied_by'=>get_current_user_id(),'applied_at'=>current_time('mysql')));return(int)$wpdb->insert_id;
    }
    public function rollback_action():void{
        $this->guard('echo_os_rollback');global $wpdb;$id=absint($_POST['change_id']??0);$change=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}echo_os_changes WHERE id=%d",$id),ARRAY_A);if(!$change||'applied'!==$change['status'])$this->redirect('activity','Change cannot be rolled back.','error');$ok=apply_filters('echo_os_rollback_change',false,$change);if(!$ok)$this->redirect('activity','This change does not have an automatic rollback handler yet.','error');$wpdb->update($wpdb->prefix.'echo_os_changes',array('status'=>'rolled_back','rolled_back_by'=>get_current_user_id(),'rolled_back_at'=>current_time('mysql')),array('id'=>$id));self::emit('change_rolled_back',sprintf('Rolled back change #%d',$id),array('object_type'=>'change','object_id'=>$id));$this->redirect('activity','Change rolled back.');
    }
    public function notice_action():void{$this->guard('echo_os_notice_action');global $wpdb;$id=absint($_POST['notice_id']??0);$wpdb->update($wpdb->prefix.'echo_os_notifications',array('status'=>'read','read_at'=>current_time('mysql')),array('id'=>$id));$this->redirect('notifications','Notification marked read.');}
    public static function default_modules():array{return array('catalog'=>1,'suppliers'=>1,'images'=>1,'vehicles'=>1,'reports'=>1,'support'=>1,'review'=>1,'automation'=>1,'developer'=>0);}
    public static function modules():array{return wp_parse_args(get_option(self::MODULES,array()),self::default_modules());}
    public function save_modules():void{$this->guard('echo_os_save_modules');$values=array();foreach(self::default_modules() as $key=>$default)$values[$key]=isset($_POST['modules'][$key])?1:0;update_option(self::MODULES,$values,false);self::emit('modules_updated','Module settings updated');$this->redirect('modules','Module settings saved.');}
    public static function rules():array{global $wpdb;return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}echo_os_rules ORDER BY id ASC",ARRAY_A)?:array();}
    public function save_rules():void{
        $this->guard('echo_os_save_rules');global $wpdb;$rules=(array)($_POST['rules']??array());foreach($rules as $id=>$data){$id=absint($id);$wpdb->update($wpdb->prefix.'echo_os_rules',array('enabled'=>isset($data['enabled'])?1:0,'threshold'=>max(0,min(100,(float)($data['threshold']??0))),'updated_at'=>current_time('mysql')),array('id'=>$id));}self::emit('automation_rules_updated','Automation rules updated');$this->redirect('automation','Automation rules saved.');
    }
    public static function counts():array{global $wpdb;return array('jobs'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}echo_os_jobs WHERE status IN ('queued','running','retry')"),'reviews'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}echo_os_reviews WHERE status='pending'"),'safe_reviews'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}echo_os_reviews WHERE status='pending' AND confidence>=98"),'notifications'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}echo_os_notifications WHERE status='unread'"));}
    public static function recent(string $kind,int $limit=20):array{global $wpdb;$limit=max(1,min(100,$limit));$tables=array('jobs'=>'echo_os_jobs','activity'=>'echo_os_activity','reviews'=>'echo_os_reviews','notifications'=>'echo_os_notifications','changes'=>'echo_os_changes');if(!isset($tables[$kind]))return array();return $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$tables[$kind]} ORDER BY id DESC LIMIT {$limit}",ARRAY_A)?:array();}
    private function guard(string $action):void{if(!current_user_can('manage_woocommerce'))wp_die('Permission denied.');check_admin_referer($action);}
    private function redirect(string $tab,string $message,string $type='success'):void{wp_safe_redirect(add_query_arg(array('page'=>'echo-catalog-manager','tab'=>$tab,'echo_notice'=>rawurlencode($message),'echo_notice_type'=>$type),admin_url('admin.php')));exit;}
}
