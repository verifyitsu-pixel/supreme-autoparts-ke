<?php
/** Local Careers review tools. No external service, candidate scoring or automatic decisions. */
declare(strict_types=1);
defined('ABSPATH') || exit;
function sa_careers_review_key(string $email,string $phone,string $role): string {
    $email=strtolower(trim($email));$phone=preg_replace('/[^0-9]/','',$phone);$role=sanitize_key($role);
    return $email!==''&&$phone!==''&&$role!=='' ? hash('sha256',$email.'|'.$phone.'|'.$role) : '';
}
function sa_careers_review_records(): array {
    if(!class_exists('Forminator_Form_Entry_Model')||!function_exists('sa_ff_id'))return [];
    $id=sa_ff_id('careers');if(!$id)return [];
    // A bounded recent window, never an unbounded CV archive read or export.
    return Forminator_Form_Entry_Model::query_entries(['form_id'=>$id,'is_spam'=>0,'per_page'=>100,'offset'=>0,'order'=>'DESC']);
}
function sa_careers_attendance_labels(): array {return ['not_recorded'=>'Not recorded','attended_day_1'=>'Attended day 1','attended_day_2'=>'Attended day 2','did_not_attend'=>'Did not attend'];}
function sa_careers_review_render(): void {
    if(!current_user_can('manage_woocommerce'))wp_die('Not permitted.');
    $entries=sa_careers_review_records();$counts=[];
    foreach($entries as$e){$key=sa_careers_review_key((string)$e->get_meta('email-1'),(string)$e->get_meta('phone-1'),(string)$e->get_meta('select-1'));if($key!=='')$counts[$key]=($counts[$key]??0)+1;}
    echo '<div class="wrap"><h1>Careers review &amp; attendance</h1><p>Latest 100 saved non-spam Forminator applications. Possible repeats match email, phone digits and role within this window only. Review each application yourself. No ranking, rejection, invitation or refund is automatic.</p><p>Attendance is a manual record, not a payment or refund instruction. Native fallback applications remain in Support Tickets.</p>';
    if(isset($_GET['saved']))echo '<div class="notice notice-success"><p>Attendance record saved. No message or refund sent.</p></div>';
    echo '<table class="widefat striped"><thead><tr><th>Application</th><th>Role</th><th>Review flag</th><th>Fee</th><th>Attendance</th></tr></thead><tbody>';
    foreach($entries as$e){
        $id=(int)$e->entry_id;$email=(string)$e->get_meta('email-1');$role=(string)$e->get_meta('select-1');$key=sa_careers_review_key($email,(string)$e->get_meta('phone-1'),$role);$oid=(int)get_option('sa_career_fee_forminator_'.$id,0);$order=$oid&&function_exists('wc_get_order')?wc_get_order($oid):false;
        $valid=$order&&$order->get_meta('_sa_careers_source')==='forminator'&&(int)$order->get_meta('_sa_careers_entry')===$id;
        $record=(array)get_option('sa_careers_attendance_'.$id,[]);$status=(string)($record['status']??'not_recorded');
        echo '<tr><td><strong>#'.esc_html((string)$id).' '.esc_html((string)$e->get_meta('name-1')).'</strong><br>'.esc_html($email).'</td><td>'.esc_html($role).'</td><td>'.esc_html($key!==''&&($counts[$key]??0)>1?'Possible repeat - human review':'No repeat found in recent window').'</td><td>';
        if($valid)echo '<a href="'.esc_url($order->get_edit_order_url()).'">#'.esc_html((string)$oid).'</a><br>'.esc_html($order->is_paid()?'Verified paid':'Not verified paid');else echo 'No matched fee order';
        echo '</td><td><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sa_careers_attendance"><input type="hidden" name="entry" value="'.esc_attr((string)$id).'">';wp_nonce_field('sa_careers_attendance_'.$id);echo '<select aria-label="Attendance for application '.esc_attr((string)$id).'" name="status">';
        foreach(sa_careers_attendance_labels()as$value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($status,$value,false).'>'.esc_html($label).'</option>';
        echo '</select> <button class="button">Save record</button>';
        if(!empty($record['at']))echo '<br><small>Updated '.esc_html((string)$record['at']).' by user #'.esc_html((string)($record['by']??'')).'</small>';
        echo '</form>'; sa_careers_confirmation_control($e); echo '</td></tr>';
    }
    echo '</tbody></table><p>CV files and experience text are not displayed or exported by this board.</p></div>';
}
add_action('admin_menu',static function(){add_submenu_page('supreme-autoparts','Careers review','Careers review','manage_woocommerce','sa-careers-review','sa_careers_review_render');},35);
add_action('admin_post_sa_careers_attendance',static function(){
    if(!current_user_can('manage_woocommerce'))wp_die('Not permitted.');
    $id=absint($_POST['entry']??0);check_admin_referer('sa_careers_attendance_'.$id);$status=sanitize_key(wp_unslash((string)($_POST['status']??'')));
    if(!$id||!isset(sa_careers_attendance_labels()[$status])||!class_exists('Forminator_Form_Entry_Model'))wp_die('Invalid attendance record.');
    $e=new Forminator_Form_Entry_Model($id);if((int)$e->form_id!==sa_ff_id('careers')||!empty($e->is_spam))wp_die('Application not available.');
    $old=(array)get_option('sa_careers_attendance_'.$id,[]);$history=(array)($old['history']??[]);if($old){unset($old['history']);$history[]=$old;}$history=array_slice($history,-20);
    update_option('sa_careers_attendance_'.$id,['status'=>$status,'by'=>get_current_user_id(),'at'=>current_time('mysql'),'history'=>$history],false);
    wp_safe_redirect(admin_url('admin.php?page=sa-careers-review&saved=1'));exit;
});

/** Prepare only the existing applicant copy, never the admin copy or CV attachment. */
function sa_careers_confirmation_args($e): array {
    if((int)$e->form_id!==sa_ff_id('careers')||!empty($e->is_spam))throw new RuntimeException('Application unavailable.');
    $email=sanitize_email((string)$e->get_meta('email-1'));$name=trim((string)$e->get_meta('name-1'));
    if(!is_email($email)||$name==='')throw new RuntimeException('Applicant name or address missing.');
    $f=clone Forminator_API::get_form(sa_ff_id('careers'));$n=array_values(array_filter($f->notifications,static fn($v)=>($v['slug']??'')==='notification-9001-0002'));
    if(count($n)!==1||($n[0]['recipients']??'')!=='{email-1}')throw new RuntimeException('Applicant notification unavailable.');
    $n[0]['email-attachment']='false';$n[0]['cc-email']='';$n[0]['bcc-email']='';$f->notifications=$n;
    Forminator_Front_Action::$prepared_data=recreate_prepared_data($f,$e);
    Forminator_Front_Action::$module_id=(int)$e->form_id;Forminator_Front_Action::$module_object=$f;Forminator_Front_Action::$module_settings=$f->settings;
    $captured=[];$trap=static function($pre,$args)use(&$captured){$captured[]=$args;return true;};add_filter('pre_wp_mail',$trap,PHP_INT_MIN,2);
    try{(new Forminator_CForm_Front_Mail())->process_mail($f,$e);}finally{remove_filter('pre_wp_mail',$trap,PHP_INT_MIN);}
    if(count($captured)!==1)throw new RuntimeException('Unexpected notification count.');$a=$captured[0];
    $to=is_array($a['to'])?$a['to']:[$a['to']];
    if(count($to)!==1||strtolower(trim($to[0]))!==strtolower($email)||!empty($a['attachments']))throw new RuntimeException('Recipient or attachment mismatch.');
    $h=is_array($a['headers'])?implode("\n",$a['headers']):(string)$a['headers'];if(preg_match('/^(Cc|Bcc):/im',$h))throw new RuntimeException('Additional recipients blocked.');
    $ref=sa_careers_application_ref('forminator',(int)$e->entry_id);
    if(!str_contains($a['subject'],$ref)||!str_contains($a['message'],$ref)||!str_contains($a['message'],esc_html($name)))throw new RuntimeException('Personalization missing.');
    return $a;
}
function sa_careers_confirmation_control($e): void {
    $id=(int)$e->entry_id;$r=(array)get_option('sa_careers_confirmation_v1_'.$id,[]);
    if($r){echo '<p>Updated confirmation: '.esc_html((string)($r['state']??'unknown')).' '.esc_html((string)($r['at']??'')).'</p>';if(!empty($r['message_id']))echo '<p>Provider ID: '.esc_html((string)$r['message_id']).'</p>';return;}
    try{$a=sa_careers_confirmation_args($e);echo '<details><summary>Preview applicant confirmation</summary><p>'.esc_html(implode(', ',(array)$a['to'])).'</p><p>'.esc_html($a['subject']).'</p><div style="max-width:500px">'.wp_kses_post($a['message']).'</div></details>';}catch(Throwable $x){echo '<p>'.esc_html($x->getMessage()).'</p>';return;}
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="sa_careers_confirmation"><input type="hidden" name="entry" value="'.esc_attr((string)$id).'">';wp_nonce_field('sa_careers_confirmation_'.$id);echo '<button class="button">Send updated applicant confirmation</button><p><small>Applicant only, no CV attachment. One send per application.</small></p></form>';
}
add_action('admin_post_sa_careers_confirmation',static function(){
    if(!current_user_can('manage_woocommerce'))wp_die('Not permitted.');$id=absint($_POST['entry']??0);check_admin_referer('sa_careers_confirmation_'.$id);
    $key='sa_careers_confirmation_v1_'.$id;if(get_option($key))wp_die('Confirmation already handled. Check its recorded outcome before any retry.');
    try{$e=new Forminator_Form_Entry_Model($id);$a=sa_careers_confirmation_args($e);}catch(Throwable $x){wp_die(esc_html($x->getMessage()));}
    $r=['state'=>'attempting','at'=>current_time('mysql'),'by'=>get_current_user_id(),'to'=>$a['to'],'subject'=>$a['subject'],'sha256'=>hash('sha256',$a['message'])];
    if(!add_option($key,$r,'','no'))wp_die('Confirmation already being handled.');
    $previous_message_id=(string)get_option('sa_brevo_last_message_id','');$ok=wp_mail($a['to'],$a['subject'],$a['message'],$a['headers'],[]);$message_id=(string)get_option('sa_brevo_last_message_id','');if($ok&&$message_id!==$previous_message_id)$r['message_id']=$message_id;$r['state']=$ok?'accepted by mail transport':'transport failed';update_option($key,$r,false);
    wp_safe_redirect(admin_url('admin.php?page=sa-careers-review'));exit;
});
