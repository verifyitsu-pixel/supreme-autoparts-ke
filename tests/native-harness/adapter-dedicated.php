<?php
/** ISOLATED HARNESS ONLY. No production bootstrap/filter registration. */
declare(strict_types=1);
if (!defined('SA_NATIVE_SESSION_HARNESS') || SA_NATIVE_SESSION_HARNESS !== true) {
    throw new RuntimeException('Test adapter requires isolated harness opt-in.');
}
if (!class_exists('WC_Session_Handler')) throw new RuntimeException('Boot actual Woo first.');

class SA_Dedicated_Session_Test_Adapter extends WC_Session_Handler {
    /** Harness-injected factory must return a NEW dedicated non-persistent mysqli handle. No reconnect wrapper. */
    public static $connection_factory = null;
    private ?mysqli $storage = null;
    private string $table_name = '';
    private function sql(string $query,string $types='',array $params=[]): array {
        try {
            if (!$this->storage) throw new RuntimeException('No dedicated storage connection.');
            $st=$this->storage->prepare($query);
            if (!$st) throw new RuntimeException('Prepare failed.');
            if ($types!=='' && !$st->bind_param($types,...$params)) throw new RuntimeException('Bind failed.');
            if (!$st->execute()) throw new RuntimeException('Execute failed.');
            $rows=[];
            if ($st->field_count) { $r=$st->get_result(); if (!$r) throw new RuntimeException('Read failed.'); $rows=$r->fetch_all(MYSQLI_ASSOC); }
            $affected=$st->affected_rows;$st->close();return ['rows'=>$rows,'affected'=>$affected];
        } catch (Throwable $e) { $this->poisoned=true;throw new RuntimeException('Dedicated session SQL failed.',0,$e); }
    }
    private function scalar(string $query,string $types='',array $params=[]) {
        $r=$this->sql($query,$types,$params);return $r['rows']?array_values($r['rows'][0])[0]:null;
    }
    private string $lock_name = '';
    private bool $held = false;
    private bool $poisoned = false;
    private bool $finalized = false;
    /** Harness callback: receives checkpoint and handler; may block/fail/kill worker. */
    public static $checkpoint = null;
    public array $trace = [];

