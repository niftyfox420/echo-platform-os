<?php

defined( 'ABSPATH' ) || exit;

final class Echo_Platform_OS_UI {
    public static function mission_control(): void {
        $counts = Echo_Platform_OS::counts();
        $diag = Echo_Motorworks_Platform::diagnostics();
        $health = class_exists( 'Echo_Motorworks_Operations' ) && method_exists( 'Echo_Motorworks_Operations', 'health_snapshot' ) ? Echo_Motorworks_Operations::health_snapshot() : array();
        $score = (int) ( $health['score'] ?? ( $diag['uploads_writable'] && $diag['tables_ready'] ? 92 : 75 ) );
        $products = (int) ( $diag['products'] ?? 0 );
        ?>
        <div class="echo-os-hero"><div><span class="echo-kicker">MISSION CONTROL</span><h2>Your store, prioritized.</h2><p>Review safe recommendations, automate trusted work, and keep risky changes under human control.</p></div><div class="echo-health-ring" style="--score:<?php echo esc_attr( $score ); ?>"><strong><?php echo esc_html( $score ); ?>%</strong><span>Store health</span></div></div>
        <div class="echo-os-grid echo-os-grid-4">
            <?php self::metric( 'Products', number_format_i18n( $products ), 'Catalog records' ); self::metric( 'Pending review', $counts['reviews'], 'Needs a decision' ); self::metric( 'Safe to review', $counts['safe_reviews'], '98%+ confidence' ); self::metric( 'Notifications', $counts['notifications'], 'Unread alerts' ); ?>
        </div>
        <div class="echo-os-grid echo-os-grid-2">
            <section class="echo-os-card"><div class="echo-card-head"><h3>Start My Day</h3><span>Recommended order</span></div><?php self::work_queue(); ?></section>
            <section class="echo-os-card"><div class="echo-card-head"><h3>Recent Activity</h3><a href="<?php echo esc_url( admin_url('admin.php?page=echo-catalog-manager&tab=activity') ); ?>">View all</a></div><?php self::activity_list( 6 ); ?></section>
        </div>
        <?php
    }

    private static function metric( string $label, $value, string $sub ): void { echo '<div class="echo-os-card echo-metric"><span>'.esc_html($label).'</span><strong>'.esc_html((string)$value).'</strong><small>'.esc_html($sub).'</small></div>'; }
    private static function work_queue(): void {
        $counts = Echo_Platform_OS::counts();
        $items = array(
            array( $counts['safe_reviews'] ? sprintf('%d high-confidence updates ready', $counts['safe_reviews']) : 'No high-confidence updates waiting', $counts['safe_reviews'] ? 'review' : 'dashboard', $counts['safe_reviews'] ? 'high' : 'good' ),
            array( $counts['reviews'] ? sprintf('%d total recommendations need review', $counts['reviews']) : 'Review Center is clear', $counts['reviews'] ? 'review' : 'dashboard', $counts['reviews'] ? 'medium' : 'good' ),
            array( 'Check automation safety rules', 'automation', 'low' ),
            array( 'Run catalog health scan', 'health', 'medium' ),
        );
        echo '<div class="echo-work-list">'; foreach ( $items as $item ) echo '<a href="'.esc_url(admin_url('admin.php?page=echo-catalog-manager&tab='.$item[1])).'"><i class="echo-priority '.$item[2].'"></i><span>'.esc_html($item[0]).'</span><b>Open →</b></a>'; echo '</div>';
    }

