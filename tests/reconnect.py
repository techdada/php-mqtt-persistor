#!/usr/bin/env python3
"""Local fault-injection broker: no third-party dependencies or real credentials."""
import os, socket, subprocess, time
from pathlib import Path
root = Path(__file__).resolve().parents[1]
php = os.environ.get('PHP', 'php')

def exact(c,n):
    b=b''
    while len(b)<n:
        x=c.recv(n-len(b))
        if not x: raise EOFError()
        b+=x
    return b

def packet(c):
    cmd=exact(c,1)[0]; n=0; m=1
    while True:
        d=exact(c,1)[0]; n+=(d&127)*m; m*=128
        if not d&128: break
    return cmd,exact(c,n)

s=socket.socket(); s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
p=subprocess.Popen([php,str(root/'tests/mqtt-client.php'),str(port)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
try:
    # Start unavailable: the first connection must fail and later recover.
    time.sleep(.5); s.listen(); s.settimeout(22)
    def accept():
        c,_=s.accept(); c.settimeout(7)
        cmd,b=packet(c); assert cmd==16 and b.startswith(b'\x00\x04MQTT\x04')
        assert b'test' in b and b'password' in b
        c.sendall(b'\x20\x02\x00\x00')
        cmd,b=packet(c); assert cmd==0x82 and b[2:]==b'\x00\x06test/+\x00'
        c.sendall(b'\x90\x03'+b[:2]+b'\x00')
        return c
    def publish(c,value):
        b=b'\x00\x06test/a'+value
        c.sendall(bytes([0x30,len(b)])+b)
    c=accept(); publish(c,b'one'); c.close() # EOF
    c=accept(); publish(c,b'two')
    # Ignore PINGREQ: client must abandon this half-open connection.
    other=accept(); c.close(); c=other; publish(c,b'three')
    # Send a truncated packet, never complete it: read timeout must recover.
    c.sendall(b'\x30\x20\x00'); other=accept(); c.close(); c=other
    publish(c,b'four'); time.sleep(.2); c.close()
    p.terminate(); out,err=p.communicate(timeout=3)
    for v in ['one','two','three','four']: assert 'test/a='+v in out, (out,err)
    print('PASS: initial outage, EOF reconnect, ping timeout, truncated packet, resubscription, credentials, delivery after each reconnect')
finally:
    s.close()
    if p.poll() is None: p.kill(); p.communicate()
