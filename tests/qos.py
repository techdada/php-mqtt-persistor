#!/usr/bin/env python3
"""Exercise configured subscription QoS and inbound QoS 0/1/2 handshakes."""
import os, socket, subprocess, time
from pathlib import Path
root=Path(__file__).resolve().parents[1]
php=os.environ.get('PHP','php')
def exact(c,n):
    b=b''
    while len(b)<n:
        part=c.recv(n-len(b))
        if not part: raise EOFError()
        b+=part
    return b
def packet(c):
    cmd=exact(c,1)[0]; n=0; m=1
    while True:
        d=exact(c,1)[0]; n+=(d&127)*m; m*=128
        if not d&128: return cmd,exact(c,n)
def publish(c,qos,value,dup=False):
    b=b'\x00\x06test/a'+(b'\x00\x07' if qos else b'')+value
    c.sendall(bytes([0x30|(qos<<1)|(8 if dup else 0),len(b)])+b)
for qos in range(3):
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); s.listen(); s.settimeout(8)
        p=subprocess.Popen([php,str(root/'tests/qos-client.php'),str(s.getsockname()[1]),str(qos)],stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        try:
            def accept():
                c,_=s.accept(); c.settimeout(3)
                assert packet(c)[0]==16
                c.sendall(b'\x20\x02\x00\x00')
                cmd,b=packet(c); assert cmd==0x82 and b[-1]==qos
                c.sendall(b'\x90\x03'+b[:2]+bytes([qos]))
                return c
            c=accept()
            for received in range(qos+1):
                publish(c,received,b'value'+str(received).encode())
                if received==1: assert packet(c)==(0x40,b'\x00\x07')
                if received==2:
                    assert packet(c)==(0x50,b'\x00\x07')
                    publish(c,2,b'value2',True)
                    assert packet(c)==(0x50,b'\x00\x07')
                    c.sendall(b'\x62\x02\x00\x07')
                    assert packet(c)==(0x70,b'\x00\x07')
                    c.sendall(b'\x62\x02\x00\x07')
                    assert packet(c)==(0x70,b'\x00\x07')
            c.close(); c=accept() # configured QoS survives reconnect
            publish(c,qos,b'after')
            if qos==1: assert packet(c)==(0x40,b'\x00\x07')
            if qos==2:
                assert packet(c)==(0x50,b'\x00\x07')
                c.sendall(b'\x62\x02\x00\x07')
                assert packet(c)==(0x70,b'\x00\x07')
            if qos:
                publish(c,qos,b'fail')
                if qos==2:
                    assert packet(c)==(0x50,b'\x00\x07')
                    c.sendall(b'\x62\x02\x00\x07')
                # A failed persistence callback must not be acknowledged as completed.
                assert c.recv(4)==b''
                assert p.wait(timeout=3)!=0
            else:
                time.sleep(.1); p.terminate()
            out,err=p.communicate(timeout=3)
            for received in range(qos+1): assert out.count('test/a=value'+str(received)+'\n')==1,(out,err)
            assert out.count('test/a=after\n')==1,(out,err)
            c.close()
        finally:
            if p.poll() is None: p.kill(); p.communicate()
for invalid in ['-1','3','abc','1.0','']:
    r=subprocess.run([php,str(root/'tests/qos-client.php'),'1',invalid],capture_output=True,text=True)
    assert r.returncode!=0 and 'mqtt_qos must be' in r.stderr+r.stdout
print('PASS: QoS 0/1/2 config, lower delivery QoS, ACKs, QoS 2 duplicates, reconnect, DB failure without completion ACK, invalid settings')
