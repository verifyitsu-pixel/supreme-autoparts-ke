import pymysql,subprocess,sys,time,json,os
SOCK='/tmp/mysql-confirm81/mysql.sock'
def db():return pymysql.connect(unix_socket=SOCK,user='root',autocommit=True)
def worker(mode):
 c=db();q=c.cursor();q.execute("SELECT GET_LOCK('sa_paid_confirmation_1',0)");got=q.fetchone()[0]
 if got!=1:print(json.dumps({'mode':mode,'lock':got,'transport':0}));return
 try:
  q.execute('SELECT pending,sent FROM sa_confirm_test.state WHERE id=1');pending,sent=q.fetchone()
  if pending or sent: print(json.dumps({'mode':mode,'lock':got,'transport':0,'held':True}));return
  q.execute('UPDATE sa_confirm_test.state SET pending=1 WHERE id=1');time.sleep(.35)
  if mode=='crash':os._exit(7)
  q.execute('INSERT INTO sa_confirm_test.transport VALUES (1)');q.execute('UPDATE sa_confirm_test.state SET pending=0,sent=1 WHERE id=1');print(json.dumps({'mode':mode,'lock':got,'transport':1}))
 finally:q.execute("SELECT RELEASE_LOCK('sa_paid_confirmation_1')");c.close()
if len(sys.argv)>1:worker(sys.argv[1]);sys.exit()
c=db();q=c.cursor();q.execute('CREATE DATABASE sa_confirm_test');q.execute('CREATE TABLE sa_confirm_test.state(id INT PRIMARY KEY,pending INT,sent INT)');q.execute('CREATE TABLE sa_confirm_test.transport(id INT)');q.execute('INSERT INTO sa_confirm_test.state VALUES(1,0,0)');q.execute('SELECT VERSION()');print('Server',q.fetchone()[0])
a=subprocess.Popen([sys.executable,__file__,'first'],stdout=subprocess.PIPE,text=True);time.sleep(.1);b=subprocess.run([sys.executable,__file__,'second'],capture_output=True,text=True);print(b.stdout.strip());print(a.communicate()[0].strip());print(subprocess.run([sys.executable,__file__,'replay'],capture_output=True,text=True).stdout.strip());q.execute('SELECT COUNT(*) FROM sa_confirm_test.transport');assert q.fetchone()[0]==1
q.execute('UPDATE sa_confirm_test.state SET pending=0,sent=0');subprocess.run([sys.executable,__file__,'crash']);print(subprocess.run([sys.executable,__file__,'after-crash'],capture_output=True,text=True).stdout.strip());q.execute('SELECT COUNT(*) FROM sa_confirm_test.transport');assert q.fetchone()[0]==1
print('PASS real MySQL zero-wait competing processes, replay, connection-death lock release and durable unknown-outcome block. Protocol harness only, not Woo integration.')