    private function point(string $name): void {
        $this->trace[]=['event'=>$name,'time'=>microtime(true),'pid'=>getmypid()];
        if (is_callable(self::$checkpoint)) (self::$checkpoint)($name,$this);
    }
    public function set($key,$value) {
        if ($this->finalized || $this->poisoned) throw new RuntimeException('Session is closed to mutation.');
        parent::set($key,$value);
    }
    public function __unset($key) {
        if ($this->finalized || $this->poisoned) throw new RuntimeException('Session is closed to mutation.');
        parent::__unset($key);
    }
    private function acquire_for(string $id): void {
        if ($this->finalized) throw new RuntimeException('Session already finalized; no stale reacquire.');
        if ($this->held) {
            if ($this->lock_name !== substr('sa_test_' . hash('sha256',$id),0,64)) throw new RuntimeException('Session identity changed under lock.');
            $this->assert_lock();
            return;
        }
        $this->lock_name='sa_test_'.hash('sha256',$id);
        // MySQL lock names max64 bytes: prefix8 + hash56. Test namespace never overlaps live names.
        $this->lock_name=substr($this->lock_name,0,64);
        $this->point('lock_wait');
        if ((string)$this->scalar('SELECT GET_LOCK(?,2)','s',[$this->lock_name]) !== '1') {
            $this->poisoned=true;throw new RuntimeException('Guest session busy; retry without loading stale data.');
        }
        $this->held=true;$this->point('lock_acquired');
    }
    private function assert_lock(): void {
        if (!$this->held || (string)$this->scalar('SELECT IS_USED_LOCK(?)=CONNECTION_ID()','s',[$this->lock_name]) !== '1') {
            $this->poisoned=true;throw new RuntimeException('Session lock lost; no write allowed.');
        }
    }
    public function init() {
        if (!is_callable(self::$connection_factory)) throw new RuntimeException('Harness dedicated connection factory required.');
        $db=(self::$connection_factory)();
        if (!$db instanceof mysqli) throw new RuntimeException('Factory must return mysqli.');
        $this->storage=$db;
        if (!$db->set_charset('utf8mb4')) throw new RuntimeException('Storage charset setup failed.');
        if (!preg_match('/^[A-Za-z0-9_]+$/',$this->_table)) throw new RuntimeException('Invalid table identifier.');
        $this->table_name='`'.$this->_table.'`';
        if (strtoupper((string)$this->scalar('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?','s',[$this->_table]))!=='INNODB') throw new RuntimeException('Transactional InnoDB session table required.');
        if (!$db->autocommit(true)) throw new RuntimeException('Storage autocommit setup failed.');
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
        $this->acquire_for((string)$customer_id);$this->point('before_read');
        $value=$this->scalar('SELECT session_value FROM '.$this->table_name.' WHERE session_key=? AND IS_USED_LOCK(?)=CONNECTION_ID()','ss',[(string)$customer_id,$this->lock_name]);
        $this->assert_lock();$this->point('after_read');return $value===null?$default_value:maybe_unserialize($value);
    }
    public function save_data($old_session_key='') {
        if ($this->finalized) {if ($this->_dirty) throw new RuntimeException('Late dirty session rejected after finalization.');return;}
        if ($this->poisoned || !$this->_dirty || !$this->has_session()) return;
        if ($old_session_key!=='') throw new RuntimeException('Migration unsupported.');
        $this->acquire_for($this->get_customer_id());
        try {
            if (!$this->storage->begin_transaction()) throw new RuntimeException('Transaction start failed.');
            $this->point('before_commit');
            $blob=maybe_serialize($this->_data);
            $this->sql('INSERT INTO '.$this->table_name.' (session_key,session_value,session_expiry) SELECT ?,?,? FROM DUAL WHERE IS_USED_LOCK(?)=CONNECTION_ID() ON DUPLICATE KEY UPDATE session_value=VALUES(session_value),session_expiry=VALUES(session_expiry)','ssis',[$this->get_customer_id(),$blob,$this->_session_expiration,$this->lock_name]);
            $this->point('before_readback');
            $actual=$this->scalar('SELECT session_value FROM '.$this->table_name.' WHERE session_key=? AND IS_USED_LOCK(?)=CONNECTION_ID()','ss',[$this->get_customer_id(),$this->lock_name]);
            if ($actual!==$blob) throw new RuntimeException('Durable session readback failed.');
            $this->assert_lock();$this->point('before_sql_commit');
            if (!$this->storage->commit()) throw new RuntimeException('Durable session commit failed.');
            $this->_dirty=false;
            wp_cache_delete(WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$this->get_customer_id(),WC_SESSION_CACHE_GROUP);
            $this->point('after_commit');
        } catch (Throwable $e) {
            $this->poisoned=true;
            try {$this->storage->rollback();} catch (Throwable $ignored) {}
            throw new RuntimeException('Dedicated session commit failed: '.$e->getMessage(),0,$e);
        }
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
    public function delete_session($customer_id) {
        if (!$customer_id) return;
        if ($this->finalized || $this->poisoned) throw new RuntimeException('Session closed to deletion.');
        $this->acquire_for((string)$customer_id);
        $this->sql('DELETE FROM '.$this->table_name.' WHERE session_key=? AND IS_USED_LOCK(?)=CONNECTION_ID()','ss',[(string)$customer_id,$this->lock_name]);
        $this->assert_lock();
        wp_cache_delete(WC_Cache_Helper::get_cache_prefix(WC_SESSION_CACHE_GROUP).$customer_id,WC_SESSION_CACHE_GROUP);
    }
    public function update_session_timestamp($customer_id,$timestamp) {
        if (!$customer_id) return;
        if ($this->finalized || $this->poisoned) throw new RuntimeException('Session closed to timestamp writes.');
        $this->acquire_for((string)$customer_id);
        $this->sql('UPDATE '.$this->table_name.' SET session_expiry=? WHERE session_key=? AND IS_USED_LOCK(?)=CONNECTION_ID()','iss',[(int)$timestamp,(string)$customer_id,$this->lock_name]);
        $this->assert_lock();
    }
    public function cleanup_sessions() {throw new RuntimeException('Global cleanup unsupported in isolated guest adapter.');}
    /** Harness fault injection only: expose non-secret connection id, never connection credentials. */
    public function harness_connection_id(): int {return (int)$this->scalar('SELECT CONNECTION_ID()');}
    public function finish(): void {
        if ($this->finalized) return;
        try {if (!$this->poisoned) $this->save_data();}
        catch (Throwable $e) {$this->trace[]=['event'=>'shutdown_commit_error','message'=>$e->getMessage()];throw $e;}
        finally {
            $this->finalized=true;
            if ($this->storage) {
                try {if ($this->held) $this->scalar('SELECT RELEASE_LOCK(?)','s',[$this->lock_name]);} catch (Throwable $ignored) {}
                try {$this->storage->close();} catch (Throwable $ignored) {}
                $this->storage=null;
            }
            $this->held=false;$this->point('lock_released');
        }
    }
}
// Harness-only; never production-register this adapter.
