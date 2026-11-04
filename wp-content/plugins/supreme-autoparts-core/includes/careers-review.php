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
        echo '</form></td></tr>';
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