    public static function review_center(): void {
        $rows = Echo_Platform_OS::recent( 'reviews', 100 );
        $filter = sanitize_key( $_GET['review_status'] ?? 'pending' );
        if ( ! in_array( $filter, array( 'pending','approved','rejected','ignored','all' ), true ) ) $filter = 'pending';
        if ( 'all' !== $filter ) $rows = array_values( array_filter( $rows, static fn($r) => $r['status'] === $filter ) );
        echo '<div class="echo-card-head"><div><h2>Review Center</h2><p>Compare before and after, inspect confidence and evidence, then approve only what makes sense.</p></div></div>';
        echo '<div class="echo-review-toolbar"><div class="echo-filter-links">';
        foreach(array('pending'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','ignored'=>'Ignored','all'=>'All') as $key=>$label){echo '<a class="'.($filter===$key?'is-active':'').'" href="'.esc_url(add_query_arg(array('page'=>'echo-catalog-manager','tab'=>'review','review_status'=>$key),admin_url('admin.php'))).'">'.esc_html($label).'</a>';}
        echo '</div></div>';
        if ( ! $rows ) { self::empty_state( 'No review items here', 'New image, price, stock, fitment, and supplier recommendations will appear here.' ); return; }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="echo_os_bulk_review">'; wp_nonce_field('echo_os_bulk_review');
        echo '<div class="echo-bulk-bar"><label><input type="checkbox" id="echo-review-select-all"> Select all</label><select name="decision"><option value="approved">Approve selected</option><option value="rejected">Reject selected</option><option value="ignored">Ignore selected</option></select><button class="button button-primary">Apply</button></div>';
        echo '<div class="echo-review-list">';
        foreach ( $rows as $r ) {
            $evidence = json_decode((string)$r['evidence'],true) ?: array();
            $before = json_decode((string)$r['before_value'], true);
            $after = json_decode((string)$r['after_value'], true);
            echo '<article class="echo-review-item">';
            echo '<div class="echo-review-check">'.('pending'===$r['status']?'<input type="checkbox" name="review_ids[]" value="'.absint($r['id']).'">':'').'</div>';
            echo '<div class="echo-confidence '.((float)$r['confidence']>=98?'is-safe':'').'"><strong>'.esc_html(round((float)$r['confidence'])).'%</strong><span>confidence</span></div>';
            echo '<div class="echo-review-body"><span class="echo-pill">'.esc_html($r['review_type']).'</span><h3>'.esc_html($r['title']).'</h3>';
            if($evidence) echo '<p>'.esc_html(implode(' • ',array_map('sanitize_text_field',$evidence))).'</p>';
            if(null!==$before||null!==$after){echo '<div class="echo-diff-grid"><div><small>Before</small><pre>'.esc_html(self::pretty($before)).'</pre></div><div><small>After</small><pre>'.esc_html(self::pretty($after)).'</pre></div></div>';}
            echo '<small>'.esc_html($r['source']).' · '.esc_html($r['created_at']).'</small></div>';
            echo '<div class="echo-review-actions">';
            if ('pending'===$r['status']) {
                foreach(array('approved'=>'Approve','rejected'=>'Reject','ignored'=>'Ignore') as $decision=>$label){
                    echo '<button type="submit" formaction="'.esc_url(admin_url('admin-post.php')).'" formmethod="post" name="single_'.$decision.'" value="'.absint($r['id']).'" class="button'.('approved'===$decision?' button-primary':'').'" onclick="this.form.action.value=\'echo_os_review_action\';this.form.review_id.value=\''.absint($r['id']).'\';this.form.decision.value=\''.esc_js($decision).'\';">'.esc_html($label).'</button>';
                }
            } else echo '<span class="echo-pill">'.esc_html($r['status']).'</span>';
            echo '</div></article>';
        }
        echo '</div><input type="hidden" name="review_id" value=""><input type="hidden" name="decision" value=""></form>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.getElementById("echo-review-select-all");if(a){a.addEventListener("change",function(){document.querySelectorAll("input[name=\"review_ids[]\"]").forEach(function(c){c.checked=a.checked;});});}});</script>';
    }

    public static function automation_rules(): void {
        $rules=Echo_Platform_OS::rules();
        $labels=array(
            'image_match'=>array('High-confidence image matches','Automatically approve only when the image match reaches the selected confidence.'),
            'stock_update'=>array('Trusted stock updates','Allow trusted supplier inventory changes to move through faster.'),
            'price_change_review'=>array('Large price-change review','Require human review when a price changes by more than this percentage.'),
            'never_delete_products'=>array('Never auto-delete products','Blocks automated permanent product deletion.'),
            'draft_discontinued'=>array('Draft discontinued products','Moves discontinued items to Draft instead of deleting them.'),
        );
        echo '<div class="echo-card-head"><div><h2>Automation Rules</h2><p>Start conservative. The OS can automate high-confidence work while risky actions remain review-only.</p></div></div>';
        echo '<div class="echo-safety-banner"><strong>Safety defaults are active.</strong><span>Permanent deletion stays blocked, and price changes above the limit require review.</span></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="echo-rule-list"><input type="hidden" name="action" value="echo_os_save_rules">';wp_nonce_field('echo_os_save_rules');
        foreach($rules as $r){$meta=$labels[$r['rule_key']]??array(ucwords(str_replace('_',' ',$r['rule_key'])),'');echo '<label class="echo-rule-card"><div><input type="checkbox" name="rules['.absint($r['id']).'][enabled]" '.checked((int)$r['enabled'],1,false).'><span><strong>'.esc_html($meta[0]).'</strong><small>'.esc_html($meta[1]).'</small></span></div><div class="echo-rule-threshold"><span>Threshold</span><input type="number" min="0" max="100" step="0.1" name="rules['.absint($r['id']).'][threshold]" value="'.esc_attr($r['threshold']).'"><b>%</b></div></label>';}
        echo '<div class="echo-rule-save"><button class="button button-primary button-hero">Save Automation Rules</button></div></form>';
    }

    public static function jobs(): void {
        $rows=Echo_Platform_OS::recent('jobs',50); echo '<div class="echo-card-head"><div><h2>Background Jobs</h2><p>Long-running work runs safely without locking the admin screen.</p></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="echo_os_run_jobs">'; wp_nonce_field('echo_os_run_jobs'); echo '<button class="button button-primary">Process Queue Now</button></form></div>'; self::table($rows,array('id'=>'#','job_type'=>'Job','status'=>'Status','progress'=>'Progress','message'=>'Message','created_at'=>'Created'));
    }
    public static function notifications(): void { $rows=Echo_Platform_OS::recent('notifications',50); echo '<div class="echo-card-head"><div><h2>Notifications</h2><p>System, catalog and supplier alerts in one place.</p></div></div>'; if(!$rows){self::empty_state('All caught up','No notifications need attention.');return;} echo '<div class="echo-notice-list">'; foreach($rows as $r){echo '<article class="echo-notice '.$r['level'].'"><div><h3>'.esc_html($r['title']).'</h3><p>'.esc_html($r['message']).'</p><small>'.esc_html($r['created_at']).'</small></div>'; if('unread'===$r['status']){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="echo_os_notice_action"><input type="hidden" name="notice_id" value="'.absint($r['id']).'">';wp_nonce_field('echo_os_notice_action');echo '<button class="button">Mark read</button></form>';} echo '</article>'; } echo '</div>'; }
    public static function activity(): void { echo '<div class="echo-card-head"><div><h2>Activity Timeline</h2><p>A permanent operational history for imports, scans, reviews and system actions.</p></div></div>'; self::activity_list(50); }
    private static function activity_list(int $limit):void{$rows=Echo_Platform_OS::recent('activity',$limit);if(!$rows){self::empty_state('No activity yet','Actions taken through Echo Platform OS will be recorded here.');return;}echo '<div class="echo-timeline">';foreach($rows as $r)echo '<div><i></i><section><strong>'.esc_html($r['summary']).'</strong><span>'.esc_html($r['event_type']).' · '.esc_html($r['created_at']).'</span></section></div>';echo '</div>';}
    public static function modules():void{$mods=Echo_Platform_OS::modules();$labels=array('catalog'=>'Catalog Engine','suppliers'=>'Supplier Engine','images'=>'Image Intelligence','vehicles'=>'Vehicle Intelligence','reports'=>'Reporting Engine','support'=>'Support','review'=>'Review Center','automation'=>'Automation Engine','developer'=>'Developer Mode');echo '<div class="echo-card-head"><div><h2>Module Manager</h2><p>Keep the OS focused. Disabling a module hides its workspace without deleting data.</p></div></div><form method="post" action="'.esc_url(admin_url('admin-post.php')).'" class="echo-module-grid"><input type="hidden" name="action" value="echo_os_save_modules">';wp_nonce_field('echo_os_save_modules');foreach($labels as $key=>$label){echo '<label class="echo-module-card"><input type="checkbox" name="modules['.esc_attr($key).']" '.checked(!empty($mods[$key]),true,false).'><span><strong>'.esc_html($label).'</strong><small>'.(!empty($mods[$key])?'Active':'Disabled').'</small></span></label>';}echo '<div class="echo-module-save"><button class="button button-primary button-hero">Save Modules</button></div></form>';}
    public static function developer():void{$diag=Echo_Motorworks_Platform::diagnostics();echo '<div class="echo-card-head"><div><h2>Developer Mode</h2><p>Read-only technical status for troubleshooting. Hidden from normal workflows.</p></div></div>';self::table(array(array('component'=>'WordPress','value'=>$diag['wordpress']),array('component'=>'PHP','value'=>$diag['php']),array('component'=>'WooCommerce','value'=>$diag['woocommerce']?:'Not detected'),array('component'=>'Echo OS','value'=>ECHO_MOTORWORKS_CORE_VERSION),array('component'=>'Uploads','value'=>$diag['uploads_writable']?'Writable':'Needs attention'),array('component'=>'Echo tables','value'=>$diag['tables_ready']?'Ready':'Needs attention')),array('component'=>'Component','value'=>'Status'));}
    private static function table(array $rows,array $columns):void{if(!$rows){self::empty_state('Nothing to show','Records will appear here as the OS works.');return;}echo '<div class="echo-table-wrap"><table class="widefat striped"><thead><tr>';foreach($columns as $label)echo '<th>'.esc_html($label).'</th>';echo '</tr></thead><tbody>';foreach($rows as $row){echo '<tr>';foreach($columns as $key=>$label){$v=$row[$key]??'';if('progress'===$key)$v=absint($v).'%';echo '<td>'.esc_html((string)$v).'</td>';}echo '</tr>';}echo '</tbody></table></div>';}
    private static function empty_state(string $title,string $text):void{echo '<div class="echo-empty"><span class="dashicons dashicons-yes-alt"></span><h3>'.esc_html($title).'</h3><p>'.esc_html($text).'</p></div>';}
    private static function pretty($value):string{if(is_scalar($value)||null===$value)return (string)$value;return wp_json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);}
}
