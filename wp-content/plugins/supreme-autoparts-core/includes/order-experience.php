<?php
/** Private shopping-experience feedback, never a public or Google review. */
if (!defined('ABSPATH')) { exit; }
function sa_order_experience_form($order): string {
    if (!$order instanceof WC_Order || !$order->is_paid() || !$order->get_date_paid()) { return ''; }
    $id = $order->get_id();
    ob_start(); ?>
    <section class="sa-thankyou__card sa-experience" aria-labelledby="sa-experience-title">
      <h2 id="sa-experience-title">How was shopping with Supreme?</h2>
      <p>Thank you for choosing Supreme. How did we do with your shopping experience?</p>
      <form class="sa-experience__form" method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
        <input type="hidden" name="action" value="sa_order_experience">
        <input type="hidden" name="order_id" value="<?php echo esc_attr($id); ?>">
        <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
        <input type="hidden" name="token" value="<?php echo esc_attr(wp_create_nonce('sa_experience_' . $id)); ?>">
        <fieldset><legend>Your shopping experience</legend><div class="sa-experience__stars">
        <?php for ($i=1;$i<=5;$i++): ?><label><input type="radio" name="rating" value="<?php echo $i; ?>" aria-label="<?php echo $i; ?> out of 5 stars" required><span aria-hidden="true">★</span></label><?php endfor; ?>
        </div></fieldset>
        <label for="sa-experience-comment">Anything you would like us to know? (optional)</label>
        <textarea id="sa-experience-comment" name="comment" rows="4" maxlength="2000" placeholder="Tell us what worked or what we could improve."></textarea>
        <p class="sa-experience__privacy">Private feedback for the Supreme team. This does not post a Google or product review. Please do not include payment details.</p>
        <button type="submit" class="sa-btn">Send feedback</button><p class="sa-experience__result" role="status" aria-live="polite"></p>
      </form>
    </section>
    <script>(function(){const f=document.querySelector('.sa-experience__form');if(!f)return;f.addEventListener('change',function(){const v=Number(new FormData(f).get('rating'));f.querySelectorAll('.sa-experience__stars label').forEach((l,i)=>l.classList.toggle('is-selected',i<v));});f.addEventListener('submit',async function(e){e.preventDefault();if(!f.reportValidity())return;const b=f.querySelector('button'),r=f.querySelector('[role=status]');b.disabled=true;r.textContent='Saving your feedback...';try{const res=await fetch(f.action,{method:'POST',body:new FormData(f),credentials:'same-origin'});const data=await res.json();if(!data.success)throw Error(data.data?.message||'We could not save your feedback. Please try again.');r.textContent='Thank you. Your feedback has been saved for the Supreme team.';}catch(err){r.textContent=err.message||'We could not save your feedback. Please try again.';}finally{b.disabled=false;}});})();</script>
    <?php return (string) ob_get_clean();
}
function sa_order_experience_save(): void {
    foreach (['order_id','token','order_key','rating','comment'] as $field) {
        if (isset($_POST[$field]) && !is_scalar($_POST[$field])) { wp_send_json_error(['message'=>'Invalid feedback form.'],400); }
    }
    $id = absint($_POST['order_id'] ?? 0);
    if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['token'] ?? '')), 'sa_experience_' . $id)) { wp_send_json_error(['message'=>'Please refresh this confirmation page and try again.'],403); }
    $o = wc_get_order($id);
    $key = (string) wp_unslash($_POST['order_key'] ?? '');
    if (!$o || !$o->is_paid() || !$o->get_date_paid() || !hash_equals((string)$o->get_order_key(),$key)) { wp_send_json_error(['message'=>'This order cannot accept feedback.'],403); }
    $rating = (string) wp_unslash($_POST['rating'] ?? '');
    if (!preg_match('/^[1-5]$/D',$rating)) { wp_send_json_error(['message'=>'Please choose 1 to 5 stars.'],400); }
    $comment = sanitize_textarea_field(wp_unslash($_POST['comment'] ?? ''));
    if (mb_strlen($comment)>2000) { wp_send_json_error(['message'=>'Please keep your comment under 2000 characters.'],400); }
    $o->update_meta_data('_sa_shopping_experience_rating',(int)$rating);
    $o->update_meta_data('_sa_shopping_experience_comment',$comment);
    $o->update_meta_data('_sa_shopping_experience_at',gmdate('c'));
    $o->save_meta_data();
    wp_send_json_success(['saved'=>true]);
}
add_action('wp_ajax_sa_order_experience','sa_order_experience_save');
add_action('wp_ajax_nopriv_sa_order_experience','sa_order_experience_save');
add_action('woocommerce_admin_order_data_after_order_details',static function($o){
    if (!current_user_can('manage_woocommerce') || !$o instanceof WC_Order) { return; }
    $r=(int)$o->get_meta('_sa_shopping_experience_rating');if($r<1||$r>5)return;
    echo '<p><strong>Private shopping feedback:</strong> '.esc_html($r.' / 5').'<br>'.nl2br(esc_html((string)$o->get_meta('_sa_shopping_experience_comment'))).'</p>';
});
