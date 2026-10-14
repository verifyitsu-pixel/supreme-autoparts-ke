#!/usr/bin/env python3
"""Fetch key pages per simulated country and list other-country copy that leaks into visible text."""
import re, sys, subprocess, html
B = sys.argv[1] if len(sys.argv) > 1 else 'https://www.supremeautoparts.co.ke'
PAGES = ['/', '/shop/', '/product/dba-13-22-honda-accord-ex-4cyl-w-292mm-front-rotor-front-xp-performance-brake-pads/', '/cart/', '/checkout/', '/contact/', '/shipping-policy/', '/about-us/', '/services/', '/roadside/']
KE_BAD = [r'continental US', r'Ships US', r'US drivers', r'\+1 917', r'Free US shipping', r'Ships across the US', r'US-spec', r'USD\b', r'\$\d']
US_BAD = [r'\bKES\b', r'Fargo', r'\bcount(y|ies)\b', r'Store pickup', r'Nairobi', r'Njiru', r'Kangundo', r'M-Pesa', r'\+254', r'Kenya']
ROW_BAD = [r'Fargo', r'Store pickup', r'Nairobi', r'Njiru', r'continental US', r'Free US shipping', r'Ships across the US']
def text(cc, path):
    out = subprocess.run(['curl', '-sL', '-H', 'X-Country-Code: ' + cc, '-b', '/tmp/aud_' + cc, '-c', '/tmp/aud_' + cc, B + path], capture_output=True, text=True).stdout
    head = ' '.join(re.findall(r'<title>[^<]*|<meta[^>]+(?:description|og:title|og:description)"[^>]*>', out))
    body = re.sub(r'<script[\s\S]*?</script>|<style[\s\S]*?</style>|<datalist[\s\S]*?</datalist>|<select[\s\S]*?</select>', ' ', out)
    body = html.unescape(re.sub(r'<[^>]+>', ' ', body))
    return re.sub(r'\s+', ' ', body), head
subprocess.run(['curl', '-s', '-o', '/dev/null', '-H', 'X-Country-Code: US', '-b', '/tmp/aud_US', '-c', '/tmp/aud_US', B + '/?add-to-cart=289'])
for cc in ('KE', 'US'): subprocess.run(['curl', '-s', '-o', '/dev/null', '-H', 'X-Country-Code: ' + cc, '-b', '/tmp/aud_' + cc, '-c', '/tmp/aud_' + cc, B + '/?add-to-cart=289'])
for cc, bad in (('KE', KE_BAD), ('US', US_BAD), ('GB', ROW_BAD)):
    print('=====', cc)
    for p in PAGES:
        t, head = text(cc, p)
        hits = []
        for b in bad:
            for m in re.finditer(b, t):
                hits.append(t[max(0, m.start()-50):m.end()+40])
        print(f'{p}: {len(hits)}')
        for h in hits[:4]: print('   …', h)
