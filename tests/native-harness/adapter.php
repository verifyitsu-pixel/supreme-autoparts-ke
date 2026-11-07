<?php
/** ISOLATED HARNESS ONLY. No production bootstrap/filter registration. */
declare(strict_types=1);
if (!defined('SA_NATIVE_SESSION_HARNESS') || SA_NATIVE_SESSION_HARNESS !== true) {
    throw new RuntimeException('Test adapter requires isolated harness opt-in.');
}
if (!class_exists('WC_Session_Handler')) throw new RuntimeException('Boot actual Woo first.');

class SA_Native_Session_Test_Adapter extends WC_Session_Handler {
    private string $lock_name = '';
    private bool $held = false;
    private bool $poisoned = false;
    /** Harness callback: receives checkpoint and handler; may block/fail/kill worker. */
    public static $checkpoint = null;
    public array $trace = [];

    private function point(string $name): void {
        $this->trace[]=['event'=>$name,'time'=>microtime(true),'pid'=>getmypid()];
        if (is_callable(self::$checkpoint)) (self::$checkpoint)($name,$this);
    }
    private function acquire_for(string $id): void {
        if ($this->held) {
            if ($this->lock_name !== substr('sa_test_' . hash('sha256',$id),0,64)) throw new RuntimeException('Session identity changed under lock.');
            $this->assert_lock();
            return;
        }
        global $wpdb;
        $this->lock_name='sa_test_'.hash('sha256',$id);
        // MySQL lock names max64 bytes: prefix8 + hash56. Test namespace never overlaps live names.
        $this->lock_name=substr($this->lock_name,0,64);
        $this->point('lock_wait');
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 2)',$this->lock_name)) !== '1') {
            $this->poisoned=true;throw new RuntimeException('Guest session busy; retry without loading stale data.');
        }
        $this->held=true;$this->point('lock_acquired');
    }
    private function assert_lock(): void {
        global $wpdb;
        if (!$this->held || (string)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()',$this->lock_name)) !== '1') {
            $this->poisoned=true;throw new RuntimeException('Session lock lost; no write allowed.');
        }
    }
    public function init() {
        // No account writes or token cloning/migration in the first adapter experiment.
        if (is_user_logged_in() || !empty($_GET['session'])) throw new RuntimeException('Test adapter supports cookie-owned guest sessions only.');
        $cookie=$this->get_session_cookie();
        if ($cookie) $this->acquire_for((string)$cookie[0]);
        parent::init();
        if (!$this->held) $this->acquire_for($this->get_customer_id());
        add_action('shutdown',[$this,'finish'],PHP_INT_MAX);
        $this->point('initialized');
    }
    public function generate_customer_id() {
        $id=parent::generate_customer_id();$this->acquire_for($id);return $id;
    }
    public function get_session($customer_id,$default_value=false) {
        global $wpdb;
        $this->acquire_for((string)$customer_id);
        $this->point('before_read');
        // Read persisted blob directly under advisory lock, not possibly stale object cache.
        $value=$wpdb->get_var($wpdb->prepare('SELECT session_value FROM %i WHERE session_key=%s',$this->_table,$customer_id));
        if ($wpdb->last_error) {$this->poisoned=true;throw new RuntimeException('Session read failed.');}
        $this->point('after_read');return $value===null?$default_value:maybe_unserialize($value);
    }
    public function save_data($old_session_key='') {
        if ($this->poisoned) return;
        if (!$this->_dirty || !$this->has_session()) return;
        if ($old_session_key!=='') throw new RuntimeException('Account/session migration unsupported in isolated adapter.');
        $this->acquire_for($this->get_customer_id());
        global $wpdb;
        $this->point('before_commit');
        $blob=maybe_serialize($this->_data);
        $ok=$wpdb->query($wpdb->prepare('INSERT INTO %i (session_key,session_value,session_expiry) VALUES (%s,%s,%d) ON DUPLICATE KEY UPDATE session_value=VALUES(session_value),session_expiry=VALUES(session_expiry)',$this->_table,$this->get_customer_id(),$blob,$this->_session_expiration));
        if ($ok===false) {$this->poisoned=true;throw new RuntimeException('Durable session commit failed.');}
        // Exact persisted readback before acknowledgement. InnoDB statement commit is atomic.
        $actual=$wpdb->get_var($wpdb->prepare('SELECT session_value FROM %i WHERE session_key=%s',$this->_table,$this->get_customer_id()));
        if ($actual!==$blob) {$this->poisoned=true;throw new RuntimeException('Durable session readback failed.');}
        $this->_dirty=false;
        // All test guest readers bypass cache; evict parent cache for any later normal-reader probe.
        wp_cache_delete(WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$this->get_customer_id(),WC_SESSION_CACHE_GROUP);
        $this->point('after_commit');
    }
    /** One atomic operation against actual native WC_Cart loaded under this session lock. */
    public function operation(string $id,string $fingerprint,callable $mutate): array {
        if (!$this->held || $this->poisoned) throw new RuntimeException('No writable locked session.');
        $this->assert_lock();
        if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/',$id)) throw new InvalidArgumentException('Invalid operation id.');
        $ops=$this->get('sa_test_operations',[]);
        if (isset($ops[$id])) {
            if (!hash_equals($ops[$id]['fingerprint'],$fingerprint)) throw new RuntimeException('Operation id reused for another action.');
            return $ops[$id]['outcome'];
        }
        if (count($ops)>=100) throw new RuntimeException('Test operation ledger full; no unsafe eviction.');
        $before=$this->_data;
        $cart=WC()->cart;
        $cart_before=$cart->get_cart();
        try {
            $outcome=$mutate($cart,$this);
            if (!is_array($outcome)) throw new RuntimeException('Operation must return structured outcome.');
            // WC normally serializes this during totals; force the native session serializer before commit.
            $cart->calculate_totals();
            (new WC_Cart_Session($cart))->set_session();
            $ops[$id]=['fingerprint'=>$fingerprint,'outcome'=>$outcome];$this->set('sa_test_operations',$ops);
            $this->set_customer_session_cookie(true);
            $this->save_data();
            $this->point('before_response');
            return $outcome;
        } catch (Throwable $e) {
            // Never allow the parent shutdown callback to overwrite a committed/uncertain outcome.
            $this->poisoned=true;$this->_data=$before;$this->_dirty=false;
            $cart->set_cart_contents($cart_before);
            throw $e;
        }
    }
    public function finish(): void {
        try {if (!$this->poisoned) $this->save_data();}
        catch (Throwable $e) {$this->trace[]=['event'=>'shutdown_commit_error','message'=>$e->getMessage()];}
        finally {
            if ($this->held) {global $wpdb;$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$this->lock_name));$this->held=false;$this->point('lock_released');}
        }
    }
}
// Harness installs through woocommerce_session_handler BEFORE WC initialize_session. Never autoload on live.
